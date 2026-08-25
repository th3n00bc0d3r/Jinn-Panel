# Architecture

## The stack

| Component | Role | Why this one |
|---|---|---|
| **FrankenPHP** | Web server + PHP runtime | Caddy-based, embeds PHP directly - no separate PHP-FPM process, automatic HTTPS built in |
| **MariaDB** | Database (panel's own state + every customer database) | Standard, well-understood, MySQL-wire-compatible |
| **Stalwart Mail** | SMTP/IMAP/JMAP mail server | Single Rust binary combining what used to be Postfix+Dovecot, with a REST-ish management API instead of flat config files |
| **SFTPGo** | SFTP server | Virtual users managed entirely via REST API - no local Linux user per hosting account |
| **Knot DNS** | Authoritative DNS | Fast, scriptable via `knotc`, used for zones this panel provisions |

All five run as systemd services on one AlmaLinux box. JinnPanel itself is a
plain-PHP application (no framework, no Composer) deployed to
`/var/www/hostpanel`, served by the same FrankenPHP instance as its own
vhost (`panel.<hostname>`).

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
up, applies it, and deletes the job file. Job types: `set_ini`,
`set_mycnf`, `set_sftpgo_json`, `restart`, `dns_create`/`dns_remove`,
`install_php_version`/`remove_php_version`, `pull_log`. Every "Save &
restart X" button and every DNS zone provisioning in the panel goes through
this queue.

This also solves the read side: since FrankenPHP can't read the systemd
journal either, the same worker snapshots each service's last 200 journal
lines into `storage/logs/live-<service>.log` every cycle, which the WHM
dashboard just reads as a plain file - no journal access needed from the
web process at all.

## Multiple PHP versions

Each additional PHP version (8.2/8.3/8.4 alongside the default 8.5) runs as
its **own fully separate FrankenPHP instance** - not a per-request switch,
because FrankenPHP embeds exactly one PHP build into the process it's
running as.

This works cleanly because each version's shared PHP library has a
**version-specific filename** (`libphp-zts-82.so`, `libphp-zts-85.so`, ...),
so multiple versions coexist on disk without touching the default install.
`install_php_version` (run by the worker, not the web app):

1. Resolves the exact RPM URLs for that version from the upstream
   `static-php` repo via `dnf repoquery --disable-modular-filtering`
   (bypassing `dnf module`, which is exclusive-per-stream and would try to
   replace the default version's packages).
2. Downloads and extracts (via `rpm2cpio`/`cpio`, not `rpm -i`) just the
   `frankenphp` binary and that version's `.so` into
   `/opt/php-versions/<version>/`.
3. Relabels the binary `httpd_exec_t` (SELinux: a *content* type like
   `httpd_sys_rw_content_t` can be written but not executed - this needs
   the actual executable type) and registers its ports (TCP admin port,
   TCP+UDP HTTP port - FrankenPHP uses HTTP/3/QUIC) under `http_port_t`,
   both idempotently.
4. Writes a dedicated systemd unit and starts it, bound to
   `127.0.0.1:90XX`, completely loopback-only.

A domain assigned to an alt version gets **two** vhost fragments written:
one in that instance's own `sites-enabled/` (real `php_server`, plain HTTP,
`bind 127.0.0.1` - explicitly `http://` scheme, otherwise Caddy sees what
looks like a real domain and tries to auto-provision TLS for an internal
loopback listener), and one in the *main* instance's `sites-enabled/` that's
just `reverse_proxy 127.0.0.1:90XX`. The main instance is the only thing
with a real TLS certificate and the only thing reachable from outside.

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

## Data model

MariaDB `hostpanel` database: `users` (role: admin/reseller/user, with
`parent_id` scoping a user to the reseller who created them), `packages`
(quotas; `owner_id` NULL = global, else a reseller's own custom package),
`domains` (+ `php_version`, `php_port`, `ssl_mode`), `db_instances`,
`email_accounts`, `ftp_accounts`, `php_versions` (installed alt PHP
versions and their ports), `activity_log`.

Two separate MariaDB accounts back the app: `hostpanel_app` (least
privilege, scoped only to the `hostpanel` database itself) and
`hostpanel_prov` (a narrower, explicitly-scoped set of privileges - NOT
`ALL PRIVILEGES` - used only by `ProvisioningService` to create/drop
per-customer databases and users; it can only re-grant privileges it
itself holds, which is why customer databases get a specific privilege
list rather than `GRANT ALL`).

## Security posture

- Every mutating request goes through `Csrf::requireValid()`.
- Passwords: bcrypt via `password_hash`/`password_verify`.
- Role checks are server-side on every controller action (`Auth::requireRole`),
  plus row-level scoping (a reseller only sees rows where `parent_id`
  matches them; a user only sees their own domains/databases/etc).
- The file manager resolves every path through `realpath()` and checks the
  result is still inside the account's own docroot (with a path-separator
  boundary check, not just a string prefix match) before touching disk.
- SQL is parameterized everywhere except a handful of identifiers
  (database/table/user names) that MySQL doesn't allow to be bound
  parameters at all - those go through a strict
  `^[a-zA-Z][a-zA-Z0-9_]{1,62}$` allow-list first.
- `hostpanel_prov`'s grant to customer accounts deliberately excludes
  `CREATE ROUTINE`/`TRIGGER`/`VIEW`/etc - it hands out exactly the
  privileges it itself has, nothing broader.
