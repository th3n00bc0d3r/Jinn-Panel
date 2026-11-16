<?php
declare(strict_types=1);

/**
 * Preview harness bootstrap: lets every view render on a laptop with plain
 * PHP - no MariaDB, Stalwart, SFTPGo or Knot. The few classes views touch
 * that need the server (Config, Auth, Database, Quota) are replaced by
 * stubs here; everything else is the real code from app/src.
 *
 * Used by router.php (browser) and render-all.php (CI / command line).
 */

const PREVIEW_APP = __DIR__ . '/../../app';
const PREVIEW_FIXTURES = __DIR__ . '/fixtures';

final class Config
{
    public const DB_HOST = '127.0.0.1';
    public const DB_NAME = 'hostpanel';
    public const DB_USER = 'preview';
    public const DB_PASS = 'preview';
    public const PROV_DB_HOST = '127.0.0.1';
    public const PROV_DB_USER = 'preview';
    public const PROV_DB_PASS = 'preview';
    public const MAIL_API_BASE = 'http://127.0.0.1:8080';
    public const MAIL_ADMIN_USER = 'admin';
    public const MAIL_ADMIN_PASS = 'preview';
    public const SFTP_API_BASE = 'http://127.0.0.1:8090';
    public const SFTP_ADMIN_USER = 'panelapi';
    public const SFTP_ADMIN_PASS = 'preview';
    public const VALKEY_ADMIN_PASS = 'preview';
    public const SERVER_HOSTNAME = 'server.example.com';
    public const SERVER_IP = '203.0.113.10';
    public const SERVER_IPV6 = '2001:db8::10';
    public const VHOSTS_DOCROOT_BASE = '/var/www';
    public const VHOSTS_CADDY_DIR = '/var/lib/frankenphp/sites-enabled';
    public const FRANKENPHP_CONFIG = '/etc/frankenphp/Caddyfile';
    public const PHP_VERSIONS_DIR = '/opt/php-versions';
    public const APP_KEY = 'preview-preview-preview-preview-';
    public const MIGRATION_DIR = '/var/lib/jinnpanel/migrations';
    public const SFTP_PUBLIC_PORT = 2022;
    public const APP_NAME = 'JinnPanel';
    public const SESSION_NAME = 'jp_preview';
}

/** Signed-in user for the preview; the role comes from the fixture or ?as=. */
final class Auth
{
    /** @var array<string,mixed>|null */
    public static ?array $user = null;

    public static function as(string $role): void
    {
        self::$user = match ($role) {
            'admin' => ['id' => 1, 'username' => 'admin', 'email' => 'admin@example.com', 'full_name' => 'Ada Admin', 'role' => 'admin', 'parent_id' => null, 'package_id' => null, 'status' => 'active', 'totp_secret_enc' => null],
            'reseller' => ['id' => 2, 'username' => 'resell', 'email' => 'sales@reseller.test', 'full_name' => 'Rae Seller', 'role' => 'reseller', 'parent_id' => null, 'package_id' => 3, 'status' => 'active', 'totp_secret_enc' => null],
            'guest' => null,
            default => ['id' => 10, 'username' => 'acme', 'email' => 'owner@acme.test', 'full_name' => 'Acme Ltd', 'role' => 'user', 'parent_id' => 2, 'package_id' => 1, 'status' => 'active', 'totp_secret_enc' => null],
        };
    }

    public static function user(): ?array { return self::$user; }
    public static function check(): bool { return self::$user !== null; }
    public static function id(): ?int { return self::$user['id'] ?? null; }
    public static function role(): ?string { return self::$user['role'] ?? null; }
    public static function isAdmin(): bool { return self::role() === 'admin'; }
    public static function isReseller(): bool { return self::role() === 'reseller'; }
    public static function requireLogin(): void {}
    public static function requireRole(array $roles): void {}
    public static function panelHost(): string { return 'panel.' . Config::SERVER_HOSTNAME; }
    public static function onPanelHost(): bool { return true; }
    public static function handoffUrl(string $next = '/whm'): string { return $next; }
}

final class Database
{
    public static function app(): never
    {
        throw new RuntimeException('A view queried the database. Views get their data from the controller - pass it in the fixture.');
    }

    public static function provisioning(): never
    {
        self::app();
    }
}

/** Package usage for quota_bars.php; fixtures can override with $GLOBALS['preview_quota']. */
final class Quota
{
    // The pure helpers are the real ones (app/src/Support/Quota.php).
    public const UNLIMITED = -1;

    public static function unlimited(mixed $limit): bool
    {
        return (int) $limit < 0;
    }

    public static function bytes(mixed $mb): int
    {
        return (int) $mb > 0 ? (int) $mb * 1048576 : 0;
    }

    public static function fmtMb(mixed $mb): string
    {
        $v = (int) $mb;
        if ($v < 0) {
            return 'Unlimited';
        }
        return $v >= 1024 ? rtrim(rtrim(number_format($v / 1024, 1), '0'), '.') . ' GB' : $v . ' MB';
    }

    public static function fmtCount(mixed $n): string
    {
        return (int) $n < 0 ? 'Unlimited' : (string) (int) $n;
    }

    public static function usage(int $userId): array
    {
        return $GLOBALS['preview_quota']['usage'] ?? ['domains' => 3, 'databases' => 2, 'email_accounts' => 7, 'ftp_accounts' => 1];
    }

    public static function measured(int $userId): ?array
    {
        return $GLOBALS['preview_quota']['measured'] ?? [
            'used' => 3_400_000_000, 'limit' => 5_368_709_120, 'over' => false,
            'files' => 2_100_000_000, 'databases' => 600_000_000, 'mail' => 700_000_000,
            'bw_used' => 18_000_000_000, 'bw_limit' => 107_374_182_400, 'bw_over' => false,
        ];
    }
}

spl_autoload_register(function (string $class): void {
    foreach (['', '/Controllers', '/Services', '/Support'] as $dir) {
        $file = PREVIEW_APP . "/src$dir/$class.php";
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

require PREVIEW_APP . '/src/View.php';

/**
 * Every fixture: view name => fixture array. A fixture file
 * (fixtures/<view>.php) returns:
 *   layout   'whm' | 'cpanel' | 'auth' | 'blank' | null
 *   role     'admin' | 'reseller' | 'user' | 'guest'
 *   path     the URL the page lives at (for the active menu item and links)
 *   data     the variables the controller passes
 *   variants name => data overrides (e.g. 'empty' => ['domains' => []]), or
 *            ['data' => [...], 'flash' => [...], 'role' => ..., 'features' => [...]]
 *   flash    list of [type, message] to show
 *   session  extra $_SESSION entries
 *   features what the account's package leaves out, e.g. ['cron' => false]
 *            (also per variant)
 *
 * @return array<string, array<string,mixed>>
 */
function preview_fixtures(): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PREVIEW_FIXTURES, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php' || str_starts_with($f->getFilename(), '_')) {
            continue;
        }
        $view = substr($f->getPathname(), strlen(PREVIEW_FIXTURES) + 1, -4);
        $out[str_replace(DIRECTORY_SEPARATOR, '/', $view)] = require $f->getPathname();
    }
    ksort($out);
    return $out;
}

/** Renders one view with its fixture (and variant) and returns the HTML. */
function preview_render(string $view, array $fx, ?string $variant = null, ?string $role = null): string
{
    $_SESSION = $fx['session'] ?? [];
    foreach ($fx['flash'] ?? [] as [$type, $msg]) {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $msg];
    }
    $data = $fx['data'] ?? [];
    $features = $fx['features'] ?? [];
    if ($variant !== null) {
        $v = $fx['variants'][$variant] ?? throw new RuntimeException("No variant '$variant' for $view");
        $data = array_replace($data, $v['data'] ?? $v);
        foreach ($v['flash'] ?? [] as [$type, $msg]) {
            $_SESSION['flash'][] = ['type' => $type, 'message' => $msg];
        }
        if (isset($v['role'])) {
            $fx['role'] = $v['role'];
        }
        if (isset($v['features'])) {
            $features = $v['features'];
            unset($data['features']);
        }
    }
    $GLOBALS['preview_quota'] = $fx['quota'] ?? null;
    Auth::as($role ?? ($fx['role'] ?? 'user'));
    // What the hosting account's package includes (fixture 'features' => [key => false, ...]).
    // Panel settings the layout reads (the alerts bell's count), without a database.
    (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, ['alerts_summary' => json_encode(['count' => 5, 'level' => 'danger', 'at' => time()])] + ($fx['settings'] ?? []));
    Features::forget();
    if (Auth::id() !== null) {
        Features::prime((int) Auth::id(), $features);
    }
    $_SERVER['REQUEST_URI'] = $fx['path'] ?? '/';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    View::reset();
    ob_start();
    try {
        // A fixture may point at a file outside app/views (the chart gallery).
        View::render($fx['view'] ?? $view, $data, array_key_exists('layout', $fx) ? $fx['layout'] : 'whm');
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    return (string) ob_get_clean();
}
