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

**Working on the interface** needs only PHP 8.2+ (and Node for the
Tailwind CLI, or the standalone `tailwindcss` binary): the preview renders
every page with sample data, no server stack.

```bash
dev/preview/serve.sh                     # builds the CSS and serves http://127.0.0.1:8787/__preview
php dev/preview/render-all.php           # every view renders, controls are labelled (CI runs it)
```

Read [docs/UI.md](docs/UI.md) before changing a view: design tokens,
component classes, the `Ui::` helpers, and how a view hands actions,
breadcrumbs and scripts to the layout. A new view needs a fixture in
`dev/preview/fixtures/` (CI fails without one).

**The real panel on your machine** (sign-in, WHM, accounts, packages,
features; no mail/DNS/SFTP/PHP pools) runs against a local MySQL 5.7+ or
MariaDB - MAMP's by default:

```bash
dev/local/setup.sh      # creates jinnpanel_local, applies the schema, writes a local Config.php, seeds demo logins
dev/local/serve.sh      # http://127.0.0.1:8088 (Chrome or Firefox)
```

Pages that talk to an account's PHP pool (File Manager, Cache) wait about
15 seconds per domain for pools that don't exist locally, then show an
error; mail, DNS and SFTP actions fail with an error. Mail the panel
sends (welcome, reset, suspension, quota) is written to
`dev/local/outbox.log` instead of being delivered. `FORCE=1
dev/local/setup.sh` starts over.

**Everything else** (provisioning, the worker, mail, DNS) is built against
a real running stack (FrankenPHP, MariaDB, Stalwart, SFTPGo, Knot):

1. Spin up an AlmaLinux 10 VM (VirtualBox, a cheap cloud instance,
   whatever).
2. Run `installer/install.sh` on it once to get the full stack up.
3. Edit files under `app/` locally, then sync your changes to
   `/var/www/hostpanel` on the VM (`rsync`, `scp`, or mount the VM's
   filesystem - whatever workflow you're comfortable with) and test against
   the real thing.
4. Run the tests before pushing (CI runs them too, plus `php -l` on every
   PHP file, the preview render check and `node --check` on the JavaScript -
   `.github/workflows/ci.yml`):
   ```bash
   php tests/UnitTest.php                 # no dependencies
   php dev/preview/render-all.php         # no dependencies
   php tests/HtaccessTranslatorTest.php   # needs the frankenphp binary
   ```
5. Changes to `app/worker/`, `app/runtime/` or `installer/` only take effect
   after re-running the installer on the VM (it re-deploys the worker to
   `/usr/local/bin` and the pool agent to `/usr/local/lib/jinnpanel/pool`).

`CONTEXT.md` at the repo root is the short orientation (layout, request
flow, privilege model, conventions) - read it before your first change.

If you change Tailwind classes, rebuild the CSS before testing on the VM
(`dev/build-css.sh` locally; on the server):
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
- Styling: the design tokens and component classes in
  `public/assets/css/input.css` (`card`, `btn btn-primary`, `bg-surface`,
  `text-muted`...) plus Tailwind utilities - never raw palette colours like
  `bg-slate-100`, so both themes keep working. Class names must be written
  out in full: Tailwind finds them by scanning `views/`, `public/` and
  `src/Support/Ui.php`, and can't see one built as `"bg-{$var}-600"`.
- No inline `<script>`, `onclick=` or `onchange=`: page code goes in
  `public/assets/js/pages/<name>.js` (`View::script()`), behaviour in
  `data-` attributes handled by `app.js`. The Content-Security-Policy
  doesn't allow inline scripts.
- Every control has a label, every icon-only button a name (`Ui::field`,
  `Ui::iconButton` do it for you).

## Security-sensitive changes

This is a hosting control panel - it manages other people's databases,
mailboxes, files, and DNS. Extra care for anything touching:
- The file manager's path resolution (`FileManagerController::safeDir` /
  `safeName`) - traversal bugs here are directly exploitable.
- SQL identifier handling (`ProvisioningService::isValidIdentifier`) - the
  handful of places that can't use parameterized queries because MySQL
  doesn't allow binding identifiers.
- Anything in `hostpanel-worker.php` - it runs as root. New job types
  carry ids only, read the rest from the database, and validate every
  value.
- Anything that writes into a customer's folders: do it through the pool
  agent (`PoolClient` -> `runtime/pool-agent.php`, as the account), never
  from panel code.
- Generated config (vhosts, routing rules, zone files, PHP-FPM pools) -
  a value that reaches one unescaped can affect every site on the server.

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
