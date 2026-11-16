# Icons & Brand Assets

## Brand mark

| File | Use |
|---|---|
| `assets/brand/logo.svg` | Full wordmark (mark + "JinnPanel" text) - README headers, marketing/docs pages |
| `assets/brand/icon.svg` | Square mark alone, 256x256 - app icon, social preview source, anywhere a wordmark doesn't fit |
| `assets/brand/favicon.svg` | Simplified square mark, tuned to stay legible at 16-32px - this is what actually ships in the running app (`app/public/assets/favicon.svg`) |

All three share one gradient (`#6366f1` &rarr; `#7c3aed`, indigo to violet). The interface uses the same indigo as its single accent colour on both the server side (WHM) and the hosting account side, in light and dark themes - see the design tokens in `app/public/assets/css/input.css` and [UI.md](UI.md).

## UI icon set

The panel's interface uses a small hand-authored set of stroke icons (24x24, `stroke-width="2"`, no fill - the same visual style as Lucide/Feather icons, written directly as inline SVG rather than pulled from a package so the whole app has zero external asset dependencies). The single source of truth is the `icon()` function in `app/src/View.php`; `assets/icons/*.svg` are that same data exported as standalone files for reuse outside the app (docs, mockups, a future design tool import, etc.) - if you add or change an icon, update `View.php` first and re-export, not the other way around.

| Icon | File | Used for |
|---|---|---|
| grid | `grid.svg` | Dashboard |
| users | `users.svg` | Accounts, resellers |
| user | `user.svg` | Hosting accounts |
| user-plus | `user-plus.svg` | Create account |
| box | `box.svg` | Packages |
| globe | `globe.svg` | Domains, DNS zones (WHM) |
| database | `database.svg` | MySQL databases, database settings |
| mail | `mail.svg` | Email accounts, mail settings, notifications |
| folder-up | `folder-up.svg` | FTP / SFTP |
| folder | `folder.svg` | File Manager, folders |
| file | `file.svg` | Files, new file |
| file-text | `file-text.svg` | Text files (open in the editor) |
| server | `server.svg` | DNS zones (cPanel), server hostname |
| archive | `archive.svg` | Backups, zip |
| activity | `activity.svg` | Activity log, recent activity |
| bell | `bell.svg` | Alerts |
| shield | `shield.svg` | Login security, certificates, exposed files |
| key | `key.svg` | SSH keys, password generator |
| lock | `lock.svg` | (reserved) |
| code | `code.svg` | PHP versions, PHP stat |
| layers | `layers.svg` | PHP extensions |
| settings | `settings.svg` | PHP settings |
| sliders | `sliders.svg` | Server tweaks |
| bolt | `bolt.svg` | Cache |
| clock | `clock.svg` | Cron jobs, expired links |
| route | `route.svg` | Routes & .htaccess |
| transfer | `transfer.svg` | cPanel migration |
| plus | `plus.svg` | Create / add |
| edit | `edit.svg` | Edit |
| save | `save.svg` | Save |
| trash | `trash.svg` | Delete |
| copy | `copy.svg` | Copy to clipboard |
| download | `download.svg` | Download, backups |
| upload | `upload.svg` | Upload |
| refresh | `refresh.svg` | Re-run, regenerate, clear cache |
| external | `external.svg` | Open in a new tab |
| login | `login.svg` | Log in as |
| logout | `logout.svg` | Sign out |
| eye | `eye.svg` | Viewing as another account, show a password |
| eye-off | `eye-off.svg` | Hide a shown password |
| pause | `pause.svg` | Suspend |
| play | `play.svg` | Unsuspend |
| check | `check.svg` | Done, all clear |
| check-circle | `check-circle.svg` | Success toasts |
| x-circle | `x-circle.svg` | Error toasts, problems |
| alert | `alert.svg` | Warnings |
| info | `info.svg` | Notes, info alerts |
| search | `search.svg` | Ctrl-K search |
| sun | `sun.svg` | Light theme |
| moon | `moon.svg` | Dark theme |
| monitor | `monitor.svg` | System theme |
| menu | `menu.svg` | Mobile menu |
| close | `close.svg` | Close, remove |
| chevron-right | `chevron-right.svg` | Breadcrumbs, "more" links |
| chevron-left | `chevron-left.svg` | Previous page |
| chevron-down | `chevron-down.svg` | Menus, disclosures |
| arrow-left | `arrow-left.svg` | Back |
| arrow-up | `arrow-up.svg` | More / less than before (rotated) |
| home | `home.svg` | Home, website folder |
| more | `more.svg` | (reserved: overflow menus) |
| terminal | `terminal.svg` | (reserved) |
| list | `list.svg` | (reserved) |
| chart | `chart.svg` | (reserved: statistics) |
| cpu | `cpu.svg` | (reserved: server stats) |
| hard-drive | `hard-drive.svg` | (reserved: disks) |

## Adding a new icon

1. Find a 24x24 stroke-style path (matching the existing set's visual weight - `stroke-width="2"`, rounded caps/joins) - most Lucide icons drop in directly since that's the style this set follows.
2. Add it to the `$paths` array in `View.php`'s `icon()` function.
3. Use it anywhere via `icon('your-name', 'h-5 w-5')` (second argument is Tailwind size classes). Icons are decorative (`aria-hidden`): an icon-only button or link needs its own name - use `Ui::iconButton()` / `Ui::iconLink()`.
4. Export it to `assets/icons/your-name.svg` and add it to the table above.

## Regenerating `assets/icons/`

The icon set there is generated, not hand-maintained - if `View.php`'s `$paths` array changes, regenerate the exports rather than hand-editing the `.svg` files (keeps the two from drifting):

```python
# See the $paths array in app/src/View.php for the current source of truth.
paths = { "grid": "...", ... }
template = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{inner}</svg>\n'
for name, inner in paths.items():
    open(f"assets/icons/{name}.svg", "w").write(template.format(inner=inner))
```
