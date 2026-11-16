#!/usr/bin/env bash
# Screenshot a preview page with headless Chrome (the preview server must be running).
#   dev/preview/shot.sh /cpanel/domains out.png [light|dark] [width] [height]
# Widths under 500 are rendered in an iframe at the left of a 500px window
# (Chrome can't make a smaller one); the rest of the image is grey.
set -euo pipefail
url="$1"; out="$2"; theme="${3:-light}"; w="${4:-1440}"; h="${5:-1000}"
port="${PORT:-8787}"
chrome="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
scheme=$([ "$theme" = dark ] && echo 0 || echo 1)
win=$w
if [ "$w" -lt 500 ]; then
    url="/__frame?w=$w&url=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$url")"
    win=500
fi
profile="$(mktemp -d)"   # own profile: several screenshots can run at once
trap 'rm -rf "$profile"' EXIT
"$chrome" --headless=new --user-data-dir="$profile" --disable-gpu --hide-scrollbars --blink-settings=preferredColorScheme=$scheme \
    --window-size="$win,$h" --virtual-time-budget=1500 --screenshot="$out" "http://127.0.0.1:$port$url" >/dev/null 2>&1
echo "$out"
