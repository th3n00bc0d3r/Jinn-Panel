<?php
require_once __DIR__ . '/../_sample.php';
require_once __DIR__ . '/../_sample_stats.php';

// 12 sample customers (stats_accounts), plus the admin and a reseller, as the controller returns them.
$accounts = [
    ['id' => 1, 'username' => 'admin', 'email' => 'admin@example.com', 'full_name' => 'Server admin', 'role' => 'admin', 'status' => 'active', 'package_name' => null, 'disk_quota_mb' => null, 'reseller_username' => null, 'used_bytes' => null, 'disk_pct' => null, 'over_disk' => 0, 'over_bandwidth' => 0, 'domain_count' => 0, 'traffic_14d' => 0, 'created_at' => '2026-01-01 09:00:00'],
    ['id' => 2, 'username' => 'resell', 'email' => 'sales@reseller.test', 'full_name' => 'North Web Reselling', 'role' => 'reseller', 'status' => 'active', 'package_name' => 'Reseller 50', 'disk_quota_mb' => 51200, 'reseller_username' => null, 'used_bytes' => null, 'disk_pct' => null, 'over_disk' => 0, 'over_bandwidth' => 0, 'domain_count' => 0, 'traffic_14d' => 0, 'created_at' => '2026-02-01 09:00:00'],
];
$sparks = [];
foreach (stats_accounts() as $s) {
    $limit = (int) $s['disk_limit_mb'];
    $accounts[] = [
        'id' => $s['id'], 'username' => $s['username'], 'email' => $s['email'], 'full_name' => $s['full_name'], 'role' => 'user', 'status' => $s['status'],
        'package_name' => $s['package'], 'disk_quota_mb' => $limit, 'reseller_username' => $s['reseller'],
        'used_bytes' => (int) ($s['disk_used_mb'] * 1048576), 'disk_pct' => $limit > 0 ? $s['disk_used_mb'] / $limit : null,
        'over_disk' => $limit > 0 && $s['disk_used_mb'] > $limit ? 1 : 0, 'over_bandwidth' => 0, 'domain_count' => $s['domains'],
        'traffic_14d' => array_sum($s['traffic']), 'created_at' => $s['created'] . ' 10:00:00',
    ];
    $sparks[$s['id']] = $s['traffic'];
}
$filters = ['q' => '', 'status' => '', 'role' => '', 'package' => 0, 'reseller' => '', 'sort' => '', 'dir' => 'asc', 'page' => 1];
$counts = ['total' => 14, 'active' => 13, 'suspended' => 1, 'over_limits' => 1];
$users = array_values(array_filter($accounts, fn($a) => $a['role'] === 'user'));
return [
    'layout' => 'whm', 'role' => 'admin', 'path' => '/whm/accounts',
    'data' => [
        'title' => 'Accounts', 'accounts' => $accounts, 'me' => ['id' => 1, 'role' => 'admin', 'username' => 'admin'], 'packages' => sample_packages(),
        'filters' => $filters, 'counts' => $counts, 'total' => 14, 'pages' => 1, 'sparks' => $sparks,
        'resellers' => [['id' => 2, 'username' => 'resell']],
    ],
    'variants' => [
        'empty' => ['accounts' => [], 'total' => 0, 'counts' => ['total' => 0, 'active' => 0, 'suspended' => 0, 'over_limits' => 0]],
        'no-match' => ['accounts' => [], 'total' => 0, 'filters' => ['q' => 'zzz'] + $filters, 'counts' => ['total' => 0, 'active' => 0, 'suspended' => 0, 'over_limits' => 0]],
        'sorted-paged' => ['accounts' => array_slice($users, 0, 5), 'total' => 132, 'pages' => 6, 'filters' => ['sort' => 'disk', 'dir' => 'desc', 'page' => 3, 'status' => 'active'] + $filters],
        'reseller' => ['role' => 'reseller', 'data' => ['accounts' => array_slice($users, 0, 4), 'total' => 4, 'resellers' => [], 'counts' => ['total' => 4, 'active' => 4, 'suspended' => 0, 'over_limits' => 0], 'me' => ['id' => 2, 'role' => 'reseller', 'username' => 'resell']]],
    ],
];
