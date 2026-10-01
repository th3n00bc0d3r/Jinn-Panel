<?php
declare(strict_types=1);

/**
 * Starts a `file` mode migration: restores cPanel full backups that are
 * already on this server (copied from offsite storage, cPanel's scheduled
 * backups, cpmove files) from MigrationService::importDir().
 *
 *   php migration-import.php --list
 *   php migration-import.php <admin-username> <account> [<account> ...]
 *
 * Run as frankenphp (`runuser -u frankenphp -- php ...`): it queues the
 * same `migration_start` job as WHM > cPanel Migration, so progress, the
 * per-account report and Retry all show up there.
 */

require __DIR__ . '/../src/cli_bootstrap.php';

$args = array_slice($argv, 1);
if ($args === ['--list']) {
    $files = MigrationService::availableBackupFiles();
    if (!$files) {
        echo 'No backups in ' . MigrationService::importDir() . "\n";
    }
    foreach ($files as $user => $file) {
        printf("%-32s %10.1f MB  %s\n", $user, filesize($file) / 1048576, basename($file));
    }
    exit(0);
}
if (count($args) < 2) {
    fwrite(STDERR, "Usage: php migration-import.php --list\n       php migration-import.php <admin-username> <account> [<account> ...]\n");
    exit(2);
}

$stmt = Database::app()->prepare("SELECT * FROM users WHERE username = ? AND role = 'admin' AND status = 'active'");
$stmt->execute([array_shift($args)]);
$admin = $stmt->fetch();
if (!$admin) {
    fwrite(STDERR, "No active admin with that username.\n");
    exit(1);
}

try {
    $id = MigrationService::startFromFiles($admin, $args);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Queued migration #$id (" . implode(', ', $args) . "). Follow it in WHM > cPanel Migration, or:\n"
    . "  journalctl -fu jinnpanel-migration-$id\n"
    . '  tail -f ' . (realpath(dirname(MigrationService::logFile($id))) ?: dirname(MigrationService::logFile($id))) . "/migration-$id.log\n";
