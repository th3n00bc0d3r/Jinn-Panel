<?php
require_once __DIR__ . '/../_sample_ops.php';
$rows = ops_activity_rows();
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/activity',
    'data' => ['title' => 'Activity Log', 'rows' => $rows, 'q' => '', 'next' => 5108],
    'variants' => [
        'search' => ['rows' => array_values(array_filter($rows, fn($r) => $r['ip'] === '203.0.113.99')), 'q' => '203.0.113.99', 'next' => null],
        'no-match' => ['rows' => [], 'q' => 'wordpress', 'next' => null],
        'empty' => ['rows' => [], 'q' => '', 'next' => null],
        'reseller' => ['role' => 'reseller', 'data' => ['rows' => array_values(array_filter($rows, fn($r) => in_array($r['actor_name'], ['resell', 'acme'], true))), 'next' => null]],
    ],
];
