<?php
declare(strict_types=1);

/**
 * Two-factor recovery codes: ten one-time codes that sign in instead of an
 * authenticator code (a lost phone). Shown once; only hashes are kept.
 * Codes look like "k7mq-2xpd-9fhw"; spaces, dashes and case don't matter.
 */
final class RecoveryCodes
{
    private const COUNT = 10;
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Replaces the user's codes with new ones and returns them (to show once). @return list<string> */
    public static function regenerate(int $userId): array
    {
        $codes = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $raw = '';
            for ($j = 0; $j < 12; $j++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[] = implode('-', str_split($raw, 4));
        }
        $pdo = Database::app();
        $pdo->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)');
        foreach ($codes as $c) {
            $ins->execute([$userId, self::hash($c)]);
        }
        return $codes;
    }

    public static function remaining(int $userId): int
    {
        $s = Database::app()->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $s->execute([$userId]);
        return (int) $s->fetchColumn();
    }

    /** Uses up a code if it's one of the user's unused ones. */
    public static function use(int $userId, string $code): bool
    {
        $norm = self::normalise($code);
        if (strlen($norm) !== 12) {
            return false;
        }
        $s = Database::app()->prepare('UPDATE recovery_codes SET used_at = NOW() WHERE user_id = ? AND code_hash = ? AND used_at IS NULL LIMIT 1');
        $s->execute([$userId, self::hash($norm)]);
        return $s->rowCount() === 1;
    }

    /** Whether the input looks like a recovery code rather than a 6-digit TOTP code. */
    public static function looksLikeOne(string $code): bool
    {
        return strlen(self::normalise($code)) === 12;
    }

    public static function clear(int $userId): void
    {
        Database::app()->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$userId]);
    }

    private static function normalise(string $code): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($code));
    }

    private static function hash(string $code): string
    {
        return hash('sha256', 'jp-recovery:' . self::normalise($code));
    }
}
