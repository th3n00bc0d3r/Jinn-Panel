# Changelog

All notable changes to this project are documented here. Format loosely
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this
project doesn't yet follow semantic versioning releases (no tags/releases
have been cut) - entries are grouped by development milestone instead.

## Unreleased - provisioning API (2026-11)

Upgrade by re-running the installer: it applies `app/migrations/027_api_tokens.sql`.

### Added
- **Provisioning API** (`docs/API.md`): a WHM API 1 compatible subset at
  `https://<panel host>/json-api/<function>` - `version`, `applist`,
  `listpkgs`, `listaccts`, `accountsummary`, `createacct` (account + first
  domain, all or nothing), `changepackage`, `suspendacct`, `unsuspendacct`, `removeacct`.
  Order forms and billing modules written for WHM's `createacct` work
  unchanged.
- **WHM > API Tokens**: tokens for admins and resellers, acting with their
  owner's rights, shown once and stored hashed, optionally limited to client
  addresses; failed attempts throttled per address; API actions in the
  Activity Log.

### Changed
- Adding a domain (cPanel > Domains) moved into `DomainService::add`, shared
  with the API.

## Unreleased - redesign and account basics (2026-11)

Upgrade by re-running the installer: it applies
`app/migrations/023_accounts_notifications.sql`, `024_package_features.sql`, `025_reseller_limits_notices.sql` and `026_statistics.sql`, rebuilds the CSS and
re-deploys the worker and pool agent (the file editor and hourly alert
emails need both).

### Changed - the interface
- A new design system (`docs/UI.md`): design tokens for a light and a dark
  theme (light / dark / follow the OS, from the top bar), one accent colour,
  component classes (`card`, `btn`, `badge`, `table`...) and PHP helpers
  (`Ui::field`, `Ui::status`, `Ui::deleteForm`...) instead of long class
  strings repeated in every view. Every page was redesigned on it.
- New shell: grouped sidebar, breadcrumbs, account menu, **Ctrl-K** (or `/`)
  to jump to any page or action, toasts for messages (errors stay until
  closed), confirmations in a dialog instead of the browser's `confirm()`.
- Accessibility: every control is labelled, icon-only buttons have names,
  text meets WCAG AA contrast, a skip link, keyboard-friendly menu and
  dialogs, reduced motion respected. The always-on animations are gone.
- A form that fails keeps what was typed (never passwords).
- Pages with a running job (backups, routes, PHP versions/extensions)
  check in the background and refresh once it's done, instead of
  reloading every few seconds.
- Settings that change a live site (PHP version, SSL mode) are saved with
  a button on the domain's page, no longer on change in the list.
- No inline scripts left: the Content-Security-Policy no longer allows
  `'unsafe-inline'` scripts.
- An expired form shows a page that explains it, not a line of text.

### Added - statistics (Plan 1 v3)
- The root worker collects statistics: the server and each service every
  minute, the web access log every 5 minutes (requests, visitors, pages,
  status codes, page time, top pages and referrers per site), each
  account's disk use daily. No IP address is stored: visitors are counted
  with a hash whose salt changes daily and is then deleted.
- **WHM dashboard**: traffic of the busiest sites over 24 hours, 7 or 30
  days, the server's figures with trends, services' uptime over 24 hours,
  and accounts nearing a limit with the day they reach it.
- **WHM > Analytics**: requests, visitors or data sent per site, the
  server's CPU, memory and network over time, and a sortable table of sites.
- **Account page**: disk use and data sent over the last 30 days against
  the package, and when each limit will be reached at the current rate.
- **Customer dashboard**: visitors to their sites over 24 hours, 7 or 30
  days, their plan with the day disk or bandwidth runs out at the current
  rate, and each site's 14-day visitors, instead of shortcut tiles.
- **Stats tab on every domain**: visitors, requests, data sent, page time,
  answers by status, top pages and referring sites.
- **Accounts list**: search, filters with counts, sorting and pages in the
  database (fast with thousands of accounts), a 14-day requests trend per
  account.
- **Ctrl-K finds accounts and domains** by name (scoped to what the user may
  open), an **alerts bell** with a count in the top bar, and dashboards that
  refresh every 30 seconds (background refreshes don't keep an idle session
  signed in).
- **Moving to a smaller package** (one account, several, or a package
  edited down) first lists what each account already has beyond the new
  limits and what that means; new limits then apply within a minute (the
  worker measures those accounts right away) instead of at the next hour.
- **SFTP keys**: the `ssh-keygen` and `sftp -i` commands to copy, filled in
  for the login, server and port.
- Charts are drawn on the server (`Chart::`), readable without JavaScript,
  each with a table view; a new type scale (IBM Plex, bundled) and accent.

### Added
- **WHM > Accounts > account page**: usage against the package, domains,
  activity, and every action in one place. **Edit** accounts (email, name,
  package, password) and **packages**.
- **Log in as** a hosting account (admins, and resellers for their own
  accounts), with a banner and a way back; logged.
- **More than one administrator** (the last active one is protected).
- **Forgot password** (single-use email link, 1 hour) and **two-factor
  recovery codes** (ten, shown once).
- **WHM > Notifications**: the panel sends mail through the local mail
  server - welcome emails (with a set-your-password link, never the
  password), password resets, alert emails, a test email.
- **WHM > Alerts**: health check results, services, disk, accounts over
  quota, failed backups and migrations, expiring certificates, routes to
  review - on the dashboard too, and new ones emailed hourly.
- **File Manager editor** for text files (UTF-8, up to 1 MB) and new files;
  upload by drag and drop.
- **SSH keys** for SFTP logins.
- **Package features**: ten switches per package (File Manager, cron jobs,
  self-service backups, DNS editor, other PHP versions, aliases, routing
  rules, object cache, phpMyAdmin/remote MySQL, SSH keys), enforced by the
  panel and hidden from the account's menu when off; each account can be
  switched differently on its page, which also changes its package in one
  step. Migration `024_package_features.sql`.
- **Unlimited** for any package limit, and a **page per package**: its
  accounts with usage and totals, limits, features, **Duplicate**, and
  **Move these accounts** to another package.
- **Bulk account actions** in WHM > Accounts: change package, suspend,
  unsuspend, or switch a feature for the selected accounts.
- **Reseller limits**: a reseller's own package caps what its accounts'
  packages add up to (disk, bandwidth, domains) and how many accounts it
  has; shown on its dashboard and its page.
- **Customer emails**: quota warnings at 90% and when out of disk or
  bandwidth (once per level), and suspension/unsuspension notices with an
  optional reason. Switches in WHM > Notifications.
- **Local run** (`dev/local/`): the real panel against MAMP's MySQL, with
  demo logins; outgoing mail goes to `dev/local/outbox.log`.
- **Local preview** (`dev/preview/`): every view renders with sample data
  on a laptop; CI renders them all and checks labels and button names.

### Fixed
- Routes: a failed apply showed as applied (the log line starts with a
  timestamp).
- SFTP settings showed the last Server Tweaks job instead of its own.
- The FTP page's "Restrict to domain" option text was garbled.
- The Routes tab answered 500 when the site's PHP pool wasn't running; it
  now says so on the domain page.

## Unreleased - public launch candidate

Everything below landed on `migration-file-import` (2026-10-01), on top of
the DNS work further down. Upgrade by re-running the installer.

### Fixed (2026-10-02)
- Deleting a package that accounts still use is refused (it used to leave
  them with no limits at all); the list shows each package's account count.
  A hosting account can no longer be created or migrated without a package
  (the migration's fallback package is required).
- Create-account form: password (10+) and username rules now match what the
  server enforces.
- Mailbox and FTP passwords follow the same rule as panel logins (10+
  characters, not common, not containing the name).
- cPanel > Cron Jobs > **Run now** works for PHP jobs (it ran as the web
  user, which can't switch to the account's user); it goes through the root
  worker now.
- **Self-signed only** sticks: the daily SSL job no longer moves those
  domains to Let's Encrypt (new `domains.ssl_pinned`); *Automatic* is a
  choice in the domain list too.
- The migration page's "what gets migrated" text was out of date.
- HTTP/3: the firewall opens 443/udp (it was only open on 2083).

### Security and isolation
- **Per-account isolation**: every hosting account is a Linux user
  (`jp_<name>`); its PHP runs in its own PHP-FPM pool per PHP version
  (`open_basedir`, exec functions off unless WHM allows them), its static
  files come from its own nginx (`jinnpanel-static@<name>`) running as that
  user with a per-domain compressed cache. The panel only reads customer
  files and works on them through the account's pool (`PoolClient`).
- **Root worker hardened**: HMAC-signed jobs, regular files of
  frankenphp/root only, every job type validates its input; root writes
  only to root-owned `/var/lib/jinnpanel/worker/`.
- **Admin APIs on loopback only**: Stalwart (8080/8443), SFTPGo (8090),
  Caddy's admin API on a Unix socket; firewall no longer opens 8080/8090 or
  Cockpit (9090).
- **Sign-in**: TOTP two-factor for every role (admins can reset it), login
  throttling, 10+ character non-common passwords, sessions ended on
  password/2FA change, roles re-read on every request, POST logout.
- **HTTPS everywhere** for the panel with HSTS, CSP and the other security
  headers; one-time setup token for `/setup`.
- **Audit log** (WHM > Activity Log) of every sign-in and panel action.
- **Suspension** that actually stops everything: sites 503 and pools
  stopped, mail/SFTP/MySQL/Valkey logins off, cron skipped, sessions ended.
- **Quotas enforced**: hourly usage (files, databases, mail, bandwidth);
  over disk -> new uploads/resources refused, optional hard XFS user quotas
  (`JINNPANEL_XFS_QUOTA=1`); over bandwidth -> 509; resellers limited by
  their package's account count.
- Reserved usernames (`Usernames`) and a domain policy (`DomainPolicy`:
  no server names, other accounts' domains, public suffixes or the most
  impersonated domains).
- fail2ban (`sshd` + `recidive`).

### Hosting
- `<domain>/jpanel` opens the customer panel at `<domain>:2083`.
- Let's Encrypt by default when a domain resolves here, certificate status
  and **Run AutoSSL** per domain; `www` served; HTTPS redirect; dotfiles
  hidden.
- A page per domain: SSL, document root, PHP version and **php.ini
  settings** (cPanel MultiPHP INI files imported), aliases, cache, exposed
  files.
- **Routes**: `.htaccess` translated to Caddy rules, editable, validated
  and applied by the worker with rollback.
- **Exposed files** check: archives, dumps, backups and logs in web folders.
- **File Manager**: multi-select, copy/move, zip/tar.gz extract and
  compress, permissions, the whole site folder.
- **Caching**: page cache, static file cache, browser caching, Valkey
  object cache per account, OPcache settings.
- **Cron jobs** (PHP scripts and URL fetches) as the account.
- **MySQL like cPanel**: users, privileges, Remote MySQL, phpMyAdmin with
  sign-on.
- **PHP extensions** page in WHM.
- **Backups**: daily per account (files, databases, mail) and of the
  server, retention, S3-compatible copy, per-part restore, customer
  downloads.

### Mail
- Mailboxes created the way Stalwart 0.16 accepts; PHP `mail()` works.
- Forwarders, autoresponders, default (catch-all) address, mail client
  settings card.
- Mail DNS published automatically: MX, SPF, DKIM (RSA + Ed25519), DMARC,
  autoconfig/autodiscover, SRV, MTA-STS; Stalwart uses Caddy's certificate.
- **Webmail** (Cypht) at `https://mail.<domain>/`.

### DNS
- Customer zone editor (cPanel > DNS).
- AAAA records next to every A record pointing here.
- Subdomain sites live in their parent zone.
- Knot zones persist across restarts (`/etc/knot/zones.conf`).

### cPanel migration
- From backup files on the server, or fetched from S3 (WHM > cPanel
  Migration > From backup files).
- DNS records, cron jobs, domain aliases (parked domains), FTP accounts,
  forwarders, autoresponders, default addresses and the account's default
  mailbox are migrated; routes translated from `.htaccess`.
- Restore mail from a backup for already-migrated accounts.

### Docs
- New `docs/OPERATIONS.md`; install, features, architecture, comparison and
  security docs rewritten for the current state.

## DNS management (merged from `dns-management`)

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

## Earlier development

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
