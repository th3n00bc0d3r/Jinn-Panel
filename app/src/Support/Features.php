<?php
declare(strict_types=1);

/**
 * What a hosting account may use, beyond the counted limits: switched on
 * or off per package (packages.features), and per account on top of that
 * (users.feature_overrides) from WHM > Accounts > account.
 *
 * A package without a setting has everything on (packages created before
 * features existed keep working as they did). Turning a feature off stops
 * new use; what already exists stays (a running object cache, aliases,
 * custom PHP versions) - except cron jobs, which stop running.
 */
final class Features
{
    /** key => [label, what it allows]. Order = display order. */
    public const LIST = [
        'file_manager' => ['File Manager', 'Browse, upload, edit and unzip files in the browser.'],
        'cron' => ['Cron jobs', 'Scheduled PHP scripts and URL calls. Off: existing jobs stop running.'],
        'backups' => ['Self-service backups', 'Back up on demand, download and restore. The server\'s own daily backup includes the account either way.'],
        'dns_editor' => ['DNS editor', 'Add, change and delete DNS records. Off: records are read-only.'],
        'php_versions' => ['Other PHP versions', 'Run a domain on a PHP version other than the default.'],
        'aliases' => ['Domain aliases', 'Serve a site on extra domain names.'],
        'routes' => ['Routing rules', 'Edit a site\'s routing rules. Following .htaccess keeps working.'],
        'object_cache' => ['Object cache', 'Turn on a Valkey object cache for its sites.'],
        'database_access' => ['phpMyAdmin and remote MySQL', 'Open phpMyAdmin, and allow MySQL connections from other hosts.'],
        'ssh_keys' => ['SSH keys for SFTP', 'Sign in to SFTP with a key as well as a password.'],
    ];

    /** @var array<int, array<string,bool>> */
    private static array $cache = [];

    /** A package's features (all on when it has no setting). @return array<string,bool> */
    public static function forPackage(?array $pkg): array
    {
        $set = json_decode((string) ($pkg['features'] ?? ''), true);
        $out = [];
        foreach (array_keys(self::LIST) as $k) {
            $out[$k] = is_array($set) && array_key_exists($k, $set) ? (bool) $set[$k] : true;
        }
        return $out;
    }

    /** An account's own overrides: feature => bool, only those set. @return array<string,bool> */
    public static function overrides(array $user): array
    {
        $set = json_decode((string) ($user['feature_overrides'] ?? ''), true);
        $out = [];
        foreach (is_array($set) ? $set : [] as $k => $v) {
            if (isset(self::LIST[$k])) {
                $out[(string) $k] = (bool) $v;
            }
        }
        return $out;
    }

    /** What an account may use: its package's features with its overrides on top. @return array<string,bool> */
    public static function forUser(int $userId): array
    {
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }
        $s = Database::app()->prepare('SELECT u.feature_overrides, p.features FROM users u LEFT JOIN packages p ON p.id = u.package_id WHERE u.id = ?');
        $s->execute([$userId]);
        $row = $s->fetch() ?: [];
        return self::$cache[$userId] = array_replace(self::forPackage($row), self::overrides($row));
    }

    /** The signed-in hosting account's features (everything on for other roles). @return array<string,bool> */
    public static function current(): array
    {
        $id = Auth::id();
        if ($id === null || Auth::role() !== 'user') {
            return array_fill_keys(array_keys(self::LIST), true);
        }
        return self::forUser($id);
    }

    public static function has(string $feature, ?int $userId = null): bool
    {
        $all = $userId === null ? self::current() : self::forUser($userId);
        return $all[$feature] ?? true;
    }

    /** Sets what forUser() returns for an account (the preview has no database). @param array<string,bool> $map */
    public static function prime(int $userId, array $map): void
    {
        self::$cache[$userId] = array_replace(array_fill_keys(array_keys(self::LIST), true), $map);
    }

    /**
     * Stops a request for a feature the account doesn't have: a page shows
     * why; a form goes back with a message.
     */
    public static function require(string $feature, string $back = '/cpanel'): void
    {
        if (self::has($feature)) {
            return;
        }
        $label = self::LIST[$feature][0] ?? $feature;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Flash::error("Your package doesn't include $label. Ask your hosting provider if you need it.");
            header('Location: ' . $back);
            exit;
        }
        http_response_code(403);
        View::render('cpanel/feature_off', ['title' => $label, 'feature' => $feature], 'cpanel');
        exit;
    }

    /** features[key]=1 checkboxes from a form -> the JSON stored on a package. */
    public static function fromForm(array $post): string
    {
        $in = is_array($post['features'] ?? null) ? $post['features'] : [];
        $out = [];
        foreach (array_keys(self::LIST) as $k) {
            $out[$k] = !empty($in[$k]);
        }
        return (string) json_encode($out);
    }

    /** override[key] = '' (package) | '1' | '0' from a form -> the JSON stored on an account, or null. */
    public static function overridesFromForm(array $post): ?string
    {
        $in = is_array($post['override'] ?? null) ? $post['override'] : [];
        $out = [];
        foreach (array_keys(self::LIST) as $k) {
            $v = (string) ($in[$k] ?? '');
            if ($v === '1' || $v === '0') {
                $out[$k] = $v === '1';
            }
        }
        return $out ? (string) json_encode($out) : null;
    }

    /** Forgets cached lookups (after a change in this request). */
    public static function forget(): void
    {
        self::$cache = [];
    }
}
