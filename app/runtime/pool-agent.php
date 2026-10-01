<?php
/**
 * JinnPanel pool agent: file work the panel does for an account, run inside
 * that account's own PHP-FPM pool - so it runs as the account's Linux user,
 * with its open_basedir, and everything it creates belongs to the account.
 * Installed root-owned to /usr/local/lib/jinnpanel/pool/ (install.sh) and
 * reached only through PoolClient: the panel sends a FastCGI request with
 * the JINNPANEL_AGENT parameter, which no web request can carry (Caddy
 * passes visitors' headers only as HTTP_* parameters, and only the web
 * server and panel can open a pool's socket).
 *
 * Request: params JINNPANEL_OP, JINNPANEL_ROOT (/var/www/<domain>); body =
 * the arguments as JSON - or, for an upload, the file (its arguments then
 * in the JINNPANEL_ARGS parameter). Reply: JSON
 * {"ok":true,...} / {"ok":false,"error":"..."}, or a raw stream for downloads.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'fpm-fcgi' || ($_SERVER['JINNPANEL_AGENT'] ?? '') !== '1' || isset($_SERVER['HTTP_HOST'])) {
    http_response_code(404);
    exit;
}
set_time_limit(0);
ignore_user_abort(true);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
require __DIR__ . '/FileManagerService.php';
require __DIR__ . '/ExposureService.php';
require __DIR__ . '/RoutesService.php';

$reply = function (array $data): never {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

try {
    $op = (string) ($_SERVER['JINNPANEL_OP'] ?? '');
    $root = (string) ($_SERVER['JINNPANEL_ROOT'] ?? '');
    // Arguments: in the body (JSON), except for an upload, whose body is the file.
    $args = json_decode($op === 'upload' ? (string) ($_SERVER['JINNPANEL_ARGS'] ?? '') : (string) file_get_contents('php://input'), true) ?: [];
    if (!preg_match('#^/var/www/[a-z0-9][a-z0-9.-]*[a-z0-9]$#', $root) || str_contains($root, '..')) {
        throw new InvalidArgumentException('Bad site folder.');
    }
    $fm = new FileManagerService($root);
    $dir = $fm->dir((string) ($args['dir'] ?? ''));
    $paths = function () use ($fm, $dir, $args): array {
        $names = array_values(array_filter(array_map('strval', (array) ($args['names'] ?? []))));
        if (!$names) {
            throw new InvalidArgumentException('Select at least one file or folder.');
        }
        $out = [];
        foreach (array_slice($names, 0, 500) as $n) {
            $p = $fm->entry($dir, $n);
            if (!file_exists($p) && !is_link($p)) {
                throw new InvalidArgumentException(basename($p) . ' no longer exists.');
            }
            $out[] = $p;
        }
        return $out;
    };

    switch ($op) {
        case 'list':
            $reply(['ok' => true, 'dir' => $fm->rel($dir), 'entries' => $fm->list($dir)]);
        case 'mkdir':
            $p = $fm->entry($dir, (string) ($args['name'] ?? ''));
            if (!file_exists($p) && !@mkdir($p, 0755)) {
                throw new RuntimeException('Could not create the folder.');
            }
            $reply(['ok' => true]);
        case 'upload':
            $target = $fm->entry($dir, (string) ($args['name'] ?? ''));
            if (is_dir($target) && !is_link($target)) {
                throw new RuntimeException(basename($target) . ' is a folder.');
            }
            $tmp = $dir . '/.jp-upload-' . bin2hex(random_bytes(6));
            $in = fopen('php://input', 'rb');
            $out = @fopen($tmp, 'xb');
            if ($in === false || $out === false) {
                throw new RuntimeException('Could not write ' . basename($target) . '.');
            }
            $n = stream_copy_to_stream($in, $out);
            fclose($out);
            if ($n === false || !@rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException('Could not write ' . basename($target) . '.');
            }
            $reply(['ok' => true, 'bytes' => $n]);
        case 'delete':
            foreach ($paths() as $p) {
                $fm->delete($p);
            }
            $reply(['ok' => true]);
        case 'move':
        case 'copy':
            $destRel = (string) ($args['dest'] ?? '');
            $dest = $fm->dir($destRel);
            if ($destRel !== '' && $fm->rel($dest) !== trim($destRel, '/')) {
                throw new InvalidArgumentException('The destination folder doesn\'t exist.');
            }
            foreach ($paths() as $p) {
                $fm->transfer($p, $dest, $op === 'copy');
            }
            $reply(['ok' => true, 'dest' => $fm->rel($dest)]);
        case 'rename':
            $fm->rename($paths()[0], (string) ($args['dest'] ?? ''));
            $reply(['ok' => true]);
        case 'chmod':
            $n = 0;
            foreach ($paths() as $p) {
                $n += $fm->chmod($p, (string) ($args['file_mode'] ?? '644'), (string) ($args['dir_mode'] ?? '755'), !empty($args['recursive']));
            }
            $reply(['ok' => true, 'count' => $n]);
        case 'compress':
            $zip = $fm->compress($dir, $paths(), (string) ($args['dest'] ?? '') ?: 'archive-' . date('Ymd-His'));
            $reply(['ok' => true, 'name' => basename($zip)]);
        case 'extract':
            $n = 0;
            foreach ($paths() as $p) {
                $n += $fm->extract($p, $dir);
            }
            $reply(['ok' => true, 'count' => $n]);
        case 'download':
            $ps = $paths();
            if (count($ps) === 1 && is_file($ps[0]) && !is_link($ps[0])) {
                header('Content-Type: application/octet-stream');
                header('X-JP-Name: ' . rawurlencode(basename($ps[0])));
                header('Content-Length: ' . filesize($ps[0]));
                readfile($ps[0]);
                exit;
            }
            $tmpDir = sys_get_temp_dir() . '/jp-dl-' . bin2hex(random_bytes(6));
            mkdir($tmpDir, 0700);
            $zipName = '.jp-download-' . bin2hex(random_bytes(4));
            $zip = $fm->compress($dir, $ps, $zipName);
            rename($zip, "$tmpDir/download.zip");
            header('Content-Type: application/zip');
            header('X-JP-Name: download.zip');
            header('Content-Length: ' . filesize("$tmpDir/download.zip"));
            readfile("$tmpDir/download.zip");
            @unlink("$tmpDir/download.zip");
            @rmdir($tmpDir);
            exit;
        case 'exposure_scan':
            $reply(['ok' => true, 'scan' => ExposureService::scan((string) ($args['docroot'] ?? ''))]);
        case 'make_private':
            $docroot = (string) ($args['docroot'] ?? '');
            if (!$fm->inside((string) realpath($docroot))) {
                throw new InvalidArgumentException('Bad document root.');
            }
            $reply(['ok' => true, 'dest' => ExposureService::makePrivate($docroot, $root, (string) ($args['rel'] ?? ''))]);
        case 'htaccess':
            $docroot = (string) ($args['docroot'] ?? '');
            $reply(['ok' => true, 'files' => $fm->inside((string) realpath($docroot)) ? RoutesService::htaccessFiles($docroot) : []]);
        case 'read':
            // One small text file (e.g. a cPanel php.ini/.user.ini to import).
            $p = realpath($root . '/' . ltrim((string) ($args['path'] ?? ''), '/'));
            if ($p === false || !$fm->inside($p) || !is_file($p) || filesize($p) > 262144) {
                $reply(['ok' => true, 'content' => null]);
            }
            $reply(['ok' => true, 'content' => (string) file_get_contents($p)]);
        case 'opcache_clear':
            // Compiled scripts of this site only (opcache.restrict_api lets only this folder call it).
            $n = 0;
            if (function_exists('opcache_invalidate')) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && str_ends_with($f->getFilename(), '.php') && @opcache_invalidate($f->getPathname(), true)) {
                        $n++;
                    }
                }
            }
            $reply(['ok' => true, 'count' => $n]);
        case 'usage':
            // Bytes in the site folder (quota accounting).
            $bytes = 0;
            $files = 0;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isLink()) {
                    $bytes += (int) $f->getSize();
                    $files++;
                }
            }
            $reply(['ok' => true, 'bytes' => $bytes, 'files' => $files]);
        case 'ping':
            $reply(['ok' => true, 'user' => function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '']);
        default:
            throw new InvalidArgumentException('Unknown operation.');
    }
} catch (Throwable $e) {
    $reply(['ok' => false, 'error' => $e->getMessage()]);
}
