<?php
declare(strict_types=1);

/**
 * cPanel > File Manager, rooted at the domain's site folder (/var/www/<domain>:
 * the document root, plus private/ and logs/ next to it). The work itself
 * runs inside the account's own PHP-FPM pool, as the account's Linux user
 * (PoolClient -> pool-agent -> FileManagerService), which keeps every path
 * inside that folder - so files belong to the account, and the panel can't
 * reach anything the account itself couldn't.
 */
final class FileManagerController
{
    private static function myDomain(int $domainId, int $userId): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
        $stmt->execute([$domainId, $userId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array{0:array,1:string} domain, current directory relative to the site folder (POST + CSRF checked) */
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
        return [$domain, trim((string) ($src['path'] ?? ''), '/')];
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
            $name = (string) $domain['domain_name'];
            $docrootRel = ltrim(substr(VhostService::effectiveDocroot($name), strlen(VhostService::siteDir($name))), '/');
            try {
                $res = PoolClient::call($name, 'list', ['dir' => array_key_exists('path', $_GET) ? (string) $_GET['path'] : $docrootRel]);
                $currentRel = (string) $res['dir'];
                $entries = (array) $res['entries'];
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
        [$domain, $dir] = self::context();
        self::needSpace($domain, $dir);
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
                $in = fopen($tmp, 'rb');
                try {
                    if ($in === false) {
                        throw new RuntimeException('unreadable');
                    }
                    PoolClient::call((string) $domain['domain_name'], 'upload', ['dir' => $dir, 'name' => (string) $name], $in);
                    $n++;
                } catch (Throwable $e) {
                    $errors[] = (string) $name . ' (' . $e->getMessage() . ')';
                } finally {
                    is_resource($in) && fclose($in);
                    @unlink($tmp);
                }
            }
        }
        $n > 0 && Flash::ok("Uploaded $n file" . ($n === 1 ? '' : 's') . '.');
        $errors && Flash::error('Not uploaded: ' . implode(', ', array_slice($errors, 0, 10)));
        self::backTo($domain, $dir);
    }

    public static function mkdir(): void
    {
        [$domain, $dir] = self::context();
        self::needSpace($domain, $dir);
        try {
            PoolClient::call((string) $domain['domain_name'], 'mkdir', ['dir' => $dir, 'name' => (string) ($_POST['name'] ?? '')]);
            Flash::ok('Folder created.');
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        self::backTo($domain, $dir);
    }

    /** Bulk actions on the selected names[] in the current folder. */
    public static function action(): void
    {
        [$domain, $dir] = self::context();
        $op = (string) ($_POST['op'] ?? '');
        $names = array_values(array_filter(array_map('strval', (array) ($_POST['names'] ?? []))));
        $name = (string) $domain['domain_name'];
        $args = ['dir' => $dir, 'names' => array_slice($names, 0, 500)];
        if (in_array($op, ['copy', 'compress', 'extract'], true)) {
            self::needSpace($domain, $dir);
        }
        try {
            if (!$names) {
                throw new InvalidArgumentException('Select at least one file or folder.');
            }
            $msg = match ($op) {
                'delete' => (function () use ($name, $args) {
                    PoolClient::call($name, 'delete', $args);
                    return count($args['names']) . ' deleted.';
                })(),
                'move', 'copy' => (function () use ($name, $args, $op) {
                    $res = PoolClient::call($name, $op, $args + ['dest' => (string) ($_POST['dest'] ?? '')]);
                    return count($args['names']) . ($op === 'copy' ? ' copied' : ' moved') . ' to /' . $res['dest'] . '.';
                })(),
                'chmod' => 'Permissions changed on ' . (int) PoolClient::call($name, 'chmod', $args + [
                    'file_mode' => (string) ($_POST['file_mode'] ?? '644'), 'dir_mode' => (string) ($_POST['dir_mode'] ?? '755'), 'recursive' => !empty($_POST['recursive']),
                ])['count'] . ' item(s).',
                'compress' => 'Created ' . PoolClient::call($name, 'compress', $args + ['dest' => trim((string) ($_POST['dest'] ?? ''))])['name'] . '.',
                'extract' => 'Extracted ' . (int) PoolClient::call($name, 'extract', $args)['count'] . ' file(s).',
                'rename' => (function () use ($name, $args) {
                    PoolClient::call($name, 'rename', $args + ['dest' => (string) ($_POST['dest'] ?? '')]);
                    return 'Renamed.';
                })(),
                default => throw new InvalidArgumentException('Unknown action.'),
            };
            Flash::ok($msg);
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        self::backTo($domain, $dir);
    }

    /** One file as-is; several (or a folder) as a zip. Streamed from the pool. */
    public static function download(): void
    {
        [$domain, $dir] = self::context(false);
        $names = array_values(array_filter(array_map('strval', (array) ($_GET['names'] ?? ($_GET['name'] ?? [])))));
        try {
            if (!$names) {
                throw new InvalidArgumentException('Nothing selected.');
            }
            $zipName = $domain['domain_name'] . '-files.zip';
            PoolClient::stream((string) $domain['domain_name'], 'download', ['dir' => $dir, 'names' => $names], function (array $h) use ($zipName): void {
                $file = rawurldecode((string) ($h['x-jp-name'] ?? 'download'));
                $file = $file === 'download.zip' ? $zipName : basename($file);
                header('Content-Type: ' . ($h['content-type'] ?? 'application/octet-stream'));
                header('Content-Disposition: attachment; filename="' . addcslashes($file, '"\\') . '"');
                if (isset($h['content-length'])) {
                    header('Content-Length: ' . (int) $h['content-length']);
                }
            }, function (string $chunk): void {
                echo $chunk;
                flush();
            });
            exit;
        } catch (Throwable $e) {
            if (headers_sent()) {
                exit;
            }
            Flash::error($e->getMessage());
            self::backTo($domain, $dir);
        }
    }

    /** Old single-item delete form (kept for bookmarks/older views). */
    public static function delete(): void
    {
        $_POST['op'] = 'delete';
        $_POST['names'] = [(string) ($_POST['name'] ?? '')];
        self::action();
    }

    /** Over the disk quota nothing new may be written (deleting and moving still work). */
    private static function needSpace(array $domain, string $dir): void
    {
        try {
            Quota::requireDiskSpace((int) $domain['user_id']);
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());
            self::backTo($domain, $dir);
        }
    }

    private static function backTo(array $domain, string $relPath): never
    {
        header('Location: /cpanel/files?domain_id=' . (int) $domain['id'] . '&path=' . rawurlencode($relPath));
        exit;
    }
}
