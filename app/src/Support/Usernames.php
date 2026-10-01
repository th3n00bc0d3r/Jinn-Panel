<?php
declare(strict_types=1);

/**
 * Account usernames. A username names a lot more than a login: the account's
 * Linux user (jp_<name>, which runs its PHP), the prefix of its MySQL
 * databases and users (<name>_db), its SFTP logins and Valkey ACL user. So
 * names that collide with the panel's own identities are refused:
 * `hostpanel` would get the panel's own folder as SFTP home and own
 * `hostpanel_app` (the panel's DB user) as a "database user".
 */
final class Usernames
{
    public const LINUX_PREFIX = 'jp_';

    /** Names (and MySQL/Linux prefixes) the panel and the system use themselves. */
    private const RESERVED = [
        'admin', 'administrator', 'root', 'toor', 'system', 'daemon', 'bin', 'sys', 'sync', 'games', 'man',
        'lp', 'mail', 'news', 'uucp', 'proxy', 'backup', 'list', 'irc', 'nobody', 'nogroup', 'operator',
        'adm', 'ftp', 'sshd', 'systemd', 'polkitd', 'dbus', 'chrony', 'tss', 'apache', 'nginx', 'httpd',
        'www', 'wwwdata', 'webmaster', 'hostmaster', 'postmaster', 'abuse', 'support', 'info', 'security',
        'hostpanel', 'jinnpanel', 'panel', 'whm', 'cpanel', 'jpanel', 'webmail', 'phpmyadmin', 'pma',
        'frankenphp', 'caddy', 'php', 'mysql', 'mariadb', 'sftpgo', 'stalwart', 'knot', 'valkey', 'redis',
        'webusers', 'setup', 'test', 'information_schema', 'performance_schema', 'global', 'idle',
    ];

    /** Error message for an invalid new account username, or null when it may be used. */
    public static function problem(string $name): ?string
    {
        if (!preg_match('/^[a-z][a-z0-9]{2,15}$/', $name)) {
            return 'Username must be 3-16 lowercase letters or digits, starting with a letter.';
        }
        if (self::isReserved($name)) {
            return "\"$name\" is reserved for the server itself - pick another username.";
        }
        if (function_exists('posix_getpwnam') && (posix_getpwnam($name) !== false || posix_getpwnam(self::linuxUser($name)) !== false)) {
            return "\"$name\" is already a user on this server - pick another username.";
        }
        return null;
    }

    /**
     * Rule for a login that has no hosting of its own (admin, reseller):
     * underscores are fine (migrated resellers become "<name>_whm"), it only
     * must not be a reserved name.
     */
    public static function loginProblem(string $name): ?string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $name)) {
            return 'Username must be 3-32 lowercase letters, digits or underscores, starting with a letter.';
        }
        return self::isReserved($name) ? "\"$name\" is reserved for the server itself - pick another username." : null;
    }

    public static function isReserved(string $name): bool
    {
        $name = strtolower($name);
        if (in_array($name, self::RESERVED, true)) {
            return true;
        }
        // Prefixes that would make <name>_x collide with the panel's own MySQL users.
        foreach (['hostpanel', 'jinnpanel', 'mysql', 'pma'] as $p) {
            if (str_starts_with($name, $p)) {
                return true;
            }
        }
        return false;
    }

    /** The Linux user an account's PHP, cron jobs and files run as. */
    public static function linuxUser(string $username): string
    {
        return self::LINUX_PREFIX . strtolower($username);
    }
}
