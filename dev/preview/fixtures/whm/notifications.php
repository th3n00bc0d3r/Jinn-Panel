<?php
return [
    'layout' => 'whm', 'role' => 'admin', 'path' => '/whm/notifications',
    'data' => [
        'title' => 'Notifications',
        's' => ['from' => 'noreply@example.com', 'from_name' => 'Example Hosting', 'admin_email' => '', 'welcome' => true, 'alerts' => true, 'quota' => true, 'suspension' => false],
        'from' => 'noreply@example.com', 'recipients' => ['admin@example.com'], 'me' => ['email' => 'admin@example.com'],
    ],
    'variants' => ['defaults' => ['s' => ['from' => '', 'from_name' => '', 'admin_email' => '', 'welcome' => true, 'alerts' => true, 'quota' => true, 'suspension' => false], 'from' => 'noreply@server.example.com', 'recipients' => []]],
];
