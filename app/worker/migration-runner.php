<?php
declare(strict_types=1);

/**
 * Background runner for one cPanel/WHM migration:
 *
 *   php /var/www/hostpanel/worker/migration-runner.php <migration-id>
 *
 * Not run by hand in normal use: the panel queues a `migration_start` job,
 * and hostpanel-worker.php (root, every 5s) launches this as a transient
 * systemd unit `jinnpanel-migration-<id>` running as frankenphp:webusers -
 * the same identity the panel itself uses for vhosts, databases and
 * mailboxes, but outside FrankenPHP's request lifecycle and sandbox.
 *
 * Follow it live with:  journalctl -fu jinnpanel-migration-<id>
 * or in the panel:      WHM > Migrations > (migration)
 */

require __DIR__ . '/../src/cli_bootstrap.php';

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "Usage: php migration-runner.php <migration-id>\n");
    exit(2);
}

// One runner per migration, even if the job gets queued twice.
$lockFile = __DIR__ . "/../storage/logs/migration-{$id}.lock";
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Migration $id is already being processed by another runner.\n");
    exit(0);
}

try {
    $code = (new MigrationRunner($id))->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Runner crashed: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $code = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
    @unlink($lockFile);
}
exit($code);
