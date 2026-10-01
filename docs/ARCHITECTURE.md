# Architecture

## The stack

| Component | Role | Why this one |
|---|---|---|
| **FrankenPHP** | Web server (every site's TLS, static files) + the panel's own PHP | Caddy-based, automatic HTTPS built in |
| **PHP-FPM** | The customer sites' PHP: one pool per hosting account, as that account's own Linux user | Process-level isolation between accounts, and between accounts and the panel |
| **MariaDB** | Database (panel's own state + every customer database) | Standard, well-understood, MySQL-wire-compatible |
| **Stalwart Mail** | SMTP/IMAP/JMAP mail server | Single Rust binary combining what used to be Postfix+Dovecot, with a REST-ish management API instead of flat config files |
| **SFTPGo** | SFTP server | Virtual users managed entirely via REST API - no local Linux user per hosting account |
| **Knot DNS** | Authoritative DNS | Fast, scriptable via `knotc`, used for zones this panel provisions |

All of them run as systemd services on one AlmaLinux box. JinnPanel itself
is a plain-PHP application (no framework, no Composer) deployed to
`/var/www/hostpanel` (root-owned, readable by `frankenphp` only), served by
FrankenPHP as its own vhost (`panel.<hostname>`, and `<domain>:2083`).

## The one architectural decision that shapes everything else

**FrankenPHP's process is deliberately sandboxed, and that sandbox blocks
things a hosting panel normally needs to do.** Two separate, verified
restrictions:

1. `ProtectSystem=full` in FrankenPHP's systemd unit makes `/etc` read-only
   for the whole process tree (including anything it forks). It genuinely
   cannot write `php.ini`, `my.cnf`, or any other `/etc` config file itself.
2. SELinux denies `httpd_t` (FrankenPHP's domain) from talking to systemd
   over D-Bus at all - `systemctl restart`, `journalctl -u`, even
   `systemctl is-active` for *other* units all come back "Access denied."
   This is intentional confinement, not a misconfiguration to route around.
3. A third, narrower one, verified by direct testing: forking a child
   process from FrankenPHP that then `connect()`s to a **Unix domain
   socket** reliably fails with `EPERM` - specifically that combination.
   The identical command succeeds via plain `php-cli`, via `nsenter` into
   the exact same mount/PID namespaces, and whether run in the foreground or
   backgrounded. Only an actual `fork()` from FrankenPHP's own process hits
   it. (SELinux was ruled out directly - the failure persists with SELinux
   in fully permissive mode.) This is very likely a FrankenPHP/Go-runtime
   quirk around forking children from a multi-threaded Go process with an
   embedded PHP SAPI, not anything specific to this deployment. It's why
   Knot DNS's `knotc` (Unix socket) can't be called directly from the app,
   while curl calls to Stalwart/SFTPGo (plain HTTP/TCP, no forking) work
   fine as normal PHP code.

**The fix, consistently applied everywhere it's needed:** a small PHP
script (`app/worker/hostpanel-worker.php`) that a **systemd timer** runs
every 5 seconds - as root, spawned by PID 1, completely outside FrankenPHP's
process tree. The web app never does privileged work itself; it drops a
small JSON "job" file into `storage/config-queue/`, and the worker picks it
up, applies it, and deletes the job file. Every "Save & restart X" button,
every DNS zone, every account/site change goes through this queue.

The worker trusts job files as little as possible: each is signed
(HMAC with a key derived from `APP_KEY`, `SystemWorkerService::enqueue`),
must be a regular file owned by `frankenphp` or root (never a link), and
each job type validates its own input - settings jobs accept only a fixed
list of keys with a value shape each (`INI_KEYS`, `MYCNF_KEYS`,
`SFTPGO_KEYS`); account jobs carry only an id and the worker reads
everything else from the database.

This also solves the read side: since FrankenPHP can't read the systemd
journal either, the same worker snapshots each service's last 200 journal
lines into `/var/lib/jinnpanel/worker/live-<service>.log` every cycle, which
the WHM dashboard just reads as a plain file. Everything root writes for
the panel (job logs, snapshots, the PHP extension list) goes to that
root-owned folder - never into `storage/`, which `frankenphp` can write
(a link planted there would aim root's writes anywhere).

## Customer isolation

Every hosting account is a Linux user, `jp_<username>` (UID 20000+, no
shell, locked password), created and kept in line by the worker's
`accountSync` (job `account_sync`, run on every account/domain change and
by `install.sh`):

- **Files.** `/var/www/<domain>` belongs to the account, mode 0750, plus an
  ACL letting `frankenphp` in (Caddy checks which files exist; it doesn't
  serve them - see *Static files*) - other accounts can't even list it. A folder the panel or a
  migration created is handed over (`chown -R -P -h`, no world-writable
  bits). `/var/www` itself is `root:frankenphp 1775`: the panel may create
  new site folders, the sticky bit stops it from renaming anyone else's.
- **PHP.** Each PHP version is one PHP-FPM master
  (`jinnpanel-php-fpm@default`, `@82`, ...; template unit from install.sh,
  config in `/etc/jinnpanel/php-fpm/<tag>/`), with one pool per account:
  `user = jp_<name>`, socket `/run/jinnpanel-php/<tag>/<name>.sock` that only
  `frankenphp` can open, `open_basedir` = the account's site folders, its
  page-cache folders, its private home (`/var/lib/jinnpanel/php/<name>`:
  sessions, temp files) and the panel's per-site settings, plus
  `disable_functions` for exec/proc_open & co. unless an admin allows them
  per account (WHM > Accounts, `users.php_exec`). OPcache is shared per
  master, with `opcache.validate_permission` and `opcache.restrict_api`.
  The FPM binary is labelled `httpd_exec_t`, so it runs as `httpd_t` like
  FrankenPHP and EL's own php-fpm.
- **Static files.** Caddy never sends a customer file itself: every
  non-PHP request goes to the account's own static server,
  `jinnpanel-static@<name>` - nginx running as `jp_<name>`, config from
  `staticServerSync` in `/etc/jinnpanel/static/<name>.conf`, sockets in
  `/run/jinnpanel-static/<name>/` (only frankenphp may connect). It reads
  files with the account's rights only, so a link in a site folder pointing
  at another account's files or the panel's gets nothing, and it refuses
  links to files the account doesn't own (`disable_symlinks if_not_owner`).
  Caddy passes the effective document root (`X-JP-Root`, checked against
  the account's own site folders) and the domain's cache lifetime
  (`X-JP-TTL`). In front of the files sits a per-domain response cache of
  gzip-compressed copies (`/var/lib/jinnpanel-static-cache/<name>/<domain>`,
  32 MB each; cPanel > Cache > Static file cache: on/off, lifetime, clear;
  File Manager changes clear it). Caddy still opens files to see whether
  they exist (try_files, file matchers), hence its read ACL - but it never
  serves their contents.
- **Vhosts.** `VhostService` renders `php_fastcgi <pool socket>` + a
  `reverse_proxy` to the static server where sites used to say
  `php_server`/`file_server`; routing rules translated from `.htaccess`
  still say `php_server` (the editor's language) and are rewritten on
  render (`VhostService::fpmRules`; `handle_errors` pages keep their status
  through `copy_response <code>`). A suspended account's sites answer 503,
  one over its monthly bandwidth 509.
- **The panel acting on customer files.** The panel can only read into
  site folders, so file work (File Manager, Exposed files, Routes' .htaccess
  scan, clearing OPcache) runs *inside the account's own pool*:
  `PoolClient` sends a FastCGI request (`FastCgi`) to the pool's socket for
  `runtime/pool-agent.php` (installed root-owned in
  `/usr/local/lib/jinnpanel/pool/` with the classes it uses). The agent
  refuses anything without the `JINNPANEL_AGENT` FastCGI parameter, which a
  web request can't carry. Files it writes belong to the account.
- **SFTP.** Logins are SFTPGo virtual users whose uid/gid are the
  account's; SFTPGo holds `CAP_CHOWN`/`CAP_FOWNER`/`CAP_DAC_OVERRIDE` (unit
  drop-in) to write as them. Account-wide logins see each site as
  `/<domain>` (SFTPGo virtual folders).
- **Cron.** `jinnpanel-cron` runs as root and starts each PHP job as the
  account's user with the pool's limits (`CronService::phpCommand`).
- **Elsewhere.** Caddy's admin API is a Unix socket
  (`/run/frankenphp/admin.sock`, frankenphp only) - on 127.0.0.1:2019 any
  local process could load a new config. `Config.php` is
  `root:frankenphp 0640`. Valkey logins are per account (key prefix); MySQL
  users per account.

Deleting an account removes its pools and Linux user and moves its site
folders to `/var/lib/jinnpanel/removed/` (root-only) - left in place they'd
belong to a bare UID the next account could be given.

## Multiple PHP versions

Each additional PHP version (8.2/8.3/8.4 alongside the default 8.5) is its
own PHP-FPM master, `jinnpanel-php-fpm@<82|83|84>`. `install_php_version`
(run by the worker, not the web app):

1. Resolves the exact RPM URLs for that version's `php-zts-fpm`,
   `php-zts-cli` and common extension packages from the upstream
   `static-php` repo via `dnf repoquery --disable-modular-filtering`
   (bypassing `dnf module`, which is exclusive-per-stream and would replace
   the default version's packages).
2. Extracts them (`rpm2cpio`/`cpio`, not `rpm -i`) into
   `/opt/php-versions/<version>/` (`php-fpm`, `php`, `modules/`, `conf.d/`,
   its own `php.ini`), labels `php-fpm` `httpd_exec_t`, and checks it runs.
3. Starts its master with a unit drop-in pointing at that binary and ini.

A domain on that version gets its account's pool in that master
(`accountSync` writes one pool file per version the account uses); its
cron jobs use that version's CLI.

## AutoSSL

Each domain has an `ssl_mode`: `self_signed` (Caddy's local dev CA - works
immediately, browsers warn once) or `letsencrypt` (the vhost simply omits
the `tls internal` line, handing the domain to Caddy's own automatic HTTPS
engine). There's no custom ACME code here - Caddy's automatic HTTPS is
mature and does the whole thing itself once a domain block exists without
an explicit `tls` directive. The only real-world requirement, which no
panel can shortcut: the domain has to actually resolve to this server
publicly, with ports 80/443 reachable, for Let's Encrypt's HTTP-01
challenge to succeed.

## Server Config / Stalwart settings

Stalwart has no fixed REST API for its ~150 system settings objects (thread
pool size, cache sizing, spam thresholds, TLS reporting, ...) - the same
structured system its own setup wizard uses is exposed at `/api/schema`
(a JMAP session + a generic `{ObjectName}/get` and `{ObjectName}/set`
per object). `StalwartAdminService` talks that protocol directly and
`ServerConfigController`'s mail settings pages render a form **generated
from the live schema** - one code path covers all ~150 objects rather than
hand-building forms for each. Simple field types (string/number/
boolean/enum) get real inputs; structural types (object/list/set/map) fall
back to a raw-JSON textarea, still fully editable.

## cPanel migrations

WHM > cPanel Migration (full guide: `docs/MIGRATION.md`) follows the same
"privileged or long-running work never happens inside a FrankenPHP
request" rule as everything else, one step further. `MigrationController`
only validates input and records a `migrations` row plus one
`migration_items` row per account; `MigrationService` queues a
`migration_start` job; `hostpanel-worker.php` turns that into a transient
systemd unit (`jinnpanel-migration-<id>`) running
`app/worker/migration-runner.php` as **frankenphp** - not root, because
everything it unpacks came from another server, and not a FrankenPHP child,
because a single account can take hours. It creates the new site folders
itself; at the end it asks for an `account_sync`, which hands the files to
the account's Linux user and starts its PHP pool, and only then creates
the account's SFTP logins (they write as that user).

`MigrationRunner` then, per account: asks the source for a full backup
through `CpanelApiClient` (WHM API 1 / UAPI, with `uapi_cpanel` proxying
UAPI calls for reseller and root logins), receives it by download (pull)
or via a single-use SFTPGo user (push), extracts it, and restores it
through `CpanelBackupReader` (which validates every name and path in the
backup) using the same services the panel already uses - `VhostService`,
`DnsService`, `ProvisioningService`, `MailService` - plus
`MailImportService` for stored mail over JMAP. Progress is written to the
item rows and a per-migration log, a heartbeat detects a dead runner, and
`AccountCleanupService` (shared with WHM > Accounts > Delete) rolls back a
half-restored account. Source credentials are encrypted with
`Crypto` (AES-256-GCM, key `Config::APP_KEY`) and wiped when no longer
needed.

## Data model

MariaDB `hostpanel` database: `users` (role: admin/reseller/user, with
`parent_id` scoping a user to the reseller who created them), `packages`
(quotas; `owner_id` NULL = global, else a reseller's own custom package),
`domains` (+ `php_version`, `php_port`, `ssl_mode`), `db_instances`,
`email_accounts`, `ftp_accounts`, `php_versions` (installed alt PHP
versions), `activity_log` (the audit trail), `login_attempts` (sign-in
throttling), `account_usage` (measured disk/bandwidth), `backups`, plus `migrations` /
`migration_items` (cPanel migrations and their per-account progress and
reports) and `db_user_accounts` (extra MySQL users an account owns - a
migrated cPanel account can have several users per database and several
databases per user, so `db_instances.db_user` isn't unique).

Two separate MariaDB accounts back the app: `hostpanel_app` (least
privilege, scoped only to the `hostpanel` database itself) and
`hostpanel_prov` (a narrower, explicitly-scoped set of privileges - NOT
`ALL PRIVILEGES` - used only by `ProvisioningService` to create/drop
per-customer databases and users; it can only re-grant privileges it
itself holds, which is why customer databases get a specific privilege
list rather than `GRANT ALL`).

## Security posture

- Customer code runs as its own account's Linux user in its own PHP-FPM
  pool - see *Customer isolation* above.
- Every mutating request goes through `Csrf::requireValid()`; logout is a
  POST too.
- Sign-in: bcrypt (`password_hash`); throttled (`LoginThrottle`: 5 failures
  per username and address, or 20 per address, in 15 minutes); optional
  TOTP two-factor (`Totp`, RFC 6238, codes single-use) for every role;
  sessions end after 2 hours idle / 12 hours, on password or two-factor
  changes (`users.session_version`) and when the account is suspended.
  Roles are read from the database on every request, never trusted from
  the session. New passwords need 10+ characters and aren't common ones.
- `/setup` (creating the first admin) needs the one-time token install.sh
  prints.
- The panel is HTTPS only (`http://panel.<host>` redirects) with HSTS,
  CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`; session
  cookies are always `Secure`, `HttpOnly`, `SameSite=Lax`.
- Usernames that would collide with the panel's own identities (Linux users,
  `hostpanel_*` MySQL users, ...) are refused (`Usernames`); domain names
  that belong to the server, to another account (or sit above/below one),
  or are public suffixes are refused (`DomainPolicy`).
- Role checks are server-side on every controller action (`Auth::requireRole`),
  plus row-level scoping (a reseller only sees rows where `parent_id`
  matches them; a user only sees their own domains/databases/etc).
- File work on customer files happens as the customer (pool agent), and
  `FileManagerService` still resolves every path and keeps it inside the
  site folder, never following links out of it.
- SQL is parameterized everywhere except a handful of identifiers
  (database/table/user names) that MySQL doesn't allow to be bound
  parameters at all - those go through a strict
  `^[a-zA-Z][a-zA-Z0-9_]{1,62}$` allow-list first.
- `hostpanel_prov`'s grant to customer accounts deliberately excludes
  `CREATE ROUTINE`/`TRIGGER`/`VIEW`/etc - it hands out exactly the
  privileges it itself has, nothing broader.
- Every panel action and sign-in is in the audit trail (WHM > Activity
  Log): who, what, which account, from which address.
- SSH brute force: fail2ban (`sshd` + `recidive` jails).
