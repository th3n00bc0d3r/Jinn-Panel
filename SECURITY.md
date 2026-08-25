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
- The JinnPanel application itself (`app/`) - authentication, CSRF,
  authorization/scoping, SQL injection, path traversal, and any way one
  hosting account could affect another's data.
- `installer/install.sh` and `app/worker/hostpanel-worker.php` - both run
  as root; anything that lets an unprivileged input reach either
  unsanitized is high severity.

Out of scope (report to the relevant upstream project instead):
- Vulnerabilities in FrankenPHP, MariaDB, Stalwart Mail, SFTPGo, or Knot
  DNS themselves, unless the issue is specifically in how JinnPanel
  configures or calls them (e.g. a command injection in how the panel
  builds a `knotc` command - that's in scope; a bug in Knot DNS's own zone
  parser is not).

## Known, accepted limitations

These are documented (not hidden) trade-offs, not vulnerabilities - see
`docs/FEATURES.md` and `docs/TROUBLESHOOTING.md` for the full list:
- Disk/bandwidth quotas are tracked, not enforced at the filesystem level.
- No per-reseller total resource pool cap.
- AutoSSL requires the domain to genuinely resolve publicly - this is an
  ACME protocol requirement, not something any panel can work around.

If you believe one of these has a security implication beyond what's
documented, please still report it - the reasoning might be missing
something.
