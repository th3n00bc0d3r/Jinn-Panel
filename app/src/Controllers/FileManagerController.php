<?php
declare(strict_types=1);

final class FileManagerController
{
    /** Resolves an EXISTING directory inside the domain's docroot, refusing traversal. */
    private static function safeDir(string $docroot, string $relPath): string
    {
        $base = realpath($docroot);
        if ($base === false) {
            throw new RuntimeException('Docroot missing.');
        }
        $target = realpath($docroot . '/' . ltrim($relPath, '/'));
        $withinBase = $target !== false && ($target === $base || str_starts_with($target, $base . DIRECTORY_SEPARATOR));
        if (!$withinBase || !is_dir($target)) {
            return $base;
        }
        return $target;
    }

    /** Sanitizes a bare filename (no slashes, no traversal). */
    private static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new InvalidArgumentException('Invalid file name.');
        }
        return $name;
    }

    private static function myDomain(int $domainId, int $userId): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
        $stmt->execute([$domainId, $userId]);
        return $stmt->fetch() ?: null;
    }

    public static function index(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $pdo = Database::app();

        $domainsStmt = $pdo->prepare('SELECT * FROM domains WHERE user_id = ? ORDER BY domain_name');
        $domainsStmt->execute([$me['id']]);
        $domains = $domainsStmt->fetchAll();

        $domainId = (int) ($_GET['domain_id'] ?? ($domains[0]['id'] ?? 0));
        $relPath = (string) ($_GET['path'] ?? '');
        $domain = self::myDomain($domainId, $me['id']);

        $entries = [];
        $currentRel = '';
        if ($domain) {
            $dir = self::safeDir($domain['docroot'], $relPath);
            $currentRel = ltrim(str_replace(realpath($domain['docroot']), '', $dir), '/');
            $items = @scandir($dir) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $full = $dir . '/' . $item;
                $entries[] = [
                    'name' => $item,
                    'is_dir' => is_dir($full),
                    'size' => is_file($full) ? filesize($full) : 0,
                    'modified' => filemtime($full),
                ];
            }
            usort($entries, fn($a, $b) => $b['is_dir'] <=> $a['is_dir'] ?: strcasecmp($a['name'], $b['name']));
        }

        View::render('cpanel/files', [
            'title' => 'File Manager',
            'domains' => $domains,
            'domain' => $domain,
            'domainId' => $domainId,
            'currentRel' => $currentRel,
            'entries' => $entries,
        ], 'cpanel');
    }

    public static function upload(): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $domainId = (int) ($_POST['domain_id'] ?? 0);
        $relPath = (string) ($_POST['path'] ?? '');
        $domain = self::myDomain($domainId, $me['id']);

        if (!$domain || empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            Flash::error('Upload failed.');
            self::backTo($domainId, $relPath);
        }

        $dir = self::safeDir($domain['docroot'], $relPath);
        try {
            $name = self::safeName($_FILES['file']['name']);
        } catch (Throwable $e) {
            Flash::error('Invalid file name.');
            self::backTo($domainId, $relPath);
            return;
        }

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir . '/' . $name)) {
            Flash::error('Could not save the uploaded file.');
        } else {
            Flash::ok("Uploaded \"$name\".");
        }
        self::backTo($domainId, $relPath);
    }

    public static function mkdir(): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $domainId = (int) ($_POST['domain_id'] ?? 0);
        $relPath = (string) ($_POST['path'] ?? '');
        $domain = self::myDomain($domainId, $me['id']);

        if ($domain) {
            $dir = self::safeDir($domain['docroot'], $relPath);
            try {
                $name = self::safeName((string) ($_POST['name'] ?? ''));
                if (!is_dir($dir . '/' . $name)) {
                    mkdir($dir . '/' . $name, 02775);
                    Flash::ok("Folder \"$name\" created.");
                }
            } catch (Throwable $e) {
                Flash::error('Invalid folder name.');
            }
        }
        self::backTo($domainId, $relPath);
    }

    public static function delete(): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $domainId = (int) ($_POST['domain_id'] ?? 0);
        $relPath = (string) ($_POST['path'] ?? '');
        $domain = self::myDomain($domainId, $me['id']);

        if ($domain) {
            $dir = self::safeDir($domain['docroot'], $relPath);
            try {
                $name = self::safeName((string) ($_POST['name'] ?? ''));
                $target = $dir . '/' . $name;
                if (is_dir($target) && !is_link($target)) {
                    @rmdir($target); // only removes if empty - safety over convenience
                } elseif (is_file($target) || is_link($target)) {
                    @unlink($target);
                }
                Flash::ok("\"$name\" deleted.");
            } catch (Throwable $e) {
                Flash::error('Invalid file name.');
            }
        }
        self::backTo($domainId, $relPath);
    }

    public static function download(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $domainId = (int) ($_GET['domain_id'] ?? 0);
        $relPath = (string) ($_GET['path'] ?? '');
        $name = (string) ($_GET['name'] ?? '');
        $domain = self::myDomain($domainId, $me['id']);

        if (!$domain) {
            http_response_code(404);
            exit;
        }
        $dir = self::safeDir($domain['docroot'], $relPath);
        try {
            $safeName = self::safeName($name);
        } catch (Throwable $e) {
            http_response_code(400);
            exit;
        }
        $file = $dir . '/' . $safeName;
        if (!is_file($file)) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    private static function backTo(int $domainId, string $relPath): void
    {
        header('Location: /cpanel/files?domain_id=' . $domainId . '&path=' . rawurlencode($relPath));
        exit;
    }
}
