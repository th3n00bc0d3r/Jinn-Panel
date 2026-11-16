<?php
require_once __DIR__ . '/../_sample_stats.php';
$metric = in_array($_GET['metric'] ?? '', ['requests', 'visitors', 'bytes'], true) ? $_GET['metric'] : 'requests';
return [
    'layout' => 'whm', 'role' => 'admin', 'path' => '/whm/analytics',
    'data' => [
        'title' => 'Analytics', 'me' => ['id' => 1, 'role' => 'admin', 'username' => 'admin'],
        'range' => stats_range(), 'metric' => $metric, 'sort' => $_GET['sort'] ?? $metric,
        'traffic' => stats_fx_traffic(null, $metric),
        'server' => stats_fx_server_range(),
        'sites' => array_map(fn($s) => $s + ['user_id' => 10], stats_fx_sites()),
    ],
    'variants' => [
        'reseller' => ['role' => 'reseller', 'data' => ['me' => ['id' => 2, 'role' => 'reseller', 'username' => 'resell'], 'server' => null]],
        'empty' => ['traffic' => ['range' => '24h', 'per' => 'hour', 'labels' => [], 'series' => [], 'stacks' => [], 'total' => 0, 'previous' => 0, 'spike' => null, 'busiest' => [], 'metric' => 'requests'],
            'server' => ['labels' => [], 'per' => 'hour', 'cpu' => [], 'mem' => [], 'load' => [], 'tx' => [], 'has' => false], 'sites' => []],
    ],
];
