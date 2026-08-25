---
name: Bug report
about: Something in JinnPanel isn't working as expected
title: ""
labels: bug
assignees: ""
---

**Describe the bug**
A clear description of what's wrong.

**To reproduce**
Steps to reproduce the behavior:
1. Go to '...'
2. Click on '...'
3. See error

**Expected behavior**
What you expected to happen instead.

**Screenshots**
If applicable, add screenshots.

**Environment**
- OS/distro and version: (e.g. AlmaLinux 10.2)
- Installed via: `install.sh` fresh install / re-run on existing install / manual setup
- JinnPanel version or commit: (`git log -1 --format=%H` in the deployed copy, if known)
- Relevant service versions if known (FrankenPHP / Stalwart / SFTPGo / Knot / MariaDB)

**Logs**
Anything relevant from:
- Browser console / network tab, if it's a UI issue
- `/var/log/jinnpanel-install.log`, if it's an installer issue
- `journalctl -u frankenphp -u hostpanel-worker -u stalwart -u sftpgo -u knot --since "1 hour ago"`, if it's a runtime issue

Please redact secrets (passwords, tokens) before pasting.

**Additional context**
Anything else useful - did this work before, is it reproducible on a fresh
VM, etc.

---
Security-sensitive bug? Please don't file it here - see `SECURITY.md`
instead.
