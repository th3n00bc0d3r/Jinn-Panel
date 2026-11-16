<?php
require_once __DIR__ . '/../../_sample_config.php';
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/server-config/sftp',
    'data' => [
        'title' => 'SFTP Server Settings',
        'common' => ['idle_timeout' => 15, 'max_total_connections' => 0, 'max_per_host_connections' => 20, 'upload_mode' => 0],
        'lastLog' => cfg_log('OK', 3 * 3600),
    ],
    'variants' => [
        // Nothing applied from the panel yet; sftpgo.json without a "common" section.
        'fresh' => ['common' => [], 'lastLog' => null],
        'failed' => ['lastLog' => cfg_log('FAILED: sftpgo.json is not valid JSON (Syntax error) - left unchanged', 120)],
    ],
];
