<?php
declare(strict_types=1);

/**
 * Manages additional PHP versions as fully isolated FrankenPHP instances,
 * installed/removed via hostpanel-worker.php (each version's shared lib has
 * a version-specific SONAME, so they coexist without touching the default
 * install). Domains on a non-default version get reverse-proxied to their
 * instance's loopback port by the main FrankenPHP - see VhostService.
 */
final class PhpVersionService
{
    /** 8.5 is the default/main instance already running; 8.6 is still beta upstream. */
    public const SUPPORTED = ['8.2', '8.3', '8.4'];

    public static function port(string $version): int
    {
        return 9000 + (int) str_replace('.', '', $version);
    }

    public static function adminPort(string $version): int
    {
        return 2000 + (int) str_replace('.', '', $version);
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

        $port = self::port($version);
        $adminPort = self::adminPort($version);
        $stmt = $pdo->prepare("INSERT INTO php_versions (version, port, status) VALUES (?, ?, 'installing')");
        $stmt->execute([$version, $port]);

        SystemWorkerService::enqueue("phpver-install-{$version}", [
            'type' => 'install_php_version',
            'version' => $version,
            'port' => $port,
            'admin_port' => $adminPort,
        ]);
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
