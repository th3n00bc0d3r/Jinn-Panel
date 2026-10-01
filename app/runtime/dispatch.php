<?php
// JinnPanel: per-site PHP settings and page cache, applied before every PHP
// request of a site (server-wide auto_prepend_file - see install.sh).
// DOCUMENT_ROOT for web requests; JINNPANEL_DOCROOT for cron jobs (the CLI
// always blanks DOCUMENT_ROOT).
(static function (): void {
    $root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: (string) getenv('JINNPANEL_DOCROOT');
    if ($root === '' || !preg_match('#^/var/www/([a-z0-9][a-z0-9.-]*)/#', $root . '/', $m)) {
        return;
    }
    $file = '/var/lib/frankenphp/site-ini/' . $m[1] . '.php';
    if (!is_file($file)) {
        return;
    }
    $conf = include $file; // ini_set()s the site's settings; returns its options
    if (is_array($conf) && !empty($conf['page_cache_ttl']) && PHP_SAPI !== 'cli') {
        require_once __DIR__ . '/_pagecache.php';
        jinnpanel_page_cache($m[1], (int) $conf['page_cache_ttl']);
    }
})();
