<?php
declare(strict_types=1);

/**
 * WHM > Server Config > PHP Extensions: install/remove php-zts-* packages
 * for the default PHP (FrankenPHP loads extensions process-wide, so they
 * apply to every site on the default version - there is no per-site
 * switch). The root worker runs dnf and restarts FrankenPHP and webmail;
 * the package list is cached by the worker in storage/.
 */
final class PhpExtensionService
{
    public const CACHE = SystemWorkerService::OUT_DIR . '/php-extensions.json';
    /** Installed by install.sh and needed by the panel or most sites: not removable here. */
    public const REQUIRED = ['pdo', 'pdo_mysql', 'mysqlnd', 'mysqli', 'gd', 'intl', 'zip', 'bcmath', 'gmp', 'soap', 'sqlite3', 'pdo_sqlite', 'xsl', 'bz2', 'gettext', 'ftp', 'imagick', 'cli', 'embed'];
    /** Packages that aren't extensions. */
    public const NOT_EXTENSIONS = ['cli', 'embed', 'cgi', 'fpm', 'devel', 'dbg', 'common'];
    public const DESCRIPTIONS = [
        'apcu' => 'In-memory user cache (APCu)', 'redis' => 'Redis client (object cache)', 'memcached' => 'Memcached client', 'memcache' => 'Memcache client (old API)',
        'igbinary' => 'Compact serializer', 'msgpack' => 'MessagePack serializer', 'mongodb' => 'MongoDB driver', 'pgsql' => 'PostgreSQL', 'pdo_pgsql' => 'PostgreSQL (PDO)',
        'sqlsrv' => 'SQL Server', 'pdo_sqlsrv' => 'SQL Server (PDO)', 'odbc' => 'ODBC', 'pdo_odbc' => 'ODBC (PDO)', 'ldap' => 'LDAP', 'imap' => 'IMAP',
        'xdebug' => 'Debugger/profiler - slows every request; not for production', 'pcov' => 'Code coverage', 'tidy' => 'HTML Tidy', 'yaml' => 'YAML',
        'uuid' => 'UUIDs', 'ssh2' => 'SSH2/SFTP client', 'swoole' => 'Async server (doesn\'t work under FrankenPHP)', 'grpc' => 'gRPC', 'protobuf' => 'Protocol Buffers',
        'maxminddb' => 'GeoIP2 lookups', 'rdkafka' => 'Kafka', 'amqp' => 'RabbitMQ', 'zstd' => 'Zstandard compression', 'brotli' => 'Brotli compression', 'lz4' => 'LZ4 compression',
        'xlswriter' => 'Excel writer', 'ffi' => 'Foreign Function Interface', 'sodium' => 'libsodium crypto', 'opentelemetry' => 'OpenTelemetry tracing', 'excimer' => 'Sampling profiler',
        'shmop' => 'Shared memory', 'sysvmsg' => 'System V messages', 'sysvsem' => 'System V semaphores', 'sysvshm' => 'System V shared memory', 'dba' => 'DBA databases',
        'decimal' => 'Arbitrary-precision decimals', 'ds' => 'Data structures', 'simdjson' => 'Fast JSON parser', 'rar' => 'RAR archives', 'inotify' => 'File change notifications',
    ];

    /** @return array{updated:?int, installed:list<string>, available:list<string>} */
    public static function list(): array
    {
        $c = is_file(self::CACHE) ? json_decode((string) file_get_contents(self::CACHE), true) : null;
        return is_array($c) ? $c + ['updated' => null, 'installed' => [], 'available' => []] : ['updated' => null, 'installed' => [], 'available' => []];
    }

    public static function refresh(): void
    {
        SystemWorkerService::enqueue('php-ext-list', ['type' => 'php_ext_list']);
    }

    public static function change(string $ext, bool $install): void
    {
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $ext) || in_array($ext, self::NOT_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Unknown extension.');
        }
        if (!$install && in_array($ext, self::REQUIRED, true)) {
            throw new InvalidArgumentException("$ext is needed by the panel or most sites and can't be removed here.");
        }
        $l = self::list();
        if (!in_array($ext, $install ? $l['available'] : $l['installed'], true)) {
            throw new InvalidArgumentException("php-zts-$ext isn't " . ($install ? 'available' : 'installed') . ' - refresh the list.');
        }
        SystemWorkerService::enqueue('php-ext', ['type' => 'php_ext_change', 'ext' => $ext, 'install' => $install]);
    }

    public static function lastResult(): ?string
    {
        return SystemWorkerService::lastLog('php-ext-');
    }
}
