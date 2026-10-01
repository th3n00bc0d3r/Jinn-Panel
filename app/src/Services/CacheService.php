<?php
declare(strict_types=1);

/**
 * Per-domain caches the customer can clear (cPanel > Domains > domain >
 * Clear cache).
 *
 * OPcache: a site's compiled files live in its PHP-FPM master's shared
 * memory; the pool agent invalidates just that site's files from inside the
 * account's pool (opcache_invalidate per file - never a global
 * opcache_reset, which would hit every other site; opcache.restrict_api
 * keeps customer code itself away from the OPcache API).
 */
final class CacheService
{
    public const PAGE_CACHE_DIR = '/var/lib/jinnpanel-pagecache';
    public const VALKEY_HOST = '127.0.0.1';
    public const VALKEY_PORT = 6379;

    /** @return array{opcache:int, pages:?int, objects:?int, static:int} counts of what was cleared (null = not in use) */
    public static function clearDomain(array $domain): array
    {
        $name = (string) $domain['domain_name'];
        // OPcache lives in the account's PHP-FPM master: cleared from inside its pool.
        try {
            $opcache = (int) PoolClient::call($name, 'opcache_clear')['count'];
        } catch (Throwable $e) {
            error_log("opcache clear ($name): " . $e->getMessage());
            $opcache = 0;
        }
        $pages = !empty($domain['page_cache_ttl']) || is_dir(self::pageDir($name)) ? self::purgePages($name) : null;
        $objects = null;
        $owner = self::owner((int) $domain['user_id']);
        if ($owner && !empty($owner['cache_secret_enc'])) {
            try {
                $objects = Valkey::admin()->deleteMatching(self::prefix($owner['username']) . $name . ':*');
            } catch (Throwable $e) {
                error_log('object cache clear: ' . $e->getMessage());
            }
        }
        $static = self::purgeStatic($name);
        return ['opcache' => $opcache, 'pages' => $pages, 'objects' => $objects, 'static' => $static];
    }

    // ------------------------------------------------------------------
    // Page cache (app/runtime/pagecache.php serves it)
    // ------------------------------------------------------------------

    public static function pageDir(string $domain): string
    {
        return self::PAGE_CACHE_DIR . '/' . preg_replace('/[^a-z0-9.-]/', '_', strtolower($domain));
    }

    /** $ttl seconds, or 0 = off. */
    public static function setPageCache(array $domain, int $ttl): void
    {
        if ($ttl !== 0 && ($ttl < 30 || $ttl > 86400)) {
            throw new InvalidArgumentException('Cache lifetime must be between 30 seconds and a day.');
        }
        Database::app()->prepare('UPDATE domains SET page_cache_ttl = ? WHERE id = ?')->execute([$ttl ?: null, $domain['id']]);
        $domain['page_cache_ttl'] = $ttl ?: null;
        PhpSettingsService::write($domain);
        if ($ttl === 0) {
            self::purgePages((string) $domain['domain_name']);
        }
    }

    public static function purgePages(string $domain): int
    {
        $dir = self::pageDir($domain);
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach (glob("$dir/*", GLOB_ONLYDIR) ?: [] as $sub) {
            foreach (glob("$sub/*") ?: [] as $f) {
                if (is_file($f) && @unlink($f)) {
                    $n++;
                }
            }
            @rmdir($sub);
        }
        return $n;
    }

    /**
     * A site with no PHP at all (plain HTML/CSS/JS): the web server serves
     * it straight from disk, so the page cache - which stores PHP output -
     * has nothing to do. Bounded scan of the document root.
     */
    public static function isStatic(string $domain): bool
    {
        if (is_file(VhostService::rulesFile($domain)) && str_contains((string) @file_get_contents(VhostService::rulesFile($domain)), '/index.php')) {
            return false;
        }
        try {
            // The site's files are the account's to read: asked inside its pool.
            return (bool) PoolClient::call($domain, 'is_static', ['docroot' => VhostService::effectiveDocroot($domain)])['static'];
        } catch (Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Static file cache: the account's static server (nginx) keeps
    // compressed copies of each domain's static files for static_cache_ttl
    // seconds (0 = off); browser_cache = Cache-Control for static files.
    // ------------------------------------------------------------------

    public const STATIC_TTLS = [60 => '1 minute', 300 => '5 minutes', 900 => '15 minutes', 3600 => '1 hour', 21600 => '6 hours', 86400 => '1 day', 604800 => '1 week'];

    public static function setStaticCache(array $domain, int $ttl, bool $browser): void
    {
        if ($ttl !== 0 && !isset(self::STATIC_TTLS[$ttl])) {
            throw new InvalidArgumentException('Pick one of the offered cache lifetimes.');
        }
        Database::app()->prepare('UPDATE domains SET static_cache_ttl = ?, browser_cache = ? WHERE id = ?')->execute([$ttl, $browser ? 1 : 0, $domain['id']]);
        VhostService::create((string) $domain['domain_name'], (string) $domain['php_version'], (string) $domain['ssl_mode'], true, false);
        if ($ttl === 0) {
            self::purgeStatic((string) $domain['domain_name']);
        }
    }

    /** Empties the domain's static file cache (from inside the account's pool); returns how many entries went. */
    public static function purgeStatic(string $domain): int
    {
        try {
            return (int) PoolClient::call($domain, 'static_cache_clear')['count'];
        } catch (Throwable $e) {
            error_log("static cache clear ($domain): " . $e->getMessage());
            return 0;
        }
    }

    public static function staticCount(string $domain): ?int
    {
        try {
            return (int) PoolClient::call($domain, 'static_cache_count')['count'];
        } catch (Throwable) {
            return null;
        }
    }

    public static function pageCount(string $domain): int
    {
        return count(glob(self::pageDir($domain) . '/*/*') ?: []);
    }

    // ------------------------------------------------------------------
    // Object cache (Valkey): one login per account, limited to its prefix
    // ------------------------------------------------------------------

    public static function prefix(string $username): string
    {
        return $username . ':';
    }

    public static function valkeyUser(string $username): string
    {
        return 'acct_' . $username;
    }

    /** @return array{user:string, password:string, prefix:string}|null */
    public static function credentials(array $user): ?array
    {
        if (empty($user['cache_secret_enc'])) {
            return null;
        }
        return ['user' => self::valkeyUser((string) $user['username']), 'password' => Crypto::decrypt((string) $user['cache_secret_enc']), 'prefix' => self::prefix((string) $user['username'])];
    }

    /** Creates (or resets the password of) the account's object cache login. */
    public static function enableObjectCache(array $user): void
    {
        $pass = bin2hex(random_bytes(20));
        $v = Valkey::admin();
        // Data commands on its own keys only; no admin, no FLUSHALL/KEYS/CONFIG
        // (the "dangerous" category), no pub/sub across accounts.
        $v->cmd('ACL', 'SETUSER', self::valkeyUser((string) $user['username']), 'reset', 'on', '>' . $pass,
            '~' . self::prefix((string) $user['username']) . '*', 'resetchannels', '+@all', '-@dangerous', '-@admin', '-@pubsub', '+info', '+ping');
        $v->cmd('ACL', 'SAVE');
        Database::app()->prepare('UPDATE users SET cache_secret_enc = ? WHERE id = ?')->execute([Crypto::encrypt($pass), $user['id']]);
    }

    public static function disableObjectCache(array $user): void
    {
        try {
            $v = Valkey::admin();
            $v->cmd('ACL', 'DELUSER', self::valkeyUser((string) $user['username']));
            $v->cmd('ACL', 'SAVE');
            $v->deleteMatching(self::prefix((string) $user['username']) . '*');
        } finally {
            Database::app()->prepare('UPDATE users SET cache_secret_enc = NULL WHERE id = ?')->execute([$user['id']]);
        }
    }

    /** Deletes all the account's keys; returns how many. */
    public static function flushObjects(array $user): int
    {
        return Valkey::admin()->deleteMatching(self::prefix((string) $user['username']) . '*');
    }

    public static function objectKeys(array $user): ?int
    {
        try {
            return Valkey::admin()->countMatching(self::prefix((string) $user['username']) . '*');
        } catch (Throwable) {
            return null;
        }
    }

    private static function owner(int $userId): ?array
    {
        $s = Database::app()->prepare('SELECT id, username, cache_secret_enc FROM users WHERE id = ?');
        $s->execute([$userId]);
        return $s->fetch() ?: null;
    }
}
