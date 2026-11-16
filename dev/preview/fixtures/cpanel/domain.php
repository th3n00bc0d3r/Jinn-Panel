<?php
require_once __DIR__ . '/../_sample.php';
$d = sample_domains()[0];
return [
    'layout' => 'cpanel', 'role' => 'user', 'path' => '/cpanel/domains/1',
    'data' => [
        'title' => 'acme.test', 'd' => $d, 'ssl' => sample_ssl('ok'), 'cert' => ['valid_to' => time() + 86400 * 74, 'issuer' => 'Let\'s Encrypt'],
        'addresses' => ['203.0.113.10', '2001:db8::10'], 'siteDir' => '/var/www/acme.test', 'docroot' => '/var/www/acme.test/public', 'routes' => true,
        'php' => ['memory_limit' => '256M', 'display_errors' => 'Off'], 'phpLog' => '/var/www/acme.test/logs/php-error.log',
        'aliases' => ['acme.example', 'acme-shop.test'], 'phpVersions' => ['8.4', '8.3', '8.2'],
    ],
    'variants' => [
        'limited-package' => ['features' => ['file_manager' => false, 'cron' => false, 'backups' => false, 'dns_editor' => false, 'php_versions' => false, 'aliases' => false, 'routes' => false, 'object_cache' => false, 'database_access' => false, 'ssh_keys' => false]],
        'dns-elsewhere' => ['d' => sample_domains()[2], 'ssl' => sample_ssl('no_dns'), 'cert' => null, 'addresses' => ['198.51.100.7'], 'aliases' => [], 'routes' => false],
    ],
];
