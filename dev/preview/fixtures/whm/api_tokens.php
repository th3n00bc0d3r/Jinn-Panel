<?php
$fns = ['version', 'applist', 'listpkgs', 'listaccts', 'accountsummary', 'createacct', 'changepackage', 'suspendacct', 'unsuspendacct', 'removeacct'];
return [
    'layout' => 'whm', 'role' => 'admin', 'path' => '/whm/api-tokens',
    'data' => [
        'title' => 'API Tokens',
        'me' => ['id' => 1, 'role' => 'admin', 'username' => 'admin'],
        'tokens' => [
            ['id' => 2, 'user_id' => 1, 'name' => 'Order form on example.com', 'token_hint' => 'Q7K2ZD', 'allowed_ips' => '203.0.113.7', 'last_used_at' => '2026-11-20 14:02:11', 'last_used_ip' => '203.0.113.7', 'created_at' => '2026-11-01 09:30:00', 'owner_username' => 'admin'],
            ['id' => 1, 'user_id' => 4, 'name' => 'Billing', 'token_hint' => 'A93MXP', 'allowed_ips' => null, 'last_used_at' => null, 'last_used_ip' => null, 'created_at' => '2026-10-12 18:00:00', 'owner_username' => 'reseller1'],
        ],
        'newToken' => ['name' => 'Order form on example.com', 'token' => 'Q7K2ZDN4W8YB1C5E6F7G8H9J0K1L2M3N', 'username' => 'admin'],
        'endpoint' => 'https://panel.example.com/json-api/',
        'functions' => $fns,
    ],
    'variants' => ['empty' => ['tokens' => [], 'newToken' => null]],
];
