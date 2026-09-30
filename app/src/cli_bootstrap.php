<?php
declare(strict_types=1);

/**
 * Bootstrap for command-line entry points (worker/migration-runner.php).
 * Same autoloader as the web bootstrap, but no session, no views, and no
 * time/memory limits sized for web requests - a migration can run for hours.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 'stderr'); // -> the transient unit's journal
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/logs/app.log');
ini_set('memory_limit', '1024M');
set_time_limit(0);
umask(0002); // files created for sites must stay group-writable for webusers

spl_autoload_register(function (string $class): void {
    $dirs = [__DIR__, __DIR__ . '/Controllers', __DIR__ . '/Services', __DIR__ . '/Support'];
    foreach ($dirs as $dir) {
        $file = $dir . "/$class.php";
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});
