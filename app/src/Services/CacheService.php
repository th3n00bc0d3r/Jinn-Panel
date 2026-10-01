<?php
declare(strict_types=1);

/**
 * Per-domain caches the customer can clear (cPanel > Domains > domain >
 * Clear cache).
 *
 * OPcache: customer sites on the default PHP version run inside the same
 * FrankenPHP process as the panel, so the panel can invalidate the site's
 * compiled files directly (opcache_invalidate per file - never a global
 * opcache_reset, which would hit every other site). Sites on an alternative
 * PHP version run in their own instance, which this doesn't reach (its
 * OPcache revalidates changed files by timestamp anyway).
 */
final class CacheService
{
    private const MAX_FILES = 50000;

    /** @return array{opcache:int, pages:?int} counts of what was cleared (pages: null = no page cache) */
    public static function clearDomain(array $domain): array
    {
        $name = (string) $domain['domain_name'];
        $opcache = 0;
        if (($domain['php_version'] ?? 'default') === 'default' && function_exists('opcache_invalidate')) {
            $opcache = self::invalidateTree(VhostService::siteDir($name));
        }
        return ['opcache' => $opcache, 'pages' => null];
    }

    private static function invalidateTree(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($n >= self::MAX_FILES) {
                break;
            }
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && @opcache_invalidate($file->getPathname(), true)) {
                $n++;
            }
        }
        return $n;
    }
}
