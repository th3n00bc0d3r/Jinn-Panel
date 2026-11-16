<?php
require_once __DIR__ . '/../_sample.php';
return [
    'layout' => 'whm', 'role' => 'admin', 'path' => '/whm/accounts/create',
    'data' => ['title' => 'Create Account', 'account' => null, 'packages' => sample_packages(), 'roles' => ['user', 'reseller', 'admin'], 'welcome' => true],
    'variants' => [
        'edit' => ['title' => 'Edit acme', 'account' => sample_accounts()[1], 'roles' => ['user'], 'welcome' => false],
        'reseller-creating' => ['role' => 'reseller', 'data' => ['roles' => ['user'], 'welcome' => false]],
    ],
    'flash' => [['error', 'That username or email is already taken.']],
    'session' => ['old' => ['username' => 'acme', 'email' => 'owner@acme.test', 'full_name' => 'Acme Ltd', 'role' => 'user', 'package_id' => '1']],
];
