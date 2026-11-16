<?php
require_once __DIR__ . '/../../_sample_config.php';
$sys = cfg_stalwart('x:SystemSettings');
$cache = cfg_stalwart('x:Cache');
$none = cfg_stalwart('x:TaskManager');
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/server-config/mail/x:SystemSettings',
    'data' => ['title' => 'x:SystemSettings', 'object' => 'x:SystemSettings'] + $sys,
    'variants' => [
        // Sizes in bytes, all numbers.
        'cache' => ['title' => 'x:Cache', 'object' => 'x:Cache'] + $cache,
        // Only server-set fields: nothing to edit.
        'nothing-editable' => ['title' => 'x:TaskManager', 'object' => 'x:TaskManager'] + $none,
    ],
];
