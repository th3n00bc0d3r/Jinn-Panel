<?php
require_once __DIR__ . '/../_sample_ops.php';
$settings = [
    'enabled' => true, 'hour' => 3, 'keep' => 2,
    's3' => ['endpoint' => 'https://s3.eu-central-003.backblazeb2.com', 'region' => 'eu-central-003', 'bucket' => 'acme-offsite', 'prefix' => 'jinnpanel', 'access_key' => '003a1b2c3d4e5f60000000001', 'secret_enc' => 'v1:preview'],
];
$accounts = [['id' => 10, 'username' => 'acme'], ['id' => 11, 'username' => 'bakery'], ['id' => 12, 'username' => 'blogger']];
$running = array_merge([
    ops_backup(['id' => 216, 'user_id' => 11, 'username' => 'bakery', 'status' => 'restoring', 'path' => '/var/backups/jinnpanel/bakery/20261113-030100', 'size_bytes' => 188_000_000,
        'parts' => ops_backup_parts('bakery', ['bakery.test'], ['bakery_wp'], ['hello@bakery.test']), 'created_at' => ops_ago(3 * 3600 + 340),
        'log' => "[03:01:10] site files: bakery.test\n[03:01:40] done: 179.3 MB\n[14:20:02] restore: site files: bakery.test\n[14:20:31] restore: database bakery_wp"]),
    ops_backup(['id' => 215, 'status' => 'running', 'created_at' => ops_ago(70),
        'log' => "[14:19:02] site files: acme.test, shop.acme.test, acme-staging.example\n[14:20:10] database acme_wp"]),
], ops_backups());
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/backups',
    'data' => [
        'title' => 'Backups',
        'settings' => $settings,
        'backups' => ops_backups(),
        'accounts' => $accounts,
        'free' => 128_849_018_880,
    ],
    'variants' => [
        'running' => ['backups' => $running],
        'empty' => ['backups' => [], 'settings' => ['enabled' => false, 'hour' => 3, 'keep' => 2, 's3' => null], 'accounts' => []],
    ],
];
