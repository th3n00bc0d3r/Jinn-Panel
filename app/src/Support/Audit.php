<?php
declare(strict_types=1);

/**
 * The audit trail (activity_log): who did what, from where. WHM > Activity
 * Log shows it. Never throws - a failed audit write must not fail the
 * action it describes (it's logged to app.log instead).
 */
final class Audit
{
    /** The account the current request acts on, for entries Flash records (see target()). */
    private static ?int $target = null;

    /** Names the account a WHM action is about, so its flashed outcome is filed under it. */
    public static function target(int $userId): void
    {
        self::$target = $userId;
    }

    public static function currentTarget(): ?int
    {
        return self::$target;
    }

    public static function log(string $action, string $detail = '', ?int $targetUserId = null, ?int $actorId = null): void
    {
        try {
            Database::app()->prepare('INSERT INTO activity_log (actor_id, action, detail, ip, target_user_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([
                    $actorId ?? (class_exists('Auth', false) ? Auth::id() : null),
                    substr($action, 0, 64),
                    mb_substr($detail, 0, 1000),
                    PHP_SAPI === 'cli' ? 'cli' : substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    $targetUserId,
                ]);
        } catch (Throwable $e) {
            error_log("audit ($action $detail): " . $e->getMessage());
        }
    }
}
