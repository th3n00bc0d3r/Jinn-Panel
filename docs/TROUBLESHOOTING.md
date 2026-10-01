# Troubleshooting / known gotchas

Everything below is **already handled by `install.sh`**. This document
exists so that if you extend the panel and hit a variation of one of these,
you don't have to re-discover the cause from scratch - every one of these
took real trial-and-error to pin down.

## "Permission denied" / "Access denied" errors from the web app

If FrankenPHP (or code running as it) can't do something that seems like it
should work, check these in order - in this environment, every single
mystery permission error traced back to one of these three, never to a
simple ownership/chmod mistake:

1. **`ProtectSystem=full`** in `frankenphp.service` - `/etc`, `/usr`,
   `/boot` are read-only for the whole process tree, forks included.
   → Anything that needs to write there goes through the background worker
   instead (see `docs/ARCHITECTURE.md`).

2. **SELinux denying `httpd_t` → systemd D-Bus calls.** Symptom:
   `systemctl is-active`/`restart` or `journalctl -u` from PHP's `exec()`
   returns "Access denied" (from systemd itself, not a shell error). No
   SELinux boolean fixes this - it's deliberate confinement. Check port
   liveness instead of `systemctl`, and route journal reads / restarts
   through the worker.

3. **The FrankenPHP-fork-plus-Unix-socket quirk.** If `exec()`ing something
   from PHP fails with `EPERM` specifically when the target does a
   `connect()` to a Unix domain socket (not TCP), and the *identical*
   command works via `php-cli` or via `nsenter` into FrankenPHP's own
   namespaces - it's this. Verified NOT to be SELinux (still fails in
   permissive mode) and NOT to be a seccomp filter (none is installed,
   `/proc/<pid>/status` shows `Seccomp: 0`). Route it through the worker.

If you hit a NEW variant of "PHP can't do X" that isn't one of these three,
it's worth checking `getsebool -a | grep httpd` and `semanage port -l` before
assuming it's a bug in the app - AlmaLinux ships fairly strict SELinux
defaults for the `httpd_t` domain, and this stack asks it to do more than a
typical single-purpose web app.

## SELinux booleans this stack needs (all set by install.sh)

| Boolean | Why |
|---|---|
| `httpd_can_network_connect` | Outbound calls to Stalwart/SFTPGo APIs |
| `httpd_can_network_connect_db` | MariaDB connections |
| `httpd_execmem` | **PHP's JIT/OPcache need to mmap executable memory.** Without this, enabling `opcache.jit` makes FrankenPHP crash on the first real request with `mprotect() failed [13] Permission denied` in the journal - it'll *start* fine (looks healthy) and only fail once a request actually hits JIT-compiled code. If you ever add JIT support somewhere else, remember this. |

## Sites' PHP (PHP-FPM) and SELinux

Every site's PHP runs in its account's PHP-FPM pool
(`jinnpanel-php-fpm@default`, `@82`...). The FPM binaries are labelled
`httpd_exec_t` (`semanage fcontext ... /usr/bin/php-fpm-zts` - the
`/usr/sbin` path is an equivalence of `/usr/bin` in the policy, so the rule
has to name `/usr/bin`), which makes them run as `httpd_t`, the same domain
as FrankenPHP: Caddy may then connect to their sockets. A site answering
**502** means its pool isn't up: `systemctl status jinnpanel-php-fpm@default`,
`ls /run/jinnpanel-php/default/` (one `<username>.sock` per account) and
`php /usr/local/bin/hostpanel-worker.php sync-accounts` (as root) rebuilds
every account's pools. A site's PHP errors go to `/var/www/<domain>/logs/`.

Caddy's admin API is a Unix socket: reload with
`frankenphp reload --config /etc/frankenphp/Caddyfile --address unix//run/frankenphp/admin.sock`
(or `systemctl reload frankenphp`).

## SELinux exec vs. content types

A file *readable/writable* by `httpd_t` (type `httpd_sys_rw_content_t`) is
**not** automatically executable - SELinux treats "content" and "code" as
different type classes on purpose. An extracted `frankenphp` binary placed
under a content-labeled directory fails to start with
`Unable to locate executable: Permission denied` even though `ls -l` shows
`rwxr-xr-x`. Fix: a more specific `semanage fcontext` rule for the exact
binary path, labeled `httpd_exec_t` (SELinux matches the *most specific*
registered pattern, so this can coexist with a broader
`httpd_sys_rw_content_t` rule on the surrounding directory).

## Caddy auto-HTTPS on internal-only listeners

A Caddyfile site block addressed by a bare domain name (e.g.
`example.com:9082`) - even one meant to be a purely internal,
loopback-only backend - gets Caddy's automatic HTTPS applied to it, because
Caddy can't tell it apart from a real public site. Symptom: a plain-HTTP
request gets back `Client sent an HTTP request to an HTTPS server.` Fix:
explicit `http://` scheme in the address (`http://example.com:9082`).

## Knot DNS transactions

`knotc conf-begin`/`conf-commit`/`conf-abort` are a stateful transaction -
only one can be open server-wide at a time. If a script dies mid-sequence
(or you're testing manually and forget to commit/abort), every subsequent
`conf-begin` fails with `error: (too many transactions)`. Fix: `knotc
conf-abort` (repeat 2-3 times if you're not sure how many are stuck) before
retrying.

## Stalwart mail domain names

Stalwart's `x:Domain` validator rejects IANA-reserved special-use TLDs
(`.example`, `.test`, `.invalid`, `.localhost` - RFC 2606) with
`"Invalid domain name"`. This is correct behavior, not a bug - those TLDs
are deliberately non-resolvable and aren't real mail domains. Use a real
domain (or at least a plausible-looking one like `.local` or `.com`) when
testing mail features.

## `dnf module` and multiple PHP versions

The `static-php` repo's PHP-version streams (`php-zts:static-8.2` through
`8.6`) are **mutually exclusive** in `dnf module` - all versions ship
identically-named packages (`frankenphp`, `php-zts-embed`, ...), only the
RPM *version* string differs (`1.12.7_82` vs `1.12.7_85`), so `dnf module
switch-to` would replace whichever version is currently active. `install.sh`
and the worker never use `dnf module` for alt versions - they resolve exact
package URLs via `dnf repoquery --disable-modular-filtering` and extract
just the two files needed (`rpm2cpio | cpio`, not `rpm -i`), which sidesteps
the module system entirely. This works because each version's shared
library has a version-specific SONAME (`libphp-zts-82.so` vs
`libphp-zts-85.so`), so they coexist on disk fine.

## AutoSSL / Let's Encrypt doesn't issue a cert

This is a protocol requirement, not a bug: Let's Encrypt's HTTP-01
challenge needs the domain to publicly resolve to this server with port 80
(and typically 443) reachable from the internet. It cannot work for
private IPs, `.local` domains, or anything behind NAT without port
forwarding - no panel can shortcut this. Caddy will log the failed ACME
attempt and keep retrying; switch the domain back to self-signed if it's
not meant to be public.

## Domains stop resolving after a Knot restart or reboot

`knotc conf-set` only changes Knot's memory - `knot.conf` is a plain text
file, not a configuration database. Zones registered that way are gone
after a restart, and Knot comes up serving nothing. The worker therefore
writes the live zone list to `/etc/knot/zones.conf` after every
registration, and the installer adds `include: "zones.conf"` to
`knot.conf`. Check: `knotc zone-status | wc -l` equals
`ls /var/lib/knot/*.zone | wc -l`. Don't use `knotc conf-export` as a
substitute (it exports knotc's view of the file, not the running zones),
and don't use `knotc zone-check` as a validator (Knot 3.5+ reports "no such
zone" for loaded zones).

## Stalwart or SFTPGo admin UI "doesn't load"

On purpose: their admin interfaces listen on 127.0.0.1 only (8080/8443 and
8090). Use an SSH tunnel -
`ssh -L 8090:127.0.0.1:8090 -L 8080:127.0.0.1:8080 root@<server>` - and
open `http://localhost:8090` / `http://localhost:8080`. Credentials are in
`/root/.jinnpanel/`. Don't open these ports in the firewall: SFTPGo can
write as any account.

## Stalwart taking port 443

Stalwart's default listeners include HTTPS on :443, which FrankenPHP
needs. Whichever starts first wins, so a reboot could leave the panel
crash-looping on "address already in use". The installer moves Stalwart's
HTTPS listener to 127.0.0.1:8443 through its API; re-run it if
`ss -ltnp | grep ':443 '` shows `stalwart`.

## systemd units that run scripts from `/root`

SELinux won't let systemd execute a script stored under `/root` (wrong
file context). Put helper scripts for units in `/usr/local/bin` or
`/usr/local/lib`, and install them with `install` (a file moved with `mv`
from `/tmp` keeps the `user_tmp_t` label, which systemd also refuses).
