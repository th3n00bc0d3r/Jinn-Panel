#!/bin/bash
# JinnPanel installer - AlmaLinux 10 (x86_64)
#
# Installs the full stack this panel manages (FrankenPHP, MariaDB, Stalwart
# Mail, SFTPGo, Knot DNS), deploys JinnPanel itself, and starts the
# background config worker. Fully non-interactive - every value is either
# auto-detected or randomly generated. Once this finishes, visit
# https://panel.<hostname>/setup in a browser to create the real admin
# account and go live; that's the one step that's deliberately NOT done by
# this script (a fixed admin password baked into an installer is exactly
# what this avoids).
#
# Usage:
#   sudo ./install.sh
#   sudo JINNPANEL_HOSTNAME=panel.example.com ./install.sh   # override auto-detected hostname
#
# Safe to re-run: package installs are idempotent (dnf skips what's already
# there), and file writes overwrite cleanly. Re-running does NOT rotate
# already-generated secrets for services that keep state (MariaDB, Stalwart,
# SFTPGo) - only genuinely fresh installs get fresh secrets for those.

set -euo pipefail

# ---------------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_SRC="$(cd "$SCRIPT_DIR/../app" && pwd)"
APP_ROOT=/var/www/hostpanel
STATE_DIR=/root/.jinnpanel
LOG_FILE=/var/log/jinnpanel-install.log

mkdir -p "$STATE_DIR"
: > "$LOG_FILE"
exec > >(tee -a "$LOG_FILE") 2>&1

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }
ok()   { printf '\033[1;32m    OK: %s\033[0m\n' "$1"; }
warn() { printf '\033[1;33m    WARN: %s\033[0m\n' "$1"; }

trap 'echo; echo "Install failed at line $LINENO. Full log: $LOG_FILE"; exit 1' ERR

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root (sudo ./install.sh)." >&2
    exit 1
fi

if ! grep -qi 'AlmaLinux' /etc/os-release 2>/dev/null; then
    warn "This isn't detected as AlmaLinux - continuing anyway, but this was only tested there."
fi

randpass() { python3 -c "import secrets,string; a=string.ascii_letters+string.digits; print(''.join(secrets.choice(a) for _ in range(24)))"; }

# ---------------------------------------------------------------------------
# 1. Hostname, DNS, base packages
# ---------------------------------------------------------------------------

log "Detecting server identity"

if [ -n "${JINNPANEL_HOSTNAME:-}" ]; then
    HOSTNAME_FQDN="$JINNPANEL_HOSTNAME"
else
    CURRENT=$(hostnamectl --static 2>/dev/null || true)
    if [ -n "$CURRENT" ] && [[ "$CURRENT" == *.* ]]; then
        HOSTNAME_FQDN="$CURRENT"
    else
        # Bounded read (head -c200 /dev/urandom) rather than piping the
        # unbounded device straight into a truncating `head` - with
        # `set -o pipefail`, an upstream producer cut off by a downstream
        # `head` can exit via SIGPIPE and abort the whole script.
        HOSTNAME_FQDN="jinnserver-$(head -c200 /dev/urandom | tr -dc a-z0-9 | head -c6).local"
    fi
fi
SERVER_IP=$(ip route get 1.1.1.1 2>/dev/null | awk '/src/ {for(i=1;i<=NF;i++) if ($i=="src") print $(i+1)}')
[ -z "$SERVER_IP" ] && SERVER_IP=$(hostname -I | awk '{print $1}')
PANEL_HOSTNAME="panel.$HOSTNAME_FQDN"

ok "Hostname: $HOSTNAME_FQDN"
ok "IP: $SERVER_IP"
ok "Panel will be at: https://$PANEL_HOSTNAME"

hostnamectl set-hostname "$HOSTNAME_FQDN"
grep -q "$HOSTNAME_FQDN" /etc/hosts || sed -i "/127.0.0.1/ s/\$/ $HOSTNAME_FQDN/" /etc/hosts
grep -q "$SERVER_IP $HOSTNAME_FQDN" /etc/hosts || echo "$SERVER_IP $HOSTNAME_FQDN $PANEL_HOSTNAME" >> /etc/hosts

# Redundant DNS (router-provided + public fallback) via NetworkManager, not a
# hand-edited /etc/resolv.conf which won't survive a reconnect.
PRIMARY_CONN=$(nmcli -t -f NAME,DEVICE connection show --active | awk -F: 'NR==1{print $1; exit}')
if [ -n "$PRIMARY_CONN" ]; then
    ROUTER_DNS=$(ip route | awk '/default/ {print $3; exit}')
    nmcli connection modify "$PRIMARY_CONN" ipv4.dns "1.1.1.1 ${ROUTER_DNS:-8.8.8.8}" ipv4.ignore-auto-dns no || true
    nmcli connection up "$PRIMARY_CONN" || true
fi

log "Installing base packages"
dnf -y makecache
# rsync + gzip: used by WHM > cPanel Migration to unpack and copy site files.
dnf -y install tar gzip rsync unzip dnf-plugins-core epel-release curl firewalld
ok "Base packages installed"

if ! systemctl is-active --quiet firewalld; then
    systemctl enable --now firewalld
fi

# ---------------------------------------------------------------------------
# 2. MariaDB
# ---------------------------------------------------------------------------

log "Installing MariaDB"
dnf -y install mariadb-server
systemctl enable --now mariadb

if [ -f "$STATE_DIR/mariadb_root_pw" ]; then
    MARIADB_ROOT_PW=$(cat "$STATE_DIR/mariadb_root_pw")
elif mysql -u root -e 'SELECT 1' >/dev/null 2>&1; then
    # Passwordless root still works - genuinely first run. Secure it now.
    log "Securing MariaDB (first install)"
    MARIADB_ROOT_PW=$(randpass)
    mysql -u root <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('$MARIADB_ROOT_PW');
DELETE FROM mysql.global_priv WHERE User='';
DELETE FROM mysql.global_priv WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
SQL
    echo "$MARIADB_ROOT_PW" > "$STATE_DIR/mariadb_root_pw"
else
    # Root already has a password this installer doesn't know (e.g. MariaDB
    # was set up by something other than this script). Can't guess it - ask
    # rather than fail with a cryptic "Access denied" from the SQL below.
    echo "MariaDB root already has a password this installer doesn't know about." >&2
    echo "Put it in $STATE_DIR/mariadb_root_pw (plain text, root-readable only) and re-run." >&2
    exit 1
fi

DB_APP_PASS=$(randpass)
DB_PROV_PASS=$(randpass)
mysql -u root -p"$MARIADB_ROOT_PW" <<SQL
CREATE DATABASE IF NOT EXISTS hostpanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'hostpanel_app'@'localhost' IDENTIFIED BY '$DB_APP_PASS';
ALTER USER 'hostpanel_app'@'localhost' IDENTIFIED BY '$DB_APP_PASS';
GRANT ALL PRIVILEGES ON hostpanel.* TO 'hostpanel_app'@'localhost';

CREATE USER IF NOT EXISTS 'hostpanel_prov'@'localhost' IDENTIFIED BY '$DB_PROV_PASS';
ALTER USER 'hostpanel_prov'@'localhost' IDENTIFIED BY '$DB_PROV_PASS';
GRANT CREATE, DROP, ALTER, INDEX, CREATE USER, SELECT, INSERT, UPDATE, DELETE, LOCK TABLES, REFERENCES
    ON *.* TO 'hostpanel_prov'@'localhost' WITH GRANT OPTION;
SQL
ok "MariaDB ready (hostpanel_app + hostpanel_prov, least-privilege)"

# ---------------------------------------------------------------------------
# 3. FrankenPHP
# ---------------------------------------------------------------------------

log "Installing FrankenPHP"
curl -fsSL https://frankenphp.dev/install.sh -o /tmp/frankenphp-install.sh
sh /tmp/frankenphp-install.sh
dnf -y install php-zts-pdo php-zts-pdo_mysql php-zts-mysqlnd

groupadd -f webusers
usermod -aG webusers frankenphp

mkdir -p /var/lib/frankenphp/sites-enabled
chown frankenphp:frankenphp /var/lib/frankenphp/sites-enabled
semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/frankenphp/sites-enabled(/.*)?' 2>/dev/null || true
restorecon -R /var/lib/frankenphp/sites-enabled

grep -q 'sites-enabled' /etc/frankenphp/Caddyfile || echo 'import /var/lib/frankenphp/sites-enabled/*.caddyfile' >> /etc/frankenphp/Caddyfile

mkdir -p /var/www
chgrp webusers /var/www
chmod 2775 /var/www
semanage fcontext -a -t httpd_sys_rw_content_t '/var/www(/.*)?' 2>/dev/null || true
restorecon -R /var/www

# JIT/OPcache need to mmap executable memory - denied by default.
setsebool -P httpd_execmem on
setsebool -P httpd_can_network_connect on
setsebool -P httpd_can_network_connect_db on

firewall-cmd --permanent --add-service=http --add-service=https >/dev/null
firewall-cmd --reload >/dev/null

systemctl enable --now frankenphp
ok "FrankenPHP running"

# ---------------------------------------------------------------------------
# 4. Stalwart Mail
# ---------------------------------------------------------------------------

log "Installing Stalwart Mail"
if [ ! -f /usr/local/bin/stalwart ]; then
    curl --proto '=https' --tlsv1.2 -sSf https://get.stalw.art/install.sh -o /tmp/stalwart-install.sh
    sh /tmp/stalwart-install.sh
fi
systemctl enable --now stalwart
sleep 3

if [ ! -f "$STATE_DIR/mail_admin_pass" ]; then
    log "Bootstrapping Stalwart (first install)"
    BOOTSTRAP_PW=$(journalctl -u stalwart -n 200 --no-pager | grep -A2 'bootstrap mode' | grep 'password:' | awk '{print $NF}' | tail -1)
    if [ -z "$BOOTSTRAP_PW" ]; then
        warn "Could not read the Stalwart bootstrap password from the journal - skipping bootstrap. Run it manually via the JMAP API (see docs/TROUBLESHOOTING.md)."
    else
        # Step 1: resolve the primary account id (separate call, plain
        # variable capture - nesting this inline in the payload below is
        # a quoting nightmare and not worth the fragility).
        curl -s -u "admin:$BOOTSTRAP_PW" http://127.0.0.1:8080/jmap/session -o /tmp/stalwart-session.json
        BOOTSTRAP_ACCOUNT_ID=$(python3 -c "
import json
d = json.load(open('/tmp/stalwart-session.json'))
print(list(d['primaryAccounts'].values())[0])
")

        # Step 2: build the request body as a real file via heredoc (only
        # plain \$VAR substitution, no nested command substitution inside
        # quotes) so there's nothing fragile to get wrong here.
        cat > /tmp/stalwart-bootstrap-payload.json <<EOF
{
  "using": ["urn:ietf:params:jmap:core", "urn:stalwart:jmap"],
  "methodCalls": [
    ["x:Bootstrap/set", {
      "accountId": "$BOOTSTRAP_ACCOUNT_ID",
      "update": {
        "singleton": {
          "serverHostname": "$HOSTNAME_FQDN",
          "defaultDomain": "$HOSTNAME_FQDN",
          "requestTlsCertificate": false,
          "generateDkimKeys": true,
          "dataStore": { "@type": "RocksDb", "path": "/var/lib/stalwart/" },
          "blobStore": { "@type": "Default" },
          "searchStore": { "@type": "Default" },
          "inMemoryStore": { "@type": "Default" },
          "directory": { "@type": "Internal" },
          "tracer": { "@type": "Log", "path": "/var/log/stalwart/" },
          "dnsServer": { "@type": "Manual" }
        }
      }
    }, "0"]
  ]
}
EOF

        curl -s -u "admin:$BOOTSTRAP_PW" -H 'Content-Type: application/json' \
            -X POST --data @/tmp/stalwart-bootstrap-payload.json \
            http://127.0.0.1:8080/jmap/ -o /tmp/stalwart-bootstrap-response.json

        MAIL_ADMIN_PASS=$(python3 -c "
import json
d = json.load(open('/tmp/stalwart-bootstrap-response.json'))
print(d['methodResponses'][0][1]['updated']['singleton']['secret'])
" 2>/dev/null || echo "")

        if [ -n "$MAIL_ADMIN_PASS" ]; then
            echo "admin@$HOSTNAME_FQDN" > "$STATE_DIR/mail_admin_user"
            echo "$MAIL_ADMIN_PASS" > "$STATE_DIR/mail_admin_pass"
            systemctl restart stalwart
            sleep 2
        else
            warn "Stalwart bootstrap response didn't parse as expected - check /tmp/stalwart-bootstrap-response.json and finish setup manually (see docs/TROUBLESHOOTING.md)."
        fi
        rm -f /tmp/stalwart-session.json /tmp/stalwart-bootstrap-payload.json
    fi
fi
MAIL_ADMIN_USER=$(cat "$STATE_DIR/mail_admin_user" 2>/dev/null || echo "admin@$HOSTNAME_FQDN")
MAIL_ADMIN_PASS=$(cat "$STATE_DIR/mail_admin_pass" 2>/dev/null || echo "")

# Verify these actually authenticate before baking them into Config.php -
# better to fail loudly here than silently deploy an app that can't reach
# its mail server. This is what catches "Stalwart was already configured by
# something other than this script" (no bootstrap password to find, so
# nothing above could have set mail_admin_pass).
if ! curl -sf -u "$MAIL_ADMIN_USER:$MAIL_ADMIN_PASS" http://127.0.0.1:8080/jmap/session >/dev/null 2>&1; then
    echo "Could not verify Stalwart admin credentials ($MAIL_ADMIN_USER)." >&2
    echo "Put working ones in $STATE_DIR/mail_admin_user and $STATE_DIR/mail_admin_pass and re-run." >&2
    exit 1
fi

firewall-cmd --permanent --add-service=smtp >/dev/null
firewall-cmd --permanent --add-port=465/tcp --add-port=587/tcp --add-port=993/tcp --add-port=995/tcp --add-port=4190/tcp --add-port=8080/tcp >/dev/null
firewall-cmd --reload >/dev/null
ok "Stalwart Mail running"

# ---------------------------------------------------------------------------
# 5. SFTPGo
# ---------------------------------------------------------------------------

log "Installing SFTPGo"
if [ ! -f /etc/yum.repos.d/sftpgo.repo ]; then
    ARCH=$(uname -m)
    curl -sS "https://oss.sftpgo.com/yum/${ARCH}/sftpgo.repo" -o /etc/yum.repos.d/sftpgo.repo
fi
dnf -y install sftpgo
usermod -aG webusers sftpgo

# Move the web admin off 8080 (Stalwart already owns that).
python3 - <<'PYEOF'
import json
p = '/etc/sftpgo/sftpgo.json'
d = json.load(open(p))
d['httpd']['bindings'][0]['port'] = 8090
json.dump(d, open(p, 'w'), indent=2)
PYEOF

if [ -f "$STATE_DIR/sftpgo_admin_pass" ]; then
    SFTPGO_ADMIN_PASS=$(cat "$STATE_DIR/sftpgo_admin_pass")
else
    log "Bootstrapping SFTPGo (first install)"
    SFTPGO_ADMIN_PASS=$(randpass)
    cat > /tmp/sftpgo-admin-seed.json <<EOF
{"admins":[{"status":1,"username":"panelapi","password":"$SFTPGO_ADMIN_PASS","email":"admin@$HOSTNAME_FQDN","permissions":["*"]}],"version":15}
EOF
    systemctl stop sftpgo 2>/dev/null || true
    # NB: loaddata mode 1 ("new users added, existing not modified") means
    # this silently does nothing useful if a 'panelapi' admin already
    # exists from a prior install this script doesn't have state for - the
    # verification below catches that rather than writing a password to
    # state that doesn't actually work.
    sftpgo initprovider --config-dir /etc/sftpgo --loaddata-from /tmp/sftpgo-admin-seed.json
    rm -f /tmp/sftpgo-admin-seed.json
fi

systemctl enable --now sftpgo
sleep 1

if ! curl -sf -u "panelapi:$SFTPGO_ADMIN_PASS" http://127.0.0.1:8090/api/v2/token >/dev/null 2>&1; then
    echo "Could not verify the SFTPGo 'panelapi' admin credentials." >&2
    echo "Put a working password in $STATE_DIR/sftpgo_admin_pass and re-run." >&2
    exit 1
fi
echo "$SFTPGO_ADMIN_PASS" > "$STATE_DIR/sftpgo_admin_pass"

firewall-cmd --permanent --add-port=2022/tcp --add-port=8090/tcp >/dev/null
firewall-cmd --reload >/dev/null
ok "SFTPGo running"

# ---------------------------------------------------------------------------
# 6. Knot DNS
# ---------------------------------------------------------------------------

log "Installing Knot DNS"
dnf -y copr enable '@cznic/knot-dns-latest' || true
dnf -y install knot

sed -i 's/#    listen: \[ 127.0.0.1@53, ::1@53 \]/    listen: [ 0.0.0.0@53, ::@53 ]/' /etc/knot/knot.conf
grep -q '^    listen:' /etc/knot/knot.conf || warn "Could not find the expected commented listen line in knot.conf - check it manually, Knot may only be listening on localhost."
systemctl enable --now knot
firewall-cmd --permanent --add-service=dns >/dev/null
firewall-cmd --reload >/dev/null
ok "Knot DNS running"

# ---------------------------------------------------------------------------
# 7. Deploy JinnPanel
# ---------------------------------------------------------------------------

log "Deploying JinnPanel application"
mkdir -p "$APP_ROOT"
cp -r "$APP_SRC"/. "$APP_ROOT"/

MAIL_ADMIN_USER_ESC=$(printf '%s' "$MAIL_ADMIN_USER" | sed 's/[&/\]/\\&/g')

# APP_KEY encrypts secrets stored by the panel (cPanel migration source
# credentials). Generated once and persisted: unlike the DB passwords above
# it must NOT change on re-run, or anything encrypted with it is lost.
if [ ! -s "$STATE_DIR/app_key" ]; then
    python3 -c "import secrets; print(secrets.token_hex(32))" > "$STATE_DIR/app_key"
    chmod 600 "$STATE_DIR/app_key"
fi
APP_KEY=$(cat "$STATE_DIR/app_key")

sed \
    -e "s/__APP_KEY__/$APP_KEY/" \
    -e "s/__DB_APP_PASS__/$DB_APP_PASS/" \
    -e "s/__DB_PROV_PASS__/$DB_PROV_PASS/" \
    -e "s/__MAIL_ADMIN_USER__/$MAIL_ADMIN_USER_ESC/" \
    -e "s/__MAIL_ADMIN_PASS__/$MAIL_ADMIN_PASS/" \
    -e "s/__SFTP_ADMIN_PASS__/$SFTPGO_ADMIN_PASS/" \
    -e "s/__SERVER_HOSTNAME__/$HOSTNAME_FQDN/" \
    -e "s/__SERVER_IP__/$SERVER_IP/" \
    "$APP_ROOT/src/Config.php.template" > "$APP_ROOT/src/Config.php"
rm -f "$APP_ROOT/src/Config.php.template"

mysql -u hostpanel_app -p"$DB_APP_PASS" hostpanel < "$APP_ROOT/migrations/schema.sql"

chown -R frankenphp:webusers "$APP_ROOT"
find "$APP_ROOT" -type d -exec chmod 2775 {} \;
find "$APP_ROOT" -type f -exec chmod 664 {} \;
semanage fcontext -a -t httpd_sys_rw_content_t "$APP_ROOT(/.*)?" 2>/dev/null || true
restorecon -R "$APP_ROOT"

# Tailwind CLI (standalone, no Node needed) + build
if [ ! -f /usr/local/bin/tailwindcss ]; then
    curl -sL "https://github.com/tailwindlabs/tailwindcss/releases/download/v3.4.17/tailwindcss-linux-x64" -o /usr/local/bin/tailwindcss
    chmod +x /usr/local/bin/tailwindcss
fi
( cd "$APP_ROOT" && tailwindcss -i public/assets/css/input.css -o public/assets/css/app.css --minify )
chown frankenphp:webusers "$APP_ROOT/public/assets/css/app.css"

cat > "/etc/frankenphp/Caddyfile.d/panel.caddyfile" <<CADDY
https://$PANEL_HOSTNAME {
	tls internal
	root * $APP_ROOT/public
	encode zstd br gzip
	try_files {path} /index.php
	php_server
}

http://$PANEL_HOSTNAME {
	root * $APP_ROOT/public
	encode zstd br gzip
	try_files {path} /index.php
	php_server
}
CADDY

frankenphp reload --config /etc/frankenphp/Caddyfile --force || systemctl restart frankenphp
ok "JinnPanel deployed to $APP_ROOT"

# ---------------------------------------------------------------------------
# 8. Background config worker (runs via systemd, not as a FrankenPHP child -
#    see docs/ARCHITECTURE.md for why that split exists)
# ---------------------------------------------------------------------------

log "Installing the background config worker"
cp "$APP_SRC/worker/hostpanel-worker.php" /usr/local/bin/hostpanel-worker.php
chmod 755 /usr/local/bin/hostpanel-worker.php

mkdir -p /opt/php-versions
chown frankenphp:webusers /opt/php-versions
chmod 2775 /opt/php-versions
semanage fcontext -a -t httpd_sys_rw_content_t '/opt/php-versions(/.*)?' 2>/dev/null || true
restorecon -R /opt/php-versions

# Scratch space for WHM > cPanel Migration: backups are received/downloaded
# and unpacked here by the migration runner (frankenphp:webusers), and in
# push mode SFTPGo (also in webusers) writes incoming backups into it.
mkdir -p /var/lib/jinnpanel/migrations
chown frankenphp:webusers /var/lib/jinnpanel /var/lib/jinnpanel/migrations
chmod 2770 /var/lib/jinnpanel/migrations
semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/jinnpanel(/.*)?' 2>/dev/null || true
restorecon -R /var/lib/jinnpanel
chmod 755 "$APP_ROOT/worker/migration-runner.php" 2>/dev/null || true

# The migration runner is a PHP CLI script: make sure the CLI has what it needs.
php_cli_missing() {
    # Written to a file first: `php -m | grep -q` under pipefail can report
    # a false failure when grep exits early and php gets SIGPIPE.
    local mods missing=""
    mods=$(mktemp)
    /usr/bin/php -m > "$mods" 2>/dev/null || true
    for ext in curl openssl pdo_mysql mbstring; do
        grep -qix "$ext" "$mods" || missing="$missing $ext"
    done
    rm -f "$mods"
    printf '%s' "$missing"
}
MISSING_EXT=$(php_cli_missing)
if [ -n "$MISSING_EXT" ]; then
    for ext in $MISSING_EXT; do dnf -y install "php-zts-$ext" >/dev/null 2>&1 || true; done
    MISSING_EXT=$(php_cli_missing)
fi
if [ -n "$MISSING_EXT" ]; then
    warn "PHP CLI is missing:$MISSING_EXT - WHM > cPanel Migration won't run until these extensions are installed for /usr/bin/php."
else
    ok "PHP CLI has everything the cPanel migration runner needs"
fi

cat > /etc/systemd/system/hostpanel-worker.service <<'UNIT'
[Unit]
Description=JinnPanel - apply queued config changes (DNS zones, PHP/MariaDB/SFTPGo settings, service restarts)

[Service]
Type=oneshot
ExecStart=/usr/bin/php /usr/local/bin/hostpanel-worker.php
UNIT

cat > /etc/systemd/system/hostpanel-worker.timer <<'UNIT'
[Unit]
Description=Run the JinnPanel config worker every 5 seconds

[Timer]
OnBootSec=5s
OnUnitActiveSec=5s
AccuracySec=1s

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now hostpanel-worker.timer
ok "Background worker running (every 5s)"

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------

{
    echo "MARIADB_ROOT_PASSWORD=$MARIADB_ROOT_PW"
    echo "HOSTPANEL_APP_DB_PASSWORD=$DB_APP_PASS"
    echo "HOSTPANEL_PROV_DB_PASSWORD=$DB_PROV_PASS"
    echo "STALWART_ADMIN=$MAIL_ADMIN_USER"
    echo "STALWART_ADMIN_PASSWORD=$MAIL_ADMIN_PASS"
    echo "SFTPGO_ADMIN=panelapi"
    echo "SFTPGO_ADMIN_PASSWORD=$SFTPGO_ADMIN_PASS"
} > "$STATE_DIR/credentials.env"
chmod 600 "$STATE_DIR/credentials.env"

log "Install complete"
cat <<SUMMARY

  JinnPanel is deployed and every service is running.

  Next step (the only manual one):
    1. On the machine you'll browse from, map this server's IP to its
       hostname - e.g. on Windows add to C:\\Windows\\System32\\drivers\\etc\\hosts:
           $SERVER_IP  $HOSTNAME_FQDN  $PANEL_HOSTNAME
    2. Visit:  https://$PANEL_HOSTNAME/setup
       and create the real administrator account. That page IS the
       "get this into production" step - no admin account exists until
       you create one there.

  Internal service credentials (DB, Stalwart, SFTPGo admin APIs - not
  needed day-to-day, only for direct troubleshooting) were generated
  randomly and saved to:
       $STATE_DIR/credentials.env   (root-only, chmod 600)

  Full docs: see docs/ in this package (ARCHITECTURE.md, FEATURES.md,
  TROUBLESHOOTING.md).

SUMMARY
