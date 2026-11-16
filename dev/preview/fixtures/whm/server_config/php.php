<?php
require_once __DIR__ . '/../../_sample_config.php';
$current = [
    'memory_limit' => '256M', 'max_execution_time' => '60', 'upload_max_filesize' => '64M', 'post_max_size' => '64M',
    'opcache.memory_consumption' => '256', 'opcache.max_accelerated_files' => '20000', 'opcache.jit' => 'tracing',
    'opcache.jit_buffer_size' => '64M', 'opcache.validate_timestamps' => '1', 'opcache.revalidate_freq' => '2',
];
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/server-config/php',
    'data' => ['title' => 'PHP Settings', 'current' => $current, 'lastLog' => cfg_log('OK', 26 * 3600)],
    'variants' => [
        // php.ini without these lines: PHP's own defaults.
        'defaults' => ['current' => [], 'lastLog' => null],
        // A check interval set by hand that isn't one of the choices.
        'custom-interval' => ['current' => ['opcache.revalidate_freq' => '10', 'opcache.jit' => 'off'] + $current],
        'failed' => ['lastLog' => cfg_log('FAILED: systemctl restart frankenphp exited with 1', 90)],
    ],
];
