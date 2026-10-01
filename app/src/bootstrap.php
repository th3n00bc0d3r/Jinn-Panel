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

// Browser hardening for every panel response. Inline <script>/<style> are
// still used by a few pages, hence 'unsafe-inline'; nothing is loaded from
// other origins. HSTS only on the panel's own hostname: on a customer
// domain's :2083 it would also force HTTPS onto that domain's own site.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (Auth::onPanelHost()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

Auth::start();
