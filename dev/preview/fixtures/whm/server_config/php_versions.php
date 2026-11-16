<?php
require_once __DIR__ . '/../../_sample_config.php';
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/server-config/php-versions',
    'data' => [
        'title' => 'PHP Versions',
        'installed' => [cfg_php_version('8.3'), cfg_php_version('8.4')],
        'available' => ['8.2'],
    ],
    'variants' => [
        // Only the default version.
        'none' => ['installed' => [], 'available' => ['8.2', '8.3', '8.4']],
        // A job running: the page reloads itself when it's done.
        'installing' => ['installed' => [cfg_php_version('8.3'), cfg_php_version('8.4', 'installing')], 'available' => ['8.2']],
        'removing' => ['installed' => [cfg_php_version('8.2', 'removing'), cfg_php_version('8.3')], 'available' => ['8.4']],
        'failed' => ['installed' => [cfg_php_version('8.2', 'failed'), cfg_php_version('8.3')], 'available' => ['8.4']],
        'all-installed' => ['installed' => [cfg_php_version('8.2'), cfg_php_version('8.3'), cfg_php_version('8.4')], 'available' => []],
    ],
];
