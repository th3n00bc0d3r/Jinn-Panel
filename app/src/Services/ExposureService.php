<?php
declare(strict_types=1);

/**
 * Files in a site's document root that anyone can download and probably
 * shouldn't: site archives, database dumps, backup copies, logs, data
 * exports (cPanel > Domains > domain > Exposed files, and the migration
 * report). Only flagged - some are intentional downloads - with "Move to
 * private/", which moves them next to (not inside) the document root.
 * scan() and makePrivate() run in the account's PHP-FPM pool (pool-agent).
 */
final class ExposureService
{
    private const MAX_ENTRIES = 60000;
    private const MAX_SECONDS = 4.0;
    private const SKIP = ['node_modules', 'vendor', '.git', 'wp-includes', 'wp-admin'];
    private const KINDS = [
        'archive' => '/\.(zip|tar|tgz|tar\.gz|tar\.bz2|tar\.xz|gz|rar|7z)$/i',
        'database dump' => '/\.(sql|sql\.gz|sqlite|sqlite3|db|mdb|dump)$/i',
        'backup copy' => '/(\.(bak|old|orig|save|swp|tmp)|~)$|(^|[_.-])backup([_.-]|$)/i',
        'log' => '/^(error_log|debug\.log|.*\.log)$/i',
        'data export' => '/^(orders|customers|users|members|subscribers|contacts|payments|export)[\w-]*\.(json|csv|xls|xlsx)$/i',
    ];
    private const DIRS = ['_backups' => 'backup folder', 'backups' => 'backup folder', 'backup' => 'backup folder', '__MACOSX' => 'macOS archive leftovers'];

    /** @return array{items: list<array{path:string,size:int,kind:string,dir:bool}>, truncated:bool} */
    public static function scan(string $docroot): array
    {
        $items = [];
        $seen = 0;
        $start = microtime(true);
        $truncated = false;
        $walk = function (string $dir, string $rel) use (&$walk, &$items, &$seen, &$truncated, $start): void {
            foreach (scandir($dir) ?: [] as $e) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                if (++$seen > self::MAX_ENTRIES || microtime(true) - $start > self::MAX_SECONDS) {
                    $truncated = true;
                    return;
                }
                $path = "$dir/$e";
                $relPath = $rel === '' ? $e : "$rel/$e";
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    if (isset(self::DIRS[$e])) {
                        $items[] = ['path' => $relPath, 'size' => self::dirSize($path), 'kind' => self::DIRS[$e], 'dir' => true];
                        continue;
                    }
                    if (!in_array($e, self::SKIP, true) && $e[0] !== '.') {
                        $walk($path, $relPath);
                    }
                    continue;
                }
                foreach (self::KINDS as $kind => $re) {
                    if (preg_match($re, $e)) {
                        $items[] = ['path' => $relPath, 'size' => (int) @filesize($path), 'kind' => $kind, 'dir' => false];
                        break;
                    }
                }
            }
        };
        if (is_dir($docroot)) {
            $walk(rtrim($docroot, '/'), '');
        }
        usort($items, fn($a, $b) => $b['size'] <=> $a['size']);
        return ['items' => $items, 'truncated' => $truncated];
    }

    /**
     * Moves $rel (inside the docroot) to <site>/private/<rel>, out of the
     * web root. Returns the new path.
     */
    public static function makePrivate(string $docroot, string $siteDir, string $rel): string
    {
        $src = realpath($docroot . '/' . $rel);
        $root = realpath($docroot);
        if ($rel === '' || str_contains($rel, '..') || $src === false || $root === false || !str_starts_with($src, $root . '/') || is_link($docroot . '/' . $rel)) {
            throw new InvalidArgumentException('That file is not in the site\'s document root.');
        }
        $private = rtrim($siteDir, '/') . '/private';
        if (str_starts_with($root . '/', $private . '/')) {
            throw new InvalidArgumentException('The document root is inside private/ - move it elsewhere first.');
        }
        $dest = $private . '/' . substr($src, strlen($root) + 1);
        if (file_exists($dest)) {
            $dest .= '.' . date('Ymd-His');
        }
        if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) {
            throw new RuntimeException('Could not create ' . dirname($dest) . '.');
        }
        if (!@rename($src, $dest)) {
            throw new RuntimeException("Could not move it to $dest.");
        }
        return $dest;
    }

    private static function dirSize(string $dir): int
    {
        $size = 0;
        $n = 0;
        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
                if (++$n > 20000) {
                    break;
                }
                $size += $f->isFile() ? (int) $f->getSize() : 0;
            }
        } catch (Throwable) {
        }
        return $size;
    }
}
