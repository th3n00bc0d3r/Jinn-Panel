<?php
return [
    'layout' => 'cpanel', 'role' => 'user', 'path' => '/cpanel/cron',
    'data' => ['title' => 'Cron jobs', 'feature' => 'cron'],
    'features' => ['cron' => false, 'backups' => false],
];
