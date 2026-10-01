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
// Everything this root process writes for the panel to read (job logs, log
// snapshots, extension lists) goes here: root-owned, readable by the
// frankenphp group only. Never into the panel's own storage/, which
// frankenphp can write - a link planted there would redirect root's writes.
const OUT_DIR = '/var/lib/jinnpanel/worker';
const KNOT_ZONE_DIR = '/var/lib/knot';
const PHP_VERSIONS_DIR = '/opt/php-versions';
const APP_CONFIG = '/var/www/hostpanel/src/Config.php';
const MIGRATION_RUNNER = '/var/www/hostpanel/worker/migration-runner.php';
const MIGRATION_DIR = '/var/lib/jinnpanel/migrations';
// Per-account PHP: each hosting account is a Linux user (jp_<username>) whose
// PHP-FPM pools run its sites; see accountSync().
const SITES_BASE = '/var/www';
const ACCOUNT_HOME = '/var/lib/jinnpanel/php';
const FPM_ETC = '/etc/jinnpanel/php-fpm';
const FPM_RUN = '/run/jinnpanel-php';
const PAGECACHE_DIR = '/var/lib/jinnpanel-pagecache';
const SITE_INI_DIR = '/var/lib/frankenphp/site-ini';
const POOL_LIB = '/usr/local/lib/jinnpanel/pool';
// Each account's static files: its own nginx (jinnpanel-static@<username>),
// as its own user, with a per-domain response cache - see staticServerSync().
const STATIC_ETC = '/etc/jinnpanel/static';
const STATIC_RUN = '/run/jinnpanel-static';
const STATIC_CACHE_DIR = '/var/lib/jinnpanel-static-cache';
const LINUX_PREFIX = 'jp_';
// putenv too: with it, LD_PRELOAD + mail() starts arbitrary programs anyway.
const DEFAULT_DISABLED_FUNCTIONS = 'exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,dl,putenv';
// Top-level consts are defined when execution reaches them (unlike functions),
// so every constant the job loop below uses must be declared up here.
const DNS_DOMAIN_RE = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i';
const SERVICES = ['mariadb', 'frankenphp', 'stalwart', 'sftpgo', 'knot'];

/**
 * The settings WHM (Server Config, Server Tweaks) may change, each with the
 * shape its value must have. A job can't name any other key - root writes
 * these files, and php.ini is also read by root's own PHP CLI.
 */
const SIZE_RE = '/^\d{1,6}[KMG]?$/';
const INT_RE = '/^\d{1,9}$/';
const INI_KEYS = [
    '/etc/php-zts/php.ini' => [
        'memory_limit' => SIZE_RE, 'max_execution_time' => INT_RE, 'upload_max_filesize' => SIZE_RE,
        'post_max_size' => SIZE_RE, 'realpath_cache_size' => SIZE_RE, 'realpath_cache_ttl' => INT_RE,
    ],
    '/etc/php-zts/conf.d/opcache.ini' => [
        'opcache.enable' => '/^[01]$/', 'opcache.memory_consumption' => INT_RE, 'opcache.interned_strings_buffer' => INT_RE,
        'opcache.max_accelerated_files' => INT_RE, 'opcache.validate_timestamps' => '/^[01]$/', 'opcache.revalidate_freq' => INT_RE,
        'opcache.jit' => '/^(off|disable|tracing|function|on)$/', 'opcache.jit_buffer_size' => SIZE_RE,
    ],
];
const MYCNF_KEYS = [
    'innodb_buffer_pool_size' => SIZE_RE, 'innodb_log_file_size' => SIZE_RE, 'max_connections' => INT_RE,
    'thread_cache_size' => INT_RE, 'table_open_cache' => INT_RE, 'innodb_flush_log_at_trx_commit' => '/^[012]$/',
    'innodb_flush_method' => '/^(O_DIRECT|fsync|O_DSYNC)$/',
];
const SFTPGO_KEYS = ['common.max_total_connections', 'common.max_per_host_connections', 'common.idle_timeout'];

/** Extension packages an alternative PHP version gets (what sites commonly need). */
const ALT_EXTENSIONS = ['mysqlnd', 'mysqli', 'pdo', 'pdo_mysql', 'opcache', 'mbstring', 'gd', 'intl', 'zip', 'bcmath', 'gmp',
    'soap', 'sqlite3', 'pdo_sqlite', 'xml', 'dom', 'simplexml', 'xmlreader', 'xmlwriter', 'xsl', 'curl', 'openssl', 'sodium', 'exif',
    'fileinfo', 'iconv', 'ctype', 'tokenizer', 'bz2', 'gettext', 'ftp', 'calendar', 'phar', 'posix', 'sockets', 'redis', 'imagick', 'igbinary', 'apcu'];


umask(0022);
if (!is_dir(OUT_DIR)) {
    mkdir(OUT_DIR, 0750, true);
}
chown(OUT_DIR, 'root');
chgrp(OUT_DIR, 'frankenphp');
chmod(OUT_DIR, 0750);

// Command-line entry points (as root):
//   sync-accounts        install.sh: every account's runtime (Linux user, folders, pools) now
//   sync-account <id>    one account (operators: after fixing something by hand)
//   usage                measure usage now and apply hard disk quotas (also at boot)
//   backup <id>          one backup (its own systemd unit, started by backupStart)
//   restore <id> <parts> one restore (files,databases,mail)
//   backup-all           the daily run of every account and the server
if (PHP_SAPI === 'cli' && isset($argv[1]) && in_array($argv[1], ['sync-accounts', 'sync-account', 'usage', 'backup', 'restore', 'backup-all'], true)) {
    $echo = function (string $m): void {
        echo '[' . date('c') . "] $m\n";
    };
    switch ($argv[1]) {
        case 'sync-accounts':
            exit(accountsSyncAll($echo, true) > 0 ? 1 : 0);
        case 'sync-account':
            accountSync((int) ($argv[2] ?? 0), $echo);
            break;
        case 'usage':
            panelClasses();
            UsageService::refresh($echo);
            break;
        case 'backup':
            panelClasses();
            BackupService::execute((int) ($argv[2] ?? 0), $echo);
            break;
        case 'restore':
            panelClasses();
            foreach (BackupService::restore((int) ($argv[2] ?? 0), explode(',', (string) ($argv[3] ?? '')), $echo) as $domain) {
                siteFixOwner($domain, $echo); // restored files are the account's again
            }
            break;
        case 'backup-all':
            panelClasses();
            BackupService::runAll($echo);
            break;
    }
    exit(0);
}

snapshotLogs();
periodicTasks();

if (is_dir(QUEUE_DIR)) {
    foreach (glob(QUEUE_DIR . '/*.json') ?: [] as $jobFile) {
        $label = preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($jobFile, '.json'));
        $log = fn(string $msg) => writeOut("worker-{$label}.log", '[' . date('c') . "] $msg\n", true);
        $job = null;

        try {
            $job = readJob($jobFile);
            runJob($job, $log);
            $log('OK');
        } catch (Throwable $e) {
            $log('FAILED: ' . $e->getMessage());
            if (is_array($job) && ($job['type'] ?? '') === 'install_php_version' && isset($job['version'])) {
                setPhpVersionStatus((string) $job['version'], 'failed', $log);
            }
        }

        @unlink($jobFile);
    }
}

/**
 * A job file as the panel wrote it: a regular file (not a link) owned by
 * frankenphp, signed with APP_KEY (SystemWorkerService::enqueue). Anything
 * else is refused unread.
 */
function readJob(string $file): array
{
    $st = @lstat($file);
    $fp = posix_getpwnam('frankenphp');
    if ($st === false || ($st['mode'] & 0170000) !== 0100000 || $fp === false || !in_array($st['uid'], [0, $fp['uid']], true) || $st['size'] > 8 * 1048576) {
        throw new RuntimeException('Refusing a job file that is not a plain file of the panel');
    }
    $h = fopen($file, 'rb');
    $fst = $h ? fstat($h) : false;
    if ($fst === false || $fst['ino'] !== $st['ino'] || $fst['dev'] !== $st['dev']) {
        throw new RuntimeException('The job file changed while it was opened');
    }
    $raw = (string) stream_get_contents($h);
    fclose($h);
    $wrapped = json_decode($raw, true);
    if (!is_array($wrapped) || !is_string($wrapped['payload'] ?? null) || !is_string($wrapped['sig'] ?? null)) {
        throw new RuntimeException('Malformed (or unsigned) job file');
    }
    if (!hash_equals(hash_hmac('sha256', $wrapped['payload'], jobKey()), $wrapped['sig'])) {
        throw new RuntimeException('Bad job signature');
    }
    $job = json_decode($wrapped['payload'], true);
    if (!is_array($job) || !is_string($job['type'] ?? null)) {
        throw new RuntimeException('Malformed job');
    }
    return $job;
}

function jobKey(): string
{
    require_once APP_CONFIG;
    return hash_hkdf('sha256', (string) Config::APP_KEY, 32, 'jinnpanel-worker-jobs-v1');
}

function runJob(array $job, callable $log): void
{
    switch ($job['type']) {
        case 'dns_create':
            dnsApply((string) $job['domain'], true, $log);
            break;
        case 'dns_remove':
            dnsApply((string) $job['domain'], false, $log);
            break;
        case 'dns_write':
            dnsWrite((string) $job['domain'], (string) ($job['content'] ?? ''), $log);
            break;
        case 'set_ini':
            setIniValues((string) $job['file'], (array) $job['settings'], $log);
            break;
        case 'set_mycnf':
            writeMycnfDropin((array) $job['settings'], $log);
            break;
        case 'set_sftpgo_json':
            setSftpgoSettings((array) $job['settings'], $log);
            break;
        case 'restart':
            restartService((string) $job['service'], $log);
            break;
        case 'install_php_version':
            installPhpVersion((string) $job['version'], $log);
            break;
        case 'remove_php_version':
            removePhpVersion((string) $job['version'], $log);
            break;
        case 'pull_log':
            pullLog((string) $job['service'], (int) ($job['lines'] ?? 1000), $log);
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
        case 'routes_apply':
            routesApply($job, $log);
            break;
        case 'php_ext_list':
            phpExtList($log);
            break;
        case 'php_ext_change':
            phpExtChange((string) ($job['ext'] ?? ''), !empty($job['install']), $log);
            break;
        case 'mysql_firewall':
            mysqlFirewall((array) ($job['sources'] ?? []), $log);
            break;
        case 'routes_remove':
            routesRemove((string) ($job['domain'] ?? ''), $log);
            break;
        case 'account_sync':
            accountSync((int) ($job['user_id'] ?? 0), $log);
            break;
        case 'accounts_sync_all':
            accountsSyncAll($log);
            break;
        case 'account_remove':
            accountRemove((string) ($job['username'] ?? ''), (array) ($job['archive'] ?? []), $log);
            break;
        case 'site_fix_owner':
            siteFixOwner((string) ($job['domain'] ?? ''), $log);
            break;
        case 'site_remove':
            siteRemove((string) ($job['domain'] ?? ''), $log);
            break;
        case 'backup_run':
            backupStart((int) ($job['backup_id'] ?? 0), $log);
            break;
        case 'backup_all':
            startUnit('jinnpanel-backup-all', 'JinnPanel backup of everything', ['backup-all']);
            break;
        case 'backup_restore':
            backupRestoreStart((int) ($job['backup_id'] ?? 0), (array) ($job['parts'] ?? []), $log);
            break;
        default:
            throw new RuntimeException('Unknown job type: ' . $job['type']);
    }
}

/** Writes (or appends to) a file in OUT_DIR, readable by the panel. */
function writeOut(string $name, string $content, bool $append = false): void
{
    if (!preg_match('/^[a-zA-Z0-9_.-]{1,200}$/', $name)) {
        return;
    }
    $file = OUT_DIR . '/' . $name;
    if (is_link($file)) {
        @unlink($file);
    }
    file_put_contents($file, $content, $append ? FILE_APPEND : 0);
    @chgrp($file, 'frankenphp');
    @chmod($file, 0640);
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

/** @return array<string,string> only allowed keys with well-formed values */
function checkedSettings(array $settings, array $allowed): array
{
    $out = [];
    foreach ($settings as $key => $value) {
        $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        if (!isset($allowed[$key])) {
            throw new RuntimeException("Setting not allowed: $key");
        }
        if (!preg_match($allowed[$key], $value)) {
            throw new RuntimeException("Bad value for $key");
        }
        $out[$key] = $value;
    }
    return $out;
}

/** @param array<string,string> $settings */
function setIniValues(string $file, array $settings, callable $log): void
{
    if (!isset(INI_KEYS[$file])) {
        throw new RuntimeException('File not allowed: ' . $file);
    }
    $settings = checkedSettings($settings, INI_KEYS[$file]);
    $content = file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException('Cannot read ' . $file);
    }
    foreach ($settings as $key => $value) {
        $line = "$key = $value";
        $pattern = '/^;?\s*' . preg_quote($key, '/') . '\s*=.*$/m';
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
    $settings = checkedSettings($settings, MYCNF_KEYS);
    $lines = ["# Managed by JinnPanel Server Tweaks - do not edit by hand.", "[mysqld]"];
    foreach ($settings as $key => $value) {
        $lines[] = "$key = $value";
    }
    file_put_contents('/etc/my.cnf.d/99-hostpanel-tuning.cnf', implode("\n", $lines) . "\n");
    $log('wrote my.cnf.d/99-hostpanel-tuning.cnf with ' . count($settings) . ' setting(s)');
}

/** @param array<string,mixed> $settings dot.path.keys (SFTPGO_KEYS) => integer */
function setSftpgoSettings(array $settings, callable $log): void
{
    $file = '/etc/sftpgo/sftpgo.json';
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        throw new RuntimeException('Cannot parse ' . $file);
    }
    foreach ($settings as $path => $value) {
        if (!in_array($path, SFTPGO_KEYS, true) || !preg_match(INT_RE, (string) $value)) {
            throw new RuntimeException("Setting not allowed: $path");
        }
        [$section, $key] = explode('.', $path, 2);
        $data[$section][$key] = (int) $value;
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $log("wrote " . count($settings) . " setting(s) to $file");
}

/** Every jinnpanel-php-fpm@<tag> instance that's set up (default + alt versions). */
function fpmTags(): array
{
    $tags = [];
    foreach (glob(FPM_ETC . '/*/php-fpm.conf') ?: [] as $f) {
        $tags[] = basename(dirname($f));
    }
    return $tags;
}

function restartService(string $service, callable $log): void
{
    if (!in_array($service, SERVICES, true)) {
        throw new RuntimeException('Service not allowed: ' . $service);
    }
    run('systemctl restart ' . escapeshellarg($service));
    if ($service === 'frankenphp') {
        // PHP settings changed: the sites' PHP-FPM masters read php.ini at start too.
        foreach (fpmTags() as $tag) {
            exec('systemctl restart ' . escapeshellarg("jinnpanel-php-fpm@$tag.service") . ' 2>&1');
        }
    }
    $log("restarted $service");
}

/**
 * Sites run their PHP in PHP-FPM, one master per PHP version ("tag":
 * "default" for the packaged php-zts, "82" etc. for alternatives) with one
 * pool per hosting account. A master is jinnpanel-php-fpm@<tag>.service
 * (template unit from install.sh) reading FPM_ETC/<tag>/.
 */
function fpmMasterEnsure(string $tag, string $bin, string $ini = '', string $scanDir = ''): void
{
    if (!preg_match('/^(default|\d{2})$/', $tag)) {
        throw new RuntimeException("Bad PHP-FPM tag $tag");
    }
    $dir = FPM_ETC . "/$tag";
    @mkdir("$dir/pools", 0755, true);
    // The template unit runs the packaged php-fpm-zts; another version's
    // binary and ini come from a drop-in for that instance.
    $dropDir = "/etc/systemd/system/jinnpanel-php-fpm@$tag.service.d";
    if ($bin !== '/usr/sbin/php-fpm-zts') {
        $drop = "[Service]\nEnvironment=PHP_INI_SCAN_DIR=$scanDir\nExecStart=\nExecStart=$bin --nodaemonize --fpm-config $dir/php-fpm.conf -c $ini -d opcache.validate_permission=1\n";
        @mkdir($dropDir, 0755, true);
        if (@file_get_contents("$dropDir/version.conf") !== $drop) {
            file_put_contents("$dropDir/version.conf", $drop);
            run('systemctl daemon-reload');
        }
    }
    $conf = <<<CONF
    ; Managed by JinnPanel (hostpanel-worker) - one pool per hosting account in pools/.
    [global]
    pid = /run/jinnpanel-php/$tag/php-fpm.pid
    error_log = syslog
    syslog.ident = jinnpanel-php-$tag
    log_level = notice
    ; All accounts together; each pool has its own pm.max_children too.
    process.max = 96
    emergency_restart_threshold = 10
    emergency_restart_interval = 1m
    ; Reloads (a pool added or changed) let running requests finish.
    process_control_timeout = 20s
    daemonize = no
    include = $dir/pools/*.conf

    CONF;
    if (@file_get_contents("$dir/php-fpm.conf") !== $conf) {
        file_put_contents("$dir/php-fpm.conf", $conf);
    }
    // FPM refuses to start without any pool: a placeholder only root can reach.
    $idle = "[_idle]\nuser = nobody\ngroup = nobody\nlisten = /run/jinnpanel-php/$tag/_idle.sock\nlisten.owner = root\nlisten.group = root\nlisten.mode = 0600\npm = ondemand\npm.max_children = 1\n";
    if (@file_get_contents("$dir/pools/_idle.conf") !== $idle) {
        file_put_contents("$dir/pools/_idle.conf", $idle);
    }
    exec('systemctl is-enabled --quiet ' . escapeshellarg("jinnpanel-php-fpm@$tag.service"), $o, $code);
    if ($code !== 0) {
        run('systemctl enable --now ' . escapeshellarg("jinnpanel-php-fpm@$tag.service"));
    }
}

/** The PHP-FPM tag for a domain's php_version ('default' or e.g. '8.2' -> '82'). */
function fpmTag(string $phpVersion): string
{
    if ($phpVersion === 'default') {
        return 'default';
    }
    if (!preg_match('/^8\.\d$/', $phpVersion)) {
        throw new RuntimeException("Unsupported PHP version $phpVersion");
    }
    return str_replace('.', '', $phpVersion);
}

/**
 * Reload (graceful: running requests finish) or start one FPM master after
 * its pools changed, then wait until $username's socket is there.
 */
function fpmReload(string $tag, ?string $username = null): void
{
    exec('systemctl is-active --quiet ' . escapeshellarg("jinnpanel-php-fpm@$tag.service"), $o, $code);
    run('systemctl ' . ($code === 0 ? 'reload' : 'restart') . ' ' . escapeshellarg("jinnpanel-php-fpm@$tag.service"));
    if ($username !== null) {
        for ($i = 0; $i < 50 && !file_exists(FPM_RUN . "/$tag/$username.sock"); $i++) {
            usleep(200000);
            clearstatcache();
        }
    }
}

/**
 * Installs an additional PHP version for sites: its PHP-FPM binary and
 * common extensions, extracted from the static-php repo's RPMs into
 * /opt/php-versions/<version> (dnf can't install two versions side by side
 * - it would switch the default's packages), with its own php.ini, and
 * starts its FPM master. Accounts get pools in it when a domain selects it.
 */
function installPhpVersion(string $version, callable $log): void
{
    if (!preg_match('/^8\.\d$/', $version)) {
        throw new RuntimeException('Unsupported version: ' . $version);
    }
    $tag = fpmTag($version);
    $dir = PHP_VERSIONS_DIR . "/$version";
    // A private work folder (root's): what's unpacked here becomes a binary root starts.
    $tmp = trim((string) shell_exec('mktemp -d /root/.jinnpanel-phpver-XXXXXXXX'));
    if ($tmp === '' || !is_dir($tmp)) {
        throw new RuntimeException('Could not create a work folder');
    }

    $query = fn(string $pattern) => trim((string) shell_exec(
        'dnf repoquery -q --disable-modular-filtering --repo=static-php --location --latest-limit=1 ' . escapeshellarg($pattern) . ' 2>/dev/null'
    ));
    $fpm = $query("php-zts-fpm-$version.*");
    if ($fpm === '') {
        throw new RuntimeException("No PHP-FPM package for PHP $version in the static-php repo");
    }
    $urls = ['php-zts-fpm' => $fpm];
    $cli = $query("php-zts-cli-$version.*");
    if ($cli !== '') {
        $urls['php-zts-cli'] = $cli; // cron jobs of domains on this version
    }
    foreach (ALT_EXTENSIONS as $ext) {
        // Core extensions carry the PHP version; PECL ones a _<tag> suffix.
        $u = $query("php-zts-$ext-$version.*") ?: $query("php-zts-$ext-*_$tag-*");
        if ($u !== '') {
            $urls[$ext] = $u;
        }
    }
    foreach ($urls as $name => $url) {
        if (!preg_match('#^https://[A-Za-z0-9./_~%+-]+\.rpm$#', $url)) {
            throw new RuntimeException("Unexpected package URL for $name");
        }
        run('curl -fsSL ' . escapeshellarg($url) . ' -o ' . escapeshellarg("$tmp/$name.rpm"));
        run('cd ' . escapeshellarg($tmp) . ' && rpm2cpio ' . escapeshellarg("$name.rpm") . ' | cpio -idm --quiet');
    }
    $log('downloaded ' . count($urls) . ' package(s)');
    if (!is_file("$tmp/usr/sbin/php-fpm-zts")) {
        throw new RuntimeException('The PHP-FPM package did not contain php-fpm-zts');
    }

    run('rm -rf ' . escapeshellarg($dir) . ' && mkdir -p ' . escapeshellarg("$dir/modules") . ' ' . escapeshellarg("$dir/conf.d"));
    run('install -m 0755 ' . escapeshellarg("$tmp/usr/sbin/php-fpm-zts") . ' ' . escapeshellarg("$dir/php-fpm"));
    if (is_file("$tmp/usr/bin/php-zts")) {
        run('install -m 0755 ' . escapeshellarg("$tmp/usr/bin/php-zts") . ' ' . escapeshellarg("$dir/php"));
    }
    foreach (glob("$tmp/usr/lib64/php-zts/modules/*.so") ?: [] as $so) {
        copy($so, "$dir/modules/" . basename($so));
    }
    foreach (glob("$tmp/etc/php-zts/conf.d/*.ini") ?: [] as $ini) {
        copy($ini, "$dir/conf.d/" . basename($ini));
    }
    // The server's own settings files (site dispatcher, mail) apply to every version.
    foreach (glob('/etc/php-zts/conf.d/99-jinnpanel-*.ini') ?: [] as $ini) {
        copy($ini, "$dir/conf.d/" . basename($ini));
    }
    $phpIni = (string) @file_get_contents('/etc/php-zts/php.ini');
    $phpIni = preg_replace('/^\s*;?\s*extension_dir\s*=.*$/m', '', $phpIni) . "\nextension_dir = \"$dir/modules\"\n";
    file_put_contents("$dir/php.ini", $phpIni);
    run('chown -R root:root ' . escapeshellarg($dir) . ' && chmod -R go-w ' . escapeshellarg($dir));
    run("semanage fcontext -a -t httpd_exec_t '/opt/php-versions/[^/]+/php-fpm' 2>/dev/null; true");
    run('restorecon -R ' . escapeshellarg($dir) . ' 2>/dev/null; true');
    run('rm -rf ' . escapeshellarg($tmp));

    // A broken build must not become selectable: the binary has to run.
    exec(escapeshellarg("$dir/php-fpm") . ' -c ' . escapeshellarg("$dir/php.ini") . ' -t 2>&1', $out, $code);
    $env = 'PHP_INI_SCAN_DIR=' . escapeshellarg("$dir/conf.d");
    exec("$env " . escapeshellarg("$dir/php-fpm") . ' -c ' . escapeshellarg("$dir/php.ini") . ' -v 2>&1', $ver, $vcode);
    if ($vcode !== 0 || !preg_match('/^PHP ' . preg_quote($version, '/') . '\./', (string) ($ver[0] ?? ''))) {
        throw new RuntimeException("PHP $version doesn't run: " . implode(' ', array_slice($ver, 0, 3)));
    }

    fpmMasterEnsure($tag, "$dir/php-fpm", "$dir/php.ini", "$dir/conf.d");
    fpmReload($tag);
    setPhpVersionStatus($version, 'active', $log);
    $log("PHP $version installed (" . trim((string) ($ver[0] ?? '')) . ')');
}

function removePhpVersion(string $version, callable $log): void
{
    if (!preg_match('/^8\.\d$/', $version)) {
        throw new RuntimeException('Unsupported version: ' . $version);
    }
    $s = appDb()->prepare('SELECT COUNT(*) FROM domains WHERE php_version = ?');
    $s->execute([$version]);
    if ((int) $s->fetchColumn() > 0) {
        setPhpVersionStatus($version, 'active', $log);
        throw new RuntimeException("Domains still use PHP $version - move them to another version first");
    }
    $tag = fpmTag($version);
    exec('systemctl disable --now ' . escapeshellarg("jinnpanel-php-fpm@$tag.service") . ' 2>&1');
    run('rm -rf ' . escapeshellarg(FPM_ETC . "/$tag") . ' ' . escapeshellarg(PHP_VERSIONS_DIR . "/$version"));
    // Left over from the FrankenPHP-instance days, if any.
    $suffix = $tag;
    exec("systemctl disable --now frankenphp-php{$suffix}.service 2>/dev/null; rm -f /etc/systemd/system/frankenphp-php{$suffix}.service /usr/lib64/libphp-zts-{$suffix}.so; systemctl daemon-reload");

    deletePhpVersionRow($version, $log);
    $log("PHP $version removed");
}

/** Services whose journal WHM shows: the fixed ones and each PHP-FPM master. */
function logServices(): array
{
    $services = SERVICES;
    foreach (fpmTags() as $tag) {
        $services[] = "jinnpanel-php-fpm@$tag";
    }
    return $services;
}

function pullLog(string $service, int $lines, callable $log): void
{
    if (!in_array($service, logServices(), true)) {
        throw new RuntimeException('Service not allowed: ' . $service);
    }
    $lines = max(50, min(5000, $lines));
    exec('journalctl -u ' . escapeshellarg($service) . " -n $lines --no-pager --output=short-iso 2>&1", $out);
    writeOut('live-' . str_replace('@', '_', $service) . '.log', implode("\n", $out));
    $log("pulled $lines lines for $service");
}

/** Refreshes the rolling log snapshots the WHM dashboard reads - runs every cycle, not job-driven. */
function snapshotLogs(): void
{
    foreach (logServices() as $svc) {
        $lines = [];
        exec('journalctl -u ' . escapeshellarg($svc) . ' -n 200 --no-pager --output=short-iso 2>&1', $lines);
        writeOut('live-' . str_replace('@', '_', $svc) . '.log', implode("\n", $lines));
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
/**
 * cPanel > Domains > Routes: write a site's routing rules (root-owned, so
 * sites - which run as frankenphp - can't change them), re-render the site,
 * and reload only if Caddy accepts the whole config; otherwise put the
 * previous rules back. The rules are re-validated here: the job file comes
 * from a directory frankenphp can write.
 */
function routesApply(array $job, callable $log): void
{
    $domain = strtolower((string) ($job['domain'] ?? ''));
    if (!preg_match(DNS_DOMAIN_RE, $domain)) {
        throw new RuntimeException('Invalid domain');
    }
    $siteDir = realpath('/var/www/' . $domain);
    $docroot = realpath((string) ($job['docroot'] ?? ''));
    if ($siteDir === false || $docroot === false || !str_starts_with($docroot . '/', $siteDir . '/')) {
        throw new RuntimeException('Document root is outside the site folder');
    }
    require_once '/var/www/hostpanel/src/Services/HtaccessTranslator.php';
    $new = ['route' => $job['route'] ?? null, 'site' => $job['site'] ?? null];
    foreach ($new as $kind => $text) {
        if ($text !== null && (!is_string($text) || ($errors = HtaccessTranslator::validate($text, $docroot, $kind)))) {
            throw new RuntimeException("Rejected $kind rules: " . implode('; ', array_slice((array) ($errors ?? ['not text']), 0, 3)));
        }
    }
    $dir = '/var/lib/frankenphp/site-rules';
    $files = ['route' => "$dir/$domain.caddy", 'site' => "$dir/$domain.site.caddy"];
    $old = array_map(fn($f) => is_file($f) ? (string) file_get_contents($f) : null, $files);
    $put = function (array $contents) use ($files, $domain): void {
        foreach ($files as $kind => $f) {
            if ($contents[$kind] === null) {
                @unlink($f);
            } else {
                file_put_contents("$f.tmp", $contents[$kind]);
                chown("$f.tmp", 'root');
                chgrp("$f.tmp", 'root');
                chmod("$f.tmp", 0644);
                rename("$f.tmp", $f);
            }
        }
        exec('runuser -u frankenphp -- ' . escapeshellarg(PHP_BINARY) . ' /var/www/hostpanel/worker/vhost-write.php ' . escapeshellarg($domain) . ' 2>&1', $o, $c);
        if ($c !== 0) {
            throw new RuntimeException('Re-rendering the site failed: ' . implode(' ', array_slice($o, -3)));
        }
    };
    $put($new);
    // As frankenphp: validating provisions the log files, which must not end up root's.
    exec('runuser -u frankenphp -- frankenphp validate --config /etc/frankenphp/Caddyfile 2>&1', $out, $code);
    if ($code !== 0) {
        $put($old);
        $msg = implode(' ', array_filter(array_map(fn($l) => preg_match('/Error:|error/i', $l) ? trim($l) : '', $out)));
        throw new RuntimeException('Caddy rejected the rules, the previous ones were kept: ' . mb_substr($msg !== '' ? $msg : implode(' ', array_slice($out, -2)), 0, 600));
    }
    caddyReload();
    $log("routes for $domain applied");
}

/** Installed and available php-zts-* extension packages, cached for WHM > PHP Extensions. */
function phpExtList(callable $log): void
{
    $names = function (string $what): array {
        exec('timeout 300 dnf -q list --' . $what . " 'php-zts-*' 2>/dev/null", $out);
        $n = [];
        foreach ($out as $line) {
            if (preg_match('/^php-zts-([a-z0-9_]+)\.(x86_64|aarch64|noarch)\s/', $line, $m)) {
                $n[] = $m[1];
            }
        }
        sort($n);
        return array_values(array_unique($n));
    };
    $skip = ['cli', 'embed', 'cgi', 'fpm', 'devel', 'dbg', 'common'];
    $installed = array_values(array_diff($names('installed'), $skip));
    $available = array_values(array_diff($names('available'), $skip, $installed));
    writeOut('php-extensions.json', json_encode(['updated' => time(), 'installed' => $installed, 'available' => $available]));
    $log(count($installed) . ' installed, ' . count($available) . ' available');
}

/** dnf install/remove one php-zts extension, then restart what runs the default PHP. */
function phpExtChange(string $ext, bool $install, callable $log): void
{
    $protected = ['pdo', 'pdo_mysql', 'mysqlnd', 'mysqli', 'gd', 'intl', 'zip', 'bcmath', 'gmp', 'soap', 'sqlite3', 'pdo_sqlite', 'xsl', 'bz2', 'gettext', 'ftp', 'imagick', 'cli', 'embed', 'cgi', 'fpm', 'devel', 'common'];
    if (!preg_match('/^[a-z0-9_]{2,40}$/', $ext) || (!$install && in_array($ext, $protected, true))) {
        throw new RuntimeException("Refusing to change php-zts-$ext");
    }
    run('dnf -y ' . ($install ? 'install' : 'remove') . ' ' . escapeshellarg("php-zts-$ext"));
    // A broken extension must not take the sites down: check the CLI loads it.
    exec('/usr/bin/php-zts -m 2>&1', $mods, $code);
    if ($code !== 0 || preg_grep('/PHP (Warning|Fatal).*Unable to load/i', $mods)) {
        if ($install) {
            exec('dnf -y remove ' . escapeshellarg("php-zts-$ext") . ' 2>&1');
        }
        throw new RuntimeException("php-zts-$ext doesn't load (" . implode(' ', array_slice($mods, 0, 2)) . ') - removed again');
    }
    run('systemctl restart frankenphp');
    exec('systemctl try-restart jinnpanel-webmail 2>&1');
    foreach (fpmTags() as $tag) {
        if ($tag === 'default') {
            exec('systemctl restart jinnpanel-php-fpm@default.service 2>&1');
        }
    }
    phpExtList(fn($m) => null);
    $log("php-zts-$ext " . ($install ? 'installed' : 'removed') . '; PHP restarted');
}

/**
 * Port 3306 only for the Remote MySQL hosts of all accounts: firewalld rich
 * rules, tracked in a state file so only rules this job added are removed.
 * "0.0.0.0/0" (a % host) opens the port to everyone.
 */
function mysqlFirewall(array $sources, callable $log): void
{
    $state = '/var/lib/jinnpanel/mysql-firewall.json';
    $old = is_file($state) ? (json_decode((string) file_get_contents($state), true) ?: []) : [];
    $want = [];
    foreach ($sources as $src) {
        $src = (string) $src;
        [$ip, $bits] = array_pad(explode('/', $src, 2), 2, null);
        $v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (!filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && (!ctype_digit($bits) || (int) $bits > ($v6 ? 128 : 32)))) {
            continue;
        }
        $want[$src] = sprintf('rule family="%s" source address="%s" port port="3306" protocol="tcp" accept', $v6 ? 'ipv6' : 'ipv4', $src);
        if ($src === '0.0.0.0/0') {
            $want['::/0'] = 'rule family="ipv6" source address="::/0" port port="3306" protocol="tcp" accept';
        }
    }
    foreach (array_diff($old, $want) as $rule) {
        exec('firewall-cmd --permanent --remove-rich-rule=' . escapeshellarg($rule) . ' 2>&1');
    }
    foreach (array_diff($want, $old) as $rule) {
        run('firewall-cmd --permanent --add-rich-rule=' . escapeshellarg($rule));
    }
    run('firewall-cmd --reload');
    @mkdir(dirname($state), 0750, true);
    file_put_contents($state, json_encode(array_values($want)));
    $log('3306 open for: ' . (implode(', ', array_keys($want)) ?: 'nobody'));
}

/** A removed domain's routing rules (only once the domain is really gone). */
function routesRemove(string $domain, callable $log): void
{
    $domain = strtolower($domain);
    if (!preg_match(DNS_DOMAIN_RE, $domain)) {
        throw new RuntimeException('Invalid domain');
    }
    $s = appDb()->prepare('SELECT COUNT(*) FROM domains WHERE domain_name = ?');
    $s->execute([$domain]);
    if ((int) $s->fetchColumn() > 0) {
        throw new RuntimeException("$domain is still hosted - not removing its rules");
    }
    foreach (["$domain.caddy", "$domain.site.caddy"] as $f) {
        @unlink("/var/lib/frankenphp/site-rules/$f");
    }
    $log("routes for $domain removed");
}

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
        . ' --uid=frankenphp --gid=frankenphp --property=Nice=10 --property=UMask=0027'
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
            . ' --uid=frankenphp --gid=frankenphp'
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

// ---------------------------------------------------------------------------
// Hosting accounts: Linux user, site folders, PHP-FPM pools
// ---------------------------------------------------------------------------

/**
 * Makes the system match the panel DB for one hosting account, as root:
 *  - Linux user + group jp_<username> (UID >= 20000, no shell, no login);
 *  - its private PHP home (ACCOUNT_HOME/<username>: sessions, tmp);
 *  - each domain's /var/www/<domain> owned by it, 0750, readable by
 *    frankenphp (static files) through an ACL, by no other account;
 *  - each domain's page cache folder (the account writes, the panel purges);
 *  - one PHP-FPM pool per PHP version its domains use, running as the
 *    account user, confined (open_basedir) to its own sites; none while
 *    the account is suspended.
 * Idempotent; the job carries only the account id - everything else comes
 * from the DB, so a forged job can't name paths or users.
 */
function accountSync(int $userId, callable $log): void
{
    $pdo = appDb();
    $s = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'user'");
    $s->execute([$userId]);
    $user = $s->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new RuntimeException("No hosting account #$userId");
    }
    $username = (string) $user['username'];
    $lu = linuxUser($username);
    ensureLinuxUser($lu);
    $home = ACCOUNT_HOME . "/$username";
    ensureDir(ACCOUNT_HOME, 'root', 'root', 0711);
    ensureDir($home, $lu, $lu, 0750);
    ensureDir("$home/tmp", $lu, $lu, 0700);
    ensureDir("$home/sessions", $lu, $lu, 0700);
    ensureDir(PAGECACHE_DIR, 'root', 'root', 0711);

    $d = $pdo->prepare('SELECT domain_name, php_version, status FROM domains WHERE user_id = ? ORDER BY domain_name');
    $d->execute([$userId]);
    $domains = $d->fetchAll(PDO::FETCH_ASSOC);
    $active = $user['status'] === 'active';
    $byTag = [];
    foreach ($domains as $row) {
        $domain = strtolower((string) $row['domain_name']);
        if (!preg_match(DNS_DOMAIN_RE, $domain)) {
            continue;
        }
        ensureSiteDir($domain, $lu, $log);
        ensurePageCacheDir($domain, $lu);
        if ($active && $row['status'] === 'active') {
            try {
                $byTag[fpmTag((string) $row['php_version'])][] = $domain;
            } catch (Throwable $e) {
                $log("$domain: " . $e->getMessage() . ' - no PHP for it');
            }
        }
    }

    $changed = [];
    foreach (fpmTags() as $tag) {
        $file = FPM_ETC . "/$tag/pools/$username.conf";
        if (!isset($byTag[$tag]) && is_file($file)) {
            unlink($file);
            $changed[$tag] = true;
        }
    }
    foreach ($byTag as $tag => $names) {
        if (!is_file(FPM_ETC . "/$tag/php-fpm.conf")) {
            if ($tag !== 'default') {
                $log("PHP version tag $tag is not installed - its domains can't run PHP");
                continue;
            }
            fpmMasterEnsure('default', '/usr/sbin/php-fpm-zts');
        }
        $conf = poolConfig($username, $lu, $tag, $names, (int) ($user['php_exec'] ?? 0) === 1);
        $file = FPM_ETC . "/$tag/pools/$username.conf";
        if (@file_get_contents($file) !== $conf) {
            file_put_contents($file, $conf);
            $changed[$tag] = true;
        }
    }
    foreach (array_keys($changed) as $tag) {
        fpmReload((string) $tag, isset($byTag[$tag]) ? $username : null);
    }
    $siteNames = array_values(array_filter(array_map(fn($r) => strtolower((string) $r['domain_name']), $domains), fn($n) => preg_match(DNS_DOMAIN_RE, $n)));
    staticServerSync($username, $lu, $active ? $siteNames : []);
    $log("account $username synced: " . count($domains) . ' domain(s), pools: ' . (implode(', ', array_keys($byTag)) ?: 'none') . ($active ? '' : ' (suspended)'));
}

/**
 * Every account. With $switchSites (install.sh's `sync-accounts`), each
 * account's sites are re-rendered and Caddy reloaded right after its pool
 * is up - so a site is never left with its files handed over but its
 * vhost still on the old setup, nor pointing at a pool that isn't there.
 * Returns how many accounts failed.
 */
function accountsSyncAll(callable $log, bool $switchSites = false): int
{
    fpmMasterEnsure('default', '/usr/sbin/php-fpm-zts');
    $failed = 0;
    foreach (appDb()->query("SELECT id FROM users WHERE role = 'user' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try {
            accountSync((int) $id, $log);
            if ($switchSites) {
                $d = appDb()->prepare('SELECT domain_name FROM domains WHERE user_id = ?');
                $d->execute([$id]);
                foreach ($d->fetchAll(PDO::FETCH_COLUMN) as $domain) {
                    exec('runuser -u frankenphp -- ' . escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file= /var/www/hostpanel/worker/vhost-write.php ' . escapeshellarg((string) $domain) . ' 2>&1', $o, $c);
                    if ($c !== 0) {
                        throw new RuntimeException("rendering $domain failed: " . implode(' ', array_slice($o, -2)));
                    }
                }
                caddyReload();
            }
        } catch (Throwable $e) {
            $failed++;
            $log("account #$id FAILED: " . $e->getMessage());
        }
    }
    return $failed;
}

/**
 * An account was deleted: its pools and Linux user go, and its site folders
 * ($archive: its domains) are moved to /var/lib/jinnpanel/removed/, root-
 * only - left in place they'd belong to a bare UID the next account could get.
 */
function accountRemove(string $username, array $archive, callable $log): void
{
    $lu = linuxUser($username);
    $pw = posix_getpwnam($lu);
    $s = appDb()->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $s->execute([$username]);
    if ((int) $s->fetchColumn() > 0) {
        throw new RuntimeException("$username still exists - not removing it");
    }
    foreach (fpmTags() as $tag) {
        $file = FPM_ETC . "/$tag/pools/$username.conf";
        if (is_file($file)) {
            unlink($file);
            fpmReload($tag);
        }
    }
    staticServerSync($username, $lu, []);
    $moved = [];
    $dest = '/var/lib/jinnpanel/removed/' . $username . '-' . date('Ymd-His');
    foreach ($archive as $domain) {
        $domain = strtolower((string) $domain);
        $dir = SITES_BASE . "/$domain";
        if (!preg_match(DNS_DOMAIN_RE, $domain) || !is_dir($dir) || is_link($dir) || $pw === false || fileowner($dir) !== $pw['uid']) {
            continue;
        }
        $s = appDb()->prepare('SELECT COUNT(*) FROM domains WHERE domain_name = ?');
        $s->execute([$domain]);
        if ((int) $s->fetchColumn() > 0) {
            continue;
        }
        ensureDir(dirname($dest), 'root', 'root', 0700);
        ensureDir($dest, 'root', 'root', 0700);
        run('mv ' . escapeshellarg($dir) . ' ' . escapeshellarg("$dest/$domain"));
        run('chown -R -P -h root:root ' . escapeshellarg("$dest/$domain"));
        exec('rm -rf ' . escapeshellarg(PAGECACHE_DIR . "/$domain"));
        $moved[] = $domain;
    }
    if ($pw !== false) {
        for ($i = 0; ; $i++) {
            exec('pkill -KILL -u ' . escapeshellarg($lu) . ' 2>/dev/null');
            exec('userdel ' . escapeshellarg($lu) . ' 2>&1', $o, $code);
            if ($code === 0 || $i >= 5) {
                break;
            }
            sleep(1); // processes still exiting ("user is currently used")
        }
        if ($code !== 0) {
            throw new RuntimeException("userdel $lu failed: " . implode(' ', $o));
        }
    }
    exec('rm -rf ' . escapeshellarg(ACCOUNT_HOME . "/$username") . ' ' . escapeshellarg("/var/lib/jinnpanel/sftp/$username"));
    $log("account $username removed (Linux user, pools)" . ($moved ? '; site folders moved to ' . $dest . ': ' . implode(', ', $moved) : ''));
}

function linuxUser(string $username): string
{
    if (!preg_match('/^[a-z][a-z0-9_]{1,27}$/', $username)) {
        throw new RuntimeException("Unusable account username \"$username\"");
    }
    return LINUX_PREFIX . $username;
}

function ensureLinuxUser(string $lu): void
{
    if (posix_getpwnam($lu) !== false) {
        return;
    }
    run('useradd -K UID_MIN=20000 -K UID_MAX=59999 -K GID_MIN=20000 -K GID_MAX=59999 --user-group --no-create-home'
        . ' --home-dir ' . escapeshellarg(ACCOUNT_HOME . '/' . substr($lu, strlen(LINUX_PREFIX)))
        . ' --shell /sbin/nologin --comment ' . escapeshellarg('JinnPanel hosting account') . ' ' . escapeshellarg($lu));
    run('passwd -l ' . escapeshellarg($lu) . ' >/dev/null');
}

function ensureDir(string $dir, string $owner, string $group, int $mode): void
{
    if (is_link($dir)) {
        throw new RuntimeException("$dir is a link - refusing");
    }
    if (!is_dir($dir)) {
        mkdir($dir, $mode, true);
    }
    chown($dir, $owner);
    chgrp($dir, $group);
    chmod($dir, $mode);
}

/**
 * /var/www/<domain>: the account owns everything in it. The top folder is
 * 0750; frankenphp (Caddy serving static files, the panel reading) gets in
 * through an ACL, inherited by everything created inside - other accounts
 * can't even list it. New folders get public/ (with a placeholder page) and
 * logs/. A folder still owned by someone else (made by the panel or a
 * migration) is handed over. Root only ever changes owners (never through
 * a link); modes and ACLs are set by the account itself, so a link it
 * swaps in can't aim root at anything.
 */
function ensureSiteDir(string $domain, string $lu, callable $log): void
{
    if (!preg_match(DNS_DOMAIN_RE, $domain) || $domain === 'hostpanel') {
        throw new RuntimeException("Bad site folder name $domain");
    }
    $dir = SITES_BASE . "/$domain";
    if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
        throw new RuntimeException("$dir is not a folder - refusing");
    }
    $pw = posix_getpwnam($lu);
    $created = false;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0750)) {
            throw new RuntimeException("Cannot create $dir");
        }
        lchown($dir, $lu);
        lchgrp($dir, $lu);
        $created = true;
    }
    $st = lstat($dir);
    if (($st['mode'] & 0170000) !== 0040000) {
        throw new RuntimeException("$dir is not a folder - refusing");
    }
    if ($st['uid'] !== $pw['uid'] || $st['gid'] !== $pw['gid']) {
        siteHandOver($dir, $lu);
        $log("$domain: files handed over to $lu");
    } elseif ($created) {
        siteGrantWeb($dir, $lu);
    }
    if (!file_exists("$dir/public") && !is_link("$dir/public")) {
        runAs($lu, ['mkdir', "$dir/public"]);
        runAs($lu, ['sh', '-c', 'printf "%s" "$1" > "$2"', 'sh', "<?php\necho '<h1>$domain</h1><p>This domain is live. Replace this file to get started.</p>';\n", "$dir/public/index.php"]);
    }
    if (!file_exists("$dir/logs") && !is_link("$dir/logs")) {
        runAs($lu, ['mkdir', "$dir/logs"]);
    }
    runAs($lu, ['chmod', '0750', $dir]);
    runAs($lu, ['setfacl', '-m', 'u:frankenphp:rx,d:u:frankenphp:rX', $dir]);
}

/** Hands a whole site folder to the account: root changes owners only (links themselves, never what they point at). */
function siteHandOver(string $dir, string $lu): void
{
    run('chown -R -P -h ' . escapeshellarg("$lu:$lu") . ' ' . escapeshellarg($dir));
    siteGrantWeb($dir, $lu);
}

/**
 * As the account: nothing world-writable, no leftover setgid (the old
 * shared-group setup), and read access for frankenphp, inherited by what's
 * created later - Caddy opens files to see whether they exist when it
 * routes a request (try_files, file matchers). It never sends their
 * contents: static files come from the account's own static server (which
 * runs as the account, so a link to someone else's file gets nothing),
 * PHP from its pool.
 */
function siteGrantWeb(string $dir, string $lu): void
{
    runAs($lu, ['chmod', '-R', 'o-w,g-s', $dir]);
    runAs($lu, ['setfacl', '-R', '-P', '-m', 'u:frankenphp:rX,d:u:frankenphp:rX', $dir]);
}

/** Runs a command as the account user (argv array, no shell unless asked for). */
function runAs(string $lu, array $argv): void
{
    run('runuser -u ' . escapeshellarg($lu) . ' -- ' . implode(' ', array_map('escapeshellarg', $argv)));
}

/**
 * PAGECACHE_DIR/<domain>: written by the site's PHP, emptied by the panel
 * ("Clear cache") - so frankenphp gets rwX, inherited. PAGECACHE_DIR is
 * root's (0711): only root makes the entries; inside, the account sets
 * the ACLs itself.
 */
function ensurePageCacheDir(string $domain, string $lu): void
{
    $dir = PAGECACHE_DIR . "/$domain";
    if (is_link($dir)) {
        unlink($dir);
    }
    if (!is_dir($dir)) {
        mkdir($dir, 0770);
    }
    run('chown -R -P -h ' . escapeshellarg("$lu:$lu") . ' ' . escapeshellarg($dir));
    runAs($lu, ['chmod', '0770', $dir]);
    runAs($lu, ['setfacl', '-R', '-P', '-m', 'u:frankenphp:rwX,d:u:frankenphp:rwX,d:u::rwX,d:g::rwX', $dir]);
}

/**
 * The account's static file server: nginx running as the account's user,
 * so it can read the account's files and nothing else - a link in a site
 * folder pointing at another account's files (or the panel's) gets 403,
 * and links to files the account doesn't own are refused outright
 * (disable_symlinks if_not_owner). Caddy hands it every non-PHP request
 * (VhostService). Two servers on Unix sockets in STATIC_RUN/<username>/
 * (jp_<name>:frankenphp 0750 - only the web server can connect):
 *  - static.sock: a response cache per domain (compressed copies, the
 *    lifetime the site chose - header X-JP-TTL, 0 = off);
 *  - files.sock: the files themselves, gzip-compressed.
 * No domains (or suspended): the server is stopped and disabled.
 *
 * @param list<string> $domains
 */
function staticServerSync(string $username, string $lu, array $domains): void
{
    $unit = "jinnpanel-static@$username.service";
    $conf = STATIC_ETC . "/$username.conf";
    if (!$domains) {
        exec('systemctl disable --now ' . escapeshellarg($unit) . ' 2>&1');
        @unlink($conf);
        return;
    }
    @mkdir(STATIC_ETC, 0755, true);
    ensureDir(STATIC_CACHE_DIR, 'root', 'root', 0711);
    $cacheBase = STATIC_CACHE_DIR . "/$username";
    if (is_link($cacheBase)) {
        unlink($cacheBase);
    }
    if (!is_dir($cacheBase)) {
        mkdir($cacheBase, 0700);
    }
    lchown($cacheBase, $lu);
    lchgrp($cacheBase, $lu);
    foreach ($domains as $d) {
        if (!is_dir("$cacheBase/$d")) {
            runAs($lu, ['mkdir', '-m', '0700', "$cacheBase/$d"]);
        }
    }
    runAs($lu, ['mkdir', '-p', '-m', '0700', ACCOUNT_HOME . "/$username/tmp/nginx"]);
    // Its sockets/pid folder: the account's, the web server may come in (nginx -t needs it too).
    ensureDir(STATIC_RUN, 'root', 'root', 0755);
    ensureDir(STATIC_RUN . "/$username", $lu, 'frankenphp', 0750);
    exec('restorecon -R ' . escapeshellarg(STATIC_RUN . "/$username") . ' 2>/dev/null');

    $text = staticConfig($username, $domains);
    $changed = @file_get_contents($conf) !== $text;
    if ($changed) {
        file_put_contents("$conf.new", $text);
        exec('runuser -u ' . escapeshellarg($lu) . ' -- /usr/sbin/nginx -t -q -c ' . escapeshellarg("$conf.new") . ' 2>&1', $out, $code);
        if ($code !== 0) {
            @unlink("$conf.new");
            throw new RuntimeException("nginx rejected the static server config of $username: " . implode(' ', array_slice($out, -2)));
        }
        rename("$conf.new", $conf);
    }
    exec('systemctl is-active --quiet ' . escapeshellarg($unit), $o, $active);
    if ($active !== 0) {
        run('systemctl enable --now ' . escapeshellarg($unit));
    } elseif ($changed) {
        run('systemctl reload ' . escapeshellarg($unit));
    }
    for ($i = 0; $i < 25 && !file_exists(STATIC_RUN . "/$username/static.sock"); $i++) {
        usleep(200000);
        clearstatcache();
    }
}

/** @param list<string> $domains */
function staticConfig(string $username, array $domains): string
{
    $run = STATIC_RUN . "/$username";
    $tmp = ACCOUNT_HOME . "/$username/tmp/nginx";
    $cache = STATIC_CACHE_DIR . "/$username";
    $alt = implode('|', array_map(fn($d) => preg_quote($d, '/'), $domains));
    $zones = '';
    $zoneMap = '';
    foreach (array_values($domains) as $i => $d) {
        $zones .= "    proxy_cache_path $cache/$d levels=1:2 keys_zone=jp$i:2m max_size=32m inactive=7d use_temp_path=off;\n";
        $zoneMap .= "        \"~^/var/www/" . preg_quote($d, '/') . "(/|\$)\" jp$i;\n";
    }
    return <<<CONF
    # Managed by JinnPanel (hostpanel-worker staticServerSync) - edits are overwritten.
    # Static files of account $username, served as jp_$username.
    worker_processes 1;
    pid $run/nginx.pid;
    error_log stderr error;
    events { worker_connections 1024; }
    http {
        include /etc/nginx/mime.types;
        default_type application/octet-stream;
        access_log off;
        server_tokens off;
        sendfile on;
        tcp_nopush on;
        absolute_redirect off;
        client_body_temp_path $tmp/body;
        proxy_temp_path $tmp/proxy;
        fastcgi_temp_path $tmp/fastcgi;
        uwsgi_temp_path $tmp/uwsgi;
        scgi_temp_path $tmp/scgi;
        open_file_cache max=4000 inactive=60s;
        open_file_cache_valid 10s;
        open_file_cache_errors on;

        # The document root Caddy sends: only this account's site folders.
        map \$http_x_jp_root \$jp_root {
            default /nonexistent;
            "~^/var/www/($alt)(/[^\\x00]*)?\$" \$http_x_jp_root;
        }
        map \$http_x_jp_root \$jp_zone {
            default off;
    $zoneMap    }
        map \$http_x_jp_ttl \$jp_nocache { default 0; "" 1; "0" 1; }
        map \$http_accept_encoding \$jp_enc { default ""; "~*gzip" gzip; }
    $zones
        # The cache: one zone per domain, lifetime from the site's setting.
        server {
            listen unix:$run/static.sock;
            # Cached descriptors would keep serving entries "Clear cache" just deleted.
            open_file_cache off;
            location / {
                proxy_pass http://unix:$run/files.sock;
                proxy_http_version 1.1;
                proxy_set_header Host \$host;
                proxy_set_header Accept-Encoding \$jp_enc;
                proxy_cache \$jp_zone;
                proxy_cache_key "\$host\$uri|\$jp_enc";
                proxy_cache_valid 200 301 302 5m;
                proxy_cache_revalidate on;
                proxy_cache_lock on;
                proxy_cache_use_stale error timeout updating;
                proxy_cache_bypass \$jp_nocache;
                proxy_no_cache \$jp_nocache;
                add_header X-JinnPanel-Static \$upstream_cache_status always;
            }
        }
        # The files, read with this account's rights; links to files it doesn't own are refused.
        server {
            listen unix:$run/files.sock;
            root \$jp_root;
            disable_symlinks if_not_owner;
            gzip on;
            gzip_proxied any;
            gzip_comp_level 6;
            gzip_min_length 512;
            gzip_vary on;
            gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml application/manifest+json image/svg+xml application/rss+xml application/atom+xml font/ttf font/otf application/vnd.ms-fontobject;
            # Never a PHP file's source (PHP goes to the pool; this is only reached by mistake).
            location ~* \.(php[0-9]?|phtml|phar|inc)(/|\$) {
                return 404;
            }
            location / {
                add_header X-Accel-Expires \$http_x_jp_ttl;
                try_files \$uri \$uri/ =404;
            }
        }
    }

    CONF;
}

/** @param list<string> $domains */
function poolConfig(string $username, string $lu, string $tag, array $domains, bool $allowExec): string
{
    $home = ACCOUNT_HOME . "/$username";
    $paths = [];
    foreach ($domains as $d) {
        $paths[] = SITES_BASE . "/$d/";
        $paths[] = PAGECACHE_DIR . "/$d/";
    }
    // Also every other domain of the account (a suspended or inactive one's
    // files may still be included by the active ones).
    $s = appDb()->prepare('SELECT d.domain_name FROM domains d JOIN users u ON u.id = d.user_id WHERE u.username = ?');
    $s->execute([$username]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) {
        if (preg_match(DNS_DOMAIN_RE, (string) $d)) {
            $paths[] = SITES_BASE . "/$d/";
        }
    }
    $paths = array_values(array_unique(array_merge($paths, ["$home/", SITE_INI_DIR . '/', POOL_LIB . '/', STATIC_CACHE_DIR . "/$username/", '/usr/share/pear/', '/usr/share/php/'])));
    $basedir = implode(':', $paths);
    $disabled = $allowExec ? '' : DEFAULT_DISABLED_FUNCTIONS;
    $sock = FPM_RUN . "/$tag/$username.sock";
    $poolLib = POOL_LIB . '/';
    return <<<POOL
    ; Managed by JinnPanel (hostpanel-worker accountSync) - edits are overwritten.
    [jp_$username]
    user = $lu
    group = $lu
    listen = $sock
    ; Only the web server (and the panel) may talk to this pool.
    listen.owner = frankenphp
    listen.group = frankenphp
    listen.mode = 0660
    pm = ondemand
    pm.max_children = 8
    pm.process_idle_timeout = 60s
    pm.max_requests = 1000
    request_terminate_timeout = 900s
    security.limit_extensions = .php
    clear_env = yes
    env[PATH] = /usr/local/bin:/usr/bin:/bin
    env[HOME] = $home
    env[TMPDIR] = $home/tmp
    php_admin_value[open_basedir] = $basedir
    php_admin_value[upload_tmp_dir] = $home/tmp
    php_admin_value[sys_temp_dir] = $home/tmp
    php_admin_value[session.save_path] = $home/sessions
    php_admin_value[soap.wsdl_cache_dir] = $home/tmp
    php_admin_value[disable_functions] = $disabled
    php_admin_flag[allow_url_include] = off
    ; mail() can't pass its own options to the mailer.
    php_admin_value[mail.force_extra_parameters] = -i
    ; APCu is shared by every pool of a master: off, so accounts can't read each other's.
    php_admin_flag[apc.enabled] = off
    ; Shared OPcache: only the panel's helper may use its API (status/reset).
    php_admin_value[opcache.restrict_api] = $poolLib

    POOL;
}

/** A migration (or the panel) put files into a site folder: hand them to the owning account. */
function siteFixOwner(string $domain, callable $log): void
{
    $domain = strtolower($domain);
    if (!preg_match(DNS_DOMAIN_RE, $domain)) {
        throw new RuntimeException('Invalid domain');
    }
    $s = appDb()->prepare("SELECT u.username FROM domains d JOIN users u ON u.id = d.user_id WHERE d.domain_name = ?");
    $s->execute([$domain]);
    $username = $s->fetchColumn();
    if (!is_string($username)) {
        throw new RuntimeException("$domain is not hosted here");
    }
    $lu = linuxUser($username);
    ensureLinuxUser($lu);
    $dir = SITES_BASE . "/$domain";
    if (!is_dir($dir) || is_link($dir)) {
        throw new RuntimeException("$dir is missing");
    }
    siteHandOver($dir, $lu);
    runAs($lu, ['chmod', '0750', $dir]);
    runAs($lu, ['setfacl', '-m', 'u:frankenphp:rx,d:u:frankenphp:rX', $dir]);
    $log("$domain: files handed over to $lu");
}

/** Deletes a site folder - only once its domain is no longer hosted. */
function siteRemove(string $domain, callable $log): void
{
    $domain = strtolower($domain);
    if (!preg_match(DNS_DOMAIN_RE, $domain) || $domain === 'hostpanel') {
        throw new RuntimeException('Invalid domain');
    }
    $s = appDb()->prepare('SELECT COUNT(*) FROM domains WHERE domain_name = ?');
    $s->execute([$domain]);
    if ((int) $s->fetchColumn() > 0) {
        throw new RuntimeException("$domain is still hosted - not deleting its files");
    }
    $dir = SITES_BASE . "/$domain";
    if (is_dir($dir) && !is_link($dir) && dirname(realpath($dir)) === SITES_BASE) {
        run('rm -rf --one-file-system ' . escapeshellarg($dir));
    }
    exec('rm -rf ' . escapeshellarg(PAGECACHE_DIR . "/$domain"));
    $log("$domain: site folder deleted");
}

/** Validate, then reload Caddy through its admin socket (never a TCP port local users could reach). */
function caddyReload(): void
{
    // As frankenphp: validating provisions the log files, which must not end up root's.
    exec('runuser -u frankenphp -- frankenphp validate --config /etc/frankenphp/Caddyfile 2>&1', $out, $code);
    if ($code !== 0) {
        $msg = implode(' ', array_filter($out, fn($l) => stripos($l, 'error') !== false));
        throw new RuntimeException('Caddy rejected the config, not reloaded: ' . mb_substr($msg !== '' ? $msg : implode(' ', array_slice($out, -2)), 0, 600));
    }
    run('frankenphp reload --config /etc/frankenphp/Caddyfile --address unix//run/frankenphp/admin.sock --force');
}

/** The panel's classes (BackupService, UsageService...) for root-side work. */
function panelClasses(): void
{
    require_once APP_CONFIG;
    require_once '/var/www/hostpanel/src/cli_bootstrap.php';
    require_once '/var/www/hostpanel/src/View.php'; // fmt_bytes()
}

/** Hourly: measured disk and bandwidth use per account (quotas). */
function usageRefresh(): void
{
    panelClasses();
    UsageService::refresh(fn(string $m) => writeOut('worker-usage.log', '[' . date('c') . "] $m\n", true));
}

/** Hourly: at the configured hour, the daily backup of everything (its own unit). */
function backupsDue(): void
{
    panelClasses();
    if (BackupService::dueNow()) {
        startUnit('jinnpanel-backup-all', 'JinnPanel daily backup', ['backup-all']);
    }
}

function backupStart(int $id, callable $log): void
{
    if ($id <= 0) {
        throw new RuntimeException('Invalid backup id');
    }
    startUnit("jinnpanel-backup-$id", "JinnPanel backup #$id", ['backup', (string) $id]);
    $log("backup #$id started");
}

function backupRestoreStart(int $id, array $parts, callable $log): void
{
    $parts = array_values(array_intersect(array_map('strval', $parts), ['files', 'databases', 'mail']));
    if ($id <= 0 || !$parts) {
        throw new RuntimeException('Invalid restore job');
    }
    startUnit("jinnpanel-restore-$id", "JinnPanel restore of backup #$id", ['restore', (string) $id, implode(',', $parts)]);
    $log("restore of backup #$id started (" . implode(', ', $parts) . ')');
}

/** Runs this script with $args in a transient systemd unit, as root, at low priority. */
function startUnit(string $unit, string $description, array $args): void
{
    exec('systemctl is-active --quiet ' . escapeshellarg("$unit.service"), $o, $code);
    if ($code === 0) {
        return;
    }
    exec('systemctl reset-failed ' . escapeshellarg("$unit.service") . ' 2>/dev/null');
    run('systemd-run --quiet --collect --unit=' . escapeshellarg($unit) . ' --description=' . escapeshellarg($description)
        . ' --property=Nice=15 --property=IOSchedulingClass=idle '
        . escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file= ' . escapeshellarg(__FILE__) . ' ' . implode(' ', array_map('escapeshellarg', $args)));
}

/** Things that run on their own schedule rather than as jobs. */
function periodicTasks(): void
{
    $stamp = OUT_DIR . '/.periodic-hourly';
    if (is_file($stamp) && filemtime($stamp) > time() - 3600) {
        return;
    }
    touch($stamp);
    try {
        usageRefresh();
    } catch (Throwable $e) {
        writeOut('worker-periodic.log', '[' . date('c') . '] usage: ' . $e->getMessage() . "\n", true);
    }
    try {
        backupsDue();
    } catch (Throwable $e) {
        writeOut('worker-periodic.log', '[' . date('c') . '] backups: ' . $e->getMessage() . "\n", true);
    }
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
