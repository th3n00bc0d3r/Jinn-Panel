#!/usr/bin/env bash
# Builds the CSS, keeps rebuilding it on changes, and serves the preview
# on http://127.0.0.1:8787 (index: /__preview). Ctrl-C stops both.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
repo="$here/../.."
port="${PORT:-8787}"
"$repo/dev/build-css.sh"
"$repo/dev/build-css.sh" --watch >/dev/null 2>&1 &
watcher=$!
trap 'kill $watcher 2>/dev/null' EXIT
echo "Preview: http://127.0.0.1:$port/__preview   Charts: http://127.0.0.1:$port/__charts"
php -S "127.0.0.1:$port" -t "$repo/app/public" "$here/router.php"
