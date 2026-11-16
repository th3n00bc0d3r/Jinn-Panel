<?php
require_once __DIR__ . '/../_sample.php';
$d = sample_domains()[0];
$items = [
    ['path' => 'backup-2025-03-01.zip', 'size' => 412_334_112, 'kind' => 'archive', 'dir' => false],
    ['path' => 'wp-content/uploads/db-export.sql.gz', 'size' => 18_220_431, 'kind' => 'database dump', 'dir' => false],
    ['path' => 'wp-config.php.bak', 'size' => 3_311, 'kind' => 'backup copy', 'dir' => false],
    ['path' => 'error_log', 'size' => 2_401_993, 'kind' => 'log', 'dir' => false],
    ['path' => 'exports/orders-2026-10.csv', 'size' => 88_120, 'kind' => 'data export', 'dir' => false],
    ['path' => '_backups', 'size' => 1_240_118_002, 'kind' => 'backup folder', 'dir' => true],
    ['path' => 'downloads/brochure 2026 (print).zip', 'size' => 9_402_112, 'kind' => 'archive', 'dir' => false],
];
return [
    'layout' => 'cpanel',
    'role' => 'user',
    'path' => '/cpanel/domains/1/exposed',
    'data' => [
        'title' => 'Exposed files · ' . $d['domain_name'],
        'd' => $d,
        'scan' => ['items' => $items, 'truncated' => false],
        'docroot' => '/var/www/acme.test/public',
        'private' => '/var/www/acme.test/private',
    ],
    'variants' => [
        'clean' => ['scan' => ['items' => [], 'truncated' => false]],
        'truncated' => ['scan' => ['items' => array_slice($items, 0, 2), 'truncated' => true]],
    ],
];
