#!/usr/bin/env bash
# Sets up the real panel to run on this machine against a local MySQL
# (MAMP's by default), for trying the interface and the account features
# end to end. Then: dev/local/serve.sh
#
# Works: signing in, WHM (accounts, packages and features, alerts,
# notification settings, activity), and the hosting-account pages that only
# need the panel database. Doesn't: anything that needs the server's own
# services (Stalwart mail, SFTPGo, Knot DNS, the PHP-FPM pools, the root
# worker) - those actions show an error instead.
#
# Settings (environment): MYSQL, DB_SOCKET, DB_PORT, DB_USER, DB_PASS,
# DB_NAME (default jinnpanel_local), ADMIN_PASS. FORCE=1 rebuilds the
# database from scratch.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
app="$here/../../app"
MYSQL="${MYSQL:-/Applications/MAMP/Library/bin/mysql}"
DB_SOCKET="${DB_SOCKET:-/Applications/MAMP/tmp/mysql/mysql.sock}"
DB_PORT="${DB_PORT:-8889}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
DB_NAME="${DB_NAME:-jinnpanel_local}"
config="$app/src/Config.php"

if [ -f "$config" ] && ! grep -q "LOCAL DEV" "$config"; then
    echo "app/src/Config.php exists and isn't a local one - not touching it." >&2
    exit 1
fi
if ! [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "DB_NAME may only contain letters, digits and _." >&2
    exit 1
fi

sql() { "$MYSQL" --socket="$DB_SOCKET" -u"$DB_USER" -p"$DB_PASS" "$@" 2>&1 | grep -v "Using a password on the command line" || true; }

if [ "${FORCE:-0}" = 1 ]; then
    sql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`"
fi
sql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
echo "Applying the schema to $DB_NAME..."
sql "$DB_NAME" < "$app/migrations/schema.sql" | grep -v '^1$' || true

app_key="$(php -r 'echo bin2hex(random_bytes(32));')"
# Unreachable service endpoints (port 9): calls fail fast with a clear error.
sed -e "1a\\
// LOCAL DEV - written by dev/local/setup.sh for this machine only (git ignores this file)." \
    -e "s|DB_HOST = '127.0.0.1'|DB_HOST = '127.0.0.1;port=$DB_PORT'|" \
    -e "s|PROV_DB_HOST = '127.0.0.1'|PROV_DB_HOST = '127.0.0.1;port=$DB_PORT'|" \
    -e "s|DB_NAME = 'hostpanel'|DB_NAME = '$DB_NAME'|" \
    -e "s|DB_USER = 'hostpanel_app'|DB_USER = '$DB_USER'|" \
    -e "s|PROV_DB_USER = 'hostpanel_prov'|PROV_DB_USER = '$DB_USER'|" \
    -e "s|__DB_APP_PASS__|$DB_PASS|; s|__DB_PROV_PASS__|$DB_PASS|" \
    -e "s|MAIL_API_BASE = 'http://127.0.0.1:8080'|MAIL_API_BASE = 'http://127.0.0.1:9'|" \
    -e "s|SFTP_API_BASE = 'http://127.0.0.1:8090'|SFTP_API_BASE = 'http://127.0.0.1:9'|" \
    -e "s|__MAIL_ADMIN_USER__|admin|; s|__MAIL_ADMIN_PASS__|local|; s|__SFTP_ADMIN_PASS__|local|; s|__VALKEY_ADMIN_PASS__|local|" \
    -e "s|__SERVER_HOSTNAME__|jinnpanel.local|; s|__SERVER_IP__|127.0.0.1|; s|__SERVER_IPV6__||" \
    -e "s|__APP_KEY__|$app_key|" \
    "$app/src/Config.php.template" > "$config"
php -l "$config" >/dev/null

ADMIN_PASS="${ADMIN_PASS:-}" php "$here/seed.php"
