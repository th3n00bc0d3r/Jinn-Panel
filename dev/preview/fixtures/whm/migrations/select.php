<?php
require_once __DIR__ . '/../../_sample.php';
require_once __DIR__ . '/../../_sample_ops.php';
$admin = ['id' => 1, 'username' => 'admin', 'role' => 'admin'];
$draft = ops_migration(['id' => 14, 'source_type' => 'root', 'source_host' => 'cp2.example.net', 'source_user' => 'root', 'status' => 'draft', 'started_at' => null, 'heartbeat_at' => null]);
$src = fn(int $mb, bool $suspended = false) => ['source' => ['email' => null, 'disk_used_mb' => $mb, 'suspended' => $suspended]];
$draftItem = fn(int $id, string $user, ?string $domain, array $x, array $report) => ops_item($id, $user, $domain, $x + ['migration_id' => 14, 'selected' => 0], $report);
$items = [
    $draftItem(40, 'agency', 'agency-hosting.test', ['is_reseller' => 1, 'source_owner' => 'root', 'source_plan' => 'Reseller 50'], $src(820)),
    $draftItem(41, 'acme', 'acme.test', ['source_owner' => 'agency'], $src(2300)),
    $draftItem(42, 'bistro', 'bistro-lyon.test', ['source_owner' => 'agency', 'source_plan' => 'Starter'], $src(410)),
    $draftItem(43, 'carwash', 'sparkle-carwash.test', ['source_owner' => 'agency'], $src(96)),
    $draftItem(44, 'dentist', 'smile-dental.test', ['source_owner' => 'root', 'source_plan' => 'Business'], $src(5120)),
    $draftItem(45, 'florist', 'petals.test', ['source_owner' => 'root', 'source_plan' => 'Starter'], $src(180, true)),
    $draftItem(46, 'garage', 'acme.test', ['source_owner' => 'root'], $src(64)),
    $draftItem(47, 'hotel', 'seaview-hotel.test', ['source_owner' => 'agency', 'source_plan' => 'Pro 20'], $src(11800)),
    $draftItem(48, 'Inn_2', 'inn.test', ['source_owner' => 'root'], $src(32)),
    $draftItem(49, 'joinery', 'oak-joinery.test', ['source_owner' => 'root'], $src(270)),
];
$conflicts = [
    41 => ['username already exists here', 'main domain already hosted here'],
    46 => ['main domain already hosted here'],
    48 => ['username not valid in JinnPanel'],
];
$opt = MigrationService::defaultOptions();
$fileDraft = ops_migration(['id' => 15, 'source_type' => 'root', 'source_host' => 'backup files', 'source_port' => 0, 'source_user' => 'import', 'auth_type' => 'password', 'secret_enc' => null, 'transfer_mode' => 'file', 'status' => 'draft', 'started_at' => null, 'heartbeat_at' => null]);
$fileItems = [
    ops_item(60, 'acme2', null, ['migration_id' => 15, 'selected' => 0, 'source_owner' => null, 'source_plan' => null], ['source' => ['file' => 'cpmove-acme2.tar.gz', 'disk_used_mb' => 1890]]),
    ops_item(61, 'florist', null, ['migration_id' => 15, 'selected' => 0, 'source_owner' => null, 'source_plan' => null], ['source' => ['file' => 'backup-11.1.2026_02-10-33_florist.tar.gz', 'disk_used_mb' => 212]]),
];
$single = ops_migration(['id' => 16, 'source_type' => 'cpanel', 'source_host' => 'old.bakery.test', 'source_port' => 2083, 'source_user' => 'bakery', 'transfer_mode' => 'push', 'status' => 'draft', 'started_at' => null, 'heartbeat_at' => null]);
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/migrations/14/select',
    'data' => [
        'title' => 'Choose accounts to migrate',
        'm' => $draft,
        'items' => $items,
        'conflicts' => $conflicts,
        'packages' => sample_packages(),
        'resellers' => [['id' => 2, 'username' => 'resell']],
        'opt' => $opt,
        'me' => $admin,
    ],
    'variants' => [
        'files' => ['m' => $fileDraft, 'items' => $fileItems, 'conflicts' => []],
        'single-push' => ['m' => $single, 'items' => [ops_item(70, 'bakery', 'bakery.test', ['migration_id' => 16, 'selected' => 0, 'source_owner' => null, 'source_plan' => null], $src(640))], 'conflicts' => []],
        'reseller' => ['role' => 'reseller', 'data' => [
            'me' => ['id' => 2, 'username' => 'resell', 'role' => 'reseller'], 'resellers' => [],
            'm' => ops_migration(['id' => 17, 'created_by' => 2, 'status' => 'draft', 'started_at' => null, 'heartbeat_at' => null]),
            'items' => array_slice($items, 1, 4), 'conflicts' => [41 => ['username already exists here']],
            'packages' => array_slice(sample_packages(), 0, 2),
        ]],
    ],
];
