<?php
require_once __DIR__ . '/../../_sample_config.php';
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/server-config/database',
    'data' => [
        'title' => 'Database Settings',
        'current' => ['innodb_buffer_pool_size' => '1536M', 'innodb_log_file_size' => '384M', 'max_connections' => '500', 'thread_cache_size' => '32', 'table_open_cache' => '4000'],
        'lastLog' => cfg_log('OK', 2 * 86400),
    ],
    'variants' => [
        // No panel drop-in yet: MariaDB runs on its own defaults.
        'not-set' => ['current' => [], 'lastLog' => null],
        'failed' => ['lastLog' => cfg_log('FAILED: mariadb did not come back after the restart - the previous settings were restored', 300)],
    ],
];
