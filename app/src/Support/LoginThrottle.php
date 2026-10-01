<?php
declare(strict_types=1);

/**
 * Slows down password guessing: after 5 failed sign-ins for one username
 * from one address, or 20 from one address for any usernames, within 15
 * minutes, that address has to wait - and after 30 for one username from
 * anywhere (many addresses), that username does. A success clears its record for that
 * username. Failures are kept for a day (the audit log keeps the history).
 */
final class LoginThrottle
{
    private const WINDOW_MIN = 15;
    private const PER_USER = 5;
    private const PER_IP = 20;
    private const PER_ACCOUNT = 30;

    public static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    /** Minutes until another attempt is allowed (0 = allowed now). */
    public static function blockedFor(string $username): int
    {
        try {
            $pdo = Database::app();
            $s = $pdo->prepare('SELECT COUNT(*), MIN(created_at) FROM login_attempts WHERE ip = ? AND username = ? AND created_at > NOW() - INTERVAL ' . self::WINDOW_MIN . ' MINUTE');
            $s->execute([self::ip(), strtolower($username)]);
            [$userCount, $userFirst] = $s->fetch(PDO::FETCH_NUM);
            $s = $pdo->prepare('SELECT COUNT(*), MIN(created_at) FROM login_attempts WHERE ip = ? AND created_at > NOW() - INTERVAL ' . self::WINDOW_MIN . ' MINUTE');
            $s->execute([self::ip()]);
            [$ipCount, $ipFirst] = $s->fetch(PDO::FETCH_NUM);
            $s = $pdo->prepare('SELECT COUNT(*), MIN(created_at) FROM login_attempts WHERE username = ? AND created_at > NOW() - INTERVAL ' . self::WINDOW_MIN . ' MINUTE');
            $s->execute([strtolower($username)]);
            [$acctCount, $acctFirst] = $s->fetch(PDO::FETCH_NUM);
        } catch (Throwable $e) {
            error_log('login throttle: ' . $e->getMessage());
            return 0;
        }
        $first = null;
        if ((int) $userCount >= self::PER_USER) {
            $first = $userFirst;
        } elseif ((int) $ipCount >= self::PER_IP) {
            $first = $ipFirst;
        } elseif ((int) $acctCount >= self::PER_ACCOUNT) {
            $first = $acctFirst;
        }
        if ($first === null) {
            return 0;
        }
        return max(1, (int) ceil((strtotime((string) $first) + self::WINDOW_MIN * 60 - time()) / 60));
    }

    public static function fail(string $username): void
    {
        try {
            $pdo = Database::app();
            $pdo->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')->execute([self::ip(), substr(strtolower($username), 0, 190)]);
            if (random_int(1, 50) === 1) {
                $pdo->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
            }
        } catch (Throwable $e) {
            error_log('login throttle: ' . $e->getMessage());
        }
    }

    public static function clear(string $username): void
    {
        try {
            Database::app()->prepare('DELETE FROM login_attempts WHERE ip = ? AND username = ?')->execute([self::ip(), strtolower($username)]);
        } catch (Throwable) {
        }
    }
}
