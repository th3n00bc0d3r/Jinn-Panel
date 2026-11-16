<?php
declare(strict_types=1);

/**
 * Single-use links that let someone set a new password without the old
 * one: "forgot password" (valid 1 hour) and the welcome email's "set your
 * password" (3 days). The link carries a random token; only its SHA-256 is
 * stored, so the table can't be used to sign in.
 */
final class PasswordResetService
{
    private const TTL = ['reset' => 3600, 'welcome' => 259200];

    /** A new token for the user (older unused ones of the same kind stop working). */
    public static function create(int $userId, string $purpose = 'reset'): string
    {
        $purpose = isset(self::TTL[$purpose]) ? $purpose : 'reset';
        $token = bin2hex(random_bytes(32));
        $pdo = Database::app();
        $pdo->prepare('DELETE FROM password_resets WHERE (user_id = ? AND purpose = ? AND used_at IS NULL) OR expires_at < NOW() - INTERVAL 7 DAY')
            ->execute([$userId, $purpose]);
        $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, purpose, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ' . self::TTL[$purpose] . ' SECOND)')
            ->execute([$userId, hash('sha256', $token), $purpose]);
        return $token;
    }

    /** The link for a token, always on the panel's own hostname. */
    public static function url(string $token): string
    {
        return 'https://' . Auth::panelHost() . '/login/reset?token=' . $token;
    }

    /**
     * The reset row (with the user's username, email, role) for a valid,
     * unused, unexpired token of an active account, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $s = Database::app()->prepare(
            "SELECT r.id AS reset_id, r.purpose, u.* FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND u.status = 'active'"
        );
        $s->execute([hash('sha256', $token)]);
        $row = $s->fetch();
        return $row ?: null;
    }

    /** Marks the token used; false if it was used in the meantime. */
    public static function consume(int $resetId): bool
    {
        $s = Database::app()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
        $s->execute([$resetId]);
        return $s->rowCount() === 1;
    }
}
