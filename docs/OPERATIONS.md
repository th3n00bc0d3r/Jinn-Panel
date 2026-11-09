# Operations

Running a JinnPanel server day to day: where things are, how to check
health, and what to do when something breaks. Install and upgrades are in
`docs/INSTALL.md`; deeper SELinux/systemd gotchas in
`docs/TROUBLESHOOTING.md`.

- [Where things live](#where-things-live)
- [Health check](#health-check)
- [Logs](#logs)
- [The root worker](#the-root-worker)
- [Backups and restores](#backups-and-restores)
- [Quotas and bandwidth](#quotas-and-bandwidth)
- [DNS](#dns)
- [Certificates](#certificates)
- [Mail deliverability](#mail-deliverability)
- [After a reboot](#after-a-reboot)
- [Common incidents](#common-incidents)

## Where things live

| Path | What |
|---|---|
| `/var/www/hostpanel` | The panel (root-owned; re-deployed by the installer - don't edit here) |
| `/var/www/hostpanel/src/Config.php` | Panel secrets and paths (`root:frankenphp 0640`, rendered by the installer) |
| `/var/www/<domain>/` | A site: `public/` (default document root), `logs/` (PHP errors). Owned by `jp_<account>`, mode 0750 |
| `/var/lib/frankenphp/sites-enabled/` | Generated Caddy vhosts, one per domain (+ `zz-mail-services.caddyfile`) |
| `/var/lib/frankenphp/site-rules/` | Per-domain routing rules (translated `.htaccess`), written only by the worker |
| `/var/lib/frankenphp/site-ini/` | Per-domain PHP settings and the page-cache dispatcher |
| `/etc/jinnpanel/php-fpm/<version>/` | PHP-FPM masters and one pool file per account |
| `/etc/jinnpanel/static/<account>.conf` | Each account's static file server (nginx) |
| `/var/lib/jinnpanel/php/<account>/` | An account's private PHP home (sessions, temp files) |
| `/var/lib/knot/<zone>.zone`, `/etc/knot/zones.conf` | Zone files and the list of zones Knot loads |
| `/var/backups/jinnpanel/` | Local backups |
| `/var/lib/jinnpanel/worker/` | What the root worker writes back (job logs, `live-<service>.log`) |
| `/var/lib/jinnpanel/migrations/` | cPanel migration working folders |
| `/var/lib/jinnpanel/removed/` | Site folders of deleted accounts (root-only; delete when you're sure) |
| `/opt/php-versions/<version>/` | Additional PHP versions |
| `/root/.jinnpanel/` | Generated service credentials, `APP_KEY`, setup token |

## Health check

```bash
systemctl --failed
systemctl is-active frankenphp mariadb stalwart sftpgo knot valkey jinnpanel-webmail jinnpanel-phpmyadmin
systemctl list-timers 'hostpanel-*' 'jinnpanel-*'
knotc zone-status | wc -l; ls /var/lib/knot/*.zone | wc -l     # must match
ss -ltn | grep -E ':(8080|8090|8443|3306|6379)\b'               # must all be 127.0.0.1
df -h / /var
```

**WHM > Dashboard** shows the same at a glance: service status (by whether
each port answers), disk and memory, and the last 200 log lines of any
service.

**Automatic:** `jinnpanel-healthcheck.timer` runs
`jinnpanel-after-upgrade check` hourly. It loads every site's home page
through the local web server, looks for failed JinnPanel units and missing
PHP/FrankenPHP/nginx binaries, and checks that the access log is still being
written. Problems go to the journal (`journalctl -t jinnpanel-health`), to
`/var/lib/jinnpanel/health.txt`, and to a banner shown at SSH login
(`/etc/motd.d/jinnpanel-health`, removed once a check passes). Run it by hand
any time: `jinnpanel-after-upgrade check`.

## Logs

| Where | What |
|---|---|
| WHM > Dashboard > logs | Last 200 lines per service (refreshed by the worker every 5 s), or "Pull latest 1000 lines" |
| WHM > Activity Log | Every sign-in and panel action: who, what, which account, from which address |
| `/var/www/hostpanel/storage/logs/app.log` | Panel errors (details behind every "something went wrong" message) |
| `/var/www/hostpanel/storage/logs/migration-<id>.log` | One cPanel migration |
| `/var/lib/jinnpanel/worker/worker-*.log` | Output of each root job (DNS writes, account syncs, usage, backups) |
| `/var/www/<domain>/logs/` | A site's PHP errors |
| `journalctl -u <unit>` | `frankenphp`, `stalwart`, `sftpgo`, `knot`, `mariadb`, `jinnpanel-php-fpm@default`, `jinnpanel-static@<account>`, `hostpanel-worker`, `jinnpanel-migration-<id>`, `jinnpanel-backup-*` |
| `/var/log/fail2ban.log` | SSH bans (`fail2ban-client status sshd`) |

## The root worker

The panel never runs privileged commands itself: it queues signed jobs in
`/var/www/hostpanel/storage/config-queue/`, and `hostpanel-worker.timer`
runs `/usr/local/bin/hostpanel-worker.php` as root every 5 seconds to carry
them out. It also snapshots service logs, measures usage hourly and starts
the daily backup at the configured hour.

If panel changes "don't happen" (a new domain gets no vhost, settings don't
apply), check the worker first:

```bash
systemctl status hostpanel-worker.timer hostpanel-worker.service
ls -la /var/www/hostpanel/storage/config-queue/     # should be empty or nearly
journalctl -u hostpanel-worker -n 50
```

Commands you can run by hand (as root):

```bash
php-zts /usr/local/bin/hostpanel-worker.php sync-accounts      # rebuild every account: user, folders, pools, static servers
php-zts /usr/local/bin/hostpanel-worker.php sync-account <id>  # one account (users.id)
php-zts /usr/local/bin/hostpanel-worker.php usage              # measure usage now, apply hard quotas
php-zts /usr/local/bin/hostpanel-worker.php backup-all         # the daily backup run, now
```

## Backups and restores

Set up in **WHM > Backups**: on/off, the hour (server time, default 03:00),
how many days to keep (1-30, default 2) and an optional S3-compatible copy
(any provider with an `https://` endpoint: AWS, Backblaze B2, Wasabi,
Cloudflare R2, MinIO...).

- Every account: site files, each database, and mail (exported over
  IMAP), as separate parts.
- The server: the panel database and every service's configuration.
- Stored in `/var/backups/jinnpanel/`, then copied to S3 if configured;
  old ones pruned by the retention setting.
- Restore per account and per part (files / databases / mail) from WHM >
  Backups, or by the customer in cPanel > Backups. Restored files are
  handed back to the account's user.
- Customers can download their own backups and start one on demand.

Local backups sit on the same disk as the data they protect - configure
S3 for anything you care about, and test a restore now and then.

## Quotas and bandwidth

`UsageService` measures every account hourly: files, databases and mail
against the package's disk quota, and this month's web traffic against its
bandwidth.

- Over the **disk** quota: uploads, new databases, mailboxes and domains
  are refused (the panel's soft limit).
- With `JINNPANEL_XFS_QUOTA=1` and after a reboot, each account's Linux
  user also gets a **hard XFS quota** for its files: writes past it fail
  at the filesystem. Check with `xfs_quota -x -c 'report -u -h' /`.
- Over **bandwidth**: the account's sites answer `509` until the next
  month (or until you move it to a bigger package).
- Resellers: limited to their package's account count.

## DNS

- Zones are stored in the panel database and rendered to
  `/var/lib/knot/<zone>.zone` by the worker; edit them in WHM > DNS Zones
  (admin) or cPanel > DNS (the customer's own), never by hand - a hand edit
  is overwritten on the next change.
- WHM > DNS Zones > *zone* > **Republish** rewrites a zone from the
  database.
- Check a zone: `dig @127.0.0.1 example.com SOA +short`, and from outside:
  `dig @ns1.<server zone> example.com A`.
- Nameserver names and IPs: WHM > DNS Zones > Nameservers.

## Certificates

Caddy (FrankenPHP) gets and renews Let's Encrypt certificates on its own
for every domain that resolves to the server. A domain that doesn't
resolve here stays on its self-signed certificate. Customers can force a
re-check with **cPanel > Domains > *domain* > Run AutoSSL**. Failures are in
`journalctl -u frankenphp | grep -i acme`.

Stalwart's mail certificate is copied from Caddy's by the daily
`jinnpanel-mail-dns.timer` (it also re-syncs mail DNS records and the
autoconfig/MTA-STS site). Run it now with
`systemctl start jinnpanel-mail-dns.service`.

## Mail deliverability

Every mail domain gets SPF (`v=spf1 mx a ~all`), DKIM (RSA + Ed25519),
DMARC (`p=quarantine`, aggregate reports to `postmaster@<domain>` - create
that mailbox or forwarder if you want them), MX, autoconfig/autodiscover and MTA-STS records
automatically. What the panel can't do for you:

- **PTR / reverse DNS** for each server IP, at your provider, matching the
  hostname.
- **Outbound port 25**: many providers block it until asked.
- IP reputation: check the IPs on a blocklist checker before going live.

Test by sending to a Gmail address and opening "Show original": SPF, DKIM
and DMARC should all say PASS.

## After a dnf upgrade

FrankenPHP (which bundles Caddy), the `php-zts-*` packages and nginx come
from repositories JinnPanel doesn't control, and a minor version can change
behaviour the panel relies on. The installer guards against this:

- **dnf hook.** `python3-dnf-plugin-post-transaction-actions` with
  `/etc/dnf/plugins/post-transaction-actions.d/jinnpanel.action`: whenever
  a transaction installs or upgrades `frankenphp`, `php-zts*` or `nginx*`,
  `jinnpanel-after-upgrade.service` runs in the background (`repair`). It
  restores `/usr/bin/php` and validates the Caddyfile with the **new**
  frankenphp. If the config is valid it restarts FrankenPHP; if not it does
  not restart, so the old process keeps serving, and the check reports it.
  It then restarts the PHP-FPM masters and static servers onto the new
  binaries and runs the full check above.
- **Nothing depends on `/usr/bin/php`.** Every unit and installer step runs
  `/usr/bin/php-zts`. The `php` symlink from `php-zts-cli` is deleted on
  every upgrade of that package: the old package's postuninstall script runs
  after the new one's postinstall.
- **Site configs don't rely on Caddy's try_files fallback.** Every
  `php_fastcgi` that `VhostService` writes has a `try_files` list ending in
  `{path}` (see the incident below).

After an upgrade, check the result:

```bash
journalctl -u jinnpanel-after-upgrade -b --no-pager | tail -20
cat /var/lib/jinnpanel/health.txt          # OK ... / FAIL ... with the list
jinnpanel-after-upgrade check              # again, by hand
```

Upgrading by hand on a box without the hook (or after `--noplugins`):
`dnf upgrade`, then `jinnpanel-after-upgrade repair`. To preview a FrankenPHP
upgrade without touching the running server, start the new binary on a spare
port with a copy of one site block (`frankenphp run --config <copy>`), as was
done in the incident below.

### Incident 2026-11: sites "File not found." after `dnf upgrade`

**Symptoms.** After a routine `dnf -y upgrade`, every site whose home page is
an `index.html` (no `index.php`) answered **404 "File not found."** on `/`;
other files on those sites, and every PHP site, still worked. Separately,
`hostpanel-worker`, `jinnpanel-cron` and `jinnpanel-mail-dns` all failed
with `status=203/EXEC` ("Unable to locate executable '/usr/bin/php'"), so
panel changes, customer cron jobs and the mail DNS/certificate sync stopped.
Neither failure raised an alert.

**Cause 1: Caddy 2.11 `php_fastcgi`.** FrankenPHP's new Caddy compiles
php_fastcgi's `try_files` with `try_policy: first_exist_fallback`: when no
listed file exists, the **last** entry is used anyway. The default list is
`{path} {path}/index.php index.php`, so `/` on an index.html-only site was
rewritten to `/index.php` and sent to PHP-FPM, which has no such file.
Earlier Caddy versions used `first_exist`: nothing found meant the path was
left alone and the static server answered. This applies to user-supplied
`try_files` too.
**Fix:** `VhostService::keepPathFallback()` appends `{path}` to every
php_fastcgi `try_files` list (or emits `try_files {path} {path}/index.php
{path}` when there is none). The fallback is then "leave the path alone",
which is the old behaviour. Lists that end in `{path}` or `=<status>` are
left as they are. Re-running the installer rewrites every site
(`worker/vhost-rebuild.php`).

**Cause 2: the `/usr/bin/php` symlink.** `php-zts-cli` creates
`/usr/bin/php -> php-zts` in its postinstall script only if the link is
missing. Its postuninstall removes the link, and on an upgrade the **old**
package's postuninstall runs **after** the new package's postinstall. Every
upgrade of the package therefore deletes the link.
**Fix:** the installer and all systemd units call `/usr/bin/php-zts`.

**Found at the same time:** `/var/log/jinnpanel-web` was labelled
`httpd_log_t`, which lets FrankenPHP append to the log but not rename it.
At 100 MiB Caddy's `roll_size` rotation was denied by SELinux (`avc: denied
{ remove_name }`), and Caddy then dropped every new access-log line, so
**bandwidth accounting counted nothing** for weeks. **Fix:** the directory is
now `httpd_sys_rw_content_t`, like the panel's other writable directories.
The health check flags an access log that hasn't been written for 24 h.

## After a reboot

```bash
systemctl --failed                                   # nothing
knotc zone-status | wc -l; ls /var/lib/knot/*.zone | wc -l   # equal
xfs_quota -x -c state / | grep -i enforcement        # ON, if XFS quotas are used
curl -sI https://panel.<hostname>/login | head -1    # HTTP/2 200
```

## Common incidents

| Symptom | Likely cause | Fix |
|---|---|---|
| A site answers **502** | The account's PHP-FPM pool isn't up | `systemctl status jinnpanel-php-fpm@default`, `ls /run/jinnpanel-php/default/`, then `php-zts /usr/local/bin/hostpanel-worker.php sync-account <id>` |
| A site answers **503** | The account is suspended | WHM > Accounts > Unsuspend |
| A site answers **509** | Monthly bandwidth used up | Bigger package, or wait for the month to roll over |
| Static files 404/502 but PHP works | The account's static server is down | `systemctl status jinnpanel-static@<account>` |
| Panel changes don't apply | Worker timer stopped or failing | See [The root worker](#the-root-worker) |
| Sites show **"File not found."** (PHP-FPM's 404) on `/`, other files fine | FrankenPHP/Caddy upgraded under configs written by an older JinnPanel | Update JinnPanel and re-run the installer; see [After a dnf upgrade](#after-a-dnf-upgrade) |
| Worker / cron / mail sync fail with `203/EXEC`, `/usr/bin/php` missing | Units written by an older installer; `php-zts-cli` upgrade removed the symlink | Re-run the installer (units use `/usr/bin/php-zts`); `ln -s php-zts /usr/bin/php` meanwhile |
| Bandwidth usage stays at 0 / access log not growing | Caddy can't roll `/var/log/jinnpanel-web/access.log` (SELinux label) | Re-run the installer (relabels the directory); `journalctl -u frankenphp \| grep 'log file'` |
| Domains stop resolving after a restart | Knot started without the zone list | `cat /etc/knot/zones.conf`; re-run the installer, or make any DNS change in the panel (the worker rewrites the list) |
| Certificate warning on a domain | Domain doesn't resolve here yet, or ACME rate limits | Fix DNS, then Run AutoSSL; read `journalctl -u frankenphp` |
| Mail rejected by Gmail/Outlook | No PTR, port 25 blocked, or IP on a blocklist | See [Mail deliverability](#mail-deliverability) |
| Customer can't log in | Throttled after failed attempts (15 minutes), lost 2FA, or suspended | Wait / WHM > Accounts > Reset 2FA / Unsuspend |
| Disk full | Backups, logs or one account | `du -xsh /var/backups/jinnpanel /var/www/* /var/lib/mysql /var/lib/stalwart | sort -h` |
