<?php
declare(strict_types=1);

/**
 * Per-site PHP settings (cPanel > Domains > domain > PHP settings, and the
 * php.ini / .user.ini files cPanel's MultiPHP INI Editor left in migrated
 * docroots).
 *
 * FrankenPHP reads neither .user.ini nor per-site ini directives, so the
 * server-wide auto_prepend_file (install.sh) is a tiny dispatcher that finds
 * the site from DOCUMENT_ROOT and includes its generated settings file,
 * which ini_set()s the values. That limits this to settings PHP allows at
 * runtime (PHP_INI_ALL); upload/post size limits are server-wide (WHM).
 * error_log always points into the site's own logs/ folder.
 */
final class PhpSettingsService
{
    public const DIR = '/var/lib/frankenphp/site-ini';

    /** key => [kind, label]; kinds: size, int, bool, timezone, errlevel */
    public const SETTINGS = [
        'memory_limit' => ['size', 'Memory limit'],
        'max_execution_time' => ['int', 'Max execution time (seconds)'],
        'max_input_time' => ['int', 'Max input time (seconds)'],
        'max_input_vars' => ['int', 'Max input variables'],
        'display_errors' => ['bool', 'Display errors'],
        'log_errors' => ['bool', 'Log errors'],
        'error_reporting' => ['errlevel', 'Error reporting'],
        'date.timezone' => ['timezone', 'Timezone'],
        'session.gc_maxlifetime' => ['int', 'Session lifetime (seconds)'],
        'zlib.output_compression' => ['bool', 'zlib output compression'],
    ];
    /** cPanel ini keys that are server-wide here (reported, not applied). */
    private const SERVER_WIDE = ['upload_max_filesize', 'post_max_size', 'allow_url_fopen', 'short_open_tag', 'max_file_uploads'];

    /** @return array<string,string> */
    public static function get(array $domain): array
    {
        $v = json_decode((string) ($domain['php_settings'] ?? ''), true);
        return is_array($v) ? $v : [];
    }

    /**
     * Validates and saves $in (key => value; '' removes the key), then
     * writes the site's settings file.
     *
     * @param array<string,mixed> $in
     */
    public static function save(array $domain, array $in): void
    {
        $settings = self::get($domain);
        foreach (self::SETTINGS as $key => [$kind, $label]) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $raw = trim((string) $in[$key]);
            if ($raw === '') {
                unset($settings[$key]);
                continue;
            }
            $settings[$key] = self::normalise($kind, $raw) ?? throw new InvalidArgumentException("$label: \"$raw\" isn't a valid value.");
        }
        self::store($domain, $settings);
    }

    /**
     * Imports cPanel's .user.ini / php.ini from the docroot (only keys this
     * can apply; others are returned as notes). Keeps settings already set.
     *
     * @return list<string> notes
     */
    public static function importCpanelIni(array $domain, string $docroot): array
    {
        $found = [];
        foreach (['php.ini', '.user.ini'] as $f) { // .user.ini wins, as on cPanel
            $file = "$docroot/$f";
            if (is_file($file) && !is_link($file) && filesize($file) < 262144) {
                $ini = @parse_ini_string((string) file_get_contents($file), false, INI_SCANNER_RAW);
                if (is_array($ini)) {
                    $found = array_merge($found, array_change_key_case($ini, CASE_LOWER));
                }
            }
        }
        if (!$found) {
            return [];
        }
        $settings = self::get($domain);
        $notes = [];
        $applied = [];
        foreach ($found as $key => $raw) {
            $raw = trim((string) $raw, " \t\"'");
            if (isset(self::SETTINGS[$key])) {
                $v = self::normalise(self::SETTINGS[$key][0], $raw);
                if ($v !== null && !isset($settings[$key])) {
                    $settings[$key] = $v;
                    $applied[] = "$key = $v";
                }
            } elseif (in_array($key, self::SERVER_WIDE, true)) {
                $notes[] = "$key = $raw is server-wide here (WHM > Server config > PHP)";
            }
            // error_log: always the site's logs/ folder; session.save_path: the server's.
        }
        self::store($domain, $settings);
        if ($applied) {
            array_unshift($notes, 'PHP settings imported from cPanel: ' . implode(', ', $applied));
        }
        return $notes;
    }

    /** The settings file the dispatcher includes for $domain. */
    public static function file(string $domain): string
    {
        return self::DIR . '/' . preg_replace('/[^a-z0-9.-]/', '_', strtolower($domain)) . '.php';
    }

    public static function logFile(string $domain): string
    {
        return VhostService::siteDir($domain) . '/logs/php-error.log';
    }

    /** (Re)writes $domain's settings file from its stored settings. */
    public static function write(array $domain): void
    {
        $name = (string) $domain['domain_name'];
        $settings = self::get($domain);
        $logDir = dirname(self::logFile($name));
        if (!is_dir($logDir)) {
            @mkdir($logDir, 02775, true);
        }
        $lines = ["<?php", "// Generated by JinnPanel (PhpSettingsService) for $name - edit in cPanel > Domains."];
        $lines[] = 'ini_set(\'error_log\', ' . var_export(self::logFile($name), true) . ');';
        foreach ($settings as $key => $value) {
            if (isset(self::SETTINGS[$key])) {
                $lines[] = 'ini_set(' . var_export($key, true) . ', ' . var_export((string) $value, true) . ');';
            }
        }
        // Options for the dispatcher (install.sh / app/runtime/dispatch.php).
        $ttl = (int) ($domain['page_cache_ttl'] ?? 0);
        $lines[] = 'return ' . var_export(['page_cache_ttl' => $ttl > 0 ? $ttl : 0], true) . ';';
        if (!is_dir(self::DIR)) {
            throw new RuntimeException(self::DIR . ' is missing - re-run install.sh.');
        }
        $tmp = self::file($name) . '.tmp';
        file_put_contents($tmp, implode("\n", $lines) . "\n");
        rename($tmp, self::file($name));
    }

    public static function remove(string $domain): void
    {
        @unlink(self::file($domain));
    }

    private static function store(array $domain, array $settings): void
    {
        ksort($settings);
        Database::app()->prepare('UPDATE domains SET php_settings = ? WHERE id = ?')
            ->execute([$settings ? json_encode($settings) : null, $domain['id']]);
        $domain['php_settings'] = $settings ? json_encode($settings) : null;
        self::write($domain);
    }

    private static function normalise(string $kind, string $raw): ?string
    {
        $raw = trim($raw);
        return match ($kind) {
            'size' => preg_match('/^(-1|\d{1,5}[KMG]?)$/i', $raw) ? strtoupper($raw) : null,
            'int' => preg_match('/^\d{1,7}$/', $raw) ? $raw : null,
            'bool' => match (strtolower($raw)) {
                '1', 'on', 'true', 'yes' => 'On',
                '0', 'off', 'false', 'no', '' => 'Off',
                default => null,
            },
            'timezone' => in_array($raw, DateTimeZone::listIdentifiers(), true) ? $raw : null,
            'errlevel' => ($n = self::errorLevel($raw)) !== null ? (string) $n : null,
            default => null,
        };
    }

    /**
     * Numeric value of an error_reporting expression ("E_ALL & ~E_DEPRECATED",
     * "32767"), or null. ini_set() doesn't evaluate these - only the ini file
     * parser does - so the number is what gets stored.
     */
    public static function errorLevel(string $expr): ?int
    {
        if (strlen($expr) > 200 || !preg_match('/^[A-Z_&~^| ()0-9-]+$/', $expr)) {
            return null;
        }
        $tokens = preg_split('/\s*([&|^~()])\s*/', trim($expr), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $pos = 0;
        $parse = null;
        $atom = function () use (&$tokens, &$pos, &$parse): ?int {
            $t = $tokens[$pos++] ?? null;
            return match (true) {
                $t === null => null,
                $t === '(' => (function () use (&$tokens, &$pos, &$parse) { $v = $parse(); return ($tokens[$pos++] ?? '') === ')' ? $v : null; })(),
                preg_match('/^-?\d{1,6}$/', $t) === 1 => (int) $t,
                preg_match('/^E_[A-Z_]+$/', $t) === 1 && defined($t) => (int) constant($t),
                default => null,
            };
        };
        $unary = function () use (&$tokens, &$pos, $atom, &$unary): ?int {
            if (($tokens[$pos] ?? '') === '~') {
                $pos++;
                $v = $unary();
                return $v === null ? null : ~$v;
            }
            return $atom();
        };
        $binary = function (array $ops, callable $next) use (&$tokens, &$pos): callable {
            return function () use ($ops, $next, &$tokens, &$pos): ?int {
                $v = $next();
                while ($v !== null && in_array($tokens[$pos] ?? '', $ops, true)) {
                    $op = $tokens[$pos++];
                    $r = $next();
                    if ($r === null) {
                        return null;
                    }
                    $v = match ($op) { '&' => $v & $r, '^' => $v ^ $r, default => $v | $r };
                }
                return $v;
            };
        };
        $and = $binary(['&'], $unary);
        $xor = $binary(['^'], $and);
        $parse = $binary(['|'], $xor);
        $v = $parse();
        return $pos === count($tokens) ? $v : null;
    }
}
