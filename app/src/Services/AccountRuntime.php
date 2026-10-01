<?php
declare(strict_types=1);

/**
 * Where a hosting account's code runs. Each account is a Linux user
 * (jp_<username>) that owns its site folders; its sites' PHP runs in its own
 * PHP-FPM pool (one per PHP version it uses), never inside the web server
 * or the panel. The root worker builds all of that from the DB
 * (hostpanel-worker accountSync); the panel only asks for a sync whenever
 * something it depends on changes, and talks to the pool over its socket
 * (PoolClient) for work that has to happen as the account user.
 */
final class AccountRuntime
{
    public const FPM_RUN = '/run/jinnpanel-php';
    public const HOME = '/var/lib/jinnpanel/php';

    /** Rebuild the account's runtime (user, folders, pools) - after account/domain/status changes. */
    public static function sync(int $userId): void
    {
        SystemWorkerService::enqueue("account-$userId", ['type' => 'account_sync', 'user_id' => $userId]);
    }

    /** After a deleted account's rows are gone: its pools and Linux user. */
    public static function remove(string $username): void
    {
        SystemWorkerService::enqueue("account-remove-$username", ['type' => 'account_remove', 'username' => $username]);
    }

    /** Files were written into a site folder by the panel/migration: hand them to the account. */
    public static function fixOwner(string $domain): void
    {
        SystemWorkerService::enqueue("site-owner-$domain", ['type' => 'site_fix_owner', 'domain' => $domain]);
    }

    /** Deletes /var/www/<domain> - the worker refuses while the domain is still hosted. */
    public static function removeSite(string $domain): void
    {
        SystemWorkerService::enqueue("site-remove-$domain", ['type' => 'site_remove', 'domain' => $domain]);
    }

    public static function linuxUser(string $username): string
    {
        return Usernames::linuxUser($username);
    }

    /** The PHP-FPM tag of a domain's php_version: "default", or "82" for 8.2. */
    public static function tag(string $phpVersion): string
    {
        return $phpVersion === 'default' ? 'default' : str_replace('.', '', $phpVersion);
    }

    /** The socket of the account's pool for a PHP version. */
    public static function socket(string $username, string $phpVersion = 'default'): string
    {
        return self::FPM_RUN . '/' . self::tag($phpVersion) . '/' . strtolower($username) . '.sock';
    }

    /** The account's static file server (nginx as the account, its cache in front). */
    public static function staticSocket(string $username): string
    {
        return '/run/jinnpanel-static/' . strtolower($username) . '/static.sock';
    }

    /** Whether the account's pool for that version is up (its socket exists). */
    public static function ready(string $username, string $phpVersion = 'default'): bool
    {
        return file_exists(self::socket($username, $phpVersion));
    }

    /** Waits (briefly) for the worker to bring the pool up; true when it's there. */
    public static function waitReady(string $username, string $phpVersion = 'default', int $seconds = 20): bool
    {
        $until = microtime(true) + $seconds;
        while (!self::ready($username, $phpVersion)) {
            if (microtime(true) > $until) {
                return false;
            }
            usleep(250000);
            clearstatcache();
        }
        return true;
    }

    /** username of the account that owns a domain (or null). */
    public static function ownerOf(string $domain): ?string
    {
        $s = Database::app()->prepare('SELECT u.username FROM domains d JOIN users u ON u.id = d.user_id WHERE d.domain_name = ?');
        $s->execute([$domain]);
        $u = $s->fetchColumn();
        return is_string($u) ? $u : null;
    }
}
