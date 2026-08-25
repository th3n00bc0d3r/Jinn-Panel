# Icons & Brand Assets

## Brand mark

| File | Use |
|---|---|
| `assets/brand/logo.svg` | Full wordmark (mark + "JinnPanel" text) - README headers, marketing/docs pages |
| `assets/brand/icon.svg` | Square mark alone, 256x256 - app icon, social preview source, anywhere a wordmark doesn't fit |
| `assets/brand/favicon.svg` | Simplified square mark, tuned to stay legible at 16-32px - this is what actually ships in the running app (`app/public/assets/favicon.svg`) |

All three share one gradient (`#6366f1` &rarr; `#7c3aed`, indigo to violet) - the same accent used throughout the WHM side of the UI (`from-indigo-500 to-indigo-700` in Tailwind terms). cPanel's side of the UI uses a second accent, sky blue, to visually separate "administering the server" from "managing your own account" - see `views/partials/shell.php`'s `$accent` parameter.

## UI icon set

The panel's interface uses a small hand-authored set of stroke icons (24x24, `stroke-width="2"`, no fill - the same visual style as Lucide/Feather icons, written directly as inline SVG rather than pulled from a package so the whole app has zero external asset dependencies). The single source of truth is the `icon()` function in `app/src/View.php`; `assets/icons/*.svg` are that same data exported as standalone files for reuse outside the app (docs, mockups, a future design tool import, etc.) - if you add or change an icon, update `View.php` first and re-export, not the other way around.

| Icon | File | Used for |
|---|---|---|
| grid | `grid.svg` | Dashboard nav |
| users | `users.svg` | Accounts nav |
| box | `box.svg` | Packages nav / PHP Versions nav |
| globe | `globe.svg` | Domains nav |
| database | `database.svg` | MySQL Databases nav |
| mail | `mail.svg` | Email Accounts nav / Mail Settings |
| folder-up | `folder-up.svg` | FTP/SFTP nav |
| server | `server.svg` | Server hostname indicator (topbar) |
| folder | `folder.svg` | File Manager nav |
| logout | `logout.svg` | Sign out |
| menu | `menu.svg` | Mobile sidebar toggle |
| close | `close.svg` | (reserved for dismissible UI) |
| plus | `plus.svg` | "Create" buttons |
| trash | `trash.svg` | Delete actions |
| pause | `pause.svg` | Suspend account |
| play | `play.svg` | Unsuspend account |
| download | `download.svg` | File Manager download |
| check | `check.svg` | Success flash messages |
| alert | `alert.svg` | Error flash messages |
| sliders | `sliders.svg` | Server Tweaks nav |
| settings | `settings.svg` | PHP Settings nav |
| shield | `shield.svg` | (reserved for a future security/permissions screen) |

## Adding a new icon

1. Find a 24x24 stroke-style path (matching the existing set's visual weight - `stroke-width="2"`, rounded caps/joins) - most Lucide icons drop in directly since that's the style this set follows.
2. Add it to the `$paths` array in `View.php`'s `icon()` function.
3. Use it anywhere via `icon('your-name', 'h-5 w-5')` (second argument is Tailwind size classes).
4. Optionally export it to `assets/icons/your-name.svg` for consistency with the rest of the set.

## Regenerating `assets/icons/`

The icon set there is generated, not hand-maintained - if `View.php`'s `$paths` array changes, regenerate the exports rather than hand-editing the `.svg` files (keeps the two from drifting):

```python
# See the $paths array in app/src/View.php for the current source of truth.
paths = { "grid": "...", ... }
template = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{inner}</svg>\n'
for name, inner in paths.items():
    open(f"assets/icons/{name}.svg", "w").write(template.format(inner=inner))
```
