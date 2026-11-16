<?php
declare(strict_types=1);

/** Sample rows shared by the fixtures. Files starting with _ are not fixtures. */

function sample_pkg(): array
{
    return ['id' => 1, 'owner_id' => null, 'name' => 'Business', 'disk_quota_mb' => 5120, 'bandwidth_mb' => 102400, 'max_domains' => 5, 'max_databases' => 10, 'max_email_accounts' => 25, 'max_ftp_accounts' => 3, 'max_accounts' => 0];
}

function sample_usage(): array
{
    return ['domains' => 3, 'databases' => 2, 'email_accounts' => 7, 'ftp_accounts' => 1];
}

function sample_ssl(string $state = 'ok'): array
{
    return match ($state) {
        'ok' => ['state' => 'ok', 'label' => 'Let\'s Encrypt', 'detail' => 'Valid until 12 Feb 2027, renewed automatically.'],
        'no_dns' => ['state' => 'no_dns', 'label' => 'DNS not pointing here', 'detail' => 'The domain resolves to 198.51.100.7, not this server. Fix DNS, then run AutoSSL.'],
        default => ['state' => 'pending', 'label' => 'Self-signed', 'detail' => 'Switches to Let\'s Encrypt once the domain points here.'],
    };
}

function sample_domains(): array
{
    $base = ['user_id' => 10, 'dns_provisioned' => 1, 'php_port' => null, 'ssl_pinned' => 0, 'routes_sync' => null, 'routes_review' => 0, 'page_cache_ttl' => 0, 'created_at' => '2026-10-02 10:00:00'];
    return [
        $base + ['id' => 1, 'domain_name' => 'acme.test', 'docroot' => '/var/www/acme.test/public', 'php_version' => 'default', 'ssl_mode' => 'letsencrypt', 'ssl' => sample_ssl('ok'), 'page_cache_ttl' => 300],
        $base + ['id' => 2, 'domain_name' => 'shop.acme.test', 'docroot' => '/var/www/shop.acme.test/public', 'php_version' => '8.3', 'ssl_mode' => 'letsencrypt', 'ssl' => sample_ssl('ok'), 'routes_review' => 1],
        $base + ['id' => 3, 'domain_name' => 'acme-staging.example', 'docroot' => '/var/www/acme-staging.example/public/app/public', 'php_version' => '8.2', 'ssl_mode' => 'self_signed', 'ssl' => sample_ssl('no_dns'), 'dns_provisioned' => 0],
    ];
}

function sample_packages(): array
{
    return [
        sample_pkg() + ['owner_username' => null, 'account_count' => 6],
        ['id' => 2, 'owner_id' => null, 'name' => 'Starter', 'disk_quota_mb' => 1024, 'bandwidth_mb' => 10240, 'max_domains' => 1, 'max_databases' => 1, 'max_email_accounts' => 5, 'max_ftp_accounts' => 1, 'max_accounts' => 0, 'owner_username' => null, 'account_count' => 0,
            'features' => '{"file_manager":true,"cron":false,"backups":true,"dns_editor":true,"php_versions":false,"aliases":false,"routes":false,"object_cache":false,"database_access":true,"ssh_keys":true}'],
        ['id' => 3, 'owner_id' => null, 'name' => 'Reseller 50', 'disk_quota_mb' => 51200, 'bandwidth_mb' => 512000, 'max_domains' => 50, 'max_databases' => 50, 'max_email_accounts' => 200, 'max_ftp_accounts' => 20, 'max_accounts' => 50, 'owner_username' => null, 'account_count' => 1],
        ['id' => 5, 'owner_id' => null, 'name' => 'Unlimited', 'disk_quota_mb' => -1, 'bandwidth_mb' => -1, 'max_domains' => -1, 'max_databases' => -1, 'max_email_accounts' => -1, 'max_ftp_accounts' => 10, 'max_accounts' => 0, 'owner_username' => null, 'account_count' => 1],
        ['id' => 4, 'owner_id' => 2, 'name' => 'Agency Pro', 'disk_quota_mb' => 20480, 'bandwidth_mb' => 204800, 'max_domains' => 10, 'max_databases' => 20, 'max_email_accounts' => 50, 'max_ftp_accounts' => 5, 'max_accounts' => 0, 'owner_username' => 'resell', 'account_count' => 2],
    ];
}

function sample_accounts(): array
{
    $u = ['full_name' => null, 'parent_id' => null, 'php_exec' => 0, 'totp_secret_enc' => null, 'created_at' => '2026-10-01 09:00:00'];
    return [
        ['id' => 2, 'username' => 'resell', 'email' => 'sales@reseller.test', 'role' => 'reseller', 'status' => 'active', 'package_id' => 3, 'package_name' => 'Reseller 50', 'reseller_username' => null] + $u,
        ['id' => 10, 'username' => 'acme', 'email' => 'owner@acme.test', 'role' => 'user', 'status' => 'active', 'package_id' => 1, 'package_name' => 'Business', 'reseller_username' => 'resell', 'php_exec' => 1, 'totp_secret_enc' => 'x', 'full_name' => 'Acme Ltd'] + $u,
        ['id' => 11, 'username' => 'bakery', 'email' => 'hello@bakery.test', 'role' => 'user', 'status' => 'suspended', 'package_id' => 2, 'package_name' => 'Starter', 'reseller_username' => null] + $u,
        ['id' => 12, 'username' => 'blogger', 'email' => 'me@blogger.test', 'role' => 'user', 'status' => 'active', 'package_id' => 1, 'package_name' => 'Business', 'reseller_username' => null] + $u,
    ];
}

function sample_alloc(bool $over = false): array
{
    return [
        'package' => sample_packages()[3],
        'accounts' => ['used' => 12, 'cap' => 50],
        'disk' => ['used' => $over ? 61440 : 30720, 'cap' => 51200],
        'bandwidth' => ['used' => 307200, 'cap' => 512000],
        'domains' => ['used' => $over ? -1 : 31, 'cap' => 50],
    ];
}

function sample_alerts(): array
{
    return [
        ['key' => 'service:sftpgo', 'level' => 'danger', 'title' => 'Sftpgo is not answering', 'detail' => 'Nothing accepts connections on its port. Check: systemctl status sftpgo', 'href' => '/whm'],
        ['key' => 'backup:41', 'level' => 'danger', 'title' => 'Backup failed: bakery', 'detail' => 'Backup #41 - see its log in Backups.', 'href' => '/whm/backups'],
        ['key' => 'quota:disk:11', 'level' => 'warning', 'title' => 'bakery is over its disk quota', 'detail' => 'Uploads, new databases, mailboxes and domains are refused until it frees space or gets a bigger package.', 'href' => '/whm/accounts/11'],
        ['key' => 'cert:shop.acme.test', 'level' => 'warning', 'title' => 'Certificate expires soon: shop.acme.test', 'detail' => 'Expires 20 Nov 2026. Renewal normally happens 30 days ahead - check that the domain still points here.', 'href' => '/whm/accounts/10'],
        ['key' => 'routes:review', 'level' => 'info', 'title' => '2 domains have routing rules to review', 'detail' => 'Some .htaccess rules couldn\'t be translated automatically; the customer sees this on the domain\'s Routes tab.', 'href' => null],
    ];
}

function sample_activity(): array
{
    $now = time();
    return [
        ['created_at' => date('Y-m-d H:i:s', $now - 120), 'action' => 'cpanel.domains', 'detail' => 'Domain "acme.test" is set up - it goes live within a few seconds.', 'actor_name' => 'acme', 'target_name' => 'acme'],
        ['created_at' => date('Y-m-d H:i:s', $now - 3600), 'action' => 'whm.accounts.suspend', 'detail' => 'Account "bakery" is now suspended.', 'actor_name' => 'admin', 'target_name' => 'bakery'],
        ['created_at' => date('Y-m-d H:i:s', $now - 7200), 'action' => 'login', 'detail' => 'admin (with two-factor)', 'actor_name' => 'admin', 'target_name' => null],
        ['created_at' => date('Y-m-d H:i:s', $now - 90000), 'action' => 'impersonate.start', 'detail' => 'resell signed in as acme', 'actor_name' => 'resell', 'target_name' => 'acme'],
    ];
}

function sample_files(): array
{
    $t = time();
    return [
        ['name' => 'app', 'is_dir' => true, 'is_link' => false, 'size' => 0, 'modified' => $t - 86400 * 3, 'mode' => '755', 'perms' => 'drwxr-xr-x'],
        ['name' => 'public', 'is_dir' => true, 'is_link' => false, 'size' => 0, 'modified' => $t - 3600, 'mode' => '755', 'perms' => 'drwxr-xr-x'],
        ['name' => '.htaccess', 'is_dir' => false, 'is_link' => false, 'size' => 412, 'modified' => $t - 600, 'mode' => '644', 'perms' => '-rw-r--r--'],
        ['name' => 'backup-2026.zip', 'is_dir' => false, 'is_link' => false, 'size' => 48_234_112, 'modified' => $t - 86400 * 40, 'mode' => '644', 'perms' => '-rw-r--r--'],
        ['name' => 'index.php', 'is_dir' => false, 'is_link' => false, 'size' => 1840, 'modified' => $t - 7200, 'mode' => '644', 'perms' => '-rw-r--r--'],
        ['name' => 'logo.png', 'is_dir' => false, 'is_link' => false, 'size' => 23_110, 'modified' => $t - 86400 * 9, 'mode' => '644', 'perms' => '-rw-r--r--'],
        ['name' => 'shared', 'is_dir' => false, 'is_link' => true, 'size' => 0, 'modified' => $t - 86400, 'mode' => '777', 'perms' => 'lrwxrwxrwx'],
    ];
}
