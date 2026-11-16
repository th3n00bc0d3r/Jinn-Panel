<?php
require_once __DIR__ . '/../../_sample_ops.php';
$admin = ['id' => 1, 'username' => 'admin', 'role' => 'admin'];
$log = implode("\n", [
    '[14:02:11] runner started for migration #13 (3 accounts)',
    '[14:02:12] acme: requesting a full backup from server.oldhost.com',
    '[14:09:40] acme: backup-11.13.2026_14-02-13_acme.tar.gz downloaded (2.3 GB)',
    '[14:12:03] acme: account created (id 31, package Business)',
    '[14:19:55] acme: done, with 2 warnings',
    '[14:20:01] bakery: requesting a full backup from server.oldhost.com',
    '[14:23:12] bakery: restoring mailboxes (2 of 3)',
]);
$acme = ops_item(31, 'acme', 'acme.test', ['status' => 'completed_with_errors', 'step' => 'Done, with notes', 'progress' => 100, 'target_user_id' => 31], ops_report('acme', 'acme.test', true));
$bakery = ops_item(32, 'bakery', 'bakery.test', ['status' => 'restoring', 'step' => 'Restoring mail: hello@bakery.test (2 of 3)', 'progress' => 62]);
$blogger = ops_item(33, 'blogger', 'blogger.test', ['is_reseller' => 0]);
$bakeryDone = ops_item(32, 'bakery', 'bakery.test', ['status' => 'completed', 'step' => 'Done', 'progress' => 100, 'target_user_id' => 32], ops_report('bakery', 'bakery.test'));
$bloggerFailed = ops_item(33, 'blogger', 'blogger.test', ['status' => 'failed', 'step' => 'Failed', 'progress' => 20,
    'error' => 'The source server refused the backup: the account is over its disk quota (cPanel: "Unable to create backup: Disk quota exceeded").']);
$running = ops_migration();
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/migrations/13',
    'data' => [
        'title' => 'Migration #13',
        'm' => $running,
        'items' => [$acme, $bakery, $blogger],
        'stalled' => false,
        'log' => $log,
        'me' => $admin,
    ],
    'variants' => [
        'finished' => ['m' => ops_migration(['status' => 'completed_with_errors', 'finished_at' => ops_ago(300)]), 'items' => [$acme, $bakeryDone, $bloggerFailed], 'log' => $log . "\n[14:41:07] migration finished: 2 of 3 accounts migrated"],
        'completed' => ['m' => ops_migration(['status' => 'completed', 'secret_enc' => null, 'finished_at' => ops_ago(86400)]), 'items' => [$bakeryDone], 'log' => $log],
        'failed' => ['m' => ops_migration(['id' => 10, 'source_type' => 'cpanel', 'source_host' => '198.51.100.7', 'source_port' => 2083, 'source_user' => 'blogger', 'secret_enc' => null, 'status' => 'failed', 'finished_at' => ops_ago(900)]),
            'title' => 'Migration #10', 'items' => [$bloggerFailed], 'log' => "[09:12:01] runner started for migration #10 (1 account)\n[09:14:40] blogger: FAILED: Unable to create backup: Disk quota exceeded"],
        'stalled' => ['m' => ops_migration(['heartbeat_at' => ops_ago(2400)]), 'stalled' => true, 'items' => [$acme, $bakery, $blogger]],
        'queued' => ['m' => ops_migration(['status' => 'queued', 'started_at' => null, 'heartbeat_at' => null]), 'items' => [ops_item(31, 'acme', 'acme.test'), ops_item(32, 'bakery', 'bakery.test')], 'log' => ''],
        'files' => ['m' => ops_migration(['id' => 12, 'source_type' => 'root', 'source_host' => 'backup files', 'source_port' => 0, 'source_user' => 'import', 'secret_enc' => null, 'transfer_mode' => 'file', 'status' => 'completed', 'finished_at' => ops_ago(3600)]),
            'title' => 'Migration #12', 'items' => [$bakeryDone]],
    ],
];
