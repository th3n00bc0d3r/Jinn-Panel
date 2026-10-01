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
# Public IPv6 (empty if none): zones get AAAA records next to the A records.
SERVER_IPV6=$(ip -6 route get 2606:4700:4700::1111 2>/dev/null | awk '/src/ {for(i=1;i<=NF;i++) if ($i=="src") print $(i+1)}')
case "$SERVER_IPV6" in fe80:*|fd*|fc*|::1) SERVER_IPV6="" ;; esac
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
# bind-utils: dig, used to verify the server's own DNS zone at the end.
dnf -y install tar gzip rsync unzip dnf-plugins-core epel-release curl firewalld bind-utils
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
# Extensions customer sites expect on shared hosting (WordPress and most PHP
# apps need mysqli; gd/imagick/intl/zip are near-universal plugin requirements).
dnf -y install php-zts-mysqli php-zts-gd php-zts-imagick php-zts-intl php-zts-zip php-zts-bcmath \
    php-zts-gmp php-zts-soap php-zts-sqlite3 php-zts-pdo_sqlite php-zts-xsl php-zts-bz2 php-zts-gettext php-zts-ftp

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
# <domain>:2083 - each customer's own panel URL (<domain>/jpanel redirects
# there). The policy labels 2083 radsec_port_t; FrankenPHP runs as httpd_t.
# (udp too: Caddy also serves HTTP/3 there.)
firewall-cmd --permanent --add-port=2083/tcp --add-port=2083/udp >/dev/null
firewall-cmd --reload >/dev/null
for proto in tcp udp; do
    semanage port -a -t http_port_t -p $proto 2083 2>/dev/null || semanage port -m -t http_port_t -p $proto 2083
done

systemctl enable --now frankenphp
ok "FrankenPHP running"

# ---------------------------------------------------------------------------
# 4. Stalwart Mail
# ---------------------------------------------------------------------------

# Stalwart takes a few seconds to open its listeners after a (re)start.
# Asking too early made curl -s below write no file at all, and the JSON
# parse of that missing file aborted the whole install on a fresh box.
wait_for_stalwart() {
    for _ in $(seq 1 60); do
        curl -s -o /dev/null http://127.0.0.1:8080/jmap/session && return 0
        sleep 1
    done
    warn "Stalwart's HTTP listener (127.0.0.1:8080) didn't come up within 60s."
}

log "Installing Stalwart Mail"
if [ ! -f /usr/local/bin/stalwart ]; then
    curl --proto '=https' --tlsv1.2 -sSf https://get.stalw.art/install.sh -o /tmp/stalwart-install.sh
    sh /tmp/stalwart-install.sh
fi
systemctl enable --now stalwart
wait_for_stalwart

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
            wait_for_stalwart
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

# Stalwart's default listeners include HTTPS on :443, which belongs to
# FrankenPHP (the panel and every hosted site). Whichever starts first wins,
# so a fresh install - or any reboot - could leave the panel crash-looping on
# "address already in use". Move any Stalwart listener bound to :443 to
# :8443 (left closed in the firewall; the panel talks to Stalwart over
# 127.0.0.1:8080). The listener object is found in Stalwart's schema rather
# than hardcoded, and this is a no-op once nothing is bound to :443.
STALWART_443_RESULT=$(MAIL_ADMIN_USER="$MAIL_ADMIN_USER" MAIL_ADMIN_PASS="$MAIL_ADMIN_PASS" python3 - 2>&1 <<'PY'
import base64, gzip, json, os, re, urllib.request

BASE = "http://127.0.0.1:8080"
AUTH = "Basic " + base64.b64encode((os.environ["MAIL_ADMIN_USER"] + ":" + os.environ["MAIL_ADMIN_PASS"]).encode()).decode()
USING = ["urn:ietf:params:jmap:core", "urn:stalwart:jmap"]
BIND = re.compile(r"^(.*:)443$")


def call(method, path, body=None):
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(BASE + path, data=data, method=method,
                                 headers={"Authorization": AUTH, "Content-Type": "application/json",
                                          "Accept-Encoding": "identity"})
    with urllib.request.urlopen(req, timeout=20) as r:
        raw = r.read()
        # /api/schema can come back gzip-encoded regardless of Accept-Encoding.
        if r.headers.get("Content-Encoding", "").lower() == "gzip" or raw[:2] == b"\x1f\x8b":
            raw = gzip.decompress(raw)
        return json.loads(raw.decode() or "null")


def fix(v):
    """Rewrite ...:443 -> ...:8443 in a bind value (string, list or set-as-map)."""
    if isinstance(v, str):
        m = BIND.match(v)
        return (m.group(1) + "8443", True) if m else (v, False)
    if isinstance(v, list):
        out, changed = [], False
        for x in v:
            nx, c = fix(x)
            out.append(nx)
            changed |= c
        return out, changed
    if isinstance(v, dict):
        out, changed = {}, False
        for k, x in v.items():
            nk, ck = fix(k)
            nx, cx = fix(x)
            out[nk] = nx
            changed |= ck or cx
        return out, changed
    return v, False


schema = call("GET", "/api/schema")
names = sorted(n for n in (schema.get("schemas") or {}) if "listener" in n.lower())
if not names:
    print("SKIP no listener object in Stalwart's schema")
    raise SystemExit(0)
account = list(call("GET", "/jmap/session")["primaryAccounts"].values())[0]
moved = []
for obj in names:
    res = call("POST", "/jmap/", {"using": USING, "methodCalls": [[obj + "/get", {"accountId": account, "ids": None}, "0"]]})
    kind, body = res["methodResponses"][0][0], res["methodResponses"][0][1]
    if kind == "error":
        continue
    updates = {}
    for item in body.get("list", []):
        patch = {}
        for prop, val in item.items():
            if "bind" in prop.lower():
                nv, changed = fix(val)
                if changed:
                    patch[prop] = nv
        if patch:
            updates[item["id"]] = patch
            moved.append(str(item.get("name") or item["id"]))
    if updates:
        res = call("POST", "/jmap/", {"using": USING, "methodCalls": [[obj + "/set", {"accountId": account, "update": updates}, "0"]]})
        result = res["methodResponses"][0][1]
        if result.get("notUpdated"):
            print("FAIL " + json.dumps(result["notUpdated"]))
            raise SystemExit(1)
print(("MOVED " + " ".join(moved)) if moved else "OK")
PY
) || true
STALWART_443_LAST=$(printf '%s\n' "$STALWART_443_RESULT" | tail -n1)
case "$STALWART_443_LAST" in
    MOVED*)
        systemctl restart stalwart
        wait_for_stalwart
        systemctl reset-failed frankenphp 2>/dev/null || true
        systemctl restart frankenphp
        ok "Moved Stalwart's HTTPS listener off :443 to :8443 (${STALWART_443_LAST#MOVED })"
        ;;
    OK*)
        ok "Port 443 is free for the panel (no Stalwart listener on it)"
        ;;
    *)
        warn "Couldn't check Stalwart's listeners for :443 ($STALWART_443_LAST). If the panel doesn't load, move Stalwart's https listener to another port in its admin UI (Listeners)."
        ;;
esac

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
    -e "s/__SERVER_IPV6__/$SERVER_IPV6/" \
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

# Real Let's Encrypt certificate once the panel hostname resolves here
# publicly (Caddy's automatic HTTPS, HTTP-01/TLS-ALPN on 80/443); until then
# Caddy's local CA, which browsers warn about. A re-run upgrades it.
PANEL_TLS_LINE="	tls internal"
if [ "$(dig +short A "$PANEL_HOSTNAME" @1.1.1.1 2>/dev/null | tail -n1)" = "$SERVER_IP" ]; then
    PANEL_TLS_LINE=""
fi
cat > "/etc/frankenphp/Caddyfile.d/panel.caddyfile" <<CADDY
# The panel app, also served at https://<every hosted domain>:2083 (the
# site files import this snippet; Caddyfile.d is imported before them).
(jinnpanel_app) {
	root * $APP_ROOT/public
	encode zstd br gzip
	try_files {path} /index.php
	php_server
}

# The bare hostname: the MX and mail clients use it, so Caddy keeps a real
# certificate for it that MailDnsService hands to Stalwart.
https://$HOSTNAME_FQDN {
	redir https://$PANEL_HOSTNAME{uri}
}

https://$PANEL_HOSTNAME {
$PANEL_TLS_LINE
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
# Re-render every site file from the current template (and reload once).
runuser -u frankenphp -- php "$APP_ROOT/worker/vhost-rebuild.php" || warn "Rewriting the site configs failed - see $APP_ROOT/storage/logs/reload.log"
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
# import/ holds backups to restore without a source server (file mode,
# worker/migration-import.php); files there must be readable by frankenphp.
mkdir -p /var/lib/jinnpanel/migrations/import
chown frankenphp:webusers /var/lib/jinnpanel /var/lib/jinnpanel/migrations /var/lib/jinnpanel/migrations/import
chmod 2770 /var/lib/jinnpanel/migrations /var/lib/jinnpanel/migrations/import
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
# DNS: nameservers + this server's own zone
# ---------------------------------------------------------------------------
# Publishes the zone for the hostname's parent (server.example.com ->
# example.com) with ns1/ns2 glue, the hostname and panel.<hostname>, so the
# panel resolves as soon as the registrar's nameservers/glue point here -
# no hosts-file edits. Overrides: JINNPANEL_NS1_HOST / _NS1_IP / _NS2_HOST /
# _NS2_IP, JINNPANEL_DNS_ZONE. Later edits in WHM > DNS Zones are kept.

log "Publishing DNS for $HOSTNAME_FQDN"
# A second public IPv4 on the box, if there is one, is the natural home for ns2.
NS2_IP_DETECTED=$(ip -4 -o addr show scope global | awk '{split($4,a,"/"); print a[1]}' | grep -vxF "$SERVER_IP" | head -n1 || true)
DNS_LINE=$(cd "$APP_ROOT" && runuser -u frankenphp -- env \
    JINNPANEL_NS1_HOST="${JINNPANEL_NS1_HOST:-}" JINNPANEL_NS1_IP="${JINNPANEL_NS1_IP:-}" \
    JINNPANEL_NS2_HOST="${JINNPANEL_NS2_HOST:-}" JINNPANEL_NS2_IP="${JINNPANEL_NS2_IP:-}" \
    JINNPANEL_NS2_IP_DETECTED="$NS2_IP_DETECTED" JINNPANEL_DNS_ZONE="${JINNPANEL_DNS_ZONE:-}" \
    /usr/bin/php worker/dns-bootstrap.php | tail -n1) || DNS_LINE=""
# "-" as the zone means the hostname has no public parent zone; a missing or
# malformed line means the bootstrap itself failed - keep the two apart.
DNS_BOOTSTRAP_FAILED=0
if [ "$(wc -w <<< "$DNS_LINE")" -ne 5 ]; then
    DNS_BOOTSTRAP_FAILED=1
    warn "DNS bootstrap failed - no server DNS zone was published. See $APP_ROOT/storage/logs/app.log and the output above, then re-run the installer."
    DNS_LINE="- - - - -"
fi
read -r DNS_ZONE NS1_HOST NS1_IP NS2_HOST NS2_IP <<< "$DNS_LINE"

PUBLIC_A=""
if [ "$DNS_ZONE" != "-" ]; then
    DNS_SOA=""
    for _ in $(seq 1 30); do
        DNS_SOA=$(dig +norec +short SOA "$DNS_ZONE" @127.0.0.1 2>/dev/null || true)
        [ -n "$DNS_SOA" ] && break
        sleep 1
    done
    if [ -n "$DNS_SOA" ]; then
        ok "Knot is serving $DNS_ZONE (ns1 $NS1_HOST $NS1_IP, ns2 $NS2_HOST $NS2_IP)"
    else
        warn "Knot isn't answering for $DNS_ZONE yet - see WHM > DNS Zones for the last publish result."
    fi
    PUBLIC_A=$(dig +short A "$PANEL_HOSTNAME" @1.1.1.1 2>/dev/null | tail -n1 || true)
elif [ "$DNS_BOOTSTRAP_FAILED" = 0 ]; then
    warn "$HOSTNAME_FQDN isn't a public hostname - no server DNS zone was published."
fi

# ---------------------------------------------------------------------------
# Webmail (Cypht) at https://mail.<domain>/
# ---------------------------------------------------------------------------
# Its own FrankenPHP instance as the "webmail" user on 127.0.0.1:8009: Cypht
# putenv()s its settings, which in the shared FrankenPHP process would leak
# into every customer site, and this keeps it away from site files too.
# The main Caddy proxies mail.<domain> to it (MailDnsService writes those).
log "Installing webmail (Cypht)"
CYPHT_VERSION="2.12.2"
CYPHT_SHA256="2461f0c692d4c89e7107a0e7f4c8978e8682cecd208f00f04e47daf2436d4057"
WEBMAIL_HOME=/opt/jinnpanel-webmail
WEBMAIL_DATA=/var/lib/jinnpanel-webmail
CYPHT_DIR="$WEBMAIL_HOME/cypht-$CYPHT_VERSION"
id webmail >/dev/null 2>&1 || useradd --system --home-dir "$WEBMAIL_DATA" --shell /sbin/nologin webmail
mkdir -p "$WEBMAIL_HOME" "$WEBMAIL_DATA"/{users,attachments,app_data,sessions,caddy}
if [ ! -f "$CYPHT_DIR/index.php" ]; then
    curl -sL -o /tmp/cypht.tar.gz "https://github.com/cypht-org/cypht/releases/download/v$CYPHT_VERSION/cypht.tar.gz"
    if [ "$(sha256sum /tmp/cypht.tar.gz | awk '{print $1}')" != "$CYPHT_SHA256" ]; then
        rm -f /tmp/cypht.tar.gz
        warn "Cypht $CYPHT_VERSION download doesn't match its checksum - not installing webmail."
        exit 1
    fi
    mkdir -p "$CYPHT_DIR" && tar -xzf /tmp/cypht.tar.gz -C "$CYPHT_DIR" && rm -f /tmp/cypht.tar.gz
fi
cat > "$CYPHT_DIR/.env" <<ENV
APP_NAME=Webmail
ENABLE_DEBUG=false
LOG_LEVEL=WARNING
SESSION_TYPE=PHP
AUTH_TYPE=IMAP
IMAP_AUTH_NAME=Mail
IMAP_AUTH_SERVER=$HOSTNAME_FQDN
IMAP_AUTH_PORT=993
IMAP_AUTH_TLS=true
IMAP_AUTH_SIEVE_CONF_HOST=
DEFAULT_SMTP_NAME=Mail
DEFAULT_SMTP_SERVER=$HOSTNAME_FQDN
DEFAULT_SMTP_PORT=465
DEFAULT_SMTP_TLS=true
USER_CONFIG_TYPE=file
USER_SETTINGS_DIR=$WEBMAIL_DATA/users
ATTACHMENT_DIR=$WEBMAIL_DATA/attachments
APP_DATA_DIR=$WEBMAIL_DATA/app_data
CYPHT_MODULES=core,contacts,local_contacts,imap,smtp,account,idle_timer,themes,profiles,inline_message,imap_folders,keyboard_shortcuts,tags,saved_searches,advanced_search,highlights,history,brute_force
ENV
# Regenerates site/ for this path, with this install's own SITE_ID (the
# release ships the CI machine's).
( cd "$CYPHT_DIR" && php scripts/config_gen.php >/dev/null )
# "info" on mail.example.com logs in as info@example.com.
cat > "$WEBMAIL_HOME/prepend.php" <<'PHP'
<?php
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['username'], $_POST['password'])
    && is_string($_POST['username']) && $_POST['username'] !== '' && !str_contains($_POST['username'], '@')
    && preg_match('/^mail\.([a-z0-9.-]+)$/', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), $m)) {
    $_POST['username'] = trim($_POST['username']) . '@' . $m[1];
}
PHP
ln -sfn "$CYPHT_DIR" "$WEBMAIL_HOME/current"
chown -R root:webmail "$WEBMAIL_HOME" && chmod -R g+rX,o-rwx "$WEBMAIL_HOME"
chown -R webmail:webmail "$WEBMAIL_DATA" && chmod 0700 "$WEBMAIL_DATA"
semanage fcontext -a -t httpd_sys_content_t "$WEBMAIL_HOME(/.*)?" 2>/dev/null || true
semanage fcontext -a -t httpd_sys_rw_content_t "$WEBMAIL_DATA(/.*)?" 2>/dev/null || true
restorecon -R "$WEBMAIL_HOME" "$WEBMAIL_DATA"
mkdir -p /etc/jinnpanel
cat > /etc/jinnpanel/webmail.caddyfile <<CADDY
{
	admin off
	auto_https off
	storage file_system $WEBMAIL_DATA/caddy
	frankenphp {
		php_ini session.save_path $WEBMAIL_DATA/sessions
		php_ini auto_prepend_file $WEBMAIL_HOME/prepend.php
		php_ini upload_max_filesize 25M
		php_ini post_max_size 26M
	}
}

http://:8009 {
	bind 127.0.0.1
	root * $WEBMAIL_HOME/current/site
	encode zstd br gzip
	php_server {
		env HTTPS on
	}
}
CADDY
cat > /etc/systemd/system/jinnpanel-webmail.service <<UNIT
[Unit]
Description=JinnPanel webmail (Cypht) on 127.0.0.1:8009
After=network.target

[Service]
User=webmail
Group=webmail
Environment=XDG_DATA_HOME=$WEBMAIL_DATA XDG_CONFIG_HOME=$WEBMAIL_DATA
ExecStart=/usr/bin/frankenphp run --config /etc/jinnpanel/webmail.caddyfile
Restart=on-failure
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$WEBMAIL_DATA

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable jinnpanel-webmail >/dev/null 2>&1
systemctl restart jinnpanel-webmail
ok "Webmail (Cypht $CYPHT_VERSION) on 127.0.0.1:8009"

# Mail DNS (DKIM/SPF/DMARC/autoconfig), the autoconfig/MTA-STS site and
# Stalwart's certificate: now, and daily (DKIM keys rotate, certs renew).
log "Syncing mail DNS and the mail server certificate"
cat > /etc/systemd/system/jinnpanel-mail-dns.service <<UNIT
[Unit]
Description=JinnPanel - daily sync: mail DNS records, autoconfig site, mail TLS certificate, Let's Encrypt upgrades
After=stalwart.service frankenphp.service

[Service]
Type=oneshot
User=frankenphp
Group=webusers
ExecStart=/usr/bin/php $APP_ROOT/worker/mail-dns-sync.php
ExecStart=/usr/bin/php $APP_ROOT/worker/ssl-sync.php
UNIT
cat > /etc/systemd/system/jinnpanel-mail-dns.timer <<'UNIT'
[Unit]
Description=Daily JinnPanel mail DNS / certificate sync

[Timer]
OnBootSec=10min
OnUnitActiveSec=1d
RandomizedDelaySec=30min

[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable jinnpanel-mail-dns.timer >/dev/null 2>&1
systemctl start jinnpanel-mail-dns.timer
MAIL_SYNC_OUT=$(runuser -u frankenphp -- /usr/bin/php "$APP_ROOT/worker/mail-dns-sync.php" 2>&1) || warn "Mail DNS sync reported problems (below); the daily timer retries."
echo "$MAIL_SYNC_OUT" | grep -v '^Deprecated' | sed 's/^/    /'
# Stalwart binds new listeners (587) only at startup.
if grep -q 'submission on 587: added' <<< "$MAIL_SYNC_OUT"; then
    systemctl restart stalwart
fi

if [ "$PUBLIC_A" = "$SERVER_IP" ]; then
    DNS_NOTE="    1. DNS is live: $PANEL_HOSTNAME already resolves to $SERVER_IP."
elif [ "$DNS_BOOTSTRAP_FAILED" = 1 ]; then
    DNS_NOTE="    1. DNS: publishing the server zone failed (see
       $APP_ROOT/storage/logs/app.log); fix it and re-run the installer.
       Until then, map \"$SERVER_IP  $PANEL_HOSTNAME\" in your local hosts
       file to reach the panel."
elif [ "$DNS_ZONE" != "-" ]; then
    DNS_NOTE="    1. DNS: this server now serves the zone $DNS_ZONE. For $PANEL_HOSTNAME
       to resolve publicly, set at the registrar of $DNS_ZONE:
           nameservers   $NS1_HOST, $NS2_HOST
           glue records  $NS1_HOST -> $NS1_IP
                         $NS2_HOST -> $NS2_IP
       Until that propagates, map \"$SERVER_IP  $PANEL_HOSTNAME\" in your
       local hosts file to reach the panel."
else
    DNS_NOTE="    1. On the machine you'll browse from, map this server's IP to its
       hostname - e.g. on Windows add to C:\\Windows\\System32\\drivers\\etc\\hosts:
           $SERVER_IP  $HOSTNAME_FQDN  $PANEL_HOSTNAME"
fi

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

  Next steps:
$DNS_NOTE
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
