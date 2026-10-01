<?php
declare(strict_types=1);

/** cPanel > Cache: object cache login (Valkey) and per-domain page cache. */
final class CacheController
{
    public static function index(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $d = Database::app()->prepare('SELECT * FROM domains WHERE user_id = ? ORDER BY domain_name');
        $d->execute([$me['id']]);
        $domains = $d->fetchAll();
        foreach ($domains as &$row) {
            $row['cached_pages'] = CacheService::pageCount((string) $row['domain_name']);
        }
        unset($row);
        $creds = null;
        try {
            $creds = CacheService::credentials($me);
        } catch (Throwable $e) {
            error_log($e->getMessage());
        }
        View::render('cpanel/cache', [
            'title' => 'Cache',
            'domains' => $domains,
            'creds' => $creds,
            'keys' => $creds ? CacheService::objectKeys($me) : null,
        ], 'cpanel');
    }

    public static function objectCache(): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        try {
            match ($_POST['op'] ?? '') {
                'enable', 'reset' => CacheService::enableObjectCache($me),
                'disable' => CacheService::disableObjectCache($me),
                'flush' => Flash::ok(CacheService::flushObjects($me) . ' keys deleted.'),
                default => throw new InvalidArgumentException('Unknown action.'),
            };
            if (($_POST['op'] ?? '') !== 'flush') {
                Flash::ok(match ($_POST['op']) { 'enable' => 'Object cache ready - connection details below.', 'reset' => 'New password set - update it in your site.', default => 'Object cache turned off and emptied.' });
            }
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        header('Location: /cpanel/cache');
        exit;
    }

    public static function pageCache(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $s = Database::app()->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
        $s->execute([(int) ($params['id'] ?? 0), $me['id']]);
        $d = $s->fetch();
        try {
            if (!$d) {
                throw new InvalidArgumentException('Domain not found.');
            }
            if (($_POST['op'] ?? '') === 'clear') {
                $n = CacheService::clearDomain($d);
                Flash::ok("Cache cleared for {$d['domain_name']} ({$n['pages']} pages" . ($n['objects'] !== null ? ", {$n['objects']} object keys" : '') . ", {$n['opcache']} PHP files).");
            } else {
                $ttl = !empty($_POST['enabled']) ? (int) ($_POST['ttl'] ?? 300) : 0;
                CacheService::setPageCache($d, $ttl);
                Flash::ok($ttl ? "Page cache on for {$d['domain_name']} ($ttl s)." : "Page cache off for {$d['domain_name']}.");
            }
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        header('Location: /cpanel/cache');
        exit;
    }
}
