<?php
require_once __DIR__ . '/../../_sample_config.php';
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/server-config/tuning',
    'data' => ['title' => 'Server Tweaks', 'hardware' => cfg_hardware(), 'preview' => 'extreme', 'computed' => cfg_tuning('extreme')],
    'variants' => [
        'balanced' => ['preview' => 'balanced', 'computed' => cfg_tuning('balanced')],
        'performance' => ['preview' => 'performance', 'computed' => cfg_tuning('performance')],
    ],
];
