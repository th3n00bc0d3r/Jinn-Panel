<?php
require_once __DIR__ . '/../_sample.php';
require_once __DIR__ . '/../_sample_stats.php';
return [
    'layout' => 'whm', 'role' => 'admin', 'path' => '/whm',
    'data' => [
        'title' => 'Dashboard', 'me' => ['id' => 1, 'role' => 'admin', 'username' => 'admin'],
        'resellerCount' => 2, 'userCount' => 14, 'domainCount' => 37, 'suspendedCount' => 1,
        'stats' => ['disk_total' => 214_748_364_800, 'disk_used' => 88_046_829_568, 'mem_total' => 8_589_934_592, 'mem_used' => 5_153_960_755, 'load' => [0.62, 0.48, 0.41], 'cpus' => 4, 'uptime' => 1_296_000 + 7300,
            'services' => ['mariadb' => 'active', 'frankenphp' => 'active', 'stalwart' => 'active', 'sftpgo' => 'inactive', 'knot' => 'active']],
        'range' => stats_range(),
        'traffic' => stats_fx_traffic(),
        'server' => stats_fx_server(),
        'uptime' => stats_fx_uptime(),
        'limits' => stats_fx_limits(),
        'alerts' => sample_alerts(),
        'recent' => sample_activity(),
        'alloc' => null,
        'logServices' => ['mariadb', 'frankenphp', 'stalwart', 'sftpgo', 'knot'],
        'selectedLog' => 'frankenphp',
        'logContent' => "Nov 14 09:12:01 server frankenphp[812]: {\"level\":\"info\",\"msg\":\"certificate obtained successfully\",\"identifier\":\"acme.test\"}\nNov 14 09:12:04 server frankenphp[812]: {\"level\":\"info\",\"msg\":\"reloading config\"}",
    ],
    'variants' => [
        'all-clear' => ['alerts' => [], 'limits' => []],
        'no-statistics' => ['traffic' => ['range' => '24h', 'per' => 'hour', 'labels' => [], 'series' => [], 'stacks' => [], 'total' => 0, 'previous' => 0, 'spike' => null, 'busiest' => [], 'metric' => 'requests'], 'server' => ['cpu' => [], 'mem' => [], 'load' => [], 'latest' => null, 'bwDays' => [], 'bwMonth' => 0], 'uptime' => array_map(fn() => ['cells' => [], 'pct' => null, 'down' => '', 'checks' => 0], stats_fx_uptime()), 'limits' => []],
        'not-migrated' => ['traffic' => null, 'server' => null, 'uptime' => null],
        'reseller' => ['role' => 'reseller', 'data' => ['me' => ['id' => 2, 'role' => 'reseller', 'username' => 'resell'], 'stats' => null, 'server' => null, 'uptime' => null, 'alerts' => [], 'logServices' => [], 'logContent' => null, 'resellerCount' => 0, 'userCount' => 2, 'domainCount' => 5, 'suspendedCount' => 0, 'alloc' => sample_alloc()]],
    ],
];
