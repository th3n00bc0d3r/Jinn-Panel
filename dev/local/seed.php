<?php
declare(strict_types=1);

/**
 * Demo data for a local run (dev/local/setup.sh): an admin, a reseller and
 * two hosting accounts with domains, so every WHM and account page has
 * something to show. Safe to run again: existing rows are left alone.
 */

require __DIR__ . '/../../app/src/Config.php';
require __DIR__ . '/../../app/src/cli_bootstrap.php';
require __DIR__ . '/../../app/src/View.php';

$pdo = Database::app();
$adminPass = getenv('ADMIN_PASS') ?: 'admin-' . bin2hex(random_bytes(4));
$demoPass = 'demo-' . bin2hex(random_bytes(4));

$pkg = fn(string $name) => (int) $pdo->query('SELECT id FROM packages WHERE name = ' . $pdo->quote($name) . ' ORDER BY id LIMIT 1')->fetchColumn();
if (!$pkg('Business')) {
    $pdo->exec("INSERT INTO packages (owner_id, name, disk_quota_mb, bandwidth_mb, max_domains, max_databases, max_email_accounts, max_ftp_accounts) VALUES (NULL, 'Business', 5120, 51200, 5, 5, 25, 3)");
}
if (!$pkg('Basic (no extras)')) {
    $pdo->prepare("INSERT INTO packages (owner_id, name, disk_quota_mb, bandwidth_mb, max_domains, max_databases, max_email_accounts, max_ftp_accounts, features) VALUES (NULL, 'Basic (no extras)', 1024, 10240, 1, 1, 5, 1, ?)")
        ->execute([json_encode(['file_manager' => true, 'cron' => false, 'backups' => true, 'dns_editor' => false, 'php_versions' => false, 'aliases' => false, 'routes' => false, 'object_cache' => false, 'database_access' => false, 'ssh_keys' => false])]);
}

$users = [
    ['admin', 'admin@jinnpanel.local', 'Local Admin', 'admin', null, null, $adminPass],
    ['resell', 'sales@reseller.test', 'Demo Reseller', 'reseller', null, 'Reseller', $demoPass],
    ['acme', 'owner@acme.test', 'Acme Ltd', 'user', 'resell', 'Business', $demoPass],
    ['bakery', 'hello@bakery.test', 'The Bakery', 'user', null, 'Basic (no extras)', $demoPass],
];
$created = [];
foreach ($users as [$username, $email, $name, $role, $parent, $package, $pass]) {
    if ($pdo->query('SELECT id FROM users WHERE username = ' . $pdo->quote($username))->fetchColumn()) {
        continue;
    }
    $parentId = $parent ? (int) $pdo->query('SELECT id FROM users WHERE username = ' . $pdo->quote($parent))->fetchColumn() : null;
    $pdo->prepare('INSERT INTO users (username, email, password_hash, full_name, role, parent_id, package_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, \'active\')')
        ->execute([$username, $email, password_hash($pass, PASSWORD_BCRYPT), $name, $role, $parentId, $package ? ($pkg($package) ?: null) : null]);
    $created[] = [$username, $role, $pass];
}

$uid = fn(string $u) => (int) $pdo->query('SELECT id FROM users WHERE username = ' . $pdo->quote($u))->fetchColumn();
foreach ([['acme', 'acme.test', 'letsencrypt', 'default'], ['acme', 'shop.acme.test', 'letsencrypt', '8.3'], ['bakery', 'bakery.test', 'self_signed', 'default']] as [$u, $domain, $ssl, $php]) {
    $pdo->prepare('INSERT IGNORE INTO domains (user_id, domain_name, docroot, dns_provisioned, php_version, ssl_mode) VALUES (?, ?, ?, 1, ?, ?)')
        ->execute([$uid($u), $domain, "/var/www/$domain/public", $php, $ssl]);
}
$pdo->prepare('INSERT IGNORE INTO account_usage (user_id, files_bytes, db_bytes, mail_bytes, bw_month, bw_bytes, over_disk, over_bandwidth, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())')
    ->execute([$uid('acme'), 2_100_000_000, 600_000_000, 700_000_000, date('Y-m'), 18_000_000_000, 0]);
$pdo->prepare('INSERT IGNORE INTO account_usage (user_id, files_bytes, db_bytes, mail_bytes, bw_month, bw_bytes, over_disk, over_bandwidth, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())')
    ->execute([$uid('bakery'), 1_150_000_000, 20_000_000, 5_000_000, date('Y-m'), 900_000_000, 1]);

echo "\nLocal panel ready (database " . Config::DB_NAME . ").\n";
if ($created) {
    echo "Logins created now (note them; they aren't shown again):\n";
    foreach ($created as [$u, $role, $p]) {
        printf("  %-8s %-9s %s\n", $u, $role, $p);
    }
} else {
    echo "The demo logins already existed; their passwords are unchanged.\n";
}
echo "\nStart it with: dev/local/serve.sh   ->   http://127.0.0.1:8088 (use Chrome or Firefox)\n";
