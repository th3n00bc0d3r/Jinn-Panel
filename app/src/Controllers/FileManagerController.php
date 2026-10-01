<?php
declare(strict_types=1);

/**
 * cPanel > File Manager, rooted at the domain's site folder (/var/www/<domain>:
 * the document root, plus private/ and logs/ next to it). File operations
 * are in FileManagerService, which keeps every path inside that folder.
 */
final class FileManagerController
{
    private static function myDomain(int $domainId, int $userId): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
        $stmt->execute([$domainId, $userId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array{0:array,1:FileManagerService,2:string} domain, service, current directory (POST + CSRF checked) */
    private static function context(bool $post = true): array
    {
        Auth::requireRole(['user']);
        if ($post) {
            Csrf::requireValid();
        }
        $src = $post ? $_POST : $_GET;
        $domain = self::myDomain((int) ($src['domain_id'] ?? 0), (int) Auth::user()['id']);
        if (!$domain) {
            Flash::error('Domain not found.');
            header('Location: /cpanel/files');
            exit;
        }
        $fm = new FileManagerService(VhostService::siteDir((string) $domain['domain_name']));
        return [$domain, $fm, $fm->dir((string) ($src['path'] ?? ''))];
    }

    public static function index(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $domainsStmt = Database::app()->prepare('SELECT * FROM domains WHERE user_id = ? ORDER BY domain_name');
        $domainsStmt->execute([$me['id']]);
        $domains = $domainsStmt->fetchAll();

        $domainId = (int) ($_GET['domain_id'] ?? ($domains[0]['id'] ?? 0));
        $domain = self::myDomain($domainId, (int) $me['id']);
        $entries = [];
        $currentRel = '';
        $docrootRel = '';
        if ($domain) {
            try {
                $fm = new FileManagerService(VhostService::siteDir((string) $domain['domain_name']));
                $docrootRel = $fm->rel(VhostService::effectiveDocroot((string) $domain['domain_name']));
                $dir = $fm->dir(array_key_exists('path', $_GET) ? (string) $_GET['path'] : $docrootRel);
                $currentRel = $fm->rel($dir);
                $entries = $fm->list($dir);
            } catch (Throwable $e) {
                Flash::error($e->getMessage());
            }
        }
        View::render('cpanel/files', [
            'title' => 'File Manager',
            'domains' => $domains,
            'domain' => $domain,
            'domainId' => $domainId,
            'currentRel' => $currentRel,
            'docrootRel' => $docrootRel,
            'entries' => $entries,
        ], 'cpanel');
    }

    public static function upload(): void
    {
        [$domain, $fm, $dir] = self::context();
        $files = $_FILES['file'] ?? null;
        $n = 0;
        $errors = [];
        if (is_array($files) && is_array($files['name'] ?? null)) {
            foreach ($files['name'] as $i => $name) {
                $tmp = $files['tmp_name'][$i] ?? '';
                if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                    $errors[] = (string) $name . ($files['error'][$i] === UPLOAD_ERR_INI_SIZE ? ' (too large)' : '');
                    continue;
                }
                try {
                    move_uploaded_file($tmp, $fm->entry($dir, (string) $name)) ? $n++ : $errors[] = (string) $name;
                } catch (Throwable) {
                    $errors[] = (string) $name;
                }
            }
        }
        $n > 0 && Flash::ok("Uploaded $n file" . ($n === 1 ? '' : 's') . '.');
        $errors && Flash::error('Not uploaded: ' . implode(', ', array_slice($errors, 0, 10)));
        self::backTo($domain, $fm->rel($dir));
    }

    public static function mkdir(): void
    {
        [$domain, $fm, $dir] = self::context();
        try {
            $path = $fm->entry($dir, (string) ($_POST['name'] ?? ''));
            if (!file_exists($path)) {
                mkdir($path, 02775);
            }
            Flash::ok('Folder created.');
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        self::backTo($domain, $fm->rel($dir));
    }

    /** Bulk actions on the selected names[] in the current folder. */
    public static function action(): void
    {
        [$domain, $fm, $dir] = self::context();
        $op = (string) ($_POST['op'] ?? '');
        $names = array_values(array_filter(array_map('strval', (array) ($_POST['names'] ?? []))));
        try {
            if (!$names) {
                throw new InvalidArgumentException('Select at least one file or folder.');
            }
            $paths = array_map(fn($n) => $fm->entry($dir, $n), array_slice($names, 0, 500));
            foreach ($paths as $p) {
                if (!file_exists($p) && !is_link($p)) {
                    throw new InvalidArgumentException(basename($p) . ' no longer exists.');
                }
            }
            $msg = match ($op) {
                'delete' => (function () use ($fm, $paths) {
                    foreach ($paths as $p) {
                        $fm->delete($p);
                    }
                    return count($paths) . ' deleted.';
                })(),
                'move', 'copy' => (function () use ($fm, $paths, $op) {
                    $dest = $fm->dir((string) ($_POST['dest'] ?? ''));
                    if ((string) ($_POST['dest'] ?? '') !== '' && $fm->rel($dest) !== trim((string) $_POST['dest'], '/')) {
                        throw new InvalidArgumentException('The destination folder doesn\'t exist.');
                    }
                    foreach ($paths as $p) {
                        $fm->transfer($p, $dest, $op === 'copy');
                    }
                    return count($paths) . ($op === 'copy' ? ' copied' : ' moved') . ' to /' . $fm->rel($dest) . '.';
                })(),
                'chmod' => (function () use ($fm, $paths) {
                    $n = 0;
                    foreach ($paths as $p) {
                        $n += $fm->chmod($p, (string) ($_POST['file_mode'] ?? '644'), (string) ($_POST['dir_mode'] ?? '755'), !empty($_POST['recursive']));
                    }
                    return "Permissions changed on $n item(s).";
                })(),
                'compress' => 'Created ' . basename($fm->compress($dir, $paths, trim((string) ($_POST['dest'] ?? '')) ?: 'archive-' . date('Ymd-His'))) . '.',
                'extract' => (function () use ($fm, $paths, $dir) {
                    $n = 0;
                    foreach ($paths as $p) {
                        $n += $fm->extract($p, $dir);
                    }
                    return "Extracted $n file(s).";
                })(),
                'rename' => (function () use ($fm, $paths) {
                    $fm->rename($paths[0], (string) ($_POST['dest'] ?? ''));
                    return 'Renamed.';
                })(),
                default => throw new InvalidArgumentException('Unknown action.'),
            };
            Flash::ok($msg);
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        self::backTo($domain, $fm->rel($dir));
    }

    /** One file as-is; several (or a folder) as a zip. */
    public static function download(): void
    {
        [$domain, $fm, $dir] = self::context(false);
        $names = array_values(array_filter(array_map('strval', (array) ($_GET['names'] ?? ($_GET['name'] ?? [])))));
        try {
            $paths = array_map(fn($n) => $fm->entry($dir, $n), $names);
            if (!$paths) {
                throw new InvalidArgumentException('Nothing selected.');
            }
            if (count($paths) === 1 && is_file($paths[0]) && !is_link($paths[0])) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . addcslashes(basename($paths[0]), '"\\') . '"');
                header('Content-Length: ' . filesize($paths[0]));
                readfile($paths[0]);
                exit;
            }
            $tmpDir = sys_get_temp_dir() . '/jp-dl-' . bin2hex(random_bytes(6));
            mkdir($tmpDir, 0700);
            $tmp = new FileManagerService($tmpDir);
            $zip = (new FileManagerService($fm->root()))->compress($dir, $paths, '.jp-download-' . bin2hex(random_bytes(4)));
            rename($zip, "$tmpDir/download.zip");
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $domain['domain_name'] . '-files.zip"');
            header('Content-Length: ' . filesize("$tmpDir/download.zip"));
            readfile("$tmpDir/download.zip");
            $tmp->delete("$tmpDir/download.zip");
            @rmdir($tmpDir);
            exit;
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            self::backTo($domain, $fm->rel($dir));
        }
    }

    /** Old single-item delete form (kept for bookmarks/older views). */
    public static function delete(): void
    {
        $_POST['op'] = 'delete';
        $_POST['names'] = [(string) ($_POST['name'] ?? '')];
        self::action();
    }

    private static function backTo(array $domain, string $relPath): never
    {
        header('Location: /cpanel/files?domain_id=' . (int) $domain['id'] . '&path=' . rawurlencode($relPath));
        exit;
    }
}
