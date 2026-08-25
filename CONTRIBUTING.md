# Contributing to JinnPanel

Thanks for considering it. This is a young project, so process is
deliberately light - the goal is to keep it easy to contribute to, not to
gate-keep.

## Before you start

- For anything more than a small fix, open an issue first describing what
  you want to change and why. Saves everyone a rewritten PR later.
- Read `docs/ARCHITECTURE.md` first, seriously - several design choices
  here (the background worker instead of direct `exec()`, the multi-PHP
  isolation approach, the schema-driven Stalwart settings UI) look
  unnecessary until you understand the constraint they work around, which
  is exactly why that document exists.

## Development setup

There's no local dev environment shortcut yet - the whole app is built
against a real running stack (FrankenPHP, MariaDB, Stalwart, SFTPGo, Knot).
The practical way to work on it:

1. Spin up an AlmaLinux 10 VM (VirtualBox, a cheap cloud instance,
   whatever).
2. Run `installer/install.sh` on it once to get the full stack up.
3. Edit files under `app/` locally, then sync your changes to
   `/var/www/hostpanel` on the VM (`rsync`, `scp`, or mount the VM's
   filesystem - whatever workflow you're comfortable with) and test against
   the real thing.
4. `php -l` every changed file before committing - there's no CI yet, so
   this is on you.

If you're changing anything that touches Tailwind classes, rebuild the CSS
before testing:
```bash
cd /var/www/hostpanel
tailwindcss -i public/assets/css/input.css -o public/assets/css/app.css --minify
```

## Code style

- Plain PHP, no framework, no Composer dependencies. Keep it that way
  unless there's a strong reason not to - the whole point is that someone
  can read the entire app in an afternoon.
- Match the existing style: 4-space indent, `declare(strict_types=1);` at
  the top of every PHP file, prepared statements for every query,
  `Csrf::requireValid()` on every state-changing POST handler.
- Views are plain PHP templates (`<?php ... ?>` / `<?= e($x) ?>`), not a
  templating engine. Always escape output with `e()` unless you have a
  specific reason not to (and comment why).
- Tailwind utility classes only - no custom CSS files beyond
  `public/assets/css/input.css`'s three `@tailwind` directives. If you
  introduce a *dynamically built* class name (e.g. `"bg-{$var}-600"`), add
  it to `tailwind.config.js`'s `safelist` - Tailwind's compiler can't
  discover those by scanning source files, and it'll silently produce no
  CSS for them otherwise (a mistake that's bitten this project before).

## Security-sensitive changes

This is a hosting control panel - it manages other people's databases,
mailboxes, files, and DNS. Extra care for anything touching:
- The file manager's path resolution (`FileManagerController::safeDir` /
  `safeName`) - traversal bugs here are directly exploitable.
- SQL identifier handling (`ProvisioningService::isValidIdentifier`) - the
  handful of places that can't use parameterized queries because MySQL
  doesn't allow binding identifiers.
- Anything in `hostpanel-worker.php` - it runs as root.

See `SECURITY.md` for how to report a vulnerability privately instead of
via a public issue.

## Pull requests

- One logical change per PR.
- Explain *why*, not just *what* - especially for anything working around
  one of the SELinux/FrankenPHP quirks in `docs/TROUBLESHOOTING.md`. If
  you hit a new one, please add it there too.
- If you're adding a new Stalwart settings object to the WHM mail settings
  menu, it likely just works by adding it to
  `StalwartAdminService::SETTINGS_GROUPS` - no new PHP needed, the form is
  schema-driven.
