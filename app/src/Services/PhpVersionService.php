<?php
declare(strict_types=1);

/**
 * Additional PHP versions for sites: each is its own PHP-FPM master
 * (jinnpanel-php-fpm@<82|83|84>) with its binaries and extensions in
 * /opt/php-versions/<version>, installed/removed by hostpanel-worker.php
 * from the static-php repo's packages without touching the default
 * install. A domain on a version gets its account's pool in that master -
 * see VhostService and AccountRuntime.
 */
final class PhpVersionService
{
    /** 8.5 is the default (the packaged php-zts); 8.6 is still beta upstream. */
    public const SUPPORTED = ['8.2', '8.3', '8.4'];
    public const DIR = '/opt/php-versions';

    /** php_versions.port is NOT NULL UNIQUE; it no longer means anything (no TCP listeners). */
    private static function placeholderPort(string $version): int
    {
        return 9000 + (int) str_replace('.', '', $version);
    }

    public static function installed(): array
    {
        return Database::app()->query('SELECT * FROM php_versions ORDER BY version')->fetchAll();
    }

    /** Versions a domain can actually be assigned to right now. */
    public static function selectable(): array
    {
        $stmt = Database::app()->query("SELECT version FROM php_versions WHERE status = 'active' ORDER BY version");
        return array_column($stmt->fetchAll(), 'version');
    }

    public static function install(string $version): void
    {
        if (!in_array($version, self::SUPPORTED, true)) {
            throw new InvalidArgumentException('Unsupported or already-default PHP version.');
        }
        $pdo = Database::app();
        $chk = $pdo->prepare('SELECT id FROM php_versions WHERE version = ?');
        $chk->execute([$version]);
        if ($chk->fetch()) {
            throw new RuntimeException('That version is already installed or installing.');
        }

        $stmt = $pdo->prepare("INSERT INTO php_versions (version, port, status) VALUES (?, ?, 'installing')");
        $stmt->execute([$version, self::placeholderPort($version)]);

        SystemWorkerService::enqueue("phpver-install-{$version}", ['type' => 'install_php_version', 'version' => $version]);
    }

    public static function remove(string $version): void
    {
        $pdo = Database::app();
        $inUse = $pdo->prepare('SELECT COUNT(*) c FROM domains WHERE php_version = ?');
        $inUse->execute([$version]);
        if ((int) $inUse->fetch()['c'] > 0) {
            throw new RuntimeException('One or more domains are still using this version - move them to another version first.');
        }

        $upd = $pdo->prepare("UPDATE php_versions SET status = 'removing' WHERE version = ?");
        $upd->execute([$version]);

        SystemWorkerService::enqueue("phpver-remove-{$version}", ['type' => 'remove_php_version', 'version' => $version]);
    }
}
