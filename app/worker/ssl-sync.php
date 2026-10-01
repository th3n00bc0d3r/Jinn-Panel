<?php
declare(strict_types=1);

/**
 * Moves self-signed sites to Let's Encrypt once their domain resolves to
 * this server (SslService::upgradeAll). Run as frankenphp, daily from
 * jinnpanel-mail-dns.service.
 */

require __DIR__ . '/../src/cli_bootstrap.php';

foreach (SslService::upgradeAll() as $line) {
    echo $line, "\n";
}
