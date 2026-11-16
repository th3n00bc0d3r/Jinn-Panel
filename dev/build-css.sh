#!/usr/bin/env bash
# Builds app/public/assets/css/app.css from input.css + tailwind.config.js.
# Uses the standalone tailwindcss binary when it's on PATH (as on the
# server, installed by install.sh), otherwise the same version via npx.
# Extra arguments go to tailwindcss (e.g. --watch).
set -euo pipefail
cd "$(dirname "$0")/../app"
if command -v tailwindcss >/dev/null 2>&1; then
    tw=(tailwindcss)
else
    tw=(npx --yes tailwindcss@3.4.17)
fi
"${tw[@]}" -i public/assets/css/input.css -o public/assets/css/app.css --minify "$@"
