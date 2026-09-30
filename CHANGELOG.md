# Changelog

All notable changes to this project are documented here. Format loosely
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this
project doesn't yet follow semantic versioning releases (no tags/releases
have been cut) - entries are grouped by development milestone instead.

## Unreleased

- **WHM > DNS Zones** (admin): nameserver settings, every zone on the server
  (the server's own, hosted domains', standalone), and record management for
  A, AAAA, CNAME, MX, TXT, NS, SRV and CAA, with a zone-file preview.
- DNS records now live in the panel DB (`dns_zones`, `dns_records`,
  `panel_settings`); zone files are rendered from them. "Re-provision" no
  longer resets a zone to the template.
- Fix: zone files were never written - FrankenPHP can't write
  `/var/lib/knot`. The root worker now writes them, reloads
  each zone in blocking mode and keeps the previous version if Knot rejects it.
- Installer publishes the server's own zone (ns1/ns2 glue, hostname,
  `panel.<hostname>`), so the panel resolves without hosts-file edits once
  the registrar's glue points at the server.
- Installer fix: wait for Stalwart's listeners before bootstrapping it (a
  fresh install could abort on the session call).
- Installer fix: move Stalwart's HTTPS listener off :443 to :8443, so it
  can't take the port from FrankenPHP.

## [Unreleased]

### cPanel Migration (WHM)
- One-click migration from cPanel & WHM: a single cPanel account, all
  accounts of a WHM reseller, or (admin only) any accounts on a server via
  WHM root. Migrates domains + site files, MySQL databases and users (with
  their password hashes), email accounts (with their password hashes) and
  stored mail (Maildir -> JMAP, folders and flags kept). Optionally
  recreates cPanel resellers as JinnPanel resellers that own their
  customers. Live progress, per-account reports, rollback + retry. See
  `docs/MIGRATION.md`.
- Runs as a background systemd unit (`jinnpanel-migration-<id>`, as
  frankenphp:webusers) launched by the config worker; new
  `migration_start` / `migration_stop` worker jobs.
- New tables `migrations`, `migration_items`, `db_user_accounts`;
  `db_instances.db_user` is no longer unique (one MySQL user can serve
  several databases). Upgrade script: `app/migrations/003_cpanel_migration.sql`.
- `Config::APP_KEY` (generated once by `install.sh`, persisted in
  `/root/.jinnpanel/app_key`) encrypts stored source credentials.
- Deleting an account now also drops every extra MySQL user it owns;
  deleting a database keeps a MySQL user that other databases still use.
- Password hashes carried over from cPanel are upgraded to bcrypt on the
  user's first login.
- `install.sh` installs `rsync`, creates `/var/lib/jinnpanel/migrations`,
  and checks the PHP CLI extensions the migration runner needs.

### Added
- Initial public release preparation: MIT license, `.gitignore`, full
  `docs/` set (`ARCHITECTURE`, `FEATURES`, `TROUBLESHOOTING`, `ICONS`,
  `INSTALL`, `COMPARISON`), `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`,
  `SECURITY.md`, brand assets (`assets/brand/`) and extracted UI icon set
  (`assets/icons/`).
- Favicon wired into all three page layouts (`shell.php`, `auth.php`,
  `blank.php`).

### Server Config (WHM)
- Full configuration control panel for Stalwart Mail, SFTPGo, FrankenPHP/
  PHP, and MariaDB.
- Schema-driven Stalwart settings UI - generated from Stalwart's own
  `/api/schema`, so new Stalwart settings objects appear automatically
  without new PHP.
- Server performance tuning: Balanced / Performance / Extreme profiles,
  auto-suggested from the box's actual CPU/RAM, applied fully
  automatically (root-owned config edits + service restarts via the
  background worker).
- Multiple PHP versions: install/remove additional versions as fully
  isolated FrankenPHP instances (separate process, separate PHP shared
  library, reverse-proxied by the main instance), selectable per domain.
- AutoSSL: Caddy's native automatic HTTPS, surfaced in the UI per domain,
  no custom ACME code.
- WHM dashboard service logs: pull recent log output for each managed
  service directly from the dashboard.

### Provisioning
- Reseller accounts and hosting packages with quotas.
- Hosting account creation: real vhosts (HTTP+HTTPS), MySQL databases,
  Stalwart mailboxes, SFTPGo virtual SFTP users, Knot DNS zones - all real
  resources, not simulated.
- File manager scoped to each account's document root.

### Core
- Background worker (`hostpanel-worker.php`) + systemd timer, polling a
  job queue in `storage/config-queue/` - the mechanism every privileged
  operation (config writes, service restarts, DNS changes, PHP version
  management) goes through, working around FrankenPHP's systemd/SELinux
  sandboxing constraints. See `docs/ARCHITECTURE.md`.
- Session-based auth, CSRF protection on all state-changing requests,
  bcrypt password hashing, prepared statements throughout.
- First-run setup wizard (`/setup`) - installer no longer bakes in an
  admin account; it's created interactively on first visit.
- `installer/install.sh` - single-command, idempotent, non-interactive
  installer for a fresh AlmaLinux 10 box. Validated via repeated live
  re-runs against an already-configured system, not just a single clean
  run.

### Fixed
- Installer: SIGPIPE risk under `pipefail` when piping through `head`.
- Installer: non-idempotent `ALTER TABLE ADD CONSTRAINT` on re-run,
  replaced with conditional DDL.
- Installer: credential-verification gap that could silently write a
  broken `Config.php` if a generated password didn't actually match what
  a service accepted - now hard-fails before writing instead.
- WHM sidebar: oversized icons on some screen widths.
