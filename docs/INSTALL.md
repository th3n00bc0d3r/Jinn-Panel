# Install Guide

- [Quick setup](#quick-setup) - copy/paste, for people who just want it running
- [Detailed setup](#detailed-setup) - what each step actually does, options, and how to verify it as you go
- [After the installer finishes](#after-the-installer-finishes)
- [Re-running / upgrading](#re-running--upgrading)
- [Uninstalling](#uninstalling)

## Requirements

- A fresh **AlmaLinux 10, x86_64** server (a VM is fine - this was built and
  tested on one). Other RHEL-family distros (Rocky, RHEL itself, CentOS
  Stream) will very likely work but aren't tested.
- Root access.
- Outbound internet access (the installer pulls packages from AlmaLinux's
  own repos, EPEL, a Fedora COPR, and the upstream FrankenPHP/Stalwart/
  SFTPGo release repos).
- At least 2 vCPUs / 4GB RAM / 20GB disk recommended. It'll run on less (this
  was developed on a 2 vCPU / 3.6GB / 16GB VM), but "Extreme" performance
  tuning has less room to work with.

---

## Quick setup

```bash
scp -r JinnPanel root@your-server-ip:/root/
ssh root@your-server-ip
cd /root/JinnPanel/installer
chmod +x install.sh
./install.sh
```

Non-interactive, no prompts. When it finishes, it prints the panel's URL.
Point your browser's hosts file (or real DNS) at the server, visit
`https://panel.<hostname>/setup`, and create the administrator account.
That's it - you're live.

Override the auto-detected hostname if you want a specific one:

```bash
JINNPANEL_HOSTNAME=panel.example.com ./install.sh
```

---

## Detailed setup

### 1. Get the files onto the server

Any method works - `scp`, `git clone` (once you've pushed this to your own
remote), a tarball. The installer just needs to find `../app` relative to
itself, so keep the folder structure intact:

```
JinnPanel/
├── installer/install.sh
└── app/                    <- must be a sibling of installer/
```

### 2. Decide the hostname (optional)

By default, the installer uses whatever hostname is already set on the
box if it looks like a real FQDN (contains a dot), otherwise it generates a
random one like `jinnserver-a1b2c3.local`. To pin a specific one:

```bash
JINNPANEL_HOSTNAME=panel.example.com ./install.sh
```

The panel itself is always served at `panel.<that hostname>` - e.g. if the
hostname is `example.com`, the panel is at `panel.example.com`, leaving the
bare domain free for the server's own default site.

### 3. Run it

```bash
cd installer
chmod +x install.sh
./install.sh
```

It logs everything to `/var/log/jinnpanel-install.log` as it goes (in
addition to the terminal), in case you need to check what happened later.
It stops immediately on any real failure (`set -euo pipefail` plus a trap
that tells you which line) rather than limping on with something broken.

Roughly what happens, in order (see `docs/ARCHITECTURE.md` for the *why*
behind each of these):

1. **Identity** - sets the hostname, adds `/etc/hosts` entries, points DNS
   at the router + a public fallback (1.1.1.1) redundantly.
2. **MariaDB** - installs it, secures the root account (random password,
   removes anonymous users, drops the test database), creates the panel's
   own database plus two scoped MySQL accounts (one least-privilege for the
   app's own data, one narrower-than-superuser account used only to
   provision customer databases).
3. **FrankenPHP** - the official install script, plus the PDO/MySQL
   extensions it doesn't ship by default, plus the SELinux booleans/port
   registrations/group setup this whole stack needs (all documented in
   `docs/TROUBLESHOOTING.md` if you're curious exactly why each one is
   there).
4. **Stalwart Mail** - installs it, then completes its setup wizard for you
   via its JMAP API (no browser needed) using the detected hostname as the
   mail domain. Its HTTP/admin listeners (8080, 8443) are bound to
   127.0.0.1; only the mail ports are opened in the firewall.
5. **SFTPGo** - installs it, moves its admin UI/API off port 8080 (Stalwart
   already owns that) to 127.0.0.1:8090, creates the API admin account the panel uses to
   provision SFTP users.
6. **Knot DNS** - installs it (via EPEL + a Fedora COPR for a dependency),
   configures it to listen on all interfaces.
7. **JinnPanel itself** - copies the app to `/var/www/hostpanel`, generates
   `src/Config.php` from the template with freshly generated secrets,
   applies the database schema, builds the Tailwind CSS, sets up its own
   vhost.
8. **The background worker** - installs a systemd timer that runs every 5
   seconds to apply anything the panel queues (service restarts, DNS zone
   changes, new PHP version installs) - see `docs/ARCHITECTURE.md` for why
   this exists as a separate systemd-managed process instead of the web app
   doing it directly.

### 4. Watch for the summary

On success it prints something like:

```
  JinnPanel is deployed and every service is running.

  Next step (the only manual one):
    1. On the machine you'll browse from, map this server's IP to its
       hostname - e.g. on Windows add to C:\Windows\System32\drivers\etc\hosts:
           203.0.113.5  example.com  panel.example.com
    2. Visit:  https://panel.example.com/setup
       and create the real administrator account.
```

Internal service credentials (DB root, Stalwart admin API, SFTPGo admin
API) are saved to `/root/.jinnpanel/credentials.env`, root-only
(`chmod 600`). You won't need these day to day - they're for direct
troubleshooting against a service, not for logging into the panel.

---

## After the installer finishes

1. Point your browser at the panel: either add a real DNS record, or edit
   your local hosts file (`/etc/hosts` on Linux/Mac,
   `C:\Windows\System32\drivers\etc\hosts` on Windows) mapping the server's
   IP to `panel.<hostname>`.
2. Visit `https://panel.<hostname>/setup`. Your browser will warn about the
   self-signed certificate (Caddy's local dev CA) - that's expected until
   you either trust that CA locally or switch a domain to AutoSSL with a
   real public DNS record (see `docs/FEATURES.md`).
3. Create the administrator account. This is the only account this
   installer doesn't create for you on purpose - see `docs/ARCHITECTURE.md`
   for why.
4. Log in and you're in WHM. `docs/FEATURES.md` is the full tour from here.

---

## Re-running / upgrading

Safe to run again. Every package install is idempotent (`dnf` just says
"already installed"), config file writes overwrite cleanly, and database
schema changes use `CREATE TABLE IF NOT EXISTS` / conditional DDL so they
don't fail against an existing database.

What re-running actually does:
- Re-deploys the app source (picks up any code changes in `app/`).
- Re-applies the database schema (safe - no destructive statements).
- Rebuilds the Tailwind CSS.
- Reloads FrankenPHP.
- Leaves already-generated secrets alone for **stateful** services
  (MariaDB root, Stalwart admin, SFTPGo admin) - it reads them back from
  `/root/.jinnpanel/` rather than regenerating, since those services would
  need to be re-bootstrapped to accept a new one anyway.
- **Does** rotate the panel's own two database passwords
  (`hostpanel_app`/`hostpanel_prov`) every run, since those are entirely
  under the installer's control and it updates both the database and
  `Config.php` together.

If MariaDB/Stalwart/SFTPGo already had credentials this installer doesn't
know about (e.g. you set them up some other way before running this), it
will tell you exactly where to put the correct values
(`/root/.jinnpanel/*_pass`) rather than silently deploying a broken config.

## Uninstalling

There's no uninstaller - this installs real system services meant to keep
running. To tear it down by hand:

```bash
systemctl disable --now hostpanel-worker.timer frankenphp stalwart sftpgo knot mariadb
rm -rf /var/www/hostpanel /opt/php-versions /usr/local/bin/hostpanel-worker.php
rm -f /etc/systemd/system/hostpanel-worker.* /etc/frankenphp/Caddyfile.d/panel.caddyfile
dnf -y remove frankenphp php-zts-cli php-zts-embed stalwart sftpgo knot mariadb-server
rm -rf /root/.jinnpanel
```

(Leaves MariaDB's data directory and any customer databases/mail/files in
place - remove `/var/lib/mysql`, `/var/www/<domain>` per-domain, `/var/lib/
stalwart`, etc. yourself if you actually want everything gone.)
