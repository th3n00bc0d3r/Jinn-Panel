<?php
declare(strict_types=1);

/**
 * Starts the cron jobs due this minute (CronService), each as a detached
 * cron-exec.php so a long job doesn't hold up the others. Run as frankenphp
 * by jinnpanel-cron.timer every minute.
 */

require __DIR__ . '/../src/cli_bootstrap.php';

$now = time();
$jobs = Database::app()->query("SELECT c.id, c.schedule FROM cron_jobs c JOIN users u ON u.id = c.user_id WHERE c.enabled = 1 AND u.status = 'active'")->fetchAll();
foreach ($jobs as $j) {
    if (CronService::due((string) $j['schedule'], $now)) {
        exec('setsid ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/cron-exec.php') . ' ' . (int) $j['id'] . ' > /dev/null 2>&1 &');
    }
}

// Expired phpMyAdmin logins (MysqlService::phpMyAdminUrl).
try {
    MysqlService::dropExpiredPmaLogins();
} catch (Throwable $e) {
    error_log('phpMyAdmin login cleanup: ' . $e->getMessage());
}
