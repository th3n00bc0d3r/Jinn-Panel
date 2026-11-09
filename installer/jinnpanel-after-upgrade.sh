#!/bin/bash
# JinnPanel upgrade guard - installed as /usr/local/sbin/jinnpanel-after-upgrade.
#
#   jinnpanel-after-upgrade repair   after a dnf transaction touched the web
#                                    stack (dnf post-transaction-actions runs
#                                    jinnpanel-after-upgrade.service): put the
#                                    new binaries into service safely, then check
#   jinnpanel-after-upgrade check    health check only (jinnpanel-healthcheck.timer)
#
# Why: a plain `dnf upgrade` once took down every index.html site (a new Caddy
# changed php_fastcgi's try_files fallback) and stopped the worker, cron and
# mail sync (php-zts-cli's upgrade deletes /usr/bin/php) - silently. See
# docs/OPERATIONS.md "After a dnf upgrade".
#
# Problems go to the journal (priority err), /var/lib/jinnpanel/health.txt and
# an SSH login banner (/etc/motd.d/jinnpanel-health); exit status 1.
set -uo pipefail

MODE=${1:-check}
CADDYFILE=/etc/frankenphp/Caddyfile
SITES_DIR=/var/lib/frankenphp/sites-enabled
ACCESS_LOG=/var/log/jinnpanel-web/access.log
STATUS=/var/lib/jinnpanel/health.txt
MOTD=/etc/motd.d/jinnpanel-health
PROBLEMS=()

say() { echo "$*"; }
problem() { PROBLEMS+=("$*"); logger -t jinnpanel-health -p daemon.err -- "$*"; echo "PROBLEM: $*" >&2; }

repair() {
    # php-zts-cli's postuninstall deletes the /usr/bin/php symlink on every
    # upgrade. JinnPanel itself runs /usr/bin/php-zts; this is for people.
    [ -e /usr/bin/php ] || ln -s php-zts /usr/bin/php
    systemctl daemon-reload

    # The FrankenPHP package doesn't restart the service: the new binary (and
    # its Caddy) only takes over on the next restart - possibly much later, at
    # a reboot. Do it now, while someone is looking, but only if the new
    # binary accepts the config; otherwise the old process keeps serving.
    if frankenphp validate --config "$CADDYFILE" >/dev/null 2>&1; then
        systemctl try-restart frankenphp && say "frankenphp restarted on $(frankenphp version | head -n1)"
    else
        problem "the new frankenphp rejects $CADDYFILE - NOT restarted (old version still serving). Run: frankenphp validate --config $CADDYFILE"
    fi
    # PHP-FPM masters keep the deleted old binary (its children crashed with
    # SIGABRT after an upgrade); nginx likewise. Their units validate first.
    systemctl try-restart 'jinnpanel-php-fpm@*.service' 'jinnpanel-static@*.service' || problem "restarting PHP-FPM / static servers failed"
    sleep 3
}

check_binaries() {
    local b
    for b in /usr/bin/php-zts /usr/bin/frankenphp /usr/sbin/php-fpm-zts /usr/sbin/nginx; do
        [ -x "$b" ] || problem "$b is missing"
    done
}

check_units() {
    local u
    # Run the worker once so a unit that can't even start shows up now, not
    # only when its timer fires.
    systemctl start hostpanel-worker.service >/dev/null 2>&1
    for u in $(systemctl list-units --failed --plain --no-legend --no-pager '*jinnpanel*' 'hostpanel*' frankenphp.service \
            mariadb.service knot.service stalwart*.service sftpgo.service valkey.service 2>/dev/null | awk '{print $1}'); do
        problem "unit $u has failed: $(systemctl show -p Result --value "$u") - journalctl -u $u"
    done
}

# Every site's home page through the local web server. 503/509 are the
# suspended / over-bandwidth pages; 404 or a PHP-FPM "File not found." on "/"
# is the failure this exists for.
check_sites() {
    local f host code body n=0 bad=0 tmp
    tmp=$(mktemp)
    for f in "$SITES_DIR"/*.caddyfile; do
        [ -e "$f" ] || continue
        host=$(head -n1 "$f" | grep -oE 'https://[^ ,{:]+' | head -n1 | sed 's#https://##')
        [ -n "$host" ] || continue
        n=$((n + 1))
        code=$(curl -sk -m 20 -o "$tmp" -w '%{http_code}' --resolve "$host:443:127.0.0.1" "https://$host/" 2>/dev/null)
        body=$(head -c 200 "$tmp")
        case "$code" in
            2??|3??|503|509) ;;
            *) problem "https://$host/ answers $code${body:+: ${body:0:60}}"; bad=$((bad + 1)); continue ;;
        esac
        [[ "$body" == "File not found."* ]] && { problem "https://$host/ answers PHP-FPM 'File not found.'"; bad=$((bad + 1)); }
    done
    rm -f "$tmp"
    say "sites: $n checked, $bad failing"
}

# Bandwidth accounting reads the access log; a log that stopped growing
# (e.g. Caddy not allowed to roll it) means usage silently counts nothing.
check_access_log() {
    [ -f "$ACCESS_LOG" ] || { problem "$ACCESS_LOG is missing"; return; }
    local age=$(( $(date +%s) - $(stat -c %Y "$ACCESS_LOG") ))
    [ "$age" -lt 86400 ] || problem "$ACCESS_LOG not written for $((age / 3600)) h - bandwidth accounting is not counting (journalctl -u frankenphp | grep 'log file')"
}

[ "$MODE" = repair ] && repair
case "$MODE" in repair|check) ;; *) echo "Usage: $0 [repair|check]" >&2; exit 2 ;; esac

check_binaries
check_units
check_sites
check_access_log

mkdir -p "$(dirname "$STATUS")"
if [ ${#PROBLEMS[@]} -eq 0 ]; then
    echo "OK $(date -u +%FT%TZ) ($MODE)" > "$STATUS"
    rm -f "$MOTD"
    say "JinnPanel health: OK"
    exit 0
fi
{
    echo "FAIL $(date -u +%FT%TZ) ($MODE)"
    printf ' - %s\n' "${PROBLEMS[@]}"
} > "$STATUS"
{
    echo "*** JinnPanel health check found ${#PROBLEMS[@]} problem(s) ($(date -u '+%F %H:%M') UTC):"
    printf '    - %s\n' "${PROBLEMS[@]:0:15}"
    [ ${#PROBLEMS[@]} -le 15 ] || echo "    ... see $STATUS"
    echo "    Re-check: jinnpanel-after-upgrade check   (docs/OPERATIONS.md, After a dnf upgrade)"
    echo
} > "$MOTD"
exit 1
