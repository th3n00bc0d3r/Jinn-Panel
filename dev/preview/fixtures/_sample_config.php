<?php
declare(strict_types=1);

/**
 * Sample data for the WHM > Configuration pages (whm/server_config/*).
 * Files starting with _ are not fixtures. Functions are prefixed cfg_ so
 * they can't clash with other sample files.
 */

/** A worker log line as SystemWorkerService::lastLog() returns it: "[ISO time] message". */
function cfg_log(string $message, int $secondsAgo = 600): string
{
    return '[' . date('c', time() - $secondsAgo) . '] ' . $message;
}

/** Hardware as TuningService::hardware() reports it: a 4-core, 8 GB, 160 GB VPS. */
function cfg_hardware(): array
{
    return ['cpus' => 4, 'mem_total' => 8 * 1024 ** 3, 'disk_total' => 160 * 1024 ** 3];
}

/**
 * What TuningService::compute() returns for $profile on cfg_hardware()
 * (same formulas; the real one reads /proc).
 */
function cfg_tuning(string $profile): array
{
    $hw = cfg_hardware();
    $cpus = $hw['cpus'];
    $memMb = (int) ($hw['mem_total'] / 1024 / 1024);
    [$frac, $capMb, $maxConn, $phpMem, $opcacheMb, $jit, $threadMult, $sftpMax] = match ($profile) {
        'balanced' => [0.25, 512, 100, 128, 96, false, 1, 100],
        'performance' => [0.40, 1024, 250, 256, 160, true, 2, 500],
        default => [0.55, 1536, 500, 512, 256, true, 4, 2000],
    };
    $pool = min($capMb, max(64, (int) ($memMb * $frac)));
    $cache = match ($profile) { 'balanced' => 1_048_576, 'performance' => 4_194_304, default => 10_485_760 };
    return [
        'mysql' => [
            'innodb_buffer_pool_size' => $pool . 'M',
            'innodb_log_file_size' => max(64, (int) ($pool * 0.25)) . 'M',
            'max_connections' => $maxConn,
            'thread_cache_size' => max(8, $cpus * 8),
            'table_open_cache' => $profile === 'extreme' ? 4000 : ($profile === 'performance' ? 2000 : 400),
            'innodb_flush_log_at_trx_commit' => $profile === 'extreme' ? 2 : 1,
            'innodb_flush_method' => 'O_DIRECT',
        ],
        'php' => [
            'memory_limit' => $phpMem . 'M',
            'max_execution_time' => $profile === 'balanced' ? 30 : 60,
            'realpath_cache_size' => '4096K',
            'realpath_cache_ttl' => 600,
        ],
        'opcache' => [
            'opcache.enable' => 1,
            'opcache.memory_consumption' => $opcacheMb,
            'opcache.interned_strings_buffer' => max(8, (int) ($opcacheMb / 16)),
            'opcache.max_accelerated_files' => $profile === 'extreme' ? 20000 : ($profile === 'performance' ? 12000 : 8000),
            'opcache.validate_timestamps' => $profile === 'extreme' ? 0 : 1,
            'opcache.jit' => $jit ? 'tracing' : 'off',
            'opcache.jit_buffer_size' => $jit ? '64M' : '0',
        ],
        'stalwartSystem' => [
            'threadPoolSize' => min(32, $cpus * $threadMult),
            'maxConnections' => $profile === 'extreme' ? 16384 : ($profile === 'performance' ? 8192 : 4096),
        ],
        'stalwartCache' => ['accounts' => $cache, 'accessTokens' => $cache, 'dnsIpv4' => $cache, 'dnsIpv6' => $cache, 'dnsMx' => $cache],
        'sftpgo' => [
            'common.max_total_connections' => $sftpMax,
            'common.max_per_host_connections' => $profile === 'extreme' ? 200 : ($profile === 'performance' ? 50 : 20),
            'common.idle_timeout' => $profile === 'extreme' ? 5 : 15,
        ],
        'hardware' => $hw,
    ];
}

/** A php_versions row. */
function cfg_php_version(string $version, string $status = 'active'): array
{
    return ['id' => (int) str_replace('.', '', $version), 'version' => $version, 'port' => 9000 + (int) str_replace('.', '', $version), 'status' => $status, 'created_at' => '2026-10-20 14:02:00'];
}

/**
 * A slice of Stalwart's /api/schema for one settings object: the field
 * definitions (fieldsFor()['properties']) and the enums they use.
 *
 * @return array{fields:array<string,array>, values:array<string,mixed>, enums:array<string,list<array{name:string,label:string}>>}
 */
function cfg_stalwart(string $object): array
{
    $enums = [
        'LogLevel' => [
            ['name' => 'error', 'label' => 'Error'], ['name' => 'warn', 'label' => 'Warning'], ['name' => 'info', 'label' => 'Info'],
            ['name' => 'debug', 'label' => 'Debug'], ['name' => 'trace', 'label' => 'Trace'],
        ],
        'IpLookupStrategy' => [
            ['name' => 'ipv4ThenIpv6', 'label' => 'IPv4 then IPv6'], ['name' => 'ipv6ThenIpv4', 'label' => 'IPv6 then IPv4'],
            ['name' => 'ipv4Only', 'label' => 'IPv4 only'], ['name' => 'ipv6Only', 'label' => 'IPv6 only'],
        ],
    ];
    $f = fn(string $type, string $desc, array $extra = []) => ['type' => ['type' => $type] + $extra, 'description' => $desc];

    return match ($object) {
        'x:Cache' => [
            'fields' => [
                'accounts' => $f('number', 'Maximum memory used by the account cache.', ['format' => 'size']),
                'accessTokens' => $f('number', 'Maximum memory used by the access token cache.', ['format' => 'size']),
                'dnsIpv4' => $f('number', 'Maximum memory used to cache IPv4 lookups.', ['format' => 'size']),
                'dnsIpv6' => $f('number', 'Maximum memory used to cache IPv6 lookups.', ['format' => 'size']),
                'dnsMx' => $f('number', 'Maximum memory used to cache MX lookups.', ['format' => 'size']),
            ],
            'values' => ['accounts' => 10_485_760, 'accessTokens' => 10_485_760, 'dnsIpv4' => 10_485_760, 'dnsIpv6' => 10_485_760, 'dnsMx' => 10_485_760],
            'enums' => $enums,
        ],
        'x:TaskManager' => [
            'fields' => [
                'id' => $f('string', 'Object id.') + ['update' => 'serverSet'],
            ],
            'values' => ['id' => 'singleton'],
            'enums' => $enums,
        ],
        default => [ // x:SystemSettings
            'fields' => [
                'id' => $f('string', 'Object id.') + ['update' => 'serverSet'],
                'hostname' => $f('string', 'The fully qualified host name this server announces in SMTP greetings and reports.'),
                'threadPoolSize' => $f('number', 'Number of worker threads. Defaults to the number of CPU cores.', ['nullable' => true]),
                'maxConnections' => $f('number', 'Maximum number of concurrent connections across all listeners.'),
                'spamScoreThreshold' => $f('number', 'Messages scoring above this are moved to Junk.'),
                'logLevel' => $f('enum', 'How much detail goes into the server log.', ['enumName' => 'LogLevel']),
                'ipStrategy' => $f('enum', 'Which address family to try first when connecting to other servers.', ['enumName' => 'IpLookupStrategy', 'nullable' => true]),
                'enableTelemetry' => $f('boolean', 'Send anonymous usage statistics to the Stalwart project.'),
                'proxyTrustedNetworks' => $f('set', 'Networks allowed to send the PROXY protocol header.'),
                'defaultLanguages' => $f('objectList', 'Languages used for system messages, in order of preference.'),
            ],
            'values' => [
                'id' => 'singleton',
                'hostname' => 'mail.server.example.com',
                'threadPoolSize' => 16,
                'maxConnections' => 16384,
                'spamScoreThreshold' => 5.5,
                'logLevel' => 'info',
                'enableTelemetry' => false,
                'proxyTrustedNetworks' => ['127.0.0.0/8', '::1'],
                'defaultLanguages' => [['code' => 'en', 'fallback' => true]],
            ],
            'enums' => $enums,
        ],
    };
}
