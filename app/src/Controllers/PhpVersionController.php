<?php
declare(strict_types=1);

final class PhpVersionController
{
    public static function index(): void
    {
        Auth::requireRole(['admin']);
        $installed = PhpVersionService::installed();
        $installedVersions = array_column($installed, 'version');
        $available = array_values(array_diff(PhpVersionService::SUPPORTED, $installedVersions));

        View::render('whm/server_config/php_versions', [
            'title' => 'PHP Versions',
            'installed' => $installed,
            'available' => $available,
        ], 'whm');
    }

    public static function install(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $version = (string) ($_POST['version'] ?? '');

        try {
            PhpVersionService::install($version);
            Flash::ok("Installing PHP $version - this downloads its PHP-FPM and extensions and starts it, usually done within a minute or two. Refresh to check status.");
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        header('Location: /whm/server-config/php-versions');
        exit;
    }

    public static function remove(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $version = $params['version'];

        try {
            PhpVersionService::remove($version);
            Flash::ok("Removing PHP $version.");
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        header('Location: /whm/server-config/php-versions');
        exit;
    }
}
