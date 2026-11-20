<?php
declare(strict_types=1);

final class View
{
    private const BASE = __DIR__ . '/../views';

    /** @var array<string,string> named HTML blocks a view hands to its layout */
    private static array $slots = [];
    /** @var list<string> page scripts (public/assets/js/pages/<name>.js) */
    private static array $scripts = [];
    /** @var list<array{0:string,1?:string}>|null */
    private static ?array $crumbs = null;

    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $view, array $data = [], ?string $layout = 'whm'): void
    {
        $content = self::capture(self::BASE . "/$view.php", $data);

        if ($layout === null) {
            echo $content;
        } else {
            $data['content'] = $content;
            echo self::capture(self::BASE . "/layouts/$layout.php", $data);
        }
        // Values of a form that failed are shown once (Ui::old), then dropped.
        unset($_SESSION['old']);
    }

    /** @param array<string,mixed> $data */
    private static function capture(string $file, array $data): string
    {
        if (!is_file($file)) {
            throw new RuntimeException("View not found: $file");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    /** Forgets slots, scripts and breadcrumbs (the preview renders many pages in one process). */
    public static function reset(): void
    {
        self::$slots = [];
        self::$scripts = [];
        self::$crumbs = null;
    }

    /** Hands a block of HTML to the layout (e.g. 'actions' for the page header). */
    public static function slot(string $name, string $html): void
    {
        self::$slots[$name] = (self::$slots[$name] ?? '') . $html;
    }

    public static function slotGet(string $name): string
    {
        return self::$slots[$name] ?? '';
    }

    /** Loads /assets/js/pages/<name>.js on this page. */
    public static function script(string $name): void
    {
        if (preg_match('/^[a-z0-9-]+$/', $name) && !in_array($name, self::$scripts, true)) {
            self::$scripts[] = $name;
        }
    }

    /** @return list<string> */
    public static function scripts(): array
    {
        return self::$scripts;
    }

    /**
     * Breadcrumbs above the page title: [label, href] pairs, the last one
     * (the current page) without a link.
     *
     * @param list<array{0:string,1?:string}> $crumbs
     */
    public static function breadcrumbs(array $crumbs): void
    {
        self::$crumbs = $crumbs;
    }

    /** @return list<array{0:string,1?:string}>|null */
    public static function crumbs(): ?array
    {
        return self::$crumbs;
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * URL of a file under public/ with its modification time appended, so a
 * deploy that rebuilds app.css or changes a script makes browsers fetch the
 * new copy instead of reusing a cached one. Returns an escaped attribute value.
 */
function asset(string $path): string
{
    $file = dirname(__DIR__) . '/public' . $path;
    return e(is_file($file) ? $path . '?v=' . filemtime($file) : $path);
}

function old(string $key, string $default = ''): string
{
    return e(Ui::old($key) ?? $default);
}

/**
 * Text shown in the interface. Today it returns the English text with
 * :name placeholders filled in; it is the single place a translation
 * lookup goes later, so new UI text should go through it.
 *
 * @param array<string,string|int> $vars
 */
function t(string $text, array $vars = []): string
{
    foreach ($vars as $k => $v) {
        $text = str_replace(':' . $k, (string) $v, $text);
    }
    return $text;
}

function fmt_bytes(int $bytes): string
{
    if ($bytes <= 0) return '0 MB';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
}

/** "3 minutes ago" style, for timestamps or DATETIME strings. */
function fmt_ago(int|string|null $when): string
{
    if ($when === null || $when === '') {
        return 'never';
    }
    $ts = is_int($when) ? $when : (int) strtotime($when);
    $d = time() - $ts;
    if ($d < 0) {
        return date('Y-m-d H:i', $ts);
    }
    return match (true) {
        $d < 60 => 'just now',
        $d < 3600 => (int) floor($d / 60) . ' min ago',
        $d < 86400 => (int) floor($d / 3600) . ' h ago',
        $d < 86400 * 7 => (int) floor($d / 86400) . ' d ago',
        default => date('j M Y', $ts),
    };
}

/**
 * Inline icon set (24x24 stroke icons, Lucide-style) so the UI has zero
 * external asset dependencies. Decorative: hidden from screen readers -
 * give the button or link around it a name. New icons also go in
 * assets/icons/ and docs/ICONS.md.
 */
function icon(string $name, string $class = 'h-5 w-5'): string
{
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'box' => '<path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18Z"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m3 6 9 7 9-7"/>',
        'folder-up' => '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/><path d="M12 10v6"/><path d="m9.5 12.5 2.5-2.5 2.5 2.5"/>',
        'server' => '<rect x="2" y="3" width="20" height="7" rx="1.5"/><rect x="2" y="14" width="20" height="7" rx="1.5"/><path d="M6 6.5h.01"/><path d="M6 17.5h.01"/>',
        'folder' => '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'menu' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'close' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'trash' => '<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>',
        'pause' => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
        'play' => '<path d="m6 3 15 9-15 9V3Z"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'bolt' => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-2.64-6.36L21 8"/><path d="M21 3v5h-5"/>',
        'download' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'transfer' => '<path d="M4 7h13"/><path d="m13 3 4 4-4 4"/><path d="M20 17H7"/><path d="m11 13-4 4 4 4"/>',
        'alert' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'sliders' => '<line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1Z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'monitor' => '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'key' => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'copy' => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'edit' => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
        'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c7 0 10 7 10 7a17 17 0 0 1-2.2 3.3"/><path d="M6.6 6.6C3.6 8.6 2 12 2 12s3 7 10 7a9.6 9.6 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="m2 2 20 20"/>',
        'file' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
        'file-text' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'x-circle' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'arrow-up' => '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
        'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'home' => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'code' => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
        'terminal' => '<path d="m4 17 6-6-6-6"/><path d="M12 19h8"/>',
        'layers' => '<path d="m12 2 10 5-10 5L2 7Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
        'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
        'save' => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/>',
        'list' => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'route' => '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
        'archive' => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'cpu' => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/>',
        'hard-drive' => '<path d="M22 12H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><path d="M6 16h.01"/><path d="M10 16h.01"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
    ];
    $inner = $paths[$name] ?? $paths['grid'];
    return "<svg xmlns=\"http://www.w3.org/2000/svg\" class=\"$class\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\" focusable=\"false\">$inner</svg>";
}
