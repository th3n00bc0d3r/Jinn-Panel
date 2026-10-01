<?php
declare(strict_types=1);

/**
 * Rewrites every hosted domain's Caddy site block from the current template
 * (VhostService::create) and reloads FrankenPHP once - e.g. after the
 * template changes, or to move all domains to one SSL mode:
 *
 *   php vhost-rebuild.php                 keep each domain's ssl_mode
 *   php vhost-rebuild.php letsencrypt     switch every domain to Let's Encrypt
 *
 * Run as frankenphp. Existing site files are not touched.
 */

require __DIR__ . '/../src/cli_bootstrap.php';

$mode = $argv[1] ?? '';
if ($mode !== '' && !in_array($mode, ['letsencrypt', 'self_signed'], true)) {
    fwrite(STDERR, "Usage: php vhost-rebuild.php [letsencrypt|self_signed]\n");
    exit(2);
}

$pdo = Database::app();
$upd = $pdo->prepare('UPDATE domains SET ssl_mode = ? WHERE id = ?');
$failed = 0;
foreach ($pdo->query('SELECT id, domain_name, php_version, ssl_mode FROM domains ORDER BY domain_name') as $d) {
    $ssl = $mode !== '' ? $mode : (string) $d['ssl_mode'];
    try {
        VhostService::create((string) $d['domain_name'], (string) ($d['php_version'] ?: 'default'), $ssl, false, false);
        $upd->execute([$ssl, $d['id']]);
        echo "ok      {$d['domain_name']} ($ssl)\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAILED  {$d['domain_name']}: {$e->getMessage()}\n";
    }
}
VhostService::reload();
exit($failed > 0 ? 1 : 0);
