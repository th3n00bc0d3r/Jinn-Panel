# Install Guide

- [Requirements](#requirements)
- [Quick setup](#quick-setup)
- [Detailed setup](#detailed-setup)
- [Installer options](#installer-options)
- [DNS: nameservers and glue](#dns-nameservers-and-glue)
- [After the installer finishes](#after-the-installer-finishes)
- [Going live checklist](#going-live-checklist)
- [Ports and services](#ports-and-services)
- [Re-running / upgrading](#re-running--upgrading)
- [Uninstalling](#uninstalling)

## Requirements

- A **fresh AlmaLinux 10 server, x86_64**, used for nothing else. JinnPanel
  takes over the web, mail, DNS and SFTP ports and installs its own copies
  of every service; don't install it next to another panel. Other
  RHEL 10-family systems (Rocky, RHEL) will very likely work but aren't
  tested.
- Root access (SSH).
- A public IPv4 address. IPv6 is optional - if the server has a public
  IPv6 address, every zone gets AAAA records too.
- **Two IPv4 addresses are better than one** for DNS: ns1 and ns2 then
  answer on different addresses. With one address both nameservers point at
  it - that works, but some registrars refuse two nameservers on the same IP.
- A domain whose registrar lets you set **nameservers and glue records**
  (e.g. for `server.example.com` you need control of `example.com`).
- For mail: outbound port 25 open at your provider (many block it by
  default - ask), and a **reverse DNS (PTR)** record for the server's IP set
  to its hostname, at the provider. Without both, other mail servers will
  reject your mail or put it in spam.
- Outbound internet access during the install (AlmaLinux repos, EPEL, the
  CZ.NIC COPR for Knot, and the upstream FrankenPHP, Stalwart, SFTPGo,
  Cypht and phpMyAdmin download servers).
- **Resources:** 2 vCPUs / 4 GB RAM / 40 GB disk is a sensible minimum for
  a few dozen small sites with mail. It runs on less (it was developed on
  2 vCPU / 3.6 GB), but mail, MariaDB and PHP-FPM share the box, and
  backups are written to the same disk unless you add S3.
- For hard per-account disk quotas: the root filesystem must be **XFS**
  (AlmaLinux's default) - see `JINNPANEL_XFS_QUOTA` below.

---

## Quick setup

```bash
ssh root@your-server-ip
dnf -y install git
git clone https://github.com/th3n00bc0d3r/Jinn-Panel.git /opt/jinnpanel
cd /opt/jinnpanel/installer
JINNPANEL_HOSTNAME=server.example.com JINNPANEL_XFS_QUOTA=1 ./install.sh
```

It runs unattended and asks nothing. At the end it prints:

1. what to set at your registrar (nameservers + glue) so
   `panel.server.example.com` resolves, and
2. a **one-time setup token**.

Open `https://panel.server.example.com/setup`, paste the token, create the
administrator account. Reboot once if you used `JINNPANEL_XFS_QUOTA=1`.
That's it.

---

## Detailed setup

### 1. Get the files onto the server

`git clone` (above) is easiest and makes upgrades a `git pull`. A tarball or
`scp -r` works too - the installer only needs `../app` next to it:

```
Jinn-Panel/
├── installer/install.sh
└── app/                    <- must be a sibling of installer/
```

### 2. Pick the hostname

The hostname is the server's own name, e.g. `server.example.com`:

- the panel is served at **`panel.<hostname>`** (`panel.server.example.com`),
- the **server zone** is the hostname's parent (`example.com`), which this
  server serves itself, with `ns1.example.com` / `ns2.example.com`,
- mail from the server itself is sent as the hostname, so the PTR record
  should match it.

Without `JINNPANEL_HOSTNAME`, the installer keeps the current hostname if it
has a dot in it, otherwise it makes up `jinnserver-xxxxxx.local` (fine for a
test VM, useless for real hosting).

### 3. Run it

```bash
cd installer
./install.sh > /root/jinnpanel-install.log 2>&1   # or just ./install.sh to watch
```

It stops on the first real failure (`set -euo pipefail` plus a trap that
names the line) instead of carrying on half-configured. What it does, in
order (`docs/ARCHITECTURE.md` explains the *why*):

1. **Identity and base system** - hostname, `/etc/hosts`, resolvers
   (1.1.1.1 + the router), base packages, EPEL, `firewalld`, **fail2ban**
   (`sshd` jail: 5 failures in 10 minutes = 1 hour ban; `recidive`: repeat
   offenders banned for a week), and the XFS quota boot option if asked.
2. **MariaDB** - random root password, anonymous users and the test DB
   removed, the panel database `hostpanel` and two MariaDB accounts:
   `hostpanel_app` (only the panel's own database) and `hostpanel_prov`
   (creates customer databases/users with a fixed privilege list, never
   `ALL PRIVILEGES`).
3. **FrankenPHP** (Caddy + PHP 8.5, ZTS) - official packages, the PHP
   extensions sites commonly need, SELinux booleans and port labels, the
   panel's vhost on `panel.<hostname>` and `:2083`, Caddy's admin API moved
   to a Unix socket, the **PHP-FPM** template unit
   `jinnpanel-php-fpm@.service` (one master per PHP version, one pool per
   account) and the per-account **static file server**
   `jinnpanel-static@.service` (nginx, running as each account).
4. **Stalwart Mail** - installs it and completes its setup wizard through
   its API. Its HTTPS listener is moved off :443 (that's FrankenPHP's), and
   its HTTP/admin listeners (8080, 8443) are bound to **127.0.0.1 only**.
   Firewall: 25, 465, 587, 993, 995, 4190.
5. **SFTPGo** - SFTP on **port 2022** (SCP enabled), its web admin/REST API
   on **127.0.0.1:8090 only**, the `panelapi` admin the panel uses, and a
   unit drop-in giving it the capabilities to write files as each account.
6. **Knot DNS** - from the CZ.NIC COPR, listening on all addresses (port
   53, UDP + TCP), with `/etc/knot/zones.conf` holding the registered zones
   so they survive restarts.
7. **JinnPanel** - copies `app/` to `/var/www/hostpanel` (root-owned,
   readable by FrankenPHP), renders `src/Config.php` with fresh secrets,
   applies the schema, builds the Tailwind CSS, generates the one-time
   setup token, publishes the server zone (`dns-bootstrap.php`), installs
   **Valkey** (object cache, loopback), **Cypht** webmail (its own
   FrankenPHP as user `webmail`), **phpMyAdmin** (its own FrankenPHP) and
   the timers.
8. **The root worker** - `hostpanel-worker.timer` runs
   `/usr/local/bin/hostpanel-worker.php` every 5 seconds to carry out
   everything the panel queues (account users and pools, vhosts, zone
   files, service restarts, PHP versions, backups, migrations). It then
   syncs every account and checks each service answers.

### 4. Read the summary

```
  JinnPanel is deployed and every service is running.

  Next steps:
    1. DNS: this server now serves the zone example.com. For panel.server.example.com
       to resolve publicly, set at the registrar of example.com:
           nameservers   ns1.example.com, ns2.example.com
           glue records  ns1.example.com -> 203.0.113.5
                         ns2.example.com -> 203.0.113.6
       Until that propagates, map "203.0.113.5  panel.server.example.com" in your
       local hosts file to reach the panel.
    2. Visit:  https://panel.server.example.com/setup
       and create the real administrator account, with this one-time
       setup token (also in /root/.jinnpanel/setup_token):
           <token>
```

Internal service credentials (MariaDB root, Stalwart admin, SFTPGo admin,
Valkey admin) are in `/root/.jinnpanel/` (`credentials.env` and one file
each), root-only. You don't need them day to day.

---

## Installer options

All are environment variables; all are optional.

| Variable | Default | What it does |
|---|---|---|
| `JINNPANEL_HOSTNAME` | current hostname if it has a dot, else a random `.local` name | The server's name. Panel at `panel.<hostname>`. |
| `JINNPANEL_XFS_QUOTA` | `0` | `1` = hard per-account disk quotas: adds `rootflags=uquota` to the kernel options (XFS root only; takes effect after **one reboot**). After that each account's Linux user gets its package's disk quota as a hard limit, at boot and hourly. Without it, quotas are enforced by the panel only (see `docs/FEATURES.md`). |
| `JINNPANEL_DNS_ZONE` | the hostname's parent | The zone the server serves for its own names (use it when the parent isn't right, e.g. `server.example.co.uk` -> `example.co.uk`). |
| `JINNPANEL_NS1_HOST` / `JINNPANEL_NS2_HOST` | `ns1.<zone>` / `ns2.<zone>` | Nameserver names. |
| `JINNPANEL_NS1_IP` | the primary IPv4 | ns1's address. |
| `JINNPANEL_NS2_IP` | a second IPv4 on the box, else the primary | ns2's address. |

Nameserver values are stored on the first run and can be changed later in
**WHM > DNS Zones**; re-runs only override them if you pass the variables
again.

Example with two addresses:

```bash
JINNPANEL_HOSTNAME=server.example.com JINNPANEL_NS2_IP=203.0.113.6 \
JINNPANEL_XFS_QUOTA=1 ./install.sh
```

## DNS: nameservers and glue

JinnPanel is authoritative for every hosted domain and for the server zone.
Once the installer is done:

1. At the registrar of the **server zone** (`example.com`), create the glue
   records (also called "child nameservers" or "host records"):
   `ns1.example.com -> <ns1 IP>`, `ns2.example.com -> <ns2 IP>`.
2. Set that domain's nameservers to `ns1.example.com` and `ns2.example.com`.
3. Check: `dig +short panel.server.example.com @8.8.8.8` returns your IP
   (can take minutes to hours).

Customers point **their** domains at `ns1/ns2.example.com`. As soon as a
domain resolves here, its Let's Encrypt certificate is issued
automatically.

If you'd rather keep DNS elsewhere (Cloudflare, your registrar), skip the
glue and create the records there by hand: an A record for
`panel.<hostname>`, and per customer domain the A/MX/SPF/DKIM/DMARC
records. **WHM > DNS Zones** shows the exact zone the panel would serve.

---

## After the installer finishes

1. Make `panel.<hostname>` resolve (DNS above, or temporarily your local
   hosts file). Until it resolves publicly the panel uses a self-signed
   certificate and your browser warns once; with public DNS it gets a Let's
   Encrypt certificate by itself within a minute or two.
2. Open `https://panel.<hostname>/setup`, enter the setup token, and create
   the administrator (10+ characters, not a common password). `/setup`
   disables itself once an admin exists.
3. Sign in. You're in **WHM**. Recommended first steps:
   - **Shield icon > Two-factor**: turn on TOTP for your admin account.
   - **Packages**: create the plans you'll sell.
   - **Backups**: check the hour and retention, add S3-compatible storage.
   - **Accounts**: create a test account, add a domain, check that the
     site, mail and SFTP work.
4. If you used `JINNPANEL_XFS_QUOTA=1`: `reboot`, then
   `xfs_quota -x -c state /` should say user quota accounting and
   enforcement are ON.

## Going live checklist

Before you sell hosting on it:

- [ ] `panel.<hostname>` has a real certificate (no browser warning).
- [ ] ns1/ns2 glue set; `dig NS <server zone> @8.8.8.8` lists both.
- [ ] PTR record for the IPv4 (and IPv6, if used) = the hostname.
- [ ] Outbound port 25 open; a test mail to Gmail/Outlook lands in the
      inbox (check that SPF/DKIM/DMARC pass in the headers).
- [ ] 2FA on every admin and reseller account.
- [ ] Backups: an S3 target configured, a manual backup run, and one
      **restore tested** on a test account.
- [ ] XFS quotas on (if wanted) after a reboot.
- [ ] SSH hardened to your taste: key logins, `PasswordAuthentication no`,
      your own IP in fail2ban's `ignoreip`
      (e.g. `/etc/fail2ban/jail.d/zz-local.local`).
- [ ] Root password changed from what the provider emailed you.
- [ ] Nothing extra open: `firewall-cmd --list-all` shows only the ports in
      the table below.

## Ports and services

Open to the internet (firewalld):

| Port | Service | What for |
|---|---|---|
| 22/tcp | sshd | Administration |
| 53/udp+tcp | Knot DNS | Authoritative DNS |
| 80/tcp, 443/tcp, 443/udp | FrankenPHP (Caddy) | Sites, panel, webmail, Let's Encrypt; 443/udp is HTTP/3 |
| 2083/tcp+udp | FrankenPHP | Customer panel on each domain (`<domain>/jpanel` redirects here; udp = HTTP/3) |
| 25/tcp | Stalwart | Incoming mail (SMTP) |
| 465/tcp, 587/tcp | Stalwart | Mail submission (implicit TLS / STARTTLS) |
| 993/tcp, 995/tcp | Stalwart | IMAPS, POP3S |
| 4190/tcp | Stalwart | ManageSieve (mail filters) |
| 2022/tcp | SFTPGo | SFTP / SCP |

Loopback only (never exposed): MariaDB 3306, Stalwart HTTP/JMAP/admin
8080 and 8443, SFTPGo admin/REST 8090, Valkey 6379, phpMyAdmin 8008,
Cypht 8009, Caddy's admin API (Unix socket `/run/frankenphp/admin.sock`),
PHP-FPM and static-server sockets under `/run/`.

To use Stalwart's or SFTPGo's own web admin, tunnel it over SSH:

```bash
ssh -L 8090:127.0.0.1:8090 -L 8080:127.0.0.1:8080 root@your-server
# then open http://localhost:8090 (SFTPGo) or http://localhost:8080 (Stalwart)
```

Services and timers the installer sets up: `frankenphp`, `mariadb`,
`stalwart`, `sftpgo`, `knot`, `valkey`, `fail2ban`,
`jinnpanel-php-fpm@<version>`, `jinnpanel-static@<account>`,
`jinnpanel-webmail`, `jinnpanel-phpmyadmin`, `hostpanel-worker.timer`
(every 5 s), `jinnpanel-cron.timer` (every minute),
`jinnpanel-mail-dns.timer` (daily), `jinnpanel-usage-boot` (at boot).
`docs/OPERATIONS.md` covers running them day to day.

---

## Re-running / upgrading

Re-running the installer **is** the upgrade:

```bash
cd /opt/jinnpanel && git pull
cd installer && JINNPANEL_HOSTNAME=server.example.com ./install.sh > /root/jinnpanel-install.log 2>&1
```

Pass the same variables you installed with. A re-run:

- re-deploys `app/` to `/var/www/hostpanel` and the worker to
  `/usr/local/bin` (worker changes only take effect this way),
- re-applies `schema.sql` (idempotent: `CREATE TABLE IF NOT EXISTS` and
  conditional DDL - never drops data),
- rebuilds the Tailwind CSS, re-renders the panel's config and units,
  re-syncs every account (users, pools, vhosts, static servers),
- **keeps** the secrets of stateful services (MariaDB root, Stalwart admin,
  SFTPGo admin, Valkey, `APP_KEY`) - read back from `/root/.jinnpanel/`,
- **rotates** the panel's own two MariaDB passwords (`hostpanel_app`,
  `hostpanel_prov`) and updates `Config.php` with them.

Sites keep serving during a re-run. If a service already had credentials
the installer doesn't know (set up by hand before), it stops and tells you
which file in `/root/.jinnpanel/` to put the right value in, instead of
deploying a broken config.

## Uninstalling

There's no uninstaller: this installs real services that hold customer
data. To tear it down by hand (removing the data folders at the end
**destroys every site, mailbox and database**):

```bash
systemctl disable --now hostpanel-worker.timer jinnpanel-cron.timer jinnpanel-mail-dns.timer \
  jinnpanel-webmail jinnpanel-phpmyadmin 'jinnpanel-php-fpm@*' 'jinnpanel-static@*' \
  frankenphp stalwart sftpgo knot valkey mariadb
rm -rf /var/www/hostpanel /opt/php-versions /usr/local/bin/hostpanel-worker.php \
  /usr/local/lib/jinnpanel /etc/jinnpanel /root/.jinnpanel
rm -f /etc/systemd/system/hostpanel-worker.* /etc/systemd/system/jinnpanel-*
systemctl daemon-reload
# accounts' Linux users: getent passwd | grep '^jp_' | cut -d: -f1 | xargs -r -n1 userdel
# data: /var/www/<domain>, /var/lib/mysql, /var/lib/stalwart, /var/lib/knot,
#       /var/lib/jinnpanel, /var/backups/jinnpanel
```
