<?php
// Built-in server router for the real panel (dev/local/serve.sh): files in
// app/public are served as they are, everything else goes to index.php.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . '/../../app/public' . $path)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../../app/public/index.php';
