<?php
declare(strict_types=1);

/**
 * Audits the box's actual specs and computes tuned settings for MariaDB,
 * PHP/OPcache, Stalwart, and SFTPGo for one of three profiles. Values are
 * formulas off real CPU/RAM, but always capped - this box runs five
 * services side by side, so "extreme" still has to leave them all room to
 * breathe rather than literally handing one service most of the RAM.
 */
final class TuningService
{
    public const PROFILES = ['balanced', 'performance', 'extreme'];

    public static function hardware(): array
    {
        $cpus = 0;
        $cpuinfo = @file('/proc/cpuinfo') ?: [];
        foreach ($cpuinfo as $line) {
            if (str_starts_with($line, 'processor')) {
                $cpus++;
            }
        }
        $cpus = max(1, $cpus);

        $memTotalKb = 0;
        foreach (@file('/proc/meminfo') ?: [] as $line) {
            if (str_starts_with($line, 'MemTotal:')) {
                $memTotalKb = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
            }
        }
        $memTotal = $memTotalKb * 1024;
        $diskTotal = @disk_total_space('/') ?: 0;

        return ['cpus' => $cpus, 'mem_total' => $memTotal, 'disk_total' => $diskTotal];
    }

    /** @return array{mysql:array,php:array,opcache:array,stalwart_system:array,stalwart_cache:array,sftpgo:array} */
    public static function compute(string $profile): array
    {
        $hw = self::hardware();
        $cpus = $hw['cpus'];
        $memMb = (int) ($hw['mem_total'] / 1024 / 1024);

        // Fraction of total RAM the InnoDB buffer pool gets, and a hard cap
        // in MB regardless of formula - this box shares RAM with 4 other
        // services, "extreme" here means "as fast as we responsibly can on
        // this hardware", not "give MySQL everything".
        [$bufferPoolFrac, $bufferPoolCapMb, $maxConn, $phpMemLimit, $opcacheMb, $jit, $threadMult, $sftpMaxConn] = match ($profile) {
            'balanced' => [0.25, 512, 100, 128, 96, false, 1, 100],
            'performance' => [0.40, 1024, 250, 256, 160, true, 2, 500],
            'extreme' => [0.55, 1536, 500, 512, 256, true, 4, 2000],
        };

        $bufferPoolMb = min($bufferPoolCapMb, max(64, (int) ($memMb * $bufferPoolFrac)));

        $mysql = [
            'innodb_buffer_pool_size' => $bufferPoolMb . 'M',
            'innodb_log_file_size' => max(64, (int) ($bufferPoolMb * 0.25)) . 'M',
            'max_connections' => $maxConn,
            'thread_cache_size' => max(8, $cpus * 8),
            'table_open_cache' => $profile === 'extreme' ? 4000 : ($profile === 'performance' ? 2000 : 400),
            'innodb_flush_log_at_trx_commit' => $profile === 'extreme' ? 2 : 1,
            'innodb_flush_method' => 'O_DIRECT',
        ];

        $php = [
            'memory_limit' => $phpMemLimit . 'M',
            'max_execution_time' => $profile === 'balanced' ? 30 : 60,
            'realpath_cache_size' => '4096K',
            'realpath_cache_ttl' => 600,
        ];

        $opcache = [
            'opcache.enable' => 1,
            'opcache.memory_consumption' => $opcacheMb,
            'opcache.interned_strings_buffer' => max(8, (int) ($opcacheMb / 16)),
            'opcache.max_accelerated_files' => $profile === 'extreme' ? 20000 : ($profile === 'performance' ? 12000 : 8000),
            'opcache.validate_timestamps' => $profile === 'extreme' ? 0 : 1,
            'opcache.jit' => $jit ? 'tracing' : 'off',
            'opcache.jit_buffer_size' => $jit ? '64M' : '0',
        ];

        $stalwartSystem = [
            'threadPoolSize' => min(32, $cpus * $threadMult),
            'maxConnections' => $profile === 'extreme' ? 16384 : ($profile === 'performance' ? 8192 : 4096),
        ];

        $cacheSizeBytes = match ($profile) {
            'balanced' => 1_048_576,   // 1MB per cache
            'performance' => 4_194_304, // 4MB
            'extreme' => 10_485_760,    // 10MB
        };
        $stalwartCache = [
            'accounts' => $cacheSizeBytes,
            'accessTokens' => $cacheSizeBytes,
            'dnsIpv4' => $cacheSizeBytes,
            'dnsIpv6' => $cacheSizeBytes,
            'dnsMx' => $cacheSizeBytes,
        ];

        $sftpgo = [
            'common.max_total_connections' => $sftpMaxConn,
            'common.max_per_host_connections' => $profile === 'extreme' ? 200 : ($profile === 'performance' ? 50 : 20),
            'common.idle_timeout' => $profile === 'extreme' ? 5 : 15,
        ];

        return compact('mysql', 'php', 'opcache', 'stalwartSystem', 'stalwartCache', 'sftpgo') + ['hardware' => $hw];
    }

    public static function apply(string $profile): void
    {
        if (!in_array($profile, self::PROFILES, true)) {
            throw new InvalidArgumentException('Unknown profile: ' . $profile);
        }
        $t = self::compute($profile);

        SystemWorkerService::enqueue('tune-mysql', ['type' => 'set_mycnf', 'settings' => $t['mysql']]);
        SystemWorkerService::enqueue('tune-mysql-restart', ['type' => 'restart', 'service' => 'mariadb']);

        SystemWorkerService::enqueue('tune-php', ['type' => 'set_ini', 'file' => '/etc/php-zts/php.ini', 'settings' => $t['php']]);
        SystemWorkerService::enqueue('tune-opcache', ['type' => 'set_ini', 'file' => '/etc/php-zts/conf.d/opcache.ini', 'settings' => $t['opcache']]);
        SystemWorkerService::enqueue('tune-php-restart', ['type' => 'restart', 'service' => 'frankenphp']);

        SystemWorkerService::enqueue('tune-sftpgo', ['type' => 'set_sftpgo_json', 'settings' => $t['sftpgo']]);
        SystemWorkerService::enqueue('tune-sftpgo-restart', ['type' => 'restart', 'service' => 'sftpgo']);

        // Stalwart is a direct HTTP call, not queued - no fork involved, so
        // none of the FrankenPHP-child restrictions apply, and it takes
        // effect immediately with no restart needed.
        StalwartAdminService::set('x:SystemSettings', $t['stalwartSystem']);
        StalwartAdminService::set('x:Cache', $t['stalwartCache']);

        file_put_contents(
            __DIR__ . '/../../storage/logs/tuning.log',
            '[' . date('c') . "] applied profile=$profile " . json_encode($t) . "\n",
            FILE_APPEND
        );
    }
}
