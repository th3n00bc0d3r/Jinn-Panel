<?php
declare(strict_types=1);

/**
 * Re-renders one domain's Caddy site file from its stored settings, without
 * reloading - the root worker validates the whole config and reloads itself
 * (routes_apply). Run as frankenphp.
 *
 *   php vhost-write.php <domain>
 */

require __DIR__ . '/../src/cli_bootstrap.php';

$domain = strtolower((string) ($argv[1] ?? ''));
$s = Database::app()->prepare('SELECT * FROM domains WHERE domain_name = ?');
$s->execute([$domain]);
$d = $s->fetch();
if (!$d) {
    fwrite(STDERR, "No such domain.\n");
    exit(2);
}
VhostService::create($domain, (string) ($d['php_version'] ?: 'default'), (string) $d['ssl_mode'], false, false);
echo "ok\n";
