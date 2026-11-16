<?php
$meta = fn(int $sites, array $dbs, int $boxes) => json_encode([
    'version' => 1, 'username' => 'acme', 'created' => '2026-11-13T03:00:00+00:00',
    'domains' => array_slice(['acme.test', 'shop.acme.test', 'acme-staging.example'], 0, $sites),
    'databases' => $dbs,
    'mailboxes' => array_fill_keys(array_map(fn($i) => "box$i@acme.test", range(1, max(1, $boxes))), []),
]);
$b = fn(array $r) => $r + [
    'kind' => 'account', 'user_id' => 10, 'username' => 'acme', 'status' => 'done', 'path' => null,
    'size_bytes' => 0, 'parts' => null, 'remote' => null, 'log' => null, 'finished_at' => null,
];
$at = fn(string $rel) => date('Y-m-d H:i:s', strtotime($rel));
// Paths are not on this machine, so BackupService::files() lists no downloads in the preview.
$backups = [
    $b(['id' => 41, 'created_at' => $at('today 03:00'), 'finished_at' => $at('today 03:06'), 'path' => '/var/backups/jinnpanel/acme/20261114-0300', 'size_bytes' => 1_873_220_114, 'parts' => $meta(3, ['acme_shop', 'acme_wp'], 4), 'remote' => 'uploaded']),
    $b(['id' => 37, 'created_at' => $at('yesterday 14:22'), 'status' => 'failed', 'log' => "[14:22:01] files: acme.test, shop.acme.test\n[14:24:40] database acme_wp\n[14:24:41] mysqldump: Got error: 1045: Access denied for user 'root'@'localhost' when trying to connect"]),
    $b(['id' => 33, 'created_at' => $at('yesterday 03:00'), 'finished_at' => $at('yesterday 03:05'), 'path' => '/var/backups/jinnpanel/acme/20261113-0300', 'size_bytes' => 1_861_004_200, 'parts' => $meta(3, ['acme_shop', 'acme_wp'], 4), 'remote' => 'failed']),
];
$settings = ['enabled' => true, 'hour' => 3, 'keep' => 2, 's3' => null];
return [
    'layout' => 'cpanel',
    'role' => 'user',
    'path' => '/cpanel/backups',
    'data' => [
        'title' => 'Backups',
        'backups' => $backups,
        'settings' => $settings,
    ],
    'variants' => [
        'running' => ['backups' => array_merge([$b(['id' => 42, 'created_at' => $at('-2 minutes'), 'status' => 'running'])], $backups)],
        'empty' => ['backups' => []],
        'auto-off' => ['backups' => [], 'settings' => ['enabled' => false] + $settings],
        'offsite' => ['settings' => ['s3' => ['bucket' => 'acme-backups']] + $settings],
    ],
];
