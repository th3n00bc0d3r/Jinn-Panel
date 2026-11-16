<?php
require_once __DIR__ . '/../_sample.php';
$accounts = [];
foreach (sample_accounts() as $a) {
    if ($a['role'] !== 'user') { continue; }
    $accounts[] = $a + ['used_bytes' => $a['username'] === 'bakery' ? 5_500_000_000 : 1_800_000_000, 'bw_month' => date('Y-m'), 'bw_bytes' => 12_000_000_000, 'over_disk' => $a['username'] === 'bakery' ? 1 : 0, 'over_bandwidth' => 0, 'domain_count' => 3];
}
$pkg = sample_packages()[0] + ['features' => '{"routes":false,"database_access":false}'];
return [
    'layout' => 'whm', 'role' => 'admin', 'path' => '/whm/packages/1',
    'data' => ['title' => 'Business', 'pkg' => $pkg, 'accounts' => $accounts, 'total' => count($accounts), 'editable' => true, 'others' => array_slice(sample_packages(), 1)],
    'variants' => [
        'empty' => ['accounts' => [], 'total' => 0],
        'unlimited' => ['title' => 'Unlimited', 'pkg' => sample_packages()[3], 'editable' => true],
        'reseller-view' => ['role' => 'reseller', 'data' => ['editable' => false, 'total' => 6]],
    ],
];
