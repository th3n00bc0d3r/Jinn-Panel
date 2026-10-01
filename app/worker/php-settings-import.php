<?php
declare(strict_types=1);

/**
 * Imports cPanel MultiPHP INI Editor settings (.user.ini / php.ini in each
 * docroot) into JinnPanel's per-site PHP settings, and (re)writes every
 * site's settings file. Settings already set in the panel are kept. Run as
 * frankenphp; install.sh runs it on every install (it's idempotent).
 */

require __DIR__ . '/../src/cli_bootstrap.php';

foreach (Database::app()->query('SELECT * FROM domains ORDER BY domain_name')->fetchAll() as $d) {
    try {
        $notes = PhpSettingsService::importCpanelIni($d, VhostService::effectiveDocroot((string) $d['domain_name']));
        if (!$notes) {
            PhpSettingsService::write($d);
        }
        echo $d['domain_name'], ': ', $notes ? implode('; ', $notes) : 'ok', "\n";
    } catch (Throwable $e) {
        echo $d['domain_name'], ': FAILED - ', $e->getMessage(), "\n";
    }
}
