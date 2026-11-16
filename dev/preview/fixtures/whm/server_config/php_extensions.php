<?php
require_once __DIR__ . '/../../_sample_config.php';
$list = [
    'updated' => time() - 3 * 3600,
    'installed' => ['apcu', 'bcmath', 'bz2', 'ftp', 'gd', 'gettext', 'gmp', 'igbinary', 'imagick', 'intl', 'mysqli', 'mysqlnd', 'opcache', 'pdo', 'pdo_mysql', 'pdo_sqlite', 'redis', 'soap', 'sodium', 'sqlite3', 'xsl', 'zip'],
    'available' => ['amqp', 'brotli', 'dba', 'decimal', 'ds', 'excimer', 'ffi', 'grpc', 'imap', 'inotify', 'ldap', 'lz4', 'maxminddb', 'memcache', 'memcached', 'mongodb', 'msgpack', 'odbc', 'opentelemetry', 'pcov', 'pdo_odbc', 'pdo_pgsql', 'pdo_sqlsrv', 'pgsql', 'protobuf', 'rar', 'rdkafka', 'shmop', 'simdjson', 'snmp', 'sqlsrv', 'ssh2', 'swoole', 'sysvmsg', 'sysvsem', 'sysvshm', 'tidy', 'uuid', 'xdebug', 'xlswriter', 'yaml', 'zstd'],
];
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/server-config/php-extensions',
    'data' => ['title' => 'PHP Extensions', 'list' => $list, 'last' => cfg_log('OK', 1800), 'pending' => false],
    'variants' => [
        // An install/remove is queued: the page reloads itself when it's done.
        'working' => ['pending' => true],
        'failed' => ['last' => cfg_log("FAILED: php-zts-swoole doesn't load (PHP Warning:  PHP Startup: Unable to load dynamic library 'swoole') - removed again", 240)],
        // First visit: the list is being fetched.
        'loading' => ['list' => ['updated' => null, 'installed' => [], 'available' => []], 'last' => null, 'pending' => true],
        // No list and nothing queued (the worker isn't running).
        'no-list' => ['list' => ['updated' => null, 'installed' => [], 'available' => []], 'last' => null, 'pending' => false],
    ],
];
