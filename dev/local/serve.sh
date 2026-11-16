#!/usr/bin/env bash
# Runs the real panel locally (after dev/local/setup.sh) on
# http://127.0.0.1:8088 - WHM and /setup only answer on that address
# (Config::SERVER_IP). Builds the CSS first and rebuilds it on changes.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
repo="$here/../.."
port="${PORT:-8088}"
if ! grep -q "LOCAL DEV" "$repo/app/src/Config.php" 2>/dev/null; then
    echo "Run dev/local/setup.sh first." >&2
    exit 1
fi
"$repo/dev/build-css.sh"
"$repo/dev/build-css.sh" --watch >/dev/null 2>&1 &
watcher=$!
trap 'kill $watcher 2>/dev/null' EXIT
echo "JinnPanel (local): http://127.0.0.1:$port"
echo "Mail the panel sends is written to dev/local/outbox.log, not delivered."
# mail() appends -f<sender> to this command; sh -c takes it as $0 and ignores it.
php -d "sendmail_path=/bin/sh -c 'cat >> \"$here/outbox.log\"'" -S "127.0.0.1:$port" -t "$repo/app/public" "$here/router.php"
