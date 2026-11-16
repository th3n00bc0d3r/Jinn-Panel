# Features

A screen-by-screen tour of JinnPanel as it is today, for operators deciding
whether it fits. Everything here is taken from the code; where something is
missing it says so (see [Current limitations](#current-limitations)).

## Contents

- [Roles](#roles)
- [Reaching the panel and signing in](#reaching-the-panel-and-signing-in)
- [WHM (admin and reseller)](#whm-admin-and-reseller)
- [cPanel (hosting accounts)](#cpanel-hosting-accounts)
- [Quotas and limits](#quotas-and-limits)
- [Current limitations](#current-limitations)

## Roles

There are three roles. The role is read from the database on every request,
so a change (or a suspension) takes effect immediately.

- **admin** - the whole server. Sees every account, every package, the
  server settings, DNS, backups and service logs. The first admin is created by `/setup`; admins can create more in WHM >
  Accounts, and the last active admin can't be suspended or deleted.
- **reseller** - a WHM login without hosting of its own. Sees only the
  hosting accounts it created, the global packages plus its own, its own
  migrations and the activity of itself and its accounts. It can create as
  many accounts as its own package's "Max accounts" allows.
- **user** - a hosting account. Sees only its own domains, databases,
  mailboxes, SFTP logins, DNS zones, files, cron jobs, cache and backups.

Each hosting account is also a Linux user, `jp_<username>`. Its sites' PHP
runs in its own PHP-FPM pool as that user (one pool per PHP version it
uses), limited by `open_basedir` to its own folders, with `exec()` and
friends disabled unless the admin allows them. Its static files are served
by its own nginx instance running as the account. The panel itself can only
read customer files; file work goes through the account's pool.

## Reaching the panel and signing in

- **`https://panel.<hostname>`** - the panel's own host. WHM and `/setup`
  only run here (also on the server's IP). Plain `http://` redirects to
  HTTPS.
- **`<domain>/jpanel`** - every hosted domain (and its `www.`) redirects
  `/jpanel` to **`https://<domain>:2083`**, where the same panel is served
  with that domain's certificate. This is the address to give customers.
  It is a separate origin from the site, and site blocks strip the panel's
  session cookie before the site's PHP sees the request. An admin or
  reseller who signs in on a customer domain is handed over to
  `panel.<hostname>` with a single-use token (60 seconds).
- `/` sends a signed-in user to `/cpanel`, admins and resellers to `/whm`,
  everyone else to `/login`.

**Forgot password.** The sign-in page links to `/login/forgot`: a
username or email gets a single-use link (valid one hour, always on the
panel hostname) at the account's email address. The answer doesn't say
whether the account exists, and requests are throttled like failed
sign-ins. Choosing a new password signs out every other session.

**Two-factor recovery codes.** Turning two-factor on shows ten one-time
recovery codes once; at the second sign-in step "Use a recovery code"
signs in with one instead of the authenticator code. Login security shows
how many are left and makes new ones (with the password).

**First run - `/setup`.** Until an admin exists, every page redirects to
`/setup`. It shows whether MariaDB, FrankenPHP, Stalwart, SFTPGo and Knot
accept connections, and a form for the admin account: the **setup token**
(printed at the end of `install.sh`, also in `/root/.jinnpanel/setup_token`),
username (3-32 characters, lowercase letters, digits, `_`, starting with a
letter), email, optional full name and password. Wrong tokens are throttled
like failed sign-ins. Once the admin exists the token is deleted and
`/setup` redirects to `/login` for good.

**Sign-in** (`/login`) takes a username or email and password.

- **Throttling** - within 15 minutes: 5 failures for one username from one
  address, or 20 from one address for any usernames, block that address; 30
  failures for one username from anywhere block that username. The wait is
  shown in minutes. A successful sign-in clears that username's record.
- **Two-factor** - TOTP (any authenticator app) for every role. When it's
  on, a 6-digit code is asked for after the password (within 5 minutes).
  Each code works once. There are no recovery codes: someone who loses
  their phone needs the admin to reset it (WHM > **Accounts**).
- **Passwords** - at least 10 characters, at most 1024, not one of a list
  of common passwords, at least 4 different characters, and not containing
  the username. Accounts migrated from cPanel keep their old hash and are
  upgraded to bcrypt on first sign-in.
- **Sessions** end after 2 hours idle or 12 hours in total. Changing your
  password or turning two-factor on signs out your other sessions; a
  suspension or an admin's 2FA reset signs out all of them. Sign-out is a
  POST.

**Login security** (shield icon at the bottom of the sidebar, every role:
`/whm/security`, `/cpanel/security`) - change your password (current
password required, other sessions signed out) and turn two-factor on
(scan a QR code or type the key, confirm with a code) or off (password
required).

Every sign-in, failed sign-in, block and panel action is written to the
activity log.

## WHM (admin and reseller)

On every page, the top bar has **search** (Ctrl-K, or `/`): pages and
actions, and accounts and domains by name as you type (a reseller finds
only its own accounts; a hosting account only its own domains); for the
admin, an **alerts bell** with the number of alerts (counted every minute
by the worker); the theme; and the account menu. Dashboards refresh their
numbers every 30 seconds while the tab is open; that doesn't stop an idle
session from signing out.

The sidebar, in order: **Dashboard**, **Analytics**, **Accounts**, **Packages**,
**cPanel Migration**, **Activity Log**; then, for the admin only, a
**Server Config** section: **Backups**, **DNS Zones**, **Mail Settings**,
**SFTP Settings**, **PHP Settings**, **PHP Extensions**, **PHP Versions**,
**Database Settings**, **Server Tweaks**. Resellers get a 403 on every
admin-only page.

### Dashboard

- The page's description says whether every service answers and counts
  hosting accounts, domains, suspended accounts and resellers (a
  reseller's counts cover only its own accounts).
- **Traffic**: requests to every site (a reseller's: its accounts' sites)
  over the last 24 hours, 7 days or 30 days, stacked for the four busiest
  sites of the last 30 days plus "Other sites", the total and its change on
  the previous period, and a note on an unusual spike. The range links
  reload only this chart. Every chart has a table view.
- Admin only, under the chart: **CPU load**, **Memory**, **Disk** and
  **Sent this month**, each with its trend.
- **Busiest sites** of the range; admin only: **Services** - each service's
  last 24 hours in 30-minute blocks from the minute-by-minute checks, its
  uptime and when it was down; **Nearing their limits** - accounts over or
  close to their disk or bandwidth, with the day they reach it at the
  current rate.
- Admin only: **Needs attention** - the top five alerts (see
  [Alerts](#alerts-admin)), or "All clear".
- **Recent activity** - the last eight entries of the activity log (a
  reseller sees its own and its accounts').
- Admin only: **Live logs** - pick MariaDB, FrankenPHP, Stalwart, SFTPGo,
  Knot or any `jinnpanel-php-fpm@<version>` instance and see its last 200
  journal lines (the root worker refreshes them every 5 seconds; reload the
  page to see the latest). **Pull latest 1000 lines** fetches a deeper
  snapshot.
- **Create account** in the page header.

### Analytics

Requests, visitors or data sent (pick one) for every site - a reseller's
accounts' sites - over 24 hours, 7 days or 30 days; for the admin, the
server's CPU and memory and all the data it sent; and a table of every
site with requests, visitors, data sent, server errors (5xx) and average
page time, sortable by each. Numbers come from the web server's access
log, read every 5 minutes (see [ARCHITECTURE.md](ARCHITECTURE.md#statistics));
no IP address is stored.

### Accounts

Lists hosting accounts (and, for the admin, resellers and administrators)
25 to a page, with package, owning reseller (admin view), disk use against
the package, requests over the last 14 days with a trend line, domain count,
quota warnings and status, **Log in as** for active hosting accounts and
**Manage**. Search by name, email or domain; filter by status (with
counts), type, package and reseller; sort by name, disk, requests, domains
or age. All of it is in the address, so a filtered list can be bookmarked.

**Several at once**: tick accounts (or all that match the filter), then
**Change package**, **Suspend**, **Unsuspend** or **Switch a feature**
(on, off, or back to the package's setting). Each account is checked as if
done alone (whose it is, the last admin, reseller limits); the result says
which ones weren't changed and why.

**Moving to a smaller package** - one account, several, or editing a
package down - first shows each account that already has more than the new
limits allow: disk, this month's bandwidth, domains, databases, mailboxes,
SFTP logins and features it would lose, with what happens (nothing is
deleted). **Move anyway** / **Save anyway** goes ahead. Either way, new
limits apply at once: the worker measures the affected accounts within a
minute (hard disk quota, bandwidth pause) instead of at the next hour.

**Create account** - account type (admin only: hosting account, reseller
or administrator), username, full name, email, password (with a
generator) and hosting package (administrators have none), and **Send a
welcome email** (when welcome emails are on in [Notifications](#notifications-admin)):
where to sign in, the username and a link to choose their own password,
valid 3 days - the password itself is never emailed.
Hosting-account usernames are 3-16 lowercase letters or digits starting
with a letter; reseller logins may be 3-32 characters with `_`. Reserved
names (`root`, `admin`, `mail`, `www`, service names, anything starting with
`hostpanel`, `jinnpanel`, `mysql` or `pma`, ...) and existing Linux users
are refused. Username and email must be unique; a hosting account needs a package. A reseller can only pick
global packages or its own, and is stopped at its package's account limit.
Creating a hosting account creates its Linux user and PHP home.

**The account's page** (click its name) shows its usage against the
package (disk, bandwidth, domains, databases, mailboxes, SFTP logins), its
domains, details (type, email, reseller, two-factor, Linux user, PHP exec)
and recent activity, with:

- **Package and features** - switch the account to another package, and
  turn each feature on or off for this account only, whatever its package
  says ("Package (on)" follows the package).
- **Edit** - email, full name, package and an optional new password (which
  signs the account out everywhere). The username and type can't change.
  A new package's disk quota goes to its SFTP logins at once; hard disk
  quotas and bandwidth follow at the next hourly usage run.
- **Log in as** (hosting accounts) - opens the account's own panel as it
  sees it, with a banner and a button back to WHM. Recorded in the activity
  log. A reseller can do this for its own accounts.
- **exec on/off** (admin, hosting accounts) - whether the account's PHP
  may run programs (`exec`, `passthru`, `shell_exec`, `system`,
  `proc_open`, `popen`, `pcntl_exec`, `dl`). Off by default; applies to its
  PHP-FPM pools and its cron jobs.
- **Reset two-factor** (admin, shown only when two-factor is on) - turns it
  off for that user, removes their recovery codes and ends their sessions.
- **Suspend / Unsuspend** - with an optional reason that goes into the
  customer's email (when suspension emails are on). For a hosting account,
  suspension:
  - signs it out everywhere and blocks its panel login;
  - makes every site answer `503` "This website is temporarily
    unavailable." (`Retry-After: 3600`) and stops its PHP-FPM pools;
  - turns off mailbox logins (IMAP/POP3/SMTP auth); incoming mail is still
    accepted and kept;
  - disables its SFTP logins;
  - locks every MySQL login of its users (`ACCOUNT LOCK`, all hosts) and
    kills their open connections;
  - disables its object cache (Valkey) login;
  - skips its cron jobs.

  Unsuspending turns each part back on. Each part is done separately; any
  that failed are listed. Suspending a reseller only blocks the reseller's
  own login - its customers stay up. The last active administrator can't
  be suspended or deleted; nobody can act on their own account here.
- **Delete** - for a hosting account, best-effort per resource: every
  domain's vhost, DNS zone (or its records in a parent zone), aliases, PHP
  settings and page cache; its object cache login; its databases and every
  MySQL user it owns (including Remote MySQL and phpMyAdmin logins); its
  mailboxes, forwarders and mail domains (with DKIM keys); its SFTP logins;
  then the panel records. The root worker then removes its PHP pools and
  Linux user and moves its site folders to
  `/var/lib/jinnpanel/removed/<username>-<timestamp>/` (root only), so a
  later account never inherits them. Backups already taken are kept.

### Packages

Package cards show every limit. Admin packages are **Global package**;
reseller packages are **Custom · <reseller>** and only usable by that
reseller. Fresh installs seed Starter (1 GB, 10 GB/mo, 1 domain, 1 DB, 5
mail, 1 FTP), Business (5 GB, 50 GB/mo, 5, 5, 25, 3) and Reseller (20 GB,
200 GB/mo, 50, 50, 250, 10, 50 accounts).

| Field | Enforced how |
|---|---|
| Disk quota (MB) | Measured hourly (site files + home folder + databases + mail). Over it, the panel refuses new domains, databases, mailboxes and File Manager writes. With XFS quotas also a hard limit on the account's files - see [Quotas and limits](#quotas-and-limits). Also each SFTP login's own quota in SFTPGo. |
| Bandwidth (MB/mo) | Bytes served by the account's sites this calendar month, from the web server's access log, hourly. Over it, its sites answer `509`. |
| Max domains | Checked when adding a domain. Aliases don't count. |
| Max databases | Checked when creating a database. MySQL users aren't limited. |
| Max email accounts | Checked when creating a mailbox. Forwarders don't count. |
| Max FTP accounts | Checked when creating an SFTP login. |
| Max accounts (for resellers) | Admin packages only: how many hosting accounts a reseller on this package may create (0 = none). |

**Features** - switched on or off per package (all on for packages made
before features existed), and per account on top of that:

| Feature | Off means |
|---|---|
| File Manager | No File Manager (or its editor); SFTP still works. |
| Cron jobs | No Cron Jobs page, and existing jobs stop running. |
| Self-service backups | No Backups page (run, download, restore). The server's daily backup still includes the account. |
| DNS editor | DNS records are read-only. |
| Other PHP versions | New domains run on the default PHP; a domain already on another version keeps it but can't switch to a third. |
| Domain aliases | No new aliases (existing ones stay and can be removed). |
| Routing rules | Hand-edited rules are refused; following `.htaccess` and resetting still work. |
| Object cache | It can't be turned on (one already on stays). |
| phpMyAdmin and remote MySQL | No phpMyAdmin; no new remote hosts (existing ones can be removed). |
| SSH keys for SFTP | No new keys (existing ones can be removed). |

Pages for tools that are off disappear from the account's menu and
dashboard; opening one says it isn't in the package.

Disk, bandwidth, domains and databases must be at least 1; email and FTP
may be 0 (none). Any limit can be **Unlimited** (a box under each field):
nothing is counted against it, and an unlimited disk sets no hard quota.
**Edit** changes a package's name, limits and features for every account on
it (lowering a limit deletes nothing; accounts over it can't add more); a
reseller can edit only its own packages. A package in use can't be deleted
(the list shows how many accounts use each). Every hosting account gets a
package when it's created or migrated.

**A package's page** (click its name) lists the accounts on it with their
disk and bandwidth use, the totals, its limits and features, and:

- **Duplicate** - a copy to start a new package from (a reseller's copy of
  a global package becomes its own).
- **Move these accounts** - all of them to another package at once, e.g.
  before deleting this one. A reseller moves only its own accounts.

### Resellers

There is no separate Resellers screen. The admin creates a reseller in
**Accounts** (account type "Reseller") and gives it a package whose **Max
accounts** is above 0 - a reseller without a package can't create
accounts. A reseller's WHM has Dashboard, Accounts, Packages (create its
own, delete its own), cPanel Migration (single account or WHM reseller
sources, public addresses only) and Activity Log. Resellers have no
hosting of their own, no cPanel side, and no access to Server Config.

**Reseller limits.** A reseller's own package is also its allocation: the
packages of its hosting accounts may add up to at most its disk,
bandwidth and domain count, and it may have at most "Max accounts"
accounts. Creating an account, moving one to a bigger package or raising
one of its own packages is refused when it wouldn't fit (an unlimited
package never fits a limited reseller). Its dashboard and its page in
WHM show what's handed out against what's allowed. An administrator isn't
held to it; a reseller pushed over is shown as over, and can still move
accounts to smaller packages.

### cPanel Migration

Moves accounts from a cPanel & WHM server, admin and reseller: connect to a
single cPanel account, a WHM reseller, or (admin only) a whole server as
WHM root, with an API token or password; tick accounts and options; start.
Backups are pulled from or pushed by the source, extracted and restored:
domains (parked domains become aliases), site files, databases and MySQL
users with their passwords, mailboxes with their passwords and all stored
mail, forwarders, autoresponders, optionally the catch-all, cron jobs, FTP
accounts (as SFTP), DNS records, `.htaccess` rules (translated into Routes,
flagged for review when something was skipped) and MultiPHP INI settings.
Progress is live; each account gets a report; failed accounts are rolled
back and can be retried; **Restore mail** / **Restore missing mailboxes**
re-imports mail without touching anything else; **Forget credentials**
deletes the stored source secret.

Admin only: **From backup files** restores cPanel full backups already in
the import folder, and **Fetch backups from S3** downloads them there from
any S3-compatible bucket. Full guide: [`MIGRATION.md`](MIGRATION.md).

### Activity Log

Sign-ins and every change made through the panel: when, who, action,
detail, account affected and source IP. Searchable by action, user, detail
or exact IP, 100 per page. The admin sees everything; a reseller sees
entries by or about itself and its accounts.

### Alerts (admin)

Everything that needs attention right now, most serious first: problems
found by the hourly server health check, services that don't answer, the
root disk over 90%, accounts over their disk quota or out of bandwidth,
failed backups (last 2 days) and migrations (last 7 days), Let's Encrypt
certificates expiring within 14 days, and domains whose routing rules need
a review. Alerts clear by themselves once fixed. New problems are emailed
hourly (once each) when alert emails are on.

### Notifications (admin)

The mail the panel sends itself, through the local mail server. Customer
emails: **quota warnings** (at 90% of disk or monthly bandwidth, and when
it runs out - once per level, bandwidth again each month) and
**suspension notices** (suspended with the reason given, and active
again). Then the **From
address** (it must be on a mail domain hosted on this server, or Stalwart
won't relay it) and name, whether new accounts may get a **welcome email**,
whether **new alerts are emailed**, and where alerts go (default: every
active administrator's email). **Send test email** checks delivery.
Password reset emails are always on.

### Backups (admin)

- **Schedule** - daily backups on/off, the hour (server time), and how many
  to keep (1-30, default 2). Every hosting account, then the server, is
  backed up to `/var/backups/jinnpanel` (free space is shown).
- **Off-site copy** - optional S3-compatible upload (endpoint, region,
  bucket, folder, access key, secret key stored encrypted); empty bucket =
  off.
- **Back up now** - every account then the server, the server only, or one
  account.
- **Backups** list with status, size, off-site status and log. An account
  backup holds `files.tar.gz` (its site folders, owners kept), one
  `db-<name>.sql.gz` per database and `mail.tar.gz` (every mailbox folder as
  mbox, read over IMAP). Each part can be downloaded. The server backup holds
  the panel database and every service's configuration; it isn't
  downloadable or restorable from the panel.
- **Restore** any of files / databases / mail into the account as it is
  now: files are written over (nothing deleted), databases replaced, mail
  already present skipped. A backup of a deleted account can't be restored.

Backups and restores run as root in their own systemd units.

### DNS Zones (admin)

Knot serves every zone; the panel database is the source of truth and each
change is published by the root worker (`knotc` reload, previous file kept
if Knot rejects the new one).

- **Nameservers** - ns1/ns2 hostname and IPv4. They generate the SOA, apex
  NS and glue of every zone; saving re-publishes all zones.
- **Create server zone** - shown when the hostname's parent zone
  (`server.example.com` -> `example.com`) is missing; without it the panel
  and nameservers don't resolve. The server zone can't be deleted.
- **Zones** - every zone with owner (Server, Account: <user>, or
  Standalone), record count and serial. **Add zone** creates a standalone
  zone, optionally with `@` and `www` pointing here.
- **Zone page** - add records (A, AAAA, CNAME, MX, TXT, NS, SRV, CAA; TTL
  60-604800, default 3600), delete records, **Re-publish**, **Delete zone**
  (standalone zones only - a hosted domain's zone goes with the domain), the
  last publish result and a zone file preview. Records the panel keeps in
  sync are labelled "Automatic (mail / IPv6 / subdomain site)"; adding your
  own record with the same name replaces them. Validation: a CNAME must be
  alone and not at `@`, `@` NS records come only from the nameserver
  settings, no exact duplicates.

### Mail Settings (admin)

27 Stalwart settings objects in groups: Server, Web & API, Mail protocols,
Security & spam, Storage, Reporting. Each form is built from Stalwart's own
schema (booleans, enums, numbers, text, JSON for complex fields). Saves go
through Stalwart's API and apply immediately, no restart.

### SFTP Settings (admin)

SFTPGo max total connections (0 = unlimited), max connections per host,
idle timeout (minutes). **Save & restart SFTPGo** writes `sftpgo.json`
through the worker.

### PHP Settings (admin)

Server-wide `memory_limit`, `max_execution_time`, `upload_max_filesize`,
`post_max_size`; OPcache memory, max accelerated files, how often changed
files are checked (every request, 2 s, 1 min, 5 min, or never - only after
Clear cache or a restart) and JIT on/off. **Save & restart PHP** restarts
FrankenPHP and every PHP-FPM instance.

### PHP Extensions (admin)

Installed and available `php-zts-*` packages for the default PHP, with
install/remove buttons. Extensions load for every site on the default
version (no per-site switch). Core ones the panel needs can't be removed.
An extension that doesn't load is removed again. The web server, webmail
and the default PHP-FPM restart after each change.

### PHP Versions (admin)

PHP 8.5 is the default and can't be removed. PHP 8.2, 8.3 and 8.4 can be
installed (static-php builds, each its own `jinnpanel-php-fpm@<version>`
service) with live status (installing, active, failed, removing). A version
can only be removed when no domain uses it.

### Database Settings (admin)

MariaDB `innodb_buffer_pool_size`, `max_connections`, `thread_cache_size`,
`table_open_cache`. **Save & restart MariaDB** (a brief interruption for
every site).

### Server Tweaks (admin)

Shows the detected CPU cores, memory and disk, previews the Balanced,
Performance or Extreme profile (exact values for MariaDB, PHP & OPcache,
Stalwart and SFTPGo) and applies one with a button. Extreme turns off
OPcache file checks (changed PHP files need Clear cache) and sets
`innodb_flush_log_at_trx_commit = 2`.

## cPanel (hosting accounts)

The sidebar, in order: **Dashboard**, **Domains**, **MySQL Databases**,
**Email Accounts**, **FTP / SFTP**, **DNS Zones**, **File Manager**, **Cron
Jobs**, **Cache**, **Backups**. Login security is the shield icon.

### Dashboard

- **Visitors to your sites** over 24 hours, 7 days or 30 days, with
  requests, data sent and the share of server errors, and the change on the
  previous period. The range links reload only this chart.
- **Your plan**: disk used (files / databases / mail) and this month's
  bandwidth against the package, each with the day it runs out at the
  current rate, and domains, databases, mailboxes and SFTP logins against
  their limits. The same usage box sits on top of Domains, Databases,
  Email and FTP.
- **Your sites**, busiest first: visitors per day over 14 days, visitors and
  requests, the certificate, and **Stats**.
- **Connection details**: server, IP address, SFTP host and port, username.

### Stats (each domain)

A tab on every domain, next to Overview: visitors, requests, data sent
and average page time over 24 hours, 7 days or 30 days, each with the change
on the previous period; visitors over time; answers by status (2xx, 3xx,
4xx, 5xx); page time and how many pages took over a second; top pages and
referring sites; data sent. From the web server's log every 5 minutes. A
visitor is one browser counted once a day; robots are left out and no IP
address is stored.

### Domains

**Add a domain** - name, PHP version (Default 8.5 or any installed
version) and SSL: *Automatic* (Let's Encrypt once DNS points here,
self-signed until then), *Let's Encrypt now*, or *Self-signed only*. It
creates the vhost (domain and `www.`), the site folder
`/var/www/<domain>/public` owned by the account with a placeholder page,
the PHP-FPM pool and a DNS zone (`@` and `www` A records here, MX to the
domain, AAAA when the server has IPv6). A subdomain of a zone already on
the server becomes records in that zone. Refused: the server's own names,
names under or above another account's domain, public suffixes
(`co.uk`, `github.io`, ...) and a list of much-impersonated domains.
Ownership isn't checked - Let's Encrypt only issues once DNS points here.

The list shows each domain with its docroot, `<domain>/jpanel`, DNS
status, PHP version and SSL dropdowns (Automatic / AutoSSL / Self-signed
only; changes apply on select), SSL state
(Let's Encrypt with expiry, issuing, can't issue with where DNS points, or
self-signed) and delete. **Removing a domain** removes its vhost, DNS zone,
mail domain (mailboxes and forwarders), aliases, PHP settings, page cache
and cron jobs; files stay on disk. Self-signed sites also answer on plain
`http://`; Let's Encrypt sites redirect it. A daily job switches every
self-signed domain that now resolves here to Let's Encrypt (except those
set to *Self-signed only*), and a Let's
Encrypt domain with no valid certificate that doesn't point here back to
self-signed (so Caddy stops retrying).

Dotfiles (`.env`, `.git`, `.htaccess`, ...) and `php.ini` are never served
(404), except `/.well-known/`.

**Domain page** (click a domain):

- **Overview** - site URLs, the `<domain>/jpanel` link, PHP version, where
  DNS points, routes status, link to the exposed-files check.
- **SSL certificate** - state, issuer, expiry (renewed automatically about
  30 days before). **Run AutoSSL** checks that the domain resolves here and
  asks Caddy to issue or renew now - after fixing DNS, or for a certificate
  that is expired or stuck.
- **Document root** - any existing folder inside `/var/www/<domain>/`
  (e.g. `public/app/public` for Laravel).
- **Aliases** - other names serving the same site (cPanel's parked
  domains), each with `www.`, each getting its own DNS zone. Same name
  rules as domains.
- **PHP settings** - per-site `memory_limit`, `max_execution_time`,
  `max_input_time`, `max_input_vars`, `display_errors`, `log_errors`,
  `error_reporting`, `date.timezone`, `session.gc_maxlifetime`,
  `zlib.output_compression`; empty = server default. Errors go to a
  per-site log file shown on the page. Upload/post sizes are server-wide.
  These apply only on the default PHP version.
- **Cache** - **Clear cache** empties the site's OPcache, static file
  cache, page cache and object cache keys (the last two when on).

**Routes** (from the domain page) - the web server doesn't read
`.htaccess`. The page shows every `.htaccess` under the docroot, the rules
translated from them (rewrites, redirects, access rules, `ErrorDocument`,
`Header`) and a list of anything that wasn't translated. **Use these
rules** applies the translation; the two text boxes (routing rules, site
rules for headers and error pages) can be edited and saved; **Reset to
default** goes back to "existing files, then `index.php`". The root worker
validates every save with Caddy and keeps the previous rules if it fails.
Only routing directives are allowed - no paths outside the site, no
proxying, no imports.

**Follow .htaccess** (on the Routes page) keeps the rules in step with the
site's `.htaccess` files: the worker checks them every minute and, when they
changed (File Manager, SFTP, WordPress rewriting its permalinks), applies
the new translation the same validated way. A change that can't be
translated exactly is never applied automatically: the rules in use stay
and the domain is marked "needs review". Editing the rules by hand or
resetting them turns it off. On domains that existed before this, it starts
on only where the rules in use already are the translation of `.htaccess`,
so no running site changes behaviour.

**Exposed files** (from the domain page) - scans the docroot for things
anyone could download: archives, database dumps, backup copies, logs, data
exports (`orders*.json`, `customers*.csv`, ...) and backup folders.
**Move selected to private** moves them to `/var/www/<domain>/private/`,
outside the web folder. Very large sites are scanned partially (says so).

### MySQL Databases

cPanel-style: databases and users are separate. Names are
`<username>_<name>` (name: letters, digits, `_`, starting with a letter).

- **Create a database**, optionally with a same-named user with all
  privileges. **Delete** drops it and every grant on it.
- **Create a MySQL user** (password 10+ characters with letters and
  digits), change its password, delete it.
- **Add a user to a database** with all privileges or a chosen subset of
  SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES,
  LOCK TABLES, CREATE TEMPORARY TABLES, CREATE VIEW, SHOW VIEW, CREATE
  ROUTINE, ALTER ROUTINE, EXECUTE, EVENT, TRIGGER. Saving replaces the
  user's privileges there; nothing ticked removes the user. Grants are read
  from MariaDB itself.
- **Remote MySQL** - an IPv4/IPv6 address, an IPv4 range or `%`. Every
  user of the account can then log in from there with its usual password,
  and the firewall opens 3306 to those sources only.
- **phpMyAdmin** - opens `/phpmyadmin/` signed in with a temporary MySQL
  login (2 hours, all the account's databases) through a single-use token.
- **Connection details** - `localhost:3306` from the sites,
  `<hostname>:3306` from allowed remote hosts.

### Email Accounts

Mail is Stalwart. Every hosted domain is a mail domain (so its sites can
send DKIM-signed mail).

- **Mailboxes** - create (`<name>@<domain>`, password 10+ characters, not a common one),
  change password, delete (with all its mail), **Webmail** link when
  `mail.<domain>` resolves here and isn't a site of its own.
- **Autoresponder** per mailbox - subject, message, optional From and Until
  dates, reply to the same sender every 1, 3, 7, 14 or 30 days; turn off.
- **Forwarders** - an address to up to 10 destinations. For an address
  without a mailbox, mail is only passed on; a mailbox that forwards keeps
  its own copy. Destinations can be removed one at a time.
- **Default address** per domain - what happens to mail for addresses that
  don't exist: reject (default), or deliver to one of the domain's
  mailboxes or forwarders.
- **Mail client settings** - IMAP `<hostname>:993` SSL/TLS, POP3 `:995`,
  SMTP `:465` SSL/TLS or `:587` STARTTLS, username = full address, webmail
  at `https://mail.<domain>/`.

Automatic, when the domain's MX points here: DKIM (Stalwart's keys, RSA
and Ed25519, picked up again after rotation), SPF `v=spf1 mx a ~all`,
DMARC `p=quarantine` with reports to `postmaster@<domain>`, `mail.<domain>`
A record, MTA-STS, TLS reporting, SRV records and autoconfig/autodiscover
so mail apps set themselves up. Your own SPF/DMARC records always win.
Webmail is Cypht, served at `mail.<domain>` for domains with mailboxes
when that name resolves here.
Stalwart uses the server hostname's Let's Encrypt certificate.

### FTP / SFTP

SFTP only (port 2022, SCP works too); there is no plain FTP. Create a login
`<username>_<name>` (password 10+ characters) either for **All my sites**
(each site appears as `/<domain>`) or restricted to one domain's folder.
Each login has the package's disk quota in SFTPGo. **SSH keys**: paste
OpenSSH public keys (one per line, up to 10) to sign in with a key as well
as the password. Under the box, the two commands to copy: `ssh-keygen` to
make a key pair (named for the server and login), and `sftp -i ... -P
<port>` to connect with it. Delete with the trash icon.

### DNS Zones

A zone editor for the account's own domains: pick a domain, add, edit
(inline) and delete records - the same types and validation as WHM, plus NS
only to delegate a subdomain. Shown read-only: the zone's NS records, the
panel-managed records (mail, IPv6, subdomain sites), and in the server zone
the server's own names (hostname, panel, nameservers). **Re-publish** /
**Provision now** rebuilds the zone if it's missing, keeping existing
records. A zone file preview is at the bottom.

### File Manager

Works inside one domain's site folder (`/var/www/<domain>/`), opening in
its document root; runs as the account, never follows symlinks out.

- Browse folders, see size, age and permissions (octal and `rwx`).
- **Upload** (several files, by the button or by dropping them on the
  list), **New folder**, **New file**.
- **Edit** text files (up to 1 MB, UTF-8) in the browser: line numbers,
  Tab indents, Ctrl/Cmd+S saves. The file keeps its line endings and
  permissions, is replaced atomically, and isn't overwritten if something
  else changed it after it was opened (the typed text is kept).
- Select several items, then **Download** (one file as-is, otherwise a
  `.zip`), **Compress to .zip** (up to 4 GB), **Move**, **Copy**,
  **Permissions** (file and folder modes, optionally recursive),
  **Delete**.
- Per row: **Extract** (`.zip`, `.tar.gz`, `.tgz`, `.tar`; up to 8 GB
  unpacked; refuses links, `..` and absolute paths; overwrites existing
  files), **Rename**, **Download**.
- Changes clear the domain's static file cache right away.

### Cron Jobs

Up to 25 jobs. Schedule as 5 cron fields or `@hourly`, `@daily`,
`@weekly`, `@monthly`, `@yearly` (server time). A job runs either **a PHP
script on one of my sites** (a `.php` file inside `/var/www/<domain>/`,
optional arguments without shell characters; run as the account with its
pool's limits and the domain's PHP version) or **a URL** fetched like a
visitor. Shell commands aren't available. Each run may take 15 minutes; a
job isn't started again while it's still running. Per job: **Run now**,
**Pause / Resume**, delete, last run time, exit status and output.

### Cache

- **Object cache** (Valkey, Redis-compatible) - **Turn on** creates a
  login limited to the account's key prefix (no `FLUSHALL`, `KEYS`,
  `CONFIG` or admin commands). Shows host, port, username, password, key
  prefix and key count, with WordPress ("Redis Object Cache" plugin) and
  Laravel snippets. **Flush**, **New password**, **Turn off**. Valkey
  drops least recently used keys when it is full.
- **Static file cache** per domain - compressed copies of HTML, CSS, JS,
  SVG, fonts and the like kept by the account's static server, lifetime 1
  minute to 1 week, **Clear**. **Browser caching**: media 30 days, CSS/JS 1
  day (or `no-cache` when off), unless the site sets its own headers.
- **Page cache** per domain - whole pages for anonymous visitors, lifetime
  1 minute to 1 day, **Clear cache**. Only GET/HEAD, 200 HTML responses
  without cookies or private/no-store; requests with any cookie other than
  common analytics ones bypass it. Responses carry `X-JinnPanel-Cache`.
  Default PHP version only.

### Backups

The account's backups (daily when the admin has them on, plus manual ones)
with download links for site files, each database and mail. **Back up
now** (at most once per 6 hours). **Restore** chosen parts (site files,
databases, mail) with the same rules as WHM. The page shows the schedule
the admin set.

## Quotas and limits

**Counts** (domains, databases, mailboxes, SFTP logins) are hard limits:
the panel refuses to create one more than the package allows. Reseller
account counts work the same way.

**Disk** is measured hourly by the root worker: `du` of the site folders
and the account's PHP home, the size of its databases, and its mail in
Stalwart. When the total passes the package quota:

- the panel refuses new domains, databases and mailboxes, and File
  Manager uploads, new folders, copy, compress and extract (deleting and
  moving still work);
- the dashboard says so; nothing is taken offline.

Without hard quotas, the account's own PHP, SFTP, MySQL and incoming mail
can still write past the limit. **Hard quotas**: on an XFS root filesystem
installed with `JINNPANEL_XFS_QUOTA=1` (it adds `rootflags=uquota`; reboot
afterwards), the account's Linux user also gets an XFS block limit equal to
the package's disk quota, set at boot and hourly. That stops its PHP, cron
and SFTP from writing more files. Databases and mail live outside the
account's files, so they stay soft. Each SFTP login also has the package
quota inside SFTPGo.

**Bandwidth** counts response bytes of the account's sites (domains, `www.`
and aliases) from the web server's JSON access log, hourly, per calendar
month. Over the limit, every site answers `509` "This website has used up
its bandwidth for this month." (`Retry-After: 86400`) until the month
changes or the package grows (the next hourly run switches the sites back).
Mail, SFTP and panel traffic aren't counted.

## Current limitations

Checked against the code; none of these exist today.

- **Accounts**: a username or account type can't be changed after creation.
- **No billing integration** (WHMCS, Blesta, ...) and **no API** for
  external use - every action is a browser form with a CSRF token.
- **One server** - no clustering, no remote DNS secondaries, no DNSSEC.
- **No app installer** (WordPress, Softaculous-style).
- No directory password protection (no `htpasswd` UI), no hotlink or IP
  blocking UI beyond hand-written Routes. The file editor handles UTF-8
  text up to 1 MB.
- **No SSH access** for accounts and no shell commands in cron; PHP and
  URL jobs only.
- **No uploaded SSL certificates** - Let's Encrypt (HTTP-01 through Caddy)
  or Caddy's self-signed CA only; no wildcards.
- **No per-mailbox quota**, spam filter settings or mail filters for
  customers (the admin can tune Stalwart's spam settings server-wide).
- **No plain FTP** - SFTP/SCP only.
- **No raw access logs** for customers (statistics only: the Stats tab),
  and statistics start with the update that added them (no import of
  older logs).
- **Per-site PHP settings, page cache and extensions** only cover the
  default PHP version.
- **No Node.js, Python or other app runtimes** - PHP and static files.
