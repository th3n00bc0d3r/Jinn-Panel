# Security Policy

JinnPanel is a hosting control panel - it manages databases, mailboxes,
SFTP accounts, DNS zones, and files for every account on a server it runs.
Security issues here are taken seriously.

## Reporting a vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Instead, report it privately:
- Preferred: use GitHub's [private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing/privately-reporting-a-security-vulnerability)
  feature on this repository (Security tab &rarr; "Report a vulnerability").
- Alternative: email **muhammad@geoblood.org** with details.

Please include:
- A description of the vulnerability and its potential impact.
- Steps to reproduce it (a minimal example helps a lot).
- The version/commit you tested against.

You should get an acknowledgment within a few days. This is a
community-maintained project without a dedicated security team, so please
be patient - but reports are taken seriously and a fix or mitigation will
be prioritized once confirmed.

## Scope

In scope:
- The JinnPanel application itself (`app/`) - authentication, 2FA, CSRF,
  authorization/scoping, SQL injection, path traversal, and any way one
  hosting account could read or affect another's data, or the panel's.
- The root side: `installer/install.sh`, `app/worker/hostpanel-worker.php`
  and the other workers - anything that lets an unprivileged input reach
  them unvalidated is high severity.
- The isolation layer the panel sets up: per-account Linux users, PHP-FPM
  pools, static file servers, SFTP, Valkey and MySQL users, and the
  pool agent (`app/runtime/pool-agent.php`).
- Generated configuration: vhosts, routing rules translated from
  `.htaccess`, zone files, firewall and listener bindings.

Out of scope (report to the relevant upstream project instead):
- Vulnerabilities in FrankenPHP/Caddy, PHP, nginx, MariaDB, Stalwart Mail,
  SFTPGo, Knot DNS, Valkey, Cypht or phpMyAdmin themselves, unless the
  issue is in how JinnPanel configures or calls them (e.g. a command
  injection in how the panel builds a `knotc` command is in scope; a bug in
  Knot's zone parser is not).
- Vulnerabilities in customer sites (a WordPress plugin, say), unless they
  let that site escape its account.

## How it's protected

A summary - `docs/ARCHITECTURE.md` ("Customer isolation", "Network
exposure", "Security posture") has the details:

- Each hosting account is its own Linux user; its PHP runs in its own
  PHP-FPM pool (`open_basedir`, exec functions off unless an admin allows
  them), its static files are served by its own nginx running as that user,
  SFTP writes as that user. The panel itself can only read customer files
  and does file work through the account's own pool.
- Privileged work goes through HMAC-signed jobs to a root worker that
  validates every field; the web process never runs root commands.
- Admin APIs (Stalwart, SFTPGo, Caddy, MariaDB, Valkey) are on loopback or
  Unix sockets only.
- Sign-in: bcrypt, throttling, TOTP two-factor for every role, sessions
  ended on password/2FA change and suspension, roles re-read from the
  database on every request; 10+ character, non-common passwords.
- HTTPS only with HSTS, CSP, `X-Frame-Options: DENY`, `nosniff`,
  `Referrer-Policy`, `Permissions-Policy`, COOP; CSRF tokens on every
  POST; `/setup` needs a one-time token.
- Full audit trail of sign-ins and panel actions (WHM > Activity Log).
- fail2ban on SSH.

## Known, accepted limitations

These are documented trade-offs, not vulnerabilities:

- **No per-account CPU/RAM limits** (no cgroup/LVE-style containment):
  each account's PHP pool has a process cap (`pm.max_children`) and PHP's
  `memory_limit`, but a busy account can still use a large share of the
  server's CPU.
- **Shared OPcache per PHP version** (mitigated with
  `opcache.validate_permission` and `opcache.restrict_api`).
- **Disk quotas**: hard limits need XFS user quotas
  (`JINNPANEL_XFS_QUOTA=1` + a reboot) and cover files only; databases and
  mail are measured hourly and limited by the panel (new resources refused),
  not by the filesystem.
- **SFTPGo has file capabilities** (`CAP_DAC_OVERRIDE`/`CAP_CHOWN`/
  `CAP_FOWNER`) so it can write as each account - its admin API must stay on
  127.0.0.1, as the installer configures it.
- **Admins can allow a customer's PHP to run programs** (WHM > Accounts >
  PHP may run programs); that account's code can then run anything its
  Linux user can.
- **SSH is the operator's job**: the installer adds fail2ban but doesn't
  change `sshd_config` (password logins stay as your provider set them).
- **Single server**: no clustering or failover; local backups live on the
  same disk unless S3 is configured.
- **cPanel migration** has been used with backup archives from a real
  cPanel server, but the live API transfer hasn't been run against a real
  cPanel server yet.
- **AutoSSL** needs the domain to resolve publicly to the server - an ACME
  requirement, not a panel limitation.

If you think one of these has a security impact beyond what's written
here, please still report it - the reasoning might be missing something.
