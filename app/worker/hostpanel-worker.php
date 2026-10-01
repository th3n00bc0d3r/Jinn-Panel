<?php
declare(strict_types=1);

/**
 * Runs every 5s via hostpanel-worker.timer (as root, via systemd - NOT as a
 * child of FrankenPHP). Two jobs each cycle:
 *  1) Drains storage/config-queue/*.json job files dropped by the panel
 *     app: rewriting php.ini/my.cnf/sftpgo.json, restarting services,
 *     registering Knot DNS zones, and installing/removing alt PHP version
 *     instances.
 *  2) Snapshots each service's recent journal into storage/logs/live-*.log
 *     so the WHM dashboard can show/"pull" logs without needing journal
 *     access itself.
 *
 * Two things push privileged work out of FrankenPHP's own process tree on
 * purpose:
 *  1) FrankenPHP is intentionally sandboxed (ProtectSystem=full, can't
 *     write to /etc) and can't query systemd/journald over D-Bus (SELinux
 *     denies it - by design, not a bug to route around).
 *  2) A verified FrankenPHP-specific quirk: forking a child from it that
 *     connect()s to a Unix domain socket (Knot's control socket) fails with
 *     EPERM - reproducible only in that exact combination. A worker spawned
 *     by systemd sidesteps both issues at once.
 */

const QUEUE_DIR = '/var/www/hostpanel/storage/config-queue';
const LOG_DIR = '/var/www/hostpanel/storage/logs';
const KNOT_ZONE_DIR = '/var/lib/knot';
const PHP_VERSIONS_DIR = '/opt/php-versions';
const APP_CONFIG = '/var/www/hostpanel/src/Config.php';
const MIGRATION_RUNNER = '/var/www/hostpanel/worker/migration-runner.php';
const MIGRATION_DIR = '/var/lib/jinnpanel/migrations';
// Top-level consts are defined when execution reaches them (unlike functions),
// so every constant the job loop below uses must be declared up here.
const DNS_DOMAIN_RE = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i';

snapshotLogs();

if (is_dir(QUEUE_DIR)) {
    foreach (glob(QUEUE_DIR . '/*.json') ?: [] as $jobFile) {
        $job = json_decode((string) file_get_contents($jobFile), true);
        $label = basename($jobFile, '.json');
        $log = fn(string $msg) => file_put_contents(LOG_DIR . "/worker-{$label}.log", '[' . date('c') . "] $msg\n", FILE_APPEND);

        try {
            if (!is_array($job) || !isset($job['type'])) {
                throw new RuntimeException('Malformed job file');
            }

            switch ($job['type']) {
                case 'dns_create':
                    dnsApply($job['domain'], true, $log);
                    break;
                case 'dns_remove':
                    dnsApply($job['domain'], false, $log);
                    break;
                case 'dns_write':
                    dnsWrite((string) $job['domain'], (string) ($job['content'] ?? ''), $log);
                    break;
                case 'set_ini':
                    setIniValues($job['file'], $job['settings'], $log);
                    break;
                case 'set_mycnf':
                    writeMycnfDropin($job['settings'], $log);
                    break;
                case 'set_sftpgo_json':
                    setJsonPaths('/etc/sftpgo/sftpgo.json', $job['settings'], $log);
                    break;
                case 'restart':
                    restartService($job['service'], $log);
                    break;
                case 'install_php_version':
                    installPhpVersion($job['version'], (int) $job['port'], (int) $job['admin_port'], $log);
                    break;
                case 'remove_php_version':
                    removePhpVersion($job['version'], $log);
                    break;
                case 'pull_log':
                    pullLog($job['service'], (int) ($job['lines'] ?? 1000), $log);
                    break;
                case 'migration_start':
                    startMigrationRunner((int) ($job['migration_id'] ?? 0), $log);
                    break;
                case 'migration_stop':
                    stopMigrationRunner((int) ($job['migration_id'] ?? 0), $log);
                    break;
                case 's3_fetch':
                    startS3Fetch((int) ($job['fetch_id'] ?? 0), $log);
                    break;
                default:
                    throw new RuntimeException('Unknown job type: ' . $job['type']);
            }
            $log('OK');
        } catch (Throwable $e) {
            $log('FAILED: ' . $e->getMessage());
            if ($job['type'] === 'install_php_version' && isset($job['version'])) {
                setPhpVersionStatus($job['version'], 'failed', $log);
            }
        }

        unlink($jobFile);
    }
}

/** Register (create) or unregister + delete (remove) a zone in Knot. */
function dnsApply(string $domain, bool $create, callable $log): void
{
    if (!preg_match(DNS_DOMAIN_RE, $domain)) {
        throw new RuntimeException('Invalid domain');
    }
    if ($create) {
        knotRegisterZone($domain);
        run('knotc zone-reload ' . escapeshellarg($domain));
    } else {
        if (knotZoneRegistered($domain)) {
            knotConf(fn() => run('knotc conf-unset ' . escapeshellarg("zone[$domain]")));
        }
        if (is_file(KNOT_ZONE_DIR . "/$domain.zone")) {
            unlink(KNOT_ZONE_DIR . "/$domain.zone");
        }
    }
    $log('dns ' . ($create ? 'create' : 'remove') . " $domain done");
}

/**
 * Write a zone rendered by DnsService (the panel DB is the source of truth),
 * register it with Knot if needed, check it and reload. The panel can't
 * write /var/lib/knot itself (knot:knot 0755), which is why this lives here.
 * If Knot can't load the new text, the previous file is put back, so one
 * bad record never takes a whole zone down.
 */
function dnsWrite(string $domain, string $content, callable $log): void
{
    if (!preg_match(DNS_DOMAIN_RE, $domain)) {
        throw new RuntimeException('Invalid domain');
    }
    if ($content === '' || strlen($content) > 4 * 1024 * 1024 || str_contains($content, "\0")) {
        throw new RuntimeException('Refusing to write an empty, oversized or binary zone');
    }
    $file = KNOT_ZONE_DIR . "/$domain.zone";
    $previous = is_file($file) ? file_get_contents($file) : false;
    $tmp = KNOT_ZONE_DIR . "/.$domain.zone.tmp";
    if (file_put_contents($tmp, $content) === false) {
        throw new RuntimeException("Cannot write $tmp");
    }
    chown($tmp, 'knot');
    chgrp($tmp, 'knot');
    chmod($tmp, 0640);
    if (!rename($tmp, $file)) {
        throw new RuntimeException("Cannot move the new zone into $file");
    }
    exec('restorecon ' . escapeshellarg($file) . ' 2>/dev/null');

    // A blocking reload is the check: if Knot can't parse the new file it
    // fails and keeps serving the zone it already had in memory. (knotc
    // zone-check can't be used - on Knot 3.5 it reports "no such zone" even
    // for loaded zones.)
    $wasRegistered = knotZoneRegistered($domain);
    try {
        if (!$wasRegistered) {
            knotRegisterZone($domain);
        }
        run('knotc -b zone-reload ' . escapeshellarg($domain));
    } catch (Throwable $e) {
        if ($previous !== false) {
            file_put_contents($file, $previous);
            exec('knotc -b zone-reload ' . escapeshellarg($domain) . ' 2>&1');
        } elseif (!$wasRegistered) {
            try {
                knotConf(fn() => run('knotc conf-unset ' . escapeshellarg("zone[$domain]")));
            } catch (Throwable) {
            }
            unlink($file);
        }
        throw new RuntimeException('Knot rejected the new zone, previous version kept: ' . $e->getMessage());
    }
    $log("dns write $domain done");
}

function knotZoneRegistered(string $domain): bool
{
    exec('knotc conf-read ' . escapeshellarg("zone[$domain]") . ' 2>&1', $out, $code);
    return $code === 0;
}

function knotRegisterZone(string $domain): void
{
    if (knotZoneRegistered($domain)) {
        return;
    }
    knotConf(function () use ($domain): void {
        run('knotc conf-set ' . escapeshellarg("zone[$domain]"));
        run('knotc conf-set ' . escapeshellarg("zone[$domain].file") . ' ' . escapeshellarg("$domain.zone"));
    });
}

/** Run knotc conf-* changes in one transaction; abort it on failure so the next job isn't locked out. */
function knotConf(callable $changes): void
{
    run('knotc conf-begin');
    try {
        $changes();
        run('knotc conf-commit');
    } catch (Throwable $e) {
        exec('knotc conf-abort 2>&1');
        throw $e;
    }
}

/** @param array<string,string> $settings */
function setIniValues(string $file, array $settings, callable $log): void
{
    $allowed = [
        '/etc/php-zts/php.ini',
        '/etc/php-zts/conf.d/opcache.ini',
    ];
    if (!in_array($file, $allowed, true)) {
        throw new RuntimeException('File not allowed: ' . $file);
    }
    $content = file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException('Cannot read ' . $file);
    }
    foreach ($settings as $key => $value) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $key)) {
            continue;
        }
        $escapedKey = preg_quote($key, '/');
        $line = "$key = $value";
        $pattern = '/^;?\s*' . $escapedKey . '\s*=.*$/m';
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $line, $content, 1);
        } else {
            $content .= "\n$line\n";
        }
    }
    file_put_contents($file, $content);
    $log("wrote " . count($settings) . " setting(s) to $file");
}

/** @param array<string,string> $settings */
function writeMycnfDropin(array $settings, callable $log): void
{
    $lines = ["# Managed by JinnPanel Server Tweaks - do not edit by hand.", "[mysqld]"];
    foreach ($settings as $key => $value) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $key)) {
            continue;
        }
        $lines[] = "$key = $value";
    }
    file_put_contents('/etc/my.cnf.d/99-hostpanel-tuning.cnf', implode("\n", $lines) . "\n");
    $log('wrote my.cnf.d/99-hostpanel-tuning.cnf with ' . count($settings) . ' setting(s)');
}

/** @param array<string,mixed> $settings dot.path.keys => value */
function setJsonPaths(string $file, array $settings, callable $log): void
{
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        throw new RuntimeException('Cannot parse ' . $file);
    }
    foreach ($settings as $path => $value) {
        $parts = explode('.', $path);
        $ref = &$data;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $ref[$part] = $value;
            } else {
                if (!isset($ref[$part]) || !is_array($ref[$part])) {
                    $ref[$part] = [];
                }
                $ref = &$ref[$part];
            }
        }
        unset($ref);
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $log("wrote " . count($settings) . " setting(s) to $file");
}

function restartService(string $service, callable $log): void
{
    $fixed = ['mariadb', 'frankenphp', 'stalwart', 'sftpgo', 'knot'];
    $isAltPhp = (bool) preg_match('/^frankenphp-php\d+$/', $service);
    if (!in_array($service, $fixed, true) && !$isAltPhp) {
        throw new RuntimeException('Service not allowed: ' . $service);
    }
    run('systemctl restart ' . escapeshellarg($service));
    $log("restarted $service");
}

/**
 * Installs an additional PHP version as its own isolated FrankenPHP
 * instance, loopback-only, reverse-proxied to by the main instance for
 * domains that select it. Each PHP version's shared lib has a
 * version-specific SONAME (libphp-zts-XX.so), so multiple versions can
 * coexist without touching the default (main) installation at all -
 * extracted directly from the upstream RPMs rather than `dnf install`,
 * which would try to switch the exclusive dnf module stream and clobber
 * the default version's packages.
 */
function installPhpVersion(string $version, int $port, int $adminPort, callable $log): void
{
    if (!preg_match('/^8\.\d$/', $version)) {
        throw new RuntimeException('Unsupported version: ' . $version);
    }
    $suffix = str_replace('.', '', $version); // "8.2" -> "82"
    $dir = PHP_VERSIONS_DIR . "/$version";
    $tmp = sys_get_temp_dir() . '/phpver-' . $suffix;

    run("rm -rf " . escapeshellarg($tmp) . " && mkdir -p " . escapeshellarg($tmp));

    $frankenphpUrl = trim(shell_exec(
        "dnf repoquery --disable-modular-filtering --repo=static-php --location --latest-limit=1 " . escapeshellarg("frankenphp-*_{$suffix}-*") . " 2>/dev/null"
    ) ?? '');
    $embedUrl = trim(shell_exec(
        "dnf repoquery --disable-modular-filtering --repo=static-php --location --latest-limit=1 " . escapeshellarg("php-zts-embed-{$version}*") . " 2>/dev/null"
    ) ?? '');
    if (!$frankenphpUrl || !$embedUrl) {
        throw new RuntimeException("No package found for PHP $version in the static-php repo");
    }
    $log("found $frankenphpUrl");
    $log("found $embedUrl");

    run("curl -fsSL " . escapeshellarg($frankenphpUrl) . " -o " . escapeshellarg("$tmp/frankenphp.rpm"));
    run("curl -fsSL " . escapeshellarg($embedUrl) . " -o " . escapeshellarg("$tmp/embed.rpm"));

    run("cd " . escapeshellarg($tmp) . " && rpm2cpio frankenphp.rpm | cpio -idm --quiet");
    run("cd " . escapeshellarg($tmp) . " && rpm2cpio embed.rpm | cpio -idm --quiet");

    $soFile = glob("$tmp/usr/lib64/libphp-zts-{$suffix}.so")[0] ?? null;
    $binFile = "$tmp/usr/bin/frankenphp";
    if (!$soFile || !is_file($soFile) || !is_file($binFile)) {
        throw new RuntimeException('Extracted RPM did not contain the expected files');
    }

    run("mkdir -p " . escapeshellarg("$dir/sites-enabled") . " " . escapeshellarg("$dir/public"));
    run("cp " . escapeshellarg($soFile) . " /usr/lib64/");
    run("cp " . escapeshellarg($binFile) . " " . escapeshellarg("$dir/frankenphp"));
    run("chmod 755 " . escapeshellarg("$dir/frankenphp"));
    run("ldconfig");

    // SELinux: the extracted binary needs the executable type (content
    // types like httpd_sys_rw_content_t, which the rest of this directory
    // correctly uses, deliberately can't be exec'd), and httpd_t may only
    // bind ports explicitly registered as http_port_t. Both are idempotent
    // - already-registered entries are silently ignored.
    run("semanage fcontext -a -t httpd_exec_t " . escapeshellarg('/opt/php-versions/[^/]+/frankenphp') . " 2>/dev/null; true");
    run("semanage port -a -t http_port_t -p tcp {$adminPort} 2>/dev/null; true");
    run("semanage port -a -t http_port_t -p tcp {$port} 2>/dev/null; true");
    run("semanage port -a -t http_port_t -p udp {$port} 2>/dev/null; true"); // HTTP/3 (QUIC)

    file_put_contents("$dir/Caddyfile", <<<CADDY
    {
    	frankenphp
    	admin 127.0.0.1:{$adminPort}
    }

    import {$dir}/sites-enabled/*.caddyfile
    CADDY);

    file_put_contents("$dir/public/index.php", "<?php\necho 'PHP $version instance is alive: ' . phpversion();\n");

    run("chown -R frankenphp:webusers " . escapeshellarg($dir));
    run("chmod 2775 " . escapeshellarg("$dir/sites-enabled") . " " . escapeshellarg("$dir/public"));
    run("semanage fcontext -a -t httpd_sys_rw_content_t " . escapeshellarg("$dir(/.*)?") . " 2>/dev/null; restorecon -R " . escapeshellarg($dir));

    $unit = <<<UNIT
    [Unit]
    Description=FrankenPHP (PHP {$version}) - JinnPanel alt version instance
    After=network.target

    [Service]
    Type=notify
    User=frankenphp
    Group=frankenphp
    ExecStartPre={$dir}/frankenphp validate --config {$dir}/Caddyfile
    ExecStart={$dir}/frankenphp run --config {$dir}/Caddyfile
    ExecReload={$dir}/frankenphp reload --config {$dir}/Caddyfile --address 127.0.0.1:{$adminPort} --force
    WorkingDirectory={$dir}
    Restart=on-failure
    RestartSec=3s
    PrivateTmp=true
    ProtectHome=true
    AmbientCapabilities=CAP_NET_BIND_SERVICE

    [Install]
    WantedBy=multi-user.target
    UNIT;
    file_put_contents("/etc/systemd/system/frankenphp-php{$suffix}.service", $unit);

    run('systemctl daemon-reload');
    run("systemctl enable --now frankenphp-php{$suffix}.service");
    run("rm -rf " . escapeshellarg($tmp));

    setPhpVersionStatus($version, 'active', $log);
    $log("PHP $version installed and running on 127.0.0.1:$port");
}

function removePhpVersion(string $version, callable $log): void
{
    if (!preg_match('/^8\.\d$/', $version)) {
        throw new RuntimeException('Unsupported version: ' . $version);
    }
    $suffix = str_replace('.', '', $version);
    $dir = PHP_VERSIONS_DIR . "/$version";

    run("systemctl stop frankenphp-php{$suffix}.service 2>/dev/null || true");
    run("systemctl disable frankenphp-php{$suffix}.service 2>/dev/null || true");
    run("rm -f /etc/systemd/system/frankenphp-php{$suffix}.service");
    run('systemctl daemon-reload');
    run("rm -rf " . escapeshellarg($dir));
    run("rm -f /usr/lib64/libphp-zts-{$suffix}.so");
    run('ldconfig');

    deletePhpVersionRow($version, $log);
    $log("PHP $version removed");
}

function pullLog(string $service, int $lines, callable $log): void
{
    $fixed = ['mariadb', 'frankenphp', 'stalwart', 'sftpgo', 'knot'];
    $isAltPhp = (bool) preg_match('/^frankenphp-php\d+$/', $service);
    if (!in_array($service, $fixed, true) && !$isAltPhp) {
        throw new RuntimeException('Service not allowed: ' . $service);
    }
    $lines = max(50, min(5000, $lines));
    exec('journalctl -u ' . escapeshellarg($service) . " -n $lines --no-pager --output=short-iso 2>&1", $out);
    file_put_contents(LOG_DIR . "/live-{$service}.log", implode("\n", $out));
    $log("pulled $lines lines for $service");
}

/** Refreshes the rolling log snapshots the WHM dashboard reads - runs every cycle, not job-driven. */
function snapshotLogs(): void
{
    $services = ['mariadb', 'frankenphp', 'stalwart', 'sftpgo', 'knot'];

    exec('systemctl list-units --type=service --all --no-legend "frankenphp-php*.service" 2>/dev/null', $altOut);
    foreach ($altOut as $line) {
        if (preg_match('/(frankenphp-php\d+)\.service/', $line, $m)) {
            $services[] = $m[1];
        }
    }

    foreach ($services as $svc) {
        exec('journalctl -u ' . escapeshellarg($svc) . ' -n 200 --no-pager --output=short-iso 2>&1', $lines);
        @file_put_contents(LOG_DIR . "/live-{$svc}.log", implode("\n", $lines));
        $lines = [];
    }
}

function setPhpVersionStatus(string $version, string $status, callable $log): void
{
    try {
        $pdo = appDb();
        $stmt = $pdo->prepare('UPDATE php_versions SET status = ? WHERE version = ?');
        $stmt->execute([$status, $version]);
    } catch (Throwable $e) {
        $log('warning: could not update php_versions status: ' . $e->getMessage());
    }
}

function deletePhpVersionRow(string $version, callable $log): void
{
    try {
        $pdo = appDb();
        $stmt = $pdo->prepare('DELETE FROM php_versions WHERE version = ?');
        $stmt->execute([$version]);
    } catch (Throwable $e) {
        $log('warning: could not delete php_versions row: ' . $e->getMessage());
    }
}

/**
 * cPanel migrations run for hours, so they get their own transient systemd
 * unit instead of blocking this 5-second worker: `jinnpanel-migration-<id>`,
 * running as frankenphp:webusers (the identity the panel already uses for
 * vhosts, databases and mail), outside FrankenPHP's sandbox and request
 * lifecycle. `journalctl -u jinnpanel-migration-<id>` shows its output.
 */
/** Background download of cPanel backups from S3 into the import folder (S3FetchService). */
function startS3Fetch(int $id, callable $log): void
{
    if ($id <= 0) {
        throw new RuntimeException('Invalid fetch id');
    }
    $unit = "jinnpanel-s3-fetch-$id";
    exec('systemctl is-active --quiet ' . escapeshellarg("$unit.service"), $out, $code);
    if ($code === 0) {
        $log("$unit is already running");
        return;
    }
    exec('systemctl reset-failed ' . escapeshellarg("$unit.service") . ' 2>/dev/null');
    run('systemd-run --quiet --collect'
        . ' --unit=' . escapeshellarg($unit)
        . ' --description=' . escapeshellarg("JinnPanel S3 backup fetch #$id")
        . ' --uid=frankenphp --gid=webusers --property=Nice=10 --property=UMask=0027'
        . ' --setenv=HOME=' . escapeshellarg(MIGRATION_DIR)
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(MIGRATION_RUNNER) . '/s3-fetch.php') . ' ' . $id);
    $log("started $unit");
}

function startMigrationRunner(int $id, callable $log): void
{
    if ($id <= 0) {
        throw new RuntimeException('Invalid migration id');
    }
    $unit = "jinnpanel-migration-$id";
    try {
        if (!is_file(MIGRATION_RUNNER)) {
            throw new RuntimeException(MIGRATION_RUNNER . ' is missing - re-run install.sh to deploy it.');
        }
        if (!is_dir(MIGRATION_DIR)) {
            mkdir(MIGRATION_DIR, 02770, true);
            chown(MIGRATION_DIR, 'frankenphp');
            chgrp(MIGRATION_DIR, 'webusers');
            chmod(MIGRATION_DIR, 02770);
            exec('restorecon -R ' . escapeshellarg(dirname(MIGRATION_DIR)) . ' 2>/dev/null');
        }

        exec('systemctl is-active --quiet ' . escapeshellarg("$unit.service"), $out, $code);
        if ($code === 0) {
            $log("$unit is already running");
            return;
        }
        exec('systemctl reset-failed ' . escapeshellarg("$unit.service") . ' 2>/dev/null');

        run('systemd-run --quiet --collect'
            . ' --unit=' . escapeshellarg($unit)
            . ' --description=' . escapeshellarg("JinnPanel cPanel migration #$id")
            . ' --uid=frankenphp --gid=webusers'
            . ' --property=Nice=10 --property=UMask=0002'
            . ' --setenv=HOME=' . escapeshellarg(MIGRATION_DIR)
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(MIGRATION_RUNNER) . ' ' . $id);
        $log("started $unit");
    } catch (Throwable $e) {
        // Don't leave the migration "queued" forever in the panel.
        try {
            $pdo = appDb();
            $pdo->prepare("UPDATE migration_items SET status = 'failed', step = 'Failed', error = ? WHERE migration_id = ? AND selected = 1 AND status = 'pending'")
                ->execute(['Could not start the migration runner: ' . $e->getMessage(), $id]);
            $pdo->prepare("UPDATE migrations SET status = 'failed', finished_at = NOW() WHERE id = ? AND status = 'queued'")->execute([$id]);
        } catch (Throwable $e2) {
            $log('warning: could not mark migration failed: ' . $e2->getMessage());
        }
        throw $e;
    }
}

function stopMigrationRunner(int $id, callable $log): void
{
    if ($id <= 0) {
        throw new RuntimeException('Invalid migration id');
    }
    $unit = "jinnpanel-migration-$id.service";
    exec('systemctl stop ' . escapeshellarg($unit) . ' 2>&1', $out);
    exec('systemctl reset-failed ' . escapeshellarg($unit) . ' 2>/dev/null');
    $log("stopped $unit");
}

function appDb(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    require_once APP_CONFIG;
    $pdo = new PDO(
        'mysql:host=' . Config::DB_HOST . ';dbname=' . Config::DB_NAME . ';charset=utf8mb4',
        Config::DB_USER,
        Config::DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

function run(string $cmd): void
{
    exec($cmd . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException("$cmd failed: " . implode(' | ', $output));
    }
}
