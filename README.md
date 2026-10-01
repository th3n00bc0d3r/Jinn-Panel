<p align="center">
  <img src="assets/brand/logo.svg" alt="JinnPanel" width="360">
</p>

<p align="center">
  <a href="LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue.svg"></a>
  <a href="https://github.com/th3n00bc0d3r/Jinn-Panel/actions"><img alt="CI" src="https://github.com/th3n00bc0d3r/Jinn-Panel/actions/workflows/ci.yml/badge.svg"></a>
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.5%2C%20no%20framework-777bb4.svg">
  <img alt="Platform" src="https://img.shields.io/badge/platform-AlmaLinux%2010-1c1c1c.svg">
  <a href="CODE_OF_CONDUCT.md"><img alt="Contributor Covenant" src="https://img.shields.io/badge/Contributor%20Covenant-2.1-4baaaa.svg"></a>
</p>

# JinnPanel

**A free, open-source WHM/cPanel-style hosting control panel** for a single
AlmaLinux 10 server: resellers, hosting accounts, domains, email, DNS,
databases, SFTP, backups and one-click migration from cPanel - on a small,
modern stack (FrankenPHP/Caddy, PHP-FPM, MariaDB, Stalwart Mail, Knot DNS,
SFTPGo, Valkey). One script installs everything on a fresh server.

> **Status: public beta.** Everything below is in use on a production
> server, but the project is new (2026). Read
> [Known limitations](#known-limitations) and
> [`SECURITY.md`](SECURITY.md) before hosting other people's sites on it.

## Why JinnPanel

- **Free and MIT-licensed.** No per-account licence fees, no closed parts.
- **Isolation without CloudLinux.** Every hosting account is its own Linux
  user with its own PHP-FPM pool and its own static file server; the panel
  only ever works on customer files *as* the customer.
- **Email that gets delivered.** SPF, DKIM (RSA + Ed25519), DMARC,
  MTA-STS, autoconfig and autodiscover are published for every mail domain
  automatically. Webmail included.
- **Automatic HTTPS.** Let's Encrypt for every domain the moment it points
  at the server - no button to press.
- **Leave cPanel in one click.** Built-in, free migration from a live
  cPanel/WHM server or from backup files: sites, databases, mailboxes and
  stored mail, DNS records, cron jobs, forwarders, autoresponders, and
  `.htaccess` rules translated for the new web server.
- **Readable.** Plain PHP, no framework, no Composer. You can read the
  code that's running your server.

## Comparison chart

| | **JinnPanel** | cPanel & WHM | Plesk | DirectAdmin | CyberPanel | HestiaCP |
|---|---|---|---|---|---|---|
| Price | **Free (MIT)** | from ~$27/mo | paid | from $5/mo | free (paid add-ons) | free |
| Open source | ✅ | ❌ | ❌ | ❌ | ✅ | ✅ |
| AlmaLinux 10 | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Resellers | ✅ | ✅ | ⚠️ top edition | ✅ | ✅ | ❌ |
| Email + webmail | ✅ | ✅ | ✅ | ✅ | ⚠️ Roundcube paid | ✅ |
| SPF + DKIM + DMARC automatic | ✅ | ⚠️ DMARC manual | ? | ? | ⚠️ | ✅ |
| Per-account PHP isolation | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| CPU/RAM limits per account | ❌ | ⚠️ CloudLinux | ? | ✅ | ? | ? |
| cPanel migration built in | ✅ free | ✅ | ✅ | ✅ | ✅ | ❌ |
| S3-compatible backups | ✅ | ✅ | ⚠️ extension | ⚠️ | ⚠️ | ✅ |
| 2FA | ✅ | ✅ | ? | ✅ | ✅ | ✅ |
| DNS clustering | ❌ | ✅ | ⚠️ | ✅ | ? | ✅ |
| App installer / billing API | ❌ | ✅ | ✅ | ✅ | ⚠️ | ⚠️ |
| Paid support | ❌ | ✅ | ✅ | ✅ | ⚠️ | ❌ |

✅ yes · ⚠️ partly, paid or with conditions · ❌ no · ? not confirmed.
Checked against vendor sites on 2026-10-01 - the full chart (also
ISPConfig, CloudPanel and aaPanel), the stack side by side, and sources
are in [`docs/COMPARISON.md`](docs/COMPARISON.md).

## What you get

**WHM** (admin and resellers)
- Hosting accounts and resellers, packages with disk, bandwidth, domain,
  database, mailbox and SFTP limits - enforced, with optional hard XFS
  disk quotas.
- Suspension that really stops an account: sites, PHP, mail, SFTP, MySQL,
  cache, cron and sessions.
- DNS zones for every domain and the server's own nameservers.
- cPanel migration: single account, reseller, or a whole server via WHM
  root; or from backup files / S3.
- Server Config: Stalwart mail settings (forms generated from its schema),
  SFTP, PHP and OPcache, PHP extensions, extra PHP versions (8.2-8.4 next
  to 8.5), MariaDB, and one-click performance profiles sized from the
  server's CPU and RAM.
- Backups (daily, per account and server, S3-compatible copies,
  per-part restore), an activity log of every action, live service logs.

**cPanel** (customers, at `panel.<server>` or `<their domain>/jpanel`)
- Domains, aliases, subdomains, document root, PHP version and php.ini
  settings per domain, SSL status and Run AutoSSL, `.htaccess` routes
  editor, exposed-file check.
- MySQL databases and users with privileges, Remote MySQL, phpMyAdmin.
- Email: mailboxes, forwarders, autoresponders, catch-all, webmail
  (Cypht), mail client settings.
- DNS zone editor, File Manager (upload, download, copy/move, zip/unzip,
  permissions),
  SFTP accounts, cron jobs, page/static/object caching, backups and
  restores, two-factor sign-in.

Screen-by-screen tour: [`docs/FEATURES.md`](docs/FEATURES.md).

## Install

On a **fresh AlmaLinux 10 x86_64** server (2 vCPU / 4 GB RAM / 40 GB disk
or more), as root:

```bash
dnf -y install git
git clone https://github.com/th3n00bc0d3r/Jinn-Panel.git /opt/jinnpanel
cd /opt/jinnpanel/installer
JINNPANEL_HOSTNAME=server.example.com JINNPANEL_XFS_QUOTA=1 ./install.sh
```

No prompts; every internal credential is generated randomly. When it
finishes it prints the nameserver/glue records to set at your registrar
and a **one-time setup token**: open `https://panel.server.example.com/setup`,
enter it, and create the administrator. No admin password is ever baked
into the installer or this repo.

Full guide - requirements, options, DNS, the going-live checklist, ports,
upgrades: [`docs/INSTALL.md`](docs/INSTALL.md). Upgrading is
`git pull` + re-running the installer (idempotent; keeps all data and
secrets).

## Documentation

| Document | What's in it |
|---|---|
| [`docs/INSTALL.md`](docs/INSTALL.md) | Requirements, quick and detailed setup, installer options, DNS and glue, going-live checklist, ports, upgrading, uninstalling |
| [`docs/FEATURES.md`](docs/FEATURES.md) | Every screen of WHM and cPanel, limits and how they're enforced |
| [`docs/OPERATIONS.md`](docs/OPERATIONS.md) | Day-to-day running: paths, health checks, logs, backups, quotas, DNS, certificates, mail deliverability, incidents |
| [`docs/MIGRATION.md`](docs/MIGRATION.md) | Moving accounts from cPanel & WHM |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | How it's built: the root worker, customer isolation, network exposure, DNS, mail, backups, security posture |
| [`docs/COMPARISON.md`](docs/COMPARISON.md) | Full comparison with cPanel, Plesk, DirectAdmin, CyberPanel, HestiaCP, ISPConfig, CloudPanel, aaPanel |
| [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) | SELinux, systemd, Knot and Stalwart gotchas already handled by the installer |
| [`docs/ICONS.md`](docs/ICONS.md) | Brand assets and the UI icon set |
| [`SECURITY.md`](SECURITY.md) | Reporting vulnerabilities, how it's protected, accepted limitations |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) / [`CONTEXT.md`](CONTEXT.md) | Dev setup, code style, tests; orientation for contributors |
| [`CHANGELOG.md`](CHANGELOG.md) | What changed |

## Known limitations

- **One server.** No clustering, DNS secondaries on other machines or
  failover.
- **No per-account CPU/RAM limits** (no cgroups/LVE); PHP pools are capped
  by process count and `memory_limit` only.
- **AlmaLinux 10 only**, and it expects the server to itself.
- **No billing integration (WHMCS etc.), external API or app installer.**
- **Account management gaps**: WHM can't yet edit an existing account
  (change package, email or password) or "log in as" a customer; no "forgot
  password", no 2FA recovery codes (an admin resets 2FA), no welcome or
  notification emails, no file editor in the File Manager, and only one
  admin account (created by `/setup`).
- No SSH access for customers, no visitor statistics (AWStats-style).
- No DNSSEC, custom (uploaded) certificates or per-mailbox quotas.
- Hard disk quotas cover files only; databases and mail are measured and
  limited by the panel (no new ones), and mail keeps arriving over quota.
- **SFTP/SCP only**, no plain FTP.
- **Backups are daily full copies**, not incremental.
- **PHP only** - no Node.js/Python app hosting.
- cPanel migration from backup files is proven on a real cPanel server's
  archives; the live API transfer hasn't been run against a real cPanel
  server yet.
- Community support only.

## Repository layout

```
Jinn-Panel/
├── installer/install.sh   the whole server install; safe to re-run (= upgrade)
├── app/                   the application, deployed to /var/www/hostpanel
│   ├── public/            front controller + every route, assets
│   ├── src/               Controllers/, Services/, Support/, Config.php.template
│   ├── views/             plain PHP templates (WHM, cPanel, auth layouts)
│   ├── worker/            root worker, migration runner, timers' scripts
│   ├── runtime/           code that runs inside customers' PHP pools
│   └── migrations/        schema.sql + upgrade scripts
├── tests/                 UnitTest.php, HtaccessTranslatorTest.php (run in CI)
├── docs/                  everything in the table above
└── assets/                logo, icons
```

## Contributing

Contributions are welcome - see [`CONTRIBUTING.md`](CONTRIBUTING.md) and
[`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md). Found a security issue? Report
it privately as described in [`SECURITY.md`](SECURITY.md), not as a public
issue.

## License

[MIT](LICENSE) - free to use, modify and self-host.
