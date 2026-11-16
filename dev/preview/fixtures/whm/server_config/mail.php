<?php
require_once __DIR__ . '/../../_sample_config.php';
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/server-config/mail',
    'data' => ['title' => 'Mail Server Settings', 'groups' => StalwartAdminService::SETTINGS_GROUPS],
];
