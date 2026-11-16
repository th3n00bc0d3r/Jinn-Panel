<?php
require_once __DIR__ . '/../_sample.php';
$db = fn(int $id, string $name, string $user) => ['id' => $id, 'user_id' => 10, 'db_name' => $name, 'db_user' => $user, 'created_at' => '2026-10-02 10:00:00'];
return [
    'layout' => 'cpanel',
    'role' => 'user',
    'path' => '/cpanel/databases',
    'flash' => [['success', 'Database acme_shop created, with user acme_shop (all privileges).']],
    'data' => [
        'title' => 'MySQL Databases',
        'dbs' => [$db(1, 'acme_shop', 'acme_shop'), $db(2, 'acme_wp', 'acme_wp'), $db(3, 'acme_staging_wordpress', 'acme_wp')],
        'users' => ['acme_reports', 'acme_shop', 'acme_wp'],
        'grants' => [
            'acme_shop' => ['acme_shop' => MysqlService::PRIVILEGES],
            'acme_wp' => ['acme_wp' => MysqlService::PRIVILEGES, 'acme_staging_wordpress' => MysqlService::PRIVILEGES],
            'acme_reports' => ['acme_shop' => ['SELECT'], 'acme_wp' => ['SELECT', 'SHOW VIEW', 'LOCK TABLES']],
        ],
        'remote' => ['%', '203.0.113.7'],
        'privileges' => MysqlService::PRIVILEGES,
        'prefix' => 'acme_',
        'serverHost' => 'server.example.com',
        'usage' => sample_usage(),
        'pkg' => sample_pkg(),
        'loadError' => null,
    ],
    'variants' => [
        'limited-package' => ['features' => ['file_manager' => false, 'cron' => false, 'backups' => false, 'dns_editor' => false, 'php_versions' => false, 'aliases' => false, 'routes' => false, 'object_cache' => false, 'database_access' => false, 'ssh_keys' => false]],
        'empty' => ['dbs' => [], 'users' => [], 'grants' => [], 'remote' => [], 'usage' => ['domains' => 3, 'databases' => 0, 'email_accounts' => 7, 'ftp_accounts' => 1]],
        'users-only' => ['dbs' => [], 'grants' => [], 'remote' => []],
        'load-error' => ['grants' => [], 'loadError' => 'SQLSTATE[HY000] [2002] Connection refused'],
        'full' => ['usage' => ['domains' => 3, 'databases' => 10, 'email_accounts' => 7, 'ftp_accounts' => 1]],
    ],
];
