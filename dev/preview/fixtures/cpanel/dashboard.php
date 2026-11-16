<?php
require_once __DIR__ . '/../_sample.php';
require_once __DIR__ . '/../_sample_stats.php';
$measured = ['files' => 2_254_857_830, 'databases' => 644_245_094, 'mail' => 751_619_276, 'used' => 3_650_722_200, 'limit' => 5_368_709_120, 'over' => false,
    'bw_used' => 44_023_414_784, 'bw_limit' => 53_687_091_200, 'bw_over' => false, 'updated' => date('Y-m-d H:i:s', time() - 2400)];
$summary = stats_fx_domain();
$sparks = [];
foreach (sample_domains() as $i => $d) {
    $days = stats_series(41 + $i, 14, [420, 160, 8][$i], 0.3, [0.3, 0.9, 0][$i]);
    $sparks[$d['id']] = ['days' => $days, 'visitors' => array_sum($days), 'requests' => array_sum($days) * 6.2];
}
return [
    'layout' => 'cpanel', 'role' => 'user', 'path' => '/cpanel',
    'data' => [
        'title' => 'Dashboard', 'me' => ['id' => 10, 'username' => 'acme', 'full_name' => 'Acme Ltd', 'role' => 'user'], 'pkg' => sample_pkg(), 'usage' => sample_usage(), 'domains' => sample_domains(),
        'measured' => $measured, 'range' => stats_range(), 'summary' => $summary, 'sparks' => $sparks,
        'projection' => [
            'disk' => ['days' => 40, 'note' => 'Reaches its limit around 24 Dec at this rate', 'perDay' => 4.2e7],
            'bw' => ['days' => 12, 'note' => 'Reaches its limit around 26 Nov at this pace', 'projected' => 9.1e10],
        ],
    ],
    'variants' => [
        'limited-package' => ['features' => ['file_manager' => false, 'cron' => false, 'backups' => false, 'dns_editor' => false, 'php_versions' => false, 'aliases' => false, 'routes' => false, 'object_cache' => false, 'database_access' => false, 'ssh_keys' => false]],
        'new-account' => ['domains' => [], 'usage' => ['domains' => 0, 'databases' => 0, 'email_accounts' => 0, 'ftp_accounts' => 0], 'sparks' => [], 'projection' => [],
            'summary' => ['sum' => array_fill_keys(['requests', 'bytes', 'visitors', 'pages', 's2xx', 's3xx', 's4xx', 's5xx', 'time_ms', 'slow'], 0.0), 'prev' => []] + stats_fx_domain(),
            'measured' => ['files' => 0, 'databases' => 0, 'mail' => 0, 'used' => 0, 'bw_used' => 0, 'updated' => null] + $measured],
        'over-limits' => ['measured' => ['over' => true, 'used' => 5_600_000_000, 'bw_over' => true, 'bw_used' => 54_000_000_000] + $measured, 'projection' => ['disk' => ['days' => 0, 'note' => 'Over since 9 Nov', 'perDay' => 0], 'bw' => ['days' => 0, 'note' => 'Used up this month', 'projected' => 6e10]]],
        'not-migrated' => ['summary' => null, 'sparks' => [], 'projection' => []],
    ],
    'session' => ['impersonator' => ['id' => 2, 'username' => 'resell', 'session_version' => 1, 'target' => 10]],
];
