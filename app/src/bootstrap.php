<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak stack traces to visitors
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/logs/app.log');

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

require __DIR__ . '/View.php'; // for the e()/old()/fmt_bytes() helpers

Auth::start();
