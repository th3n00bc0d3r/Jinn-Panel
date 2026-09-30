# Features

## First run

`https://panel.<hostname>/setup` - shown automatically until an admin
account exists (checked on every request; nothing else in the app is
reachable until this is done). Shows live status of the five core services
and a form to create the administrator account. Once any admin account
exists, this page redirects to `/login` and never shows again.

## WHM (admin / reseller)

**Dashboard** - account/domain counts, disk & memory usage, live status of
all five services (checked by whether their port actually accepts a
connection, not `systemctl` - see `docs/TROUBLESHOOTING.md` for why), and
a **live logs** panel: pick any service (including every installed PHP
version) from a dropdown, see its last 200 log lines (refreshed every 5s by
the background worker), or hit "Pull latest 1000 lines" for a deeper
on-demand snapshot.

**Accounts** - create resellers (admin only) or hosting accounts, assign a
package, suspend/unsuspend, delete. Deleting an account cascades: every
domain's vhost, DNS zone, database, mailbox, and SFTP account it owns gets
cleaned up (best-effort per-resource, so one failure doesn't block the
rest).

**Packages** - disk/bandwidth/domain/database/email/FTP quotas. Admin-
created packages are global; a reseller can also define their own custom
packages, usable only by their own hosting accounts.

**cPanel Migration** (admin + reseller) - one-click move of accounts from a
cPanel & WHM server: a single cPanel account, every account of a WHM
reseller, or (admin only) any accounts on a whole server through WHM
root. Connect with an API token (or password), tick the accounts, press
Start. Each account's full cPanel backup is brought over and restored:
main/addon/subdomains with their site files, MySQL databases and users
(same names and, where MariaDB can use them, the same passwords), email
accounts with their existing passwords, and all stored mail with its
folders and read/flagged state. Hosting accounts keep their username and
cPanel password; with WHM root, cPanel resellers can be recreated as
JinnPanel resellers that own their customers. Progress is live, every
account gets a detailed report (anything skipped, generated passwords,
things to check), failed accounts are rolled back and can be retried.
Full guide: [`docs/MIGRATION.md`](MIGRATION.md).

**Server Config** (admin only):
- **Mail Settings** - browse and edit any of ~150 Stalwart settings objects,
  grouped (Server, Web & API, Mail protocols, Security & spam, Storage,
  Reporting). Saves apply immediately, no restart.
- **SFTP / PHP / Database Settings** - connection limits and timeouts;
  memory/execution/upload limits + OPcache/JIT; MariaDB buffer pool/
  connections/caching. Each "Save" writes the real config file and
  restarts the service automatically (within a few seconds, via the
  background worker).
- **PHP Versions** - install or remove additional PHP versions (8.2/8.3/
  8.4) as fully isolated instances. Shows install/active/failed/removing
  status live.
- **Server Tweaks** - Balanced / Performance / Extreme profile selector.
  Reads the box's actual CPU/RAM/disk, shows the exact values it would
  apply to MariaDB, PHP/OPcache, Stalwart, and SFTPGo before you commit,
  then applies all four at once with one button. "Extreme" is capped
  sensibly (not literally maximized) since this one box runs five services
  side by side.

## cPanel (end user)

**Dashboard** - package usage bars (domains/databases/email/FTP against
the account's quota) and quick links.

**Domains** - add a domain: creates a real FrankenPHP vhost (HTTP + HTTPS)
and a DNS zone pointed at this server. Per domain, pick:
  - **PHP version** - default, or any installed alt version (switches
    between direct serving and reverse-proxying to that version's isolated
    instance).
  - **SSL** - self-signed (instant) or AutoSSL/Let's Encrypt (real
    certificate, requires the domain to actually resolve here publicly).

**MySQL Databases** - creates a real database + a scoped MySQL user
(namespaced `<account>_<name>` to avoid collisions across tenants, matching
the cPanel convention).

**Email Accounts** - creates a real Stalwart mailbox on one of the
account's domains.

**FTP / SFTP** - creates a real SFTPGo virtual user (port 2022), scoped
either to the account root or a specific domain's docroot.

**DNS Zones** - view the zone file for each domain and re-provision it
(useful if the initial DNS provisioning failed - it's treated as
non-fatal, the domain still goes live even if DNS provisioning has an
issue).

**File Manager** - browse/upload/download/delete/create folders, scoped
strictly to the selected domain's docroot.

## What's genuinely real vs. simplified

**Real, not mocked:** every "creates a database/mailbox/SFTP account/DNS
zone/vhost" above does exactly that against the live service - verified
end-to-end during development (e.g. a domain switched to PHP 8.2 was
confirmed, over a real HTTPS request through the reverse proxy chain, to
actually be executing PHP 8.2.33 - then switched back and confirmed 8.5.9).

**Known simplifications:**
- Disk/bandwidth quotas are tracked as numbers, not enforced by real
  filesystem quotas.
- No "reseller's total resource pool" cap - a reseller can define custom
  packages with any limits for their own users.
- DNS is "one zone with sane defaults" (SOA/NS/A/MX), not a full
  per-record editor.
- No account impersonation ("login as user") from WHM yet.
- No password-reset/profile page yet for changing your own password after
  the initial setup wizard.
