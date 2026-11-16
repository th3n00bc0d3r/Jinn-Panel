<?php
$job = fn(array $j) => $j + [
    'user_id' => 10, 'domain_id' => 1, 'kind' => 'php', 'args' => '', 'enabled' => 1,
    'last_run_at' => null, 'last_status' => null, 'last_output' => null,
    'created_at' => '2026-10-02 10:00:00', 'domain_name' => 'acme.test',
];
$ago = fn(string $rel) => date('Y-m-d H:i:s', strtotime($rel));
$jobs = [
    $job(['id' => 1, 'schedule' => '*/5 * * * *', 'kind' => 'url', 'domain_id' => null, 'domain_name' => null, 'target' => 'https://acme.test/wp-cron.php?doing_wp_cron', 'last_run_at' => $ago('-3 minutes'), 'last_status' => 200, 'last_output' => '']),
    $job(['id' => 2, 'schedule' => '0 3 * * *', 'target' => '/var/www/acme.test/public/artisan', 'args' => 'schedule:run --quiet', 'last_run_at' => $ago('-11 hours'), 'last_status' => 0, 'last_output' => "Running scheduled command: Closure\nRunning scheduled command: App\\Jobs\\PruneCarts\nDone in 4.2 s."]),
    $job(['id' => 3, 'schedule' => '30 * * * *', 'domain_id' => 2, 'domain_name' => 'shop.acme.test', 'target' => '/var/www/shop.acme.test/public/cron/sync-stock.php', 'last_run_at' => $ago('-35 minutes'), 'last_status' => 255, 'last_output' => "PHP Fatal error:  Uncaught PDOException: SQLSTATE[HY000] [1045] Access denied for user 'acme_shop'@'localhost' (using password: YES) in /var/www/shop.acme.test/public/cron/sync-stock.php:14\nStack trace:\n#0 /var/www/shop.acme.test/public/cron/sync-stock.php(14): PDO->__construct()\n#1 {main}\n  thrown in /var/www/shop.acme.test/public/cron/sync-stock.php on line 14"]),
    $job(['id' => 4, 'schedule' => '15 2 * * 1', 'target' => '/var/www/acme.test/scripts/weekly-report.php', 'enabled' => 0, 'last_run_at' => $ago('-9 days'), 'last_status' => -1, 'last_output' => "[stopped after 900 seconds]"]),
    $job(['id' => 5, 'schedule' => '0 0 1 * *', 'target' => '/var/www/acme.test/scripts/invoices.php']),
];
return [
    'layout' => 'cpanel',
    'role' => 'user',
    'path' => '/cpanel/cron',
    'data' => [
        'title' => 'Cron Jobs',
        'jobs' => $jobs,
        'domains' => [['id' => 1, 'domain_name' => 'acme.test'], ['id' => 2, 'domain_name' => 'shop.acme.test'], ['id' => 3, 'domain_name' => 'acme-staging.example']],
    ],
    'variants' => [
        'empty' => ['jobs' => []],
        'no-domains' => ['jobs' => [], 'domains' => []],
    ],
];
