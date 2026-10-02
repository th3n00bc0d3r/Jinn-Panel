# How JinnPanel compares

An honest comparison, not a sales pitch. JinnPanel is new (2026); several
of these panels have 15-25 years of production use behind them. This page
is here to help you decide whether it fits, not to claim it's better across
the board.

Facts about other panels were checked against their official sites,
documentation and repositories on **2026-10-01** (sources at the bottom).
Products change - check the vendor's page before you buy. **?** means we
couldn't confirm it from an official source; it doesn't mean "no".

## At a glance

✅ yes / built in  ·  ⚠️ partly, paid, or with conditions  ·  ❌ no  ·  ? not confirmed

| | **JinnPanel** | cPanel & WHM | Plesk Obsidian | DirectAdmin | CyberPanel | HestiaCP | ISPConfig | CloudPanel | aaPanel |
|---|---|---|---|---|---|---|---|---|---|
| **Free** | ✅ MIT | ❌ | ❌ | ❌ | ✅ GPL-3.0 | ✅ GPL-3.0 | ✅ BSD | ✅ (not open source) | ✅ (source-available, Pro paid) |
| **Open source (OSI)** | ✅ | ❌ | ❌ | ❌ | ✅ | ✅ | ✅ | ❌ | ❌ |
| **AlmaLinux 10** | ✅ (only OS) | ✅ (v132+) | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| **Debian / Ubuntu** | ❌ | ⚠️ Ubuntu 24.04 only | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Email server** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ⚠️ plugin |
| **Webmail** | ✅ Cypht | ✅ Roundcube | ✅ Roundcube | ✅ Roundcube | ⚠️ own client; Roundcube paid | ✅ Roundcube / SnappyMail | ? | ❌ | ? |
| **DNS server** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ? |
| **DNS clustering / secondaries** | ❌ | ✅ | ⚠️ extension | ✅ | ? | ✅ | ✅ | ❌ | ? |
| **Reseller accounts** | ✅ | ✅ | ⚠️ Web Host edition only | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| **Import from cPanel** | ✅ built in, free | ✅ Transfer Tool | ✅ Plesk Migrator | ✅ restores cpmove archives | ✅ | ❌ | ⚠️ paid toolkit | ❌ | ? |
| **Backups to S3-compatible storage** | ✅ | ✅ | ⚠️ extension | ⚠️ FTP/FTPS out of the box | ⚠️ module | ✅ via Rclone | ? | ✅ via Rclone | ✅ |
| **Incremental backups** | ❌ (daily full) | ✅ | ✅ | ⚠️ manual Borg setup | ✅ | ✅ Restic | ? | ? | ? |
| **2FA for logins** | ✅ every role | ✅ | ? | ✅ | ✅ | ✅ | ? | ? | ? |
| **Per-account PHP isolation** | ✅ Linux user + PHP-FPM pool per account | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ per site | ? |
| **CPU/RAM limits per account** | ❌ | ⚠️ needs CloudLinux | ? | ✅ cgroups v2 | ? | ? | ? | ? | ? |
| **Hard disk quotas** | ✅ XFS (opt-in) | ✅ | ? | ✅ | ? | ? | ✅ | ? | ? |
| **Plain FTP** | ❌ SFTP/SCP only | ✅ (off by default) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **App installer (WordPress etc.)** | ❌ | ⚠️ add-ons | ✅ | ⚠️ add-ons | ✅ | ✅ | ? | ✅ | ✅ |
| **Billing / WHMCS integration, external API** | ❌ | ✅ | ✅ | ✅ | ? | ? | ✅ | ? | ? |
| **Paid vendor support** | ❌ community | ✅ | ✅ | ✅ | ⚠️ paid add-ons | ❌ | ? | ❌ | ⚠️ Pro |
| **Around since**¹ | 2026 | 1990s | early 2000s | early 2000s | ~2017 | ~2018 (VestaCP fork) | 2000s | ~2020 | ~2019 |

¹ Approximate. CyberPanel, HestiaCP and aaPanel from their GitHub
repositories' creation dates; the others from general knowledge, not
confirmed from an official page.

Approximate list prices seen on 2026-10-01 (monthly, single server):
cPanel & WHM from $27.46 (1 account) to $69.99 (100 accounts) plus $0.49
per extra account; DirectAdmin $5 (2 accounts) / $15 (10) / $29
(unlimited); aaPanel Pro $28.80. Plesk is priced by domain count (Web
Admin 10 / Web Pro 30 / Web Host unlimited); its pricing page showed
conflicting figures, so check it directly.

## The stack, side by side

| | **JinnPanel** | cPanel & WHM | Plesk | DirectAdmin | CyberPanel | HestiaCP | ISPConfig | CloudPanel |
|---|---|---|---|---|---|---|---|---|
| **Web server** | FrankenPHP (Caddy) in front; nginx per account for static files | Apache (EasyApache 4) | nginx + Apache | Apache, nginx, LiteSpeed or OpenLiteSpeed | OpenLiteSpeed / LiteSpeed | nginx (+ Apache) | Apache or nginx | nginx (+ Varnish) |
| **PHP** | PHP-FPM, one pool per account per version (8.2-8.5) | PHP-FPM, LSAPI, CGI... | PHP-FPM | PHP-FPM or CGI | LSAPI | PHP-FPM | PHP-FPM / FastCGI | PHP-FPM |
| **TLS** | Caddy automatic HTTPS (Let's Encrypt) | AutoSSL | Let's Encrypt | Let's Encrypt | Let's Encrypt | Let's Encrypt | Let's Encrypt | Let's Encrypt |
| **Mail** | Stalwart (SMTP, IMAP, POP3, JMAP, Sieve in one binary) | Exim + Dovecot | Postfix + Dovecot | Exim + Dovecot | Postfix | Exim + Dovecot | Postfix + Dovecot | - |
| **SPF/DKIM/DMARC** | All automatic, DKIM RSA + Ed25519, plus MTA-STS and autoconfig | SPF/DKIM automatic, DMARC manual | ? | ? | DKIM manager | Records created | DKIM signing | - |
| **DNS** | Knot DNS | PowerDNS (BIND optional) | BIND | BIND | PowerDNS | BIND | BIND or PowerDNS | - |
| **File transfer** | SFTPGo: SFTP/SCP, logins write as the account | Pure-FTPd / ProFTPD | ProFTPD | ProFTPD / Pure-FTPd | Pure-FTPd | vsftpd | Pure-FTPd | ProFTPD + SSH |
| **Object cache** | Valkey, one login per account | ? | ? | ? | ? | ? | ? | ? |
| **Panel code** | Plain PHP, no framework, no Composer | Perl/C, proprietary | Proprietary | Proprietary | Python/Django | PHP + Bash | PHP | Proprietary |

## Where JinnPanel is a different choice, not just a free clone

- **A small, modern stack.** One mail server (Stalwart) instead of
  Exim/Postfix + Dovecot + OpenDKIM + a spam filter; Caddy's automatic HTTPS
  instead of a separate certificate tool; Knot for DNS; SFTPGo instead of an
  FTP daemon. Each is managed through an API, not by editing config files.
- **Isolation by default, without CloudLinux.** Each account is a Linux
  user with its own PHP-FPM pool *and* its own static file server, so not
  even static files are read by a shared web server user. The panel itself
  can only read customer files and works on them as the customer.
- **Mail DNS done for you.** Every mail domain gets MX, SPF, DKIM (RSA and
  Ed25519), DMARC, MTA-STS, autoconfig/autodiscover and SRV records, kept in
  sync daily - most panels leave DMARC or MTA-STS to you.
- **cPanel migration is free and built in**: from a live cPanel/WHM server
  (single account, reseller or root), from backup files, or from backups in
  S3 - including mail, DNS records, cron jobs, forwarders, autoresponders
  and `.htaccess` rules translated to the new web server.
- **The mail settings UI is generated from Stalwart's own schema**: forms
  come from the live schema, so exposing another Stalwart settings object
  is one line, not a new page.
- **Readable.** Plain PHP with no framework or dependencies. If something
  breaks, you can read the code that did it.

## Where the established panels are still the safer choice

- **Track record.** cPanel, Plesk and DirectAdmin have been run by hosting
  companies at scale for decades. JinnPanel hasn't.
- **Resource limits.** No per-account CPU/RAM containment (cgroups, LVE).
  One busy site can slow down the others.
- **Ecosystem.** No billing/WHMCS integration, no external API, no
  one-click app installer, no marketplace.
- **Account management basics still missing**: WHM can't edit an existing
  account (change its package, contact email or password) or log in as a
  customer, there's no "forgot password" or 2FA recovery codes, and no
  welcome or notification emails yet, and only one admin account.
- **No DNSSEC**, no uploaded (custom) certificates, no per-mailbox
  quotas.
- **More than one server.** No DNS clustering, no multi-server management,
  no failover.
- **Backups.** Daily full backups (per part, with S3 copies) - not
  incremental, so large accounts take more space and time.
- **OS choice.** AlmaLinux 10 only.
- **Plain FTP.** Not offered (SFTP/SCP only) - some older customers'
  tools expect FTP.
- **Paid support.** If you need someone to call at 3am, that's cPanel,
  Plesk or DirectAdmin.

## Who JinnPanel is for

Self-hosters, agencies and small hosting providers who want a free,
modern cPanel-style panel on their own server, care about isolation and
email deliverability out of the box, are moving off cPanel, and are
comfortable reading PHP and shell when something needs fixing. Not (yet)
for hosting companies running thousands of paying accounts across many
servers unattended.

## Sources (checked 2026-10-01)

- cPanel: [pricing](https://cpanel.net/pricing/) ·
  [AlmaLinux requirements](https://docs.cpanel.net/installation-guide/system-requirements-almalinux/) ·
  [Ubuntu requirements](https://docs.cpanel.net/installation-guide/system-requirements-ubuntu/) ·
  [nameserver selection](https://docs.cpanel.net/whm/service-configuration/nameserver-selection/) ·
  [FTP server selection](https://docs.cpanel.net/whm/service-configuration/ftp-server-selection/) ·
  [backups](https://docs.cpanel.net/whm/backup/backup-configuration/) ·
  [DNS cluster](https://docs.cpanel.net/whm/clusters/dns-cluster/) ·
  [Transfer Tool](https://docs.cpanel.net/whm/transfers/transfer-tool/) ·
  [2FA](https://docs.cpanel.net/whm/security-center/two-factor-authentication-for-whm/) ·
  [email deliverability](https://docs.cpanel.net/whm/email/email-deliverability-in-whm/)
- Plesk: [pricing](https://www.plesk.com/pricing/) ·
  [software requirements](https://docs.plesk.com/release-notes/obsidian/software-requirements/) ·
  [Plesk Migrator](https://docs.plesk.com/en-US/obsidian/migration-guide/75496/) ·
  [S3 backup](https://www.plesk.com/extensions/s3-backup/) ·
  [Slave DNS Manager](https://plesk.com/extensions/slave-dns-manager)
- DirectAdmin: [pricing](https://www.directadmin.com/pricing.php) ·
  [OS support](https://docs.directadmin.com/operation-system-level/os-general/) ·
  [web services](https://docs.directadmin.com/custombuild/web-services/) ·
  [remote backup](https://docs.directadmin.com/directadmin/backup-restore-migration/backup-to-remote/) ·
  [user cgroups](https://docs.directadmin.com/operation-system-level/os-general/user_cgroups/) ·
  [multi-server](https://docs.directadmin.com/directadmin/general-usage/multi-server-setup/)
- CyberPanel: [GitHub](https://github.com/usmannasir/cyberpanel)
- HestiaCP: [GitHub](https://github.com/hestiacp/hestiacp) ·
  [getting started](https://hestiacp.com/docs/introduction/getting-started) ·
  [backups](https://hestiacp.com/docs/server-administration/backup-restore)
- ISPConfig: [features](https://www.ispconfig.org/ispconfig/services-and-functions/) ·
  [3.3.1 release](https://www.ispconfig.org/blog/ispconfig-3-3-1-released/) ·
  [migration toolkit](https://www.ispconfig.org/add-ons/ispconfig-migration-tool/)
- CloudPanel: [requirements](https://www.cloudpanel.io/docs/v2/requirements/) ·
  [technology stack](https://www.cloudpanel.io/docs/v2/technology-stack/) ·
  [e-mail](https://www.cloudpanel.io/docs/v2/frontend-area/e-mail/) ·
  [backups](https://www.cloudpanel.io/docs/v2/admin-area/backups/) ·
  [users](https://www.cloudpanel.io/docs/v2/admin-area/users/) ·
  [license](https://www.cloudpanel.io/license-terms/)
- aaPanel: [GitHub](https://github.com/aaPanel/aaPanel) ·
  [pricing](https://www.aapanel.com/new/pricing.html)
