<?php
require_once __DIR__ . '/../_sample.php';
return [
    'layout' => 'cpanel', 'role' => 'user', 'path' => '/cpanel/ftp',
    'data' => [
        'title' => 'FTP / SFTP Accounts',
        'accounts' => [
            ['id' => 1, 'username' => 'acme_uploads', 'home_dir' => '/var/lib/jinnpanel/sftp/acme', 'public_keys' => "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl me@laptop", 'created_at' => '2026-10-04 10:00:00'],
            ['id' => 2, 'username' => 'acme_shopdev', 'home_dir' => '/var/www/shop.acme.test', 'public_keys' => null, 'created_at' => '2026-10-09 10:00:00'],
        ],
        'domains' => sample_domains(), 'usage' => sample_usage(), 'pkg' => sample_pkg(),
    ],
    'variants' => ['limited-package' => ['features' => ['file_manager' => false, 'cron' => false, 'backups' => false, 'dns_editor' => false, 'php_versions' => false, 'aliases' => false, 'routes' => false, 'object_cache' => false, 'database_access' => false, 'ssh_keys' => false]], 'empty' => ['accounts' => []]],
];
