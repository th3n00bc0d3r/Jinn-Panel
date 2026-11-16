<?php
require_once __DIR__ . '/../_sample_mail.php';
// CacheController adds cached_pages, static and static_entries to each domain row.
$domains = sample_mail_domains();
$domains[0] = ['page_cache_ttl' => 300, 'cached_pages' => 184, 'static' => false, 'static_entries' => 1342] + $domains[0];
$domains[1] = ['page_cache_ttl' => null, 'static_cache_ttl' => 3600, 'browser_cache' => 0, 'cached_pages' => 0, 'static' => false, 'static_entries' => 87] + $domains[1];
$domains[2] = ['page_cache_ttl' => null, 'static_cache_ttl' => 0, 'cached_pages' => 0, 'static' => true, 'static_entries' => null] + $domains[2];
return [
    'layout' => 'cpanel',
    'role' => 'user',
    'path' => '/cpanel/cache',
    'data' => [
        'title' => 'Cache',
        'domains' => $domains,
        'creds' => ['user' => 'acct_acme', 'password' => '3f9a1c27be04d6e58a1f0c93b7d24e6a5c8f1b02', 'prefix' => 'acme:'],
        'keys' => 4821,
    ],
    'variants' => [
        'limited-package' => ['features' => ['file_manager' => false, 'cron' => false, 'backups' => false, 'dns_editor' => false, 'php_versions' => false, 'aliases' => false, 'routes' => false, 'object_cache' => false, 'database_access' => false, 'ssh_keys' => false], 'data' => ['creds' => null, 'keys' => null]],
        'object-off' => ['creds' => null, 'keys' => null],
        'empty' => ['domains' => [], 'creds' => null, 'keys' => null],
    ],
];
