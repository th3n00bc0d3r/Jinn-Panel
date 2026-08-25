<?php
declare(strict_types=1);

final class PackageController
{
    public static function index(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $user = Auth::user();
        $pdo = Database::app();

        if ($user['role'] === 'admin') {
            $packages = $pdo->query('SELECT p.*, u.username AS owner_username FROM packages p LEFT JOIN users u ON u.id = p.owner_id ORDER BY p.owner_id IS NOT NULL, p.id')->fetchAll();
        } else {
            $stmt = $pdo->prepare('SELECT p.*, u.username AS owner_username FROM packages p LEFT JOIN users u ON u.id = p.owner_id WHERE p.owner_id IS NULL OR p.owner_id = ? ORDER BY p.owner_id IS NOT NULL, p.id');
            $stmt->execute([$user['id']]);
            $packages = $stmt->fetchAll();
        }

        View::render('whm/packages', ['title' => 'Packages', 'packages' => $packages], 'whm');
    }

    public static function create(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        View::render('whm/package_form', ['title' => 'Create Package', 'pkg' => null], 'whm');
    }

    public static function store(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $user = Auth::user();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::error('Package name is required.');
            header('Location: /whm/packages/create');
            exit;
        }

        $stmt = Database::app()->prepare(
            'INSERT INTO packages (owner_id, name, disk_quota_mb, bandwidth_mb, max_domains, max_databases, max_email_accounts, max_ftp_accounts)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $user['role'] === 'admin' ? null : $user['id'],
            $name,
            max(1, (int) ($_POST['disk_quota_mb'] ?? 1024)),
            max(1, (int) ($_POST['bandwidth_mb'] ?? 10240)),
            max(1, (int) ($_POST['max_domains'] ?? 1)),
            max(1, (int) ($_POST['max_databases'] ?? 1)),
            max(0, (int) ($_POST['max_email_accounts'] ?? 5)),
            max(0, (int) ($_POST['max_ftp_accounts'] ?? 1)),
        ]);

        Flash::ok("Package \"$name\" created.");
        header('Location: /whm/packages');
        exit;
    }

    public static function destroy(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $user = Auth::user();
        $id = (int) $params['id'];

        $stmt = Database::app()->prepare('SELECT * FROM packages WHERE id = ?');
        $stmt->execute([$id]);
        $pkg = $stmt->fetch();

        if (!$pkg || ($user['role'] !== 'admin' && (int) $pkg['owner_id'] !== $user['id'])) {
            Flash::error('Package not found.');
            header('Location: /whm/packages');
            exit;
        }

        $del = Database::app()->prepare('DELETE FROM packages WHERE id = ?');
        $del->execute([$id]);

        Flash::ok('Package deleted.');
        header('Location: /whm/packages');
        exit;
    }
}
