<?php
declare(strict_types=1);

/**
 * Sample rows for the operations pages (backups, activity log, cPanel
 * migration). Files starting with _ are not fixtures. Times are relative to
 * now so "5 min ago" stays true.
 */

/** A DATETIME $seconds ago. */
function ops_ago(int $seconds): string
{
    return date('Y-m-d H:i:s', time() - $seconds);
}

/** A backups row (BackupService::recent). */
function ops_backup(array $x): array
{
    return $x + [
        'kind' => 'account', 'user_id' => 10, 'username' => 'acme', 'status' => 'done', 'path' => null,
        'size_bytes' => 0, 'parts' => null, 'remote' => null, 'log' => null, 'finished_at' => null,
    ];
}

function ops_backup_parts(string $user, array $domains, array $dbs, array $mailboxes): string
{
    return (string) json_encode(['version' => 1, 'username' => $user, 'created' => date('c'), 'domains' => $domains, 'databases' => $dbs,
        'mailboxes' => array_fill_keys($mailboxes, ['INBOX' => 'INBOX', 'Sent' => 'Sent'])]);
}

/** @return list<array<string,mixed>> newest first */
function ops_backups(): array
{
    $day = 86400;
    $acme = ops_backup_parts('acme', ['acme.test', 'shop.acme.test', 'acme-staging.example'], ['acme_wp', 'acme_shop'], ['info@acme.test', 'sales@acme.test', 'jo@acme.test', 'support@acme.test']);
    $bakery = ops_backup_parts('bakery', ['bakery.test'], ['bakery_wp'], ['hello@bakery.test']);
    $server = (string) json_encode(['version' => 1, 'created' => date('c'), 'paths' => ['/etc/frankenphp', '/etc/jinnpanel', '/etc/stalwart', '/etc/knot']]);
    $log = fn(string ...$lines) => implode("\n", array_map(fn($l, $i) => '[03:0' . $i . ':1' . $i . '] ' . $l, $lines, array_keys($lines)));
    return [
        ops_backup(['id' => 214, 'kind' => 'server', 'user_id' => null, 'username' => '', 'path' => '/var/backups/jinnpanel/_server/20261113-030512', 'size_bytes' => 48_300_000, 'parts' => $server, 'remote' => 'uploaded',
            'created_at' => ops_ago(3 * 3600), 'finished_at' => ops_ago(3 * 3600 - 40),
            'log' => $log('panel database', 'server configuration', 'done: 46.1 MB', 'off-site copy uploaded (jinnpanel/_server/20261113-030512/)')]),
        ops_backup(['id' => 213, 'path' => '/var/backups/jinnpanel/acme/20261113-030140', 'size_bytes' => 2_480_000_000, 'parts' => $acme, 'remote' => 'uploaded',
            'created_at' => ops_ago(3 * 3600 + 300), 'finished_at' => ops_ago(3 * 3600 + 60),
            'log' => $log('site files: acme.test, shop.acme.test, acme-staging.example', 'database acme_wp', 'database acme_shop', 'mailbox info@acme.test', 'done: 2.3 GB', 'off-site copy uploaded (jinnpanel/acme/20261113-030140/)')]),
        ops_backup(['id' => 212, 'user_id' => 11, 'username' => 'bakery', 'path' => '/var/backups/jinnpanel/bakery/20261113-030100', 'size_bytes' => 188_000_000, 'parts' => $bakery, 'remote' => 'uploaded',
            'created_at' => ops_ago(3 * 3600 + 340), 'finished_at' => ops_ago(3 * 3600 + 310), 'log' => $log('site files: bakery.test', 'database bakery_wp', 'mailbox hello@bakery.test', 'done: 179.3 MB')]),
        ops_backup(['id' => 211, 'user_id' => 12, 'username' => 'blogger', 'status' => 'failed', 'created_at' => ops_ago(3 * 3600 + 400), 'finished_at' => ops_ago(3 * 3600 + 390),
            'log' => $log('FAILED: Not enough free disk space for this backup (3.1 GB free, about 7.4 GB needed).')]),
        ops_backup(['id' => 207, 'kind' => 'server', 'user_id' => null, 'username' => '', 'path' => '/var/backups/jinnpanel/_server/20261112-030455', 'size_bytes' => 47_900_000, 'parts' => $server, 'remote' => 'failed',
            'created_at' => ops_ago($day + 3 * 3600), 'finished_at' => ops_ago($day + 3 * 3600 - 35),
            'log' => $log('panel database', 'server configuration', 'done: 45.7 MB', 'off-site upload failed: The bucket "acme-offsite" does not exist.')]),
        ops_backup(['id' => 206, 'path' => '/var/backups/jinnpanel/acme/20261112-030130', 'size_bytes' => 2_460_000_000, 'parts' => $acme, 'created_at' => ops_ago($day + 3 * 3600 + 300)]),
        ops_backup(['id' => 205, 'user_id' => 11, 'username' => 'bakery', 'path' => '/var/backups/jinnpanel/bakery/20261112-030100', 'size_bytes' => 187_500_000, 'parts' => $bakery, 'created_at' => ops_ago($day + 3 * 3600 + 340)]),
        ops_backup(['id' => 204, 'user_id' => 12, 'username' => 'blogger', 'path' => '/var/backups/jinnpanel/blogger/20261112-030050', 'size_bytes' => 912_000_000,
            'parts' => ops_backup_parts('blogger', ['blogger.test'], ['blogger_ghost'], []), 'created_at' => ops_ago($day + 3 * 3600 + 400)]),
        ops_backup(['id' => 190, 'user_id' => null, 'username' => 'oldshop', 'path' => '/var/backups/jinnpanel/oldshop/20261103-030040', 'size_bytes' => 640_000_000,
            'parts' => ops_backup_parts('oldshop', ['oldshop.test'], ['oldshop_db'], ['orders@oldshop.test']), 'created_at' => ops_ago(10 * $day + 3 * 3600)]),
    ];
}

/** An activity_log row with actor_name / target_name joined. */
function ops_activity(int $id, int $ago, string $action, ?string $detail, ?string $actor, ?string $target = null, ?string $ip = '198.51.100.24'): array
{
    $ids = ['admin' => 1, 'resell' => 2, 'acme' => 10, 'bakery' => 11, 'blogger' => 12];
    return [
        'id' => $id, 'actor_id' => $actor !== null ? ($ids[$actor] ?? 99) : null, 'action' => $action, 'detail' => $detail,
        'created_at' => ops_ago($ago), 'ip' => $ip, 'target_user_id' => $target !== null ? ($ids[$target] ?? null) : null,
        'actor_name' => $actor, 'target_name' => $target,
    ];
}

/** @return list<array<string,mixed>> newest first */
function ops_activity_rows(): array
{
    $h = 3600;
    $d = 86400;
    return [
        ops_activity(5120, 90, 'whm.accounts.suspend', 'Account bakery suspended.', 'admin', 'bakery'),
        ops_activity(5119, 6 * 60, 'cpanel.domains', 'Domain "shop.acme.test" is set up - it goes live within a few seconds.', 'acme', 'acme', '192.0.2.44'),
        ops_activity(5118, 22 * 60, 'login.failed', 'Wrong password for "admin".', null, null, '203.0.113.99'),
        ops_activity(5117, 23 * 60, 'login.blocked', 'Too many failed sign-ins from 203.0.113.99 - blocked for 15 minutes.', null, null, '203.0.113.99'),
        ops_activity(5116, 2 * $h, 'whm.backups.settings', 'Backup settings saved.', 'admin'),
        ops_activity(5115, 3 * $h, 'backup.download', '#213 files.tar.gz', 'admin', 'acme'),
        ops_activity(5114, 5 * $h, 'cpanel.email.delete', 'Mailbox old@acme.test deleted.', 'acme', 'acme', '192.0.2.44'),
        ops_activity(5113, 9 * $h, 'whm.migrations.connect', 'Connected to server.oldhost.com - 12 accounts found.', 'resell', null, '198.51.100.80'),
        ops_activity(5112, $d + 2 * $h, 'cpanel.databases.failed', 'A database named "acme_wp" already exists.', 'acme', 'acme', '192.0.2.44'),
        ops_activity(5111, $d + 4 * $h, 'whm.packages.delete', 'Package "Trial" deleted.', 'admin'),
        ops_activity(5110, 2 * $d, 'login', 'Signed in.', 'resell', 'resell', '198.51.100.80'),
        ops_activity(5109, 2 * $d + $h, 'whm.accounts.reset-2fa', 'Two-factor sign-in reset for blogger.', 'admin', 'blogger'),
        ops_activity(5108, 9 * $d, 'cpanel.files.upload', 'Uploaded 14 files to /var/www/acme.test/public/wp-content/uploads/2026/11 (38.2 MB) - existing files with the same names were replaced.', 'acme', 'acme', '2001:db8:85a3::8a2e:370:7334'),
    ];
}

/** A migrations row (MigrationService::find / listFor without the joins). */
function ops_migration(array $x = []): array
{
    return $x + [
        'id' => 13, 'created_by' => 1, 'source_type' => 'reseller', 'source_host' => 'server.oldhost.com', 'source_port' => 2087,
        'source_user' => 'oldresell', 'auth_type' => 'token', 'secret_enc' => 'v1:preview', 'verify_tls' => 1, 'transfer_mode' => 'pull',
        'options' => null, 'status' => 'running', 'cancel_requested' => 0, 'created_at' => ops_ago(40 * 60),
        'started_at' => ops_ago(25 * 60), 'heartbeat_at' => ops_ago(20), 'finished_at' => null,
    ];
}

/** A migration_items row; $report is the decoded report (show.php also gets it as report_data). */
function ops_item(int $id, string $user, ?string $domain, array $x = [], array $report = []): array
{
    return $x + [
        'id' => $id, 'migration_id' => 13, 'source_username' => $user, 'source_domain' => $domain, 'source_owner' => 'oldresell',
        'source_plan' => 'Business', 'is_reseller' => 0, 'selected' => 1, 'mail_restore' => 0, 'status' => 'pending', 'step' => 'Waiting',
        'progress' => 0, 'report' => json_encode($report), 'report_data' => $report, 'error' => null, 'target_user_id' => null,
        'started_at' => null, 'finished_at' => null,
    ];
}

/** A finished account's report, as MigrationRunner writes it. */
function ops_report(string $user, string $domain, bool $problems = false): array
{
    return [
        'source' => ['email' => "owner@$domain", 'disk_used_mb' => 2300, 'suspended' => false],
        'account' => ['username' => $user, 'password' => $problems ? 'generated' : 'preserved', 'generated_password' => $problems ? 'Vq7-pL2xWm9!sKe4' : null, 'package' => 'Business', 'owner' => 'resell'],
        'domains' => [
            ['name' => $domain, 'type' => 'main', 'status' => 'ok', 'note' => ''],
            ['name' => "shop.$domain", 'type' => 'addon', 'status' => 'ok', 'note' => ''],
            ['name' => "www2.$domain", 'type' => 'parked', 'status' => 'ok', 'note' => "Alias of $domain (serves the same site)."],
        ],
        'databases' => [
            ['name' => "{$user}_wp", 'status' => 'ok', 'size' => '84.2 MB'],
            ['name' => "{$user}_shop", 'status' => $problems ? 'partial' : 'ok', 'size' => '12.9 MB', 'note' => $problems ? '2 views could not be imported (views aren\'t migrated).' : ''],
        ],
        'db_users' => [
            ['name' => "{$user}_wp", 'status' => 'ok', 'databases' => ["{$user}_wp"], 'password' => 'preserved'],
            ['name' => "{$user}_ro", 'status' => 'ok', 'databases' => ["{$user}_shop"], 'password' => 'generated', 'generated_password' => 'h3Rk-9vLq2Zx', 'note' => 'Created because no user in the backup had access to this database.'],
        ],
        'email' => array_merge(
            [['address' => "info@$domain", 'status' => 'ok', 'password' => 'preserved', 'messages' => 4182, 'note' => '']],
            [['address' => "sales@$domain", 'status' => 'ok', 'password' => 'preserved', 'messages' => 917, 'note' => '']],
            $problems ? [['address' => "archive@$domain", 'status' => 'failed', 'note' => 'IMAP: mailbox quota exceeded while copying "Archive/2019".']] : []
        ),
        'forwarders' => [['address' => "orders@$domain", 'status' => 'ok', 'note' => "to sales@$domain"]],
        'ftp' => [['name' => "deploy@$domain", 'status' => 'ok', 'note' => 'As an SFTP account.']],
        'warnings' => $problems ? [
            'Cron job not recreated: */5 * * * * /usr/local/bin/ea-php74 /home/' . $user . '/cron.php',
            "DNS record not imported for $domain: SRV _sip._tcp (unsupported target)",
        ] : [],
        'info' => ["The backup file /home/$user/backup-11.13.2026_09-12-44_$user.tar.gz was left in the source account's home directory - delete it there once you've checked the migration."],
    ];
}
