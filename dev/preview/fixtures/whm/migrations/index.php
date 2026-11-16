<?php
require_once __DIR__ . '/../../_sample_ops.php';
$admin = ['id' => 1, 'username' => 'admin', 'role' => 'admin'];
$reseller = ['id' => 2, 'username' => 'resell', 'role' => 'reseller'];
$list = fn(array $x, int $items, int $done, string $creator = 'admin') => ops_migration($x) + ['creator' => $creator, 'item_count' => $items, 'done_count' => $done];
$migrations = [
    $list(['id' => 14, 'source_type' => 'root', 'source_host' => 'cp2.example.net', 'source_user' => 'root', 'status' => 'draft', 'created_at' => ops_ago(300), 'started_at' => null, 'heartbeat_at' => null], 0, 0),
    $list([], 5, 2),
    $list(['id' => 12, 'source_type' => 'root', 'source_host' => 'backup files', 'source_port' => 0, 'source_user' => 'import', 'auth_type' => 'password', 'secret_enc' => null, 'transfer_mode' => 'file',
        'status' => 'completed_with_errors', 'created_at' => ops_ago(26 * 3600), 'finished_at' => ops_ago(25 * 3600)], 3, 3),
    $list(['id' => 11, 'source_type' => 'cpanel', 'source_host' => 'old.bakery.test', 'source_port' => 2083, 'source_user' => 'bakery', 'transfer_mode' => 'push',
        'created_at' => ops_ago(3 * 86400), 'started_at' => ops_ago(3 * 86400 - 60), 'heartbeat_at' => ops_ago(3 * 86400 - 600)], 1, 0, 'resell'),
    $list(['id' => 10, 'source_type' => 'cpanel', 'source_host' => '198.51.100.7', 'source_port' => 2083, 'source_user' => 'blogger', 'secret_enc' => null, 'status' => 'failed',
        'created_at' => ops_ago(5 * 86400), 'finished_at' => ops_ago(5 * 86400 - 900)], 1, 0),
    $list(['id' => 9, 'source_type' => 'root', 'source_host' => 'whm.legacyhost.com', 'source_user' => 'root', 'secret_enc' => null, 'status' => 'completed',
        'created_at' => ops_ago(12 * 86400), 'finished_at' => ops_ago(12 * 86400 - 7200)], 8, 8),
];
$files = [
    'acme2' => '/var/lib/jinnpanel/migrations/import/cpmove-acme2.tar.gz',
    'florist' => '/var/lib/jinnpanel/migrations/import/backup-11.1.2026_02-10-33_florist.tar.gz',
    'garage' => '/var/lib/jinnpanel/migrations/import/garage.tar.gz',
];
$fetches = [
    ['id' => 3, 'endpoint' => 'https://s3.eu-central-003.backblazeb2.com', 'bucket' => 'old-backups', 'prefix' => 'cpanel/2026-11-01/', 'status' => 'done', 'progress' => '3 archive(s) fetched', 'log' => '', 'created_at' => ops_ago(2 * 3600), 'finished_at' => ops_ago(3600)],
    ['id' => 2, 'endpoint' => 'https://s3.us-east-1.amazonaws.com', 'bucket' => 'cpanel-weekly', 'prefix' => '', 'status' => 'failed', 'progress' => 'Access denied (check the key can list the bucket)', 'log' => '', 'created_at' => ops_ago(2 * 86400), 'finished_at' => ops_ago(2 * 86400)],
];
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/migrations',
    'data' => [
        'backupFiles' => $files,
        'importDir' => MigrationService::importDir(),
        's3Fetches' => $fetches,
        'title' => 'Migrations',
        'migrations' => $migrations,
        'me' => $admin,
    ],
    'variants' => [
        'empty' => ['migrations' => [], 'backupFiles' => [], 's3Fetches' => []],
        'fetching' => ['migrations' => array_slice($migrations, 2), 'backupFiles' => [], 's3Fetches' => array_merge([
            ['id' => 4, 'endpoint' => 'https://s3.eu-central-003.backblazeb2.com', 'bucket' => 'old-backups', 'prefix' => 'cpanel/2026-11-13/', 'status' => 'running', 'progress' => 'cpmove-shop.tar.gz: 1.2 GB of 3.4 GB (2 of 4)', 'log' => '', 'created_at' => ops_ago(240), 'finished_at' => null],
        ], $fetches)],
        'reseller' => ['role' => 'reseller', 'data' => [
            'me' => $reseller, 'backupFiles' => [], 's3Fetches' => [],
            'migrations' => array_values(array_filter($migrations, fn($m) => $m['creator'] === 'resell')),
        ]],
    ],
];
