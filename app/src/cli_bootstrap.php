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
// Root (the worker's backup jobs, cron-run) never writes into storage/,
// which frankenphp can write: a link planted there would aim root's writes.
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    @mkdir('/var/log/jinnpanel', 0750);
    ini_set('error_log', '/var/log/jinnpanel/root-cli.log');
} else {
    ini_set('error_log', __DIR__ . '/../storage/logs/app.log');
}
ini_set('memory_limit', '1024M');
set_time_limit(0);
umask(0022);

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
