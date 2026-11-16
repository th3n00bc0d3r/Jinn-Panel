<?php
require_once __DIR__ . '/../_sample.php';
return [
    'layout' => 'whm', 'role' => 'admin', 'path' => '/whm/alerts',
    'data' => ['title' => 'Alerts', 'alerts' => sample_alerts(), 'healthLine' => 'FAIL ' . gmdate('Y-m-d\TH:i:s\Z', time() - 1800) . ' (check)', 'emails' => true],
    'variants' => ['all-clear' => ['alerts' => [], 'healthLine' => 'OK ' . gmdate('Y-m-d\TH:i:s\Z', time() - 900) . ' (check)'], 'no-health' => ['healthLine' => null, 'emails' => false]],
];
