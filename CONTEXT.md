# JinnPanel - project context

Read this first. It's the orientation for anyone (human or AI assistant)
working on the codebase: what JinnPanel is, how it's put together, the
conventions to follow, and where it stands. Deeper detail lives in `docs/`
(ARCHITECTURE, FEATURES, INSTALL, MIGRATION, TROUBLESHOOTING, ICONS).

Last reviewed: 2026-09-30.

## What it is

A self-hosted WHM/cPanel-style hosting control panel for a single
AlmaLinux 10 server:

- **WHM side** (`/whm/*`, roles `admin` and `reseller`): accounts, packages,
  resellers, cPanel migration, server config (mail, SFTP, PHP, MariaDB,
  tuning), multi-PHP versions, service status and logs.
- **cPanel side** (`/cpanel/*`, role `user`): domains (vhost + DNS +
  SSL mode + PHP version), MySQL databases, mailboxes, SFTP accounts, file
  manager, DNS zone view, quota bars.

The stack is FrankenPHP (Caddy + embedded PHP 8.5) serving both the panel
and every customer site, MariaDB, Stalwart Mail (JMAP admin API), SFTPGo
(REST API), and Knot DNS. It is installed by `installer/install.sh`.

## Code layout

```
app/
  public/index.php          front controller + every route (Router)
  public/assets/            app.js (confirm dialogs, flash), Tailwind input/output css
  src/bootstrap.php         web bootstrap: autoloader, error logging, session start
  src/cli_bootstrap.php     CLI bootstrap (migration runner): no session, no limits
  src/Config.php.template   secrets + paths; install.sh renders it to Config.php
  src/{Router,Auth,Database,View}.php
  src/Controllers/          one static class per area; public static methods = routes
  src/Services/             integrations (Vhost, Dns, Mail, Sftp, Provisioning, ...)
  src/Support/              Csrf, Flash, Http (cURL JSON), Quota, Crypto
  views/                    plain PHP templates; layouts/ (whm, cpanel, auth, blank)
  migrations/               schema.sql (idempotent, re-applied by install.sh) + upgrade scripts
  worker/hostpanel-worker.php  root worker, run every 5s by a systemd timer
  worker/migration-runner.php  cPanel migration runner (transient systemd unit)
  storage/config-queue/     job files for the root worker
  storage/logs/             app.log, worker-*.log, live-*.log, migration-<id>.log
installer/install.sh        full server install; safe to re-run (re-deploys the app)
docs/                       user/operator documentation
```

Autoloading is flat: a class named `X` is loaded from `src/X.php`,
`src/Controllers/X.php`, `src/Services/X.php` or `src/Support/X.php`.
No namespaces, no Composer, no framework.

## How a request flows

1. `public/index.php` requires `src/bootstrap.php` (autoloader, error log to
   `storage/logs/app.log`, `display_errors=0`, `Auth::start()`), registers
   all routes, dispatches. `{id}` segments arrive as `$params['id']` (string).
2. Controllers call `Auth::requireRole([...])`, then `Csrf::requireValid()`
   on every POST, validate input, call services, then either
   `View::render('dir/view', $data, 'layout')` or `Flash::ok/error()` +
   `header('Location: ...'); exit;`.
3. Views escape everything with `e()`; numbers with `(int)`. `icon($name)`
   renders the inline SVG set defined in `View.php`.

Scoping rules used everywhere: a `user` only ever touches rows with
`user_id = <own id>` (every query filters on it); a `reseller` only sees
`users.parent_id = <own id>`; `admin` sees all. Packages with
`owner_id IS NULL` are global; reseller packages have `owner_id` set.

## Privilege model (important)

- The panel **and all customer sites** run inside the same FrankenPHP
  process as the `frankenphp` user (group `webusers`). Sites are
  `php_server` blocks in `/var/lib/frankenphp/sites-enabled/*.caddyfile`;
  alternative PHP versions are separate FrankenPHP instances on loopback
  ports (9082, 9083, ...) that the main instance reverse-proxies to.
- Anything that needs root (writing `/etc`, `systemctl`, `knotc`, installing
  PHP versions, starting migration runners) goes through a **job file** in
  `storage/config-queue/` that `hostpanel-worker.php` (root) validates and
  executes. Enqueue with `SystemWorkerService::enqueue($label, $job)`.
- Why the queue exists: FrankenPHP is sandboxed (`ProtectSystem=full`,
  SELinux denies D-Bus), and forking from FrankenPHP to connect to Knot's
  Unix socket fails with EPERM. Never call privileged commands directly
  from a request.
- Config reloads: `VhostService::reload()` fires a detached
  `frankenphp reload` (a synchronous reload from inside a request deadlocks).
- Databases: `Database::app()` = `hostpanel_app` (panel schema only);
  `Database::provisioning()` = `hostpanel_prov` (creates/drops customer
  databases and users; it can only grant `ProvisioningService::GRANT_SET`).
- Mail: `MailService` / `StalwartAdminService` talk JMAP to Stalwart on
  127.0.0.1:8080. SFTP: `SftpService` talks REST to SFTPGo on 127.0.0.1:8090.

## Data model (app/migrations/schema.sql)

`users` (role admin/reseller/user, `parent_id` = owning reseller,
`package_id`, status), `packages` (limits; `owner_id` for reseller
packages), `domains` (docroot `/var/www/<domain>/public`, php_version,
ssl_mode, `mail_domain_id`), `db_instances` (db_name unique, db_user not
unique), `db_user_accounts` (extra MySQL users an account owns),
`email_accounts` (`mail_account_id` = Stalwart id), `ftp_accounts`,
`php_versions`, `migrations` + `migration_items` (cPanel migration),
`activity_log` (present but not written to yet).

Schema changes: add them to `schema.sql` **idempotently** (`CREATE TABLE IF
NOT EXISTS`, conditional DDL through `information_schema` + `PREPARE`, as in
the `db_user` index change) and also ship an upgrade file in
`app/migrations/NNN_*.sql`. `install.sh` re-applies `schema.sql` on every run.

## Conventions

- `declare(strict_types=1)`, `final class`, static methods, early returns,
  no framework magic. Keep it that way.
- Every POST: `Csrf::requireValid()`; every form: `<?= Csrf::field() ?>`.
  Destructive buttons use `data-confirm="..."` (handled in `app.js`).
- Validate identifiers with allow-list regexes before they touch a shell,
  a path or SQL identifier (domains, usernames, db names); shell arguments
  always via `escapeshellarg`; SQL values always via prepared statements.
- **Tailwind**: `tailwind.config.js` only scans `views/**/*.php`, so class
  names must appear literally in a view file (build them with a ternary of
  full class names, never `"bg-{$color}-500"`).
- New icons: add to `View::icon()` and to `assets/icons/` + `docs/ICONS.md`.
- Controllers show friendly errors via `Flash::error()` and log the
  details with `error_log()`; never echo exceptions to visitors.
- There is no local PHP on the development machine (Windows); lint/test
  in a Linux sandbox or on the server (`php -l`).

## Build, deploy, operate

- Install / update: copy the repo to the server and run
  `sudo bash installer/install.sh`. It is idempotent: it re-renders
  `Config.php` (DB passwords are **rotated** on each run; `APP_KEY` is kept in
  `/root/.jinnpanel/app_key`), re-applies the schema, rebuilds Tailwind, and
  re-deploys the worker to `/usr/local/bin/hostpanel-worker.php` (worker
  changes only take effect after a re-run).
- First run: `/setup` creates the first admin account; it disables itself
  once an admin exists.
- Logs: `app/storage/logs/` on the server (`/var/www/hostpanel/storage/logs`),
  `journalctl -u frankenphp|stalwart|sftpgo|knot|mariadb`,
  `journalctl -u jinnpanel-migration-<id>`.

## Feature notes

- **cPanel migration** (`docs/MIGRATION.md`): `MigrationController` ->
  `MigrationService` (records, queues `migration_start`) -> worker starts
  `migration-runner.php` as `frankenphp:webusers` -> `MigrationRunner`
  (`CpanelApiClient`, `CpanelBackupReader`, `MailImportService`,
  `AccountCleanupService`). Tested against a real MariaDB with a synthetic
  cPanel backup; **not yet run against a real cPanel server**.
- Account deletion and migration rollback share `AccountCleanupService::purge()`.

## DNS zones (branch `dns-management`)

- **Source of truth is the panel DB**: `dns_zones`, `dns_records`,
  `panel_settings` (`app/migrations/004_dns_zones.sql`, also appended to
  `schema.sql`). A hosted domain's zone is linked by name
  (`dns_zones.zone_name = domains.domain_name`), not by FK.
- `DnsService` renders zone text. SOA, apex NS and in-zone glue for ns1/ns2
  are generated from the nameserver settings (`panel_settings` keys
  `dns_ns1_host`, `dns_ns1_ip`, `dns_ns2_host`, `dns_ns2_ip`, optional
  `dns_server_zone`) and are never stored as records.
- Every change goes through `DnsService::publish()`: bump serial, enqueue a
  `dns_write` job carrying the full zone text. `hostpanel-worker.php` (root)
  writes `/var/lib/knot/<zone>.zone` (knot:knot 0640), registers the zone in
  Knot if needed and runs `knotc -b zone-reload`; if that fails it restores
  the previous file (Knot keeps serving the zone it had).
- Never write zone files from the web process: FrankenPHP can't write
  `/var/lib/knot` (knot:knot 0755). Don't use `knotc zone-check` as a
  validator either - on Knot 3.5 it reports "no such zone" even for loaded
  zones.
- UI: WHM > DNS Zones (`WhmDnsController`, `views/whm/dns.php`,
  `views/whm/dns_zone.php`), admin only. cPanel > DNS is read-only and
  renders from the DB.
- Server zone = the hostname's parent (server.example.com -> example.com),
  flagged `is_server_zone`: can't be deleted, and `removeZone()` leaves it
  alone when a hosted domain with the same name is removed.
- `app/worker/dns-bootstrap.php`, run by install.sh as frankenphp: stores
  nameservers on the first run (env `JINNPANEL_NS1_HOST` / `_NS1_IP` /
  `_NS2_HOST` / `_NS2_IP` always win, `JINNPANEL_DNS_ZONE` overrides the
  zone), ensures the server zone, and imports zones for domains that existed
  before zones lived in the DB.

### Open issues on this branch

Fixed on 2026-09-30 and verified with an installer re-run on a live server
(`dig @127.0.0.1 SOA <server zone>` answers, `panel.<hostname>` resolves
publicly, WHM > DNS Zones renders):

- ~~Collation mismatch~~: the three DNS tables had taken the database default
  `utf8mb4_unicode_ci`; every other table is `utf8mb4_general_ci`. They now
  declare `COLLATE=utf8mb4_general_ci`, and `schema.sql` / `004_dns_zones.sql`
  convert existing tables with conditional `ALTER TABLE ... CONVERT TO`.
  New tables must declare `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci` explicitly.
- ~~Stalwart :443 check~~: the installer now decompresses gzip responses from
  `/api/schema`.
- ~~Misleading summary~~: a failed dns-bootstrap is reported as a failure, not
  as "isn't a public hostname".
- ~~Worker `dns_write` jobs failed~~ with `Undefined constant "DNS_DOMAIN_RE"`:
  the const was declared after the job loop (top-level `const` isn't hoisted
  like functions). It now sits with the other constants at the top.

Still open:

1. Not yet: record editing for cPanel users (own domains), AAAA for the
   server's own names, importing DNS records during cPanel migration.

## Production readiness (review of 2026-09-30)

Verdict: **not ready for production with untrusted customers.** It is a
solid, well-structured beta that works for a single operator hosting their
own sites. The blockers below are architectural, not cosmetic; fix the
Critical ones before any customer (or reseller) gets an account.

### Critical - customer code can take over the server

1. **No isolation between customers, or between customers and the panel.**
   All sites execute as `frankenphp`, the same user that owns the panel code
   and `src/Config.php` (mode 664). Any customer PHP script can read
   `Config.php` (DB provisioning credentials with `CREATE USER`/`GRANT`,
   Stalwart and SFTPGo admin passwords, `APP_KEY`), read every other
   customer's files and sessions (hijack an admin session), rewrite panel
   code, write Caddy fragments in `sites-enabled/`, and drop job files into
   `config-queue/`. Needed: per-account Linux users with per-account PHP
   workers (e.g. separate FrankenPHP instances or PHP-FPM pools running as
   the account user), `open_basedir`/`disable_functions` as defence in depth,
   the panel on its own instance/user, `Config.php` 0640 root:frankenphp.
2. **Root worker trusts a directory customers can write to.**
   `hostpanel-worker.php` (root) executes whatever job files appear in
   `storage/config-queue/` and writes `worker-<label>.log` / `live-*.log` into
   `storage/logs/` - both writable by `frankenphp`, i.e. by customer code.
   A planted symlink in `storage/logs/` makes root write attacker-controlled
   text to any file (e.g. `/etc/cron.d/*`), and `set_ini` accepts any ini key
   and unvalidated values (`auto_prepend_file`, newline injection), which
   also reaches the root worker's own PHP CLI if it reads the same php.ini.
   Needed: root-owned queue/log dirs written via a root-owned setgid helper
   or a Unix socket with peer-credential checks, `O_NOFOLLOW`/no symlinks for
   root writes, strict per-key value validation for every job type, and
   authenticated jobs (HMAC with a key customers can't read).
3. **Reserved usernames aren't blocked.** An account named `hostpanel`
   gets `/var/www/hostpanel` (the panel itself) as its SFTP home, and its
   database `app` would be user `hostpanel_app` - the panel's own DB user,
   which deleting that database then drops (panel outage). Block
   `hostpanel`, `root`, `mysql`, `admin`, service/system user names, and
   any name whose `<name>_...` prefix collides with `hostpanel_*`; make
   usernames lowercase only.
4. **Admin APIs exposed to the internet over plain HTTP.** `install.sh`
   opens 8080 (Stalwart HTTP: web admin + JMAP) and 8090 (SFTPGo web admin +
   REST API) in the firewall. Keep both on 127.0.0.1 (or behind the panel's
   TLS with an allow-list); expose only real mail/SFTP ports.

### High

5. The panel itself is also served on `http://` (login in clear text) and
   uses `tls internal` (untrusted certificate). Redirect HTTP to HTTPS and use
   a real certificate for the panel hostname; set `Secure` cookies always.
6. No login throttling or lockout, no 2FA, 8-character minimum passwords.
7. `/setup` is open to anyone until the first admin is created - the
   installer should print a one-time setup token and require it.
8. Suspending an account only blocks panel login: its sites, mailboxes and
   SFTP keep working.
9. Quotas are counted, not enforced: disk limits apply only to SFTP uploads;
   web, database and mail usage are unlimited; bandwidth is not metered.
   Resellers have no limit on how many accounts they create.
10. Domains are not verified: a customer can add any domain (another
   customer's subdomain, a well-known domain, even the panel hostname, which
   produces a duplicate Caddy site and breaks reloads).
11. No backups of customer data or of the panel database, and no restore.
12. DNS zones use a per-domain `ns1.<domain>` (needs glue records at every
   registrar), a single NS, and no SPF/DKIM/DMARC records even though
   Stalwart generates DKIM keys - expect mail deliverability problems.

### Medium / low

13. `Auth::requireRole()` checks the role cached in the session; a role or
    ownership change applies only after the user logs in again.
14. No security headers (HSTS, CSP, X-Frame-Options, nosniff); logout is a
    GET (CSRF-able); `/` sends logged-in `user` accounts to `/whm` (403).
15. `activity_log` exists but nothing is written - no audit trail of
    admin/reseller actions.
16. File manager downloads follow symlinks (only harmful once #1 is fixed;
    then it becomes the next escape - resolve and contain the file path too).
17. Dynamic Tailwind classes in `views/partials/shell.php` (`$accent`) and
    `whm/server_config/php_versions.php` (`$color`) may be purged from the
    built CSS unless safelisted.
18. No automated tests and no CI (only issue/PR templates in `.github/`).
19. The cPanel migration has not been exercised against a real cPanel server,
    Stalwart's acceptance of imported password hashes is unverified, and
    SFTPGo's SCP support for push mode should be confirmed on the deployed
    version.

### Suggested order of work

1. Isolation redesign (#1) together with the worker hardening (#2) - they
   shape each other; decide the per-account runtime model first.
2. Quick wins that are independent of that: #3, #4, #5, #7, #13, #14.
3. Real multi-tenant features: suspension (#8), quota enforcement (#9),
   domain verification (#10), backups (#11), DNS/mail records (#12),
   audit log (#15).
4. Test suite + CI (#18), then a real-server migration trial (#19).
