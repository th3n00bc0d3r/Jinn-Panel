<?php
require_once __DIR__ . '/../_sample.php';
require_once __DIR__ . '/../_sample_stats.php';
$a = sample_accounts()[1];
$o = [
    'package' => sample_pkg(), 'parent' => 'resell',
    'usage' => ['files_bytes' => 2_100_000_000, 'db_bytes' => 600_000_000, 'mail_bytes' => 700_000_000, 'bw_month' => date('Y-m'), 'bw_bytes' => 18_000_000_000, 'over_disk' => 0, 'over_bandwidth' => 0, 'updated_at' => date('Y-m-d H:i:s', time() - 1500)],
    'domains' => array_map(fn($d) => array_intersect_key($d, array_flip(['id', 'domain_name', 'php_version', 'ssl_mode', 'created_at'])), sample_domains()),
    'counts' => ['domains' => 3, 'databases' => 2, 'mailboxes' => 7, 'sftp' => 1, 'accounts' => 0],
    'activity' => sample_activity(),
];
return [
    'layout' => 'whm', 'role' => 'admin', 'path' => '/whm/accounts/10',
    'data' => [
        'title' => 'acme', 'a' => $a, 'me' => ['id' => 1, 'role' => 'admin', 'username' => 'admin'], 'o' => $o, 'lastAdmin' => false,
        'packages' => sample_packages(),
        'pkgFeatures' => ['file_manager' => true, 'cron' => true, 'backups' => true, 'dns_editor' => true, 'php_versions' => true, 'aliases' => true, 'routes' => false, 'object_cache' => true, 'database_access' => false, 'ssh_keys' => true],
        'overrides' => ['database_access' => true, 'backups' => false],
        'alloc' => null,
        'history' => stats_fx_account_history(5120 * 1048576),
        'projection' => [
            'disk' => ['days' => 19, 'note' => 'Reaches its limit around 3 Dec at this rate', 'perDay' => 52e6],
            'bw' => ['days' => 10, 'note' => 'Reaches its limit around 24 Nov at this pace', 'projected' => 130e9],
        ],
    ],
    'variants' => [
        'over-disk' => ['history' => stats_fx_account_history(5120 * 1048576, 0.9, 1.08), 'projection' => ['disk' => ['days' => 0, 'note' => 'Over since 9 Nov', 'perDay' => 0], 'bw' => ['days' => null, 'note' => 'About 41 GB by the end of the month', 'projected' => 41e9]]],
        'no-history' => ['history' => ['labels' => [], 'disk' => [], 'files' => [], 'db' => [], 'mail' => [], 'bw' => [], 'requests' => [], 'has' => false], 'projection' => []],
        'reseller-account' => ['alloc' => sample_alloc(), 'a' => sample_accounts()[0], 'o' => ['package' => sample_packages()[2], 'parent' => null, 'usage' => null, 'domains' => [], 'counts' => ['domains' => 0, 'databases' => 0, 'mailboxes' => 0, 'sftp' => 0, 'accounts' => 12], 'activity' => []]],
        'reseller-over' => ['alloc' => sample_alloc(true), 'a' => sample_accounts()[0], 'o' => ['package' => sample_packages()[2], 'parent' => null, 'usage' => null, 'domains' => [], 'counts' => ['domains' => 0, 'databases' => 0, 'mailboxes' => 0, 'sftp' => 0, 'accounts' => 12], 'activity' => []]],
        'unlimited-package' => ['o' => ['package' => sample_packages()[3]] + ['parent' => 'resell', 'usage' => null, 'domains' => [], 'counts' => ['domains' => 7, 'databases' => 12, 'mailboxes' => 40, 'sftp' => 2, 'accounts' => 0], 'activity' => []]],
        'suspended' => ['a' => sample_accounts()[2], 'o' => ['usage' => null, 'domains' => [], 'activity' => []] + $o],
        'last-admin' => ['a' => ['id' => 5, 'username' => 'ops', 'email' => 'ops@example.com', 'role' => 'admin', 'status' => 'active', 'full_name' => null, 'parent_id' => null, 'package_id' => null, 'php_exec' => 0, 'totp_secret_enc' => 'x', 'created_at' => '2026-10-01 09:00:00'], 'o' => ['package' => null, 'parent' => null, 'usage' => null, 'domains' => [], 'counts' => ['domains' => 0, 'databases' => 0, 'mailboxes' => 0, 'sftp' => 0, 'accounts' => 0], 'activity' => []], 'lastAdmin' => true],
    ],
];
