<?php
declare(strict_types=1);

/**
 * WHM > Backups (admin: schedule, off-site storage, every account and the
 * server) and cPanel > Backups (an account's own: back up now, download,
 * restore). The work runs as root in the background (BackupService).
 */
final class BackupController
{
    public static function whm(): void
    {
        Auth::requireRole(['admin']);
        $accounts = Database::app()->query("SELECT id, username FROM users WHERE role = 'user' ORDER BY username")->fetchAll();
        View::render('whm/backups', [
            'title' => 'Backups',
            'settings' => BackupService::settings(),
            'backups' => BackupService::recent(150),
            'accounts' => $accounts,
            'free' => (int) @disk_free_space('/var/backups'),
        ], 'whm');
    }

    public static function whmSettings(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        try {
            BackupService::saveSettings($_POST);
            Flash::ok('Backup settings saved.');
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        header('Location: /whm/backups');
        exit;
    }

    public static function whmRun(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $target = (string) ($_POST['target'] ?? '');
        try {
            if ($target === 'server') {
                BackupService::queue(null);
                Flash::ok('Server backup started.');
            } elseif ($target === 'all') {
                SystemWorkerService::enqueue('backup-all', ['type' => 'backup_all']);
                Flash::ok('Backing up every account, one after another, then the server.');
            } else {
                BackupService::queue((int) $target);
                Flash::ok('Backup started.');
            }
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        header('Location: /whm/backups');
        exit;
    }

    public static function whmRestore(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        try {
            BackupService::queueRestore((int) $params['id'], array_map('strval', (array) ($_POST['parts'] ?? [])));
            Audit::target((int) (BackupService::find((int) $params['id'])['user_id'] ?? 0));
            Flash::ok('Restore started - it runs in the background.');
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        header('Location: /whm/backups');
        exit;
    }

    public static function whmDownload(array $params): void
    {
        Auth::requireRole(['admin']);
        self::download((int) $params['id'], (string) ($_GET['file'] ?? ''), null);
    }

    // ---- cPanel ----

    public static function cpanel(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        View::render('cpanel/backups', [
            'title' => 'Backups',
            'backups' => BackupService::forUser((int) $me['id']),
            'settings' => BackupService::settings(),
        ], 'cpanel');
    }

    public static function cpanelRun(): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        try {
            // One self-service backup per 6 hours: they're full copies.
            $s = Database::app()->prepare("SELECT COUNT(*) FROM backups WHERE user_id = ? AND created_at > NOW() - INTERVAL 6 HOUR AND status <> 'failed'");
            $s->execute([$me['id']]);
            if ((int) $s->fetchColumn() > 0) {
                throw new RuntimeException('There is already a backup from the last 6 hours - download that one, or try again later.');
            }
            BackupService::queue((int) $me['id']);
            Flash::ok('Backup started - it appears below when it\'s done.');
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        header('Location: /cpanel/backups');
        exit;
    }

    public static function cpanelRestore(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $b = BackupService::find((int) $params['id']);
        try {
            if (!$b || (int) $b['user_id'] !== (int) $me['id']) {
                throw new RuntimeException('Backup not found.');
            }
            BackupService::queueRestore((int) $b['id'], array_map('strval', (array) ($_POST['parts'] ?? [])));
            Flash::ok('Restore started - it runs in the background; refresh to see when it\'s done.');
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        header('Location: /cpanel/backups');
        exit;
    }

    public static function cpanelDownload(array $params): void
    {
        Auth::requireRole(['user']);
        self::download((int) $params['id'], (string) ($_GET['file'] ?? ''), (int) Auth::id());
    }

    private static function download(int $id, string $file, ?int $ownerId): void
    {
        $b = BackupService::find($id);
        $files = $b ? BackupService::files($b) : [];
        if (!$b || ($ownerId !== null && (int) $b['user_id'] !== $ownerId) || !isset($files[$file])) {
            http_response_code(404);
            echo 'Not found.';
            exit;
        }
        $path = $files[$file];
        Audit::log('backup.download', "#$id $file", $b['user_id'] !== null ? (int) $b['user_id'] : null);
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $b['username'] . '-' . basename((string) $b['path']) . '-' . $file . '"');
        header('Content-Length: ' . filesize($path));
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }
}
