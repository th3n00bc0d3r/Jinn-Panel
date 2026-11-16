<?php
require_once __DIR__ . '/../_sample.php';
require_once __DIR__ . '/../_sample_stats.php';
$d = sample_domains()[0];
$zero = array_fill_keys(['requests', 'bytes', 'visitors', 'pages', 's2xx', 's3xx', 's4xx', 's5xx', 'time_ms', 'slow'], 0.0);
return [
    'layout' => 'cpanel', 'role' => 'user', 'path' => '/cpanel/domains/1/stats',
    'data' => ['title' => 'Stats · ' . $d['domain_name'], 'd' => $d, 'range' => stats_range(), 'stats' => stats_fx_domain()],
    'variants' => [
        'no-visits' => ['stats' => ['sum' => $zero, 'prev' => [], 'pages' => [], 'referrers' => [], 'since' => null] + stats_fx_domain()],
        'not-migrated' => ['stats' => null],
    ],
];
