<?php
require_once __DIR__ . '/../_sample.php';
$acme = sample_accounts()[1];
$bakery = sample_accounts()[2];
$items = [
    ['what' => 'Disk', 'used' => '3.2 GB', 'limit' => '1 GB', 'effect' => 'Uploads and new mailboxes, databases and domains are refused until it frees space.'],
    ['what' => 'Domains', 'used' => '3', 'limit' => '1', 'effect' => 'It keeps them all, but can\'t add more until it is under the limit.'],
    ['what' => 'Cron jobs', 'used' => 'On', 'limit' => 'Off', 'effect' => 'Switched off for this account.'],
];
return [
    'layout' => 'whm', 'role' => 'admin', 'path' => '/whm/accounts/10/package',
    'data' => [
        'title' => 'Move acme to Starter?',
        'intro' => '"acme" already has more than Starter allows. Nothing is deleted; this is what happens:',
        'rows' => [['account' => $acme, 'items' => $items]],
        'action' => '/whm/accounts/10/package', 'hidden' => ['package_id' => 2], 'confirm' => 'Move anyway', 'back' => '/whm/accounts/10#features',
    ],
    'variants' => [
        'bulk' => [
            'title' => 'Move 3 accounts to Starter?',
            'intro' => '2 of them already have more than Starter allows. Nothing is deleted; this is what happens:',
            'rows' => [['account' => $acme, 'items' => $items], ['account' => $bakery, 'items' => array_slice($items, 0, 1)]],
            'action' => '/whm/accounts/bulk', 'hidden' => ['op' => 'package', 'package_id' => 2, 'ids' => [10, 11, 12]], 'confirm' => 'Move all 3', 'back' => '/whm/accounts',
        ],
    ],
];
