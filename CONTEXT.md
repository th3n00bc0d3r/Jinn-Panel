# JinnPanel - project context

Read this first. It's the orientation for anyone (human or AI assistant)
working on the codebase: what JinnPanel is, how it's put together, the
conventions to follow, and where it stands. Deeper detail lives in `docs/`
(ARCHITECTURE, FEATURES, INSTALL, MIGRATION, TROUBLESHOOTING, ICONS).

Last reviewed: 2026-10-01.

## What it is

A self-hosted WHM/cPanel-style hosting control panel for a single
AlmaLinux 10 server:

- **WHM side** (`/whm/*`, roles `admin` and `reseller`): accounts, packages,
  resellers, cPanel migration, server config (mail, SFTP, PHP, MariaDB,
  tuning), multi-PHP versions, backups, activity log, service status and logs.
- **cPanel side** (`/cpanel/*`, role `user`): domains (vhost + DNS +
  SSL mode + PHP version), MySQL databases, mailboxes, SFTP accounts, file
  manager, DNS zone editor, cron, cache, backups, quota bars.

The stack is FrankenPHP (Caddy + embedded PHP 8.5) serving the panel and
every site's TLS/static files, PHP-FPM running each hosting account's PHP
as that account's own Linux user, MariaDB, Stalwart Mail (JMAP admin API),
SFTPGo (REST API), Knot DNS and Valkey. It is installed by
`installer/install.sh`.

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
                               (also: `sync-accounts`, `backup <id>`, `restore`, `backup-all`)
  worker/migration-runner.php  cPanel migration runner (transient systemd unit)
  runtime/pool-agent.php    file work run inside an account's PHP-FPM pool (PoolClient)
  runtime/dispatch.php      per-site PHP settings + page cache (auto_prepend_file)
  storage/config-queue/     signed job files for the root worker
  storage/logs/             app.log, reload.log, migration-<id>.log
/var/lib/jinnpanel/worker/  what the root worker writes back: worker-*.log, live-*.log
tests/                      UnitTest.php (no deps), HtaccessTranslatorTest.php (needs frankenphp)
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

- The **panel** runs in FrankenPHP as `frankenphp`. Static files of a site
  come from its account's own nginx (`jinnpanel-static@<username>`, as the
  account, per-domain response cache) - Caddy only routes to it. Each **hosting account**
  is a Linux user `jp_<username>` that owns `/var/www/<domain>` (0750 + an
  ACL for frankenphp) and whose sites' PHP runs in its own PHP-FPM pool
  (`jinnpanel-php-fpm@<default|82|...>`, socket
  `/run/jinnpanel-php/<tag>/<username>.sock`, open_basedir to its own
  folders, exec & co. disabled unless WHM allows it). Vhosts in
  `/var/lib/frankenphp/sites-enabled/` say `php_fastcgi <socket>` +
  `file_server`. Built by the worker's `accountSync` (`AccountRuntime::sync()`
  after any account/domain change). Details: docs/ARCHITECTURE.md
  "Customer isolation".
- The panel **can only read** customer files. File work on them goes
  through `PoolClient::call($domain, $op, $args)` -> the account's pool ->
  `runtime/pool-agent.php` (as the account). Don't write into site folders
  from panel code; add an agent op instead.
- Anything that needs root (writing `/etc`, `systemctl`, `knotc`, accounts'
  users/pools/folders, installing PHP versions, starting migration runners
  and backups) goes through a **signed job file** in `storage/config-queue/`
  that `hostpanel-worker.php` (root) validates and executes. Enqueue with
  `SystemWorkerService::enqueue($label, $job)`; the worker writes results to
  `/var/lib/jinnpanel/worker/` (`SystemWorkerService::lastLog()/output()`).
  New job types: carry ids, read the rest from the DB, validate every value.
- Why the queue exists: FrankenPHP is sandboxed (`ProtectSystem=full`,
  SELinux denies D-Bus), and forking from FrankenPHP to connect to Knot's
  Unix socket fails with EPERM. Never call privileged commands directly
  from a request.
- Config reloads: `VhostService::reload()` fires a detached
  `frankenphp reload --address unix//run/frankenphp/admin.sock` (a
  synchronous reload from inside a request deadlocks; Caddy's admin API is
  only on that socket).
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
`activity_log` (audit trail: `Audit::log()`, and every flashed POST outcome
automatically - see `Flash::set`), `login_attempts`, `account_usage`
(UsageService, hourly), `backups`, `panel_settings`.

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
- First run: `/setup` creates the first admin account with the one-time
  token install.sh prints (also `/root/.jinnpanel/setup_token`); it disables
  itself once an admin exists.
- Tests: `php tests/UnitTest.php`, `php tests/HtaccessTranslatorTest.php`;
  CI (`.github/workflows/ci.yml`) lints every PHP file and runs both.
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

## Moving parts added on 2026-10-01 (TODO.md has the why)

- **Timers/units** (installer): `hostpanel-worker.timer` (root jobs, 5 s),
  `jinnpanel-mail-dns.timer` (daily: `mail-dns-sync.php` - mail DNS records,
  autoconfig/MTA-STS site, Stalwart's TLS cert from Caddy's, 587 listener,
  pending mail-domain deletes - and `ssl-sync.php`, Let's Encrypt up/down),
  `jinnpanel-cron.timer` (customer cron jobs, every minute, `KillMode=process`),
  `jinnpanel-webmail.service` (Cypht, own FrankenPHP as user `webmail` on
  127.0.0.1:8009 - Cypht putenv()s, which would leak into shared sites),
  `valkey` (object cache, loopback, ACL user per account), transient
  `jinnpanel-s3-fetch-<id>` units.
- **Per-site runtime**: server-wide `auto_prepend_file`
  (`app/runtime/dispatch.php` -> `/var/lib/frankenphp/site-ini/_dispatch.php`)
  includes `site-ini/<domain>.php` (PhpSettingsService: ini_set()s, returns
  the page-cache TTL) and `_pagecache.php`. CLI (cron) passes
  `JINNPANEL_DOCROOT` because the CLI blanks DOCUMENT_ROOT.
- **Routes**: `site-rules/<domain>.caddy` / `.site.caddy` are root-owned and
  written only by the worker's `routes_apply` (re-validates with
  `HtaccessTranslator::validate`, `frankenphp validate`, restores on failure).
  Tests: `php tests/HtaccessTranslatorTest.php` (needs frankenphp).
- **Mail**: Stalwart 0.16 takes no credentials in an Account create (create,
  then patch `credentials/0`; one password per account). Panel acts as a
  mailbox via the master login `<address>%<admin>`. Forwarders = mailing
  lists (non-mailbox) or the mailbox's single panel Sieve script, which also
  holds the autoresponder (Stalwart runs one active script).
- **Domains**: `domains.docroot` is honoured (VhostService::effectiveDocroot),
  aliases in `domain_aliases`, `<domain>/jpanel` -> `<domain>:2083` (panel per
  domain; site blocks strip the panel session cookie).

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
- Zones are registered with `knotc conf-set`, which only changes Knot's
  memory (knot.conf is a text file, not a confdb). After every conf-*
  transaction the worker writes the live zone list to `/etc/knot/zones.conf`,
  which knot.conf includes (`include: "zones.conf"`, added by the installer).
  Without it a Knot restart or a reboot loads 0 zones and every hosted domain
  - the server hostname too - stops resolving (it happened on 2026-10-01).
  `knotc conf-export` is no substitute: it exports knotc's view of the file,
  not the running server's zones.
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

Since done (2026-10-01): cPanel > DNS is an editor for the customer's own
zones (the server's own names in the server zone stay WHM-only); AAAA
records next to every A record pointing here; DNS records are imported from
cPanel zone files during migration; subdomain sites are records in their
parent zone. Records the panel keeps in sync itself carry
`dns_records.managed` ('mail', 'ipv6', 'site') - `DnsService::syncManaged()`
replaces a tag's set, and a customer record with the same name wins.

## Production readiness

The security review of 2026-09-30 listed 19 items. Status after the work of
2026-10-01 (branch `migration-file-import`):

| # | Item | Status |
|---|---|---|
| 1 | No isolation between customers / customers and the panel | **Fixed**: per-account Linux users + PHP-FPM pools, panel file work inside the pool, Config.php 0640 root:frankenphp, panel code root-owned (docs/ARCHITECTURE.md "Customer isolation") |
| 2 | Root worker trusts a customer-writable queue | **Fixed**: customers no longer run as frankenphp; jobs are HMAC-signed, plain files of frankenphp/root only, every job type validates its input (settings: fixed key list + value shapes); root writes only to root-owned `/var/lib/jinnpanel/worker/`; root's PHP runs with `auto_prepend_file=` empty |
| 3 | Reserved usernames | **Fixed**: `Usernames` (reserved names, `hostpanel*`/`mysql*`/`pma*` prefixes, lowercase, existing system users) - accounts, resellers, migrations |
| 4 | Admin APIs on the internet (8080/8090) | **Open by the owner's choice** (2026-10-01: leave 8080/8090/9090 open). Caddy's admin API (127.0.0.1:2019, unauthenticated) moved to a Unix socket |
| 5 | Panel over http://, tls internal | **Fixed**: http:// redirects to https (308), real certificate once DNS resolves (already the case here), cookies always Secure, HSTS on the panel host |
| 6 | No throttling/2FA, 8-char passwords | **Fixed**: `LoginThrottle`, TOTP two-factor for all roles (WHM/cPanel > shield icon; admins can reset), 10+ chars and no common passwords (`Passwords`) |
| 7 | /setup open until the first admin | **Fixed**: one-time setup token from install.sh |
| 8 | Suspension only blocks login | **Fixed**: `SuspensionService` - sites 503 + pools stopped, mail logins off (Stalwart `authenticate` permission), SFTP off, MySQL users locked, Valkey login off, cron skipped, sessions ended |
| 9 | Quotas counted, not enforced; resellers unlimited | **Fixed (soft) / hard with XFS quotas**: `UsageService` measures files+DBs+mail and monthly bandwidth hourly; over disk -> uploads/new DBs/mailboxes/domains refused; over bandwidth -> sites 509; resellers limited by `packages.max_accounts`. Hard per-user limits: XFS user quotas (`JINNPANEL_XFS_QUOTA=1` adds `rootflags=uquota`; on this server since 2026-10-01, active after the next reboot), applied at boot (`jinnpanel-usage-boot`) and hourly as each account's package disk quota on its Linux user (files; DBs/mail are counted softly) |
| 10 | Domains not verified | **Fixed (policy)**: `DomainPolicy` refuses the server's names, names under/above another account's domain, public suffixes and the most impersonated domains. Ownership is proven by DNS (Let's Encrypt only issues when it points here) |
| 11 | No backups | **Fixed**: `BackupService` - daily per-account (files, DBs, mail via IMAP) + server (panel DB, configs), retention, optional S3 copy, restore per part, customer downloads (WHM/cPanel > Backups) |
| 12 | DNS: ns1.<domain>, one NS, no SPF/DKIM/DMARC | **Fixed** earlier (DNS work): ns1/ns2 of the server zone, SPF, DKIM (RSA + Ed25519), DMARC on every mail domain |
| 13 | Role cached in the session | **Fixed**: role read from the DB on every request; `session_version` ends other sessions on password/2FA changes and suspension |
| 14 | No security headers; GET logout; / for users | **Fixed**: CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, COOP; logout is a POST; / already sent users to /cpanel |
| 15 | No audit trail | **Fixed**: `activity_log` written for sign-ins and every panel action (`Flash::set` records each POST outcome, `Audit::log` the rest); WHM > Activity Log |
| 16 | File manager follows symlinks | **Was already fixed** (realpath containment); now also runs as the account |
| 17 | Dynamic Tailwind classes | **Was already fixed** (safelist in tailwind.config.js) |
| 18 | No tests/CI | **Fixed**: tests/UnitTest.php (TOTP RFC vectors, archives, FastCGI, rules rewrite, cron, ...), tests/HtaccessTranslatorTest.php, GitHub Actions CI |
| 19 | Migration vs a real cPanel server; Stalwart hashes; SFTPGo SCP | **Partly**: Stalwart verified to accept imported `$6$`, `$1$` and bcrypt hashes; SFTPGo has `scp` enabled; a live cPanel source server was not available to test against |

Known limits of the isolation (worth knowing before letting strangers host):

- (Fixed 2026-10-01) Static files used to be served by Caddy as
  `frankenphp`, which can read every account's files; they now come from
  each account's own static server (nginx as the account, links to others'
  files refused), so a symlink reaches nothing the account can't read.
- SFTPGo now holds CAP_DAC_OVERRIDE/CHOWN/FOWNER (to write as the account),
  so its admin API (8090, open on the internet by the owner's choice) is
  close to root on files. Binding it to 127.0.0.1 is strongly recommended.

Still open (owner's side): rotating the root password, SSH password logins
(kept on by choice; fail2ban added), restricting 8080/8090/9090, IPv6 rDNS
at the provider, the reboot that turns XFS user quotas on, a migration trial
against a real cPanel server.
