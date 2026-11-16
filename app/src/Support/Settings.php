<?php
declare(strict_types=1);

/** Key/value panel settings (panel_settings), read once per request. */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Database::app()->query('SELECT setting_key, setting_value FROM panel_settings')->fetchAll() as $r) {
                    self::$cache[(string) $r['setting_key']] = (string) ($r['setting_value'] ?? '');
                }
            } catch (Throwable $e) {
                error_log('settings: ' . $e->getMessage());
            }
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        Database::app()->prepare('REPLACE INTO panel_settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }
}
