<?php
declare(strict_types=1);

final class Auth
{
    /** Signed-in sessions end after this long without a request, and after MAX_AGE regardless. */
    private const IDLE_TIMEOUT = 7200;
    private const MAX_AGE = 43200;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(Config::SESSION_NAME);
            // The panel is only ever served over HTTPS (install.sh redirects
            // http:// on the panel hostname), so the cookie never travels in clear.
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            ini_set('session.use_strict_mode', '1');
            session_start();
        }
    }

    /**
     * The user row for a correct username/email + password of an active
     * account, or null. Doesn't sign in (two-factor may still be needed).
     */
    public static function verifyPassword(string $username, string $password): ?array
    {
        $stmt = Database::app()->prepare(
            'SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1'
        );
        $stmt->execute([$username, $username]);
        $row = $stmt->fetch();

        if (!$row) {
            // Same cost as a real check, so response times don't reveal which usernames exist.
            password_verify($password, '$2y$12$GyABU0PDJMYXy1vUQdququNI9Q8Ytyma1HHkQzAs.X9BhNViIt8sy');
            return null;
        }
        if (!password_verify($password, $row['password_hash']) || $row['status'] !== 'active') {
            return null;
        }

        // Accounts migrated from cPanel keep their original crypt() hash
        // ($6$ SHA-512) so the old password works on day one; upgrade it to
        // the panel's normal bcrypt hash the first time it's used.
        if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT)) {
            try {
                Database::app()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_BCRYPT), $row['id']]);
            } catch (Throwable $e) {
                error_log('password rehash failed: ' . $e->getMessage());
            }
        }
        return $row;
    }

    /** Starts a session for a verified user row (password + two-factor check, or login handoff). */
    public static function loginAs(array $row): void
    {
        session_regenerate_id(true);
        unset($_SESSION['pending_2fa']);
        $_SESSION['uid'] = (int) $row['id'];
        $_SESSION['role'] = $row['role'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['session_version'] = (int) ($row['session_version'] ?? 1);
        $_SESSION['login_at'] = $_SESSION['seen_at'] = time();
    }

    /**
     * Ends every other session of a user (password change, two-factor
     * change, suspension): their session_version no longer matches. The
     * current session, if it's this user's, is carried over.
     */
    public static function invalidateSessions(int $userId): void
    {
        Database::app()->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?')->execute([$userId]);
        if (self::id() === $userId) {
            $_SESSION['session_version'] = ($_SESSION['session_version'] ?? 1) + 1;
        }
    }

    /** The panel's own hostname (WHM lives only there). */
    public static function panelHost(): string
    {
        return 'panel.' . strtolower(Config::SERVER_HOSTNAME);
    }

    public static function onPanelHost(): bool
    {
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        return $host === self::panelHost() || $host === strtolower(Config::SERVER_IP);
    }

    /**
     * URL on the panel hostname that logs the current admin/reseller in
     * there and opens $next: a random single-use token, valid 60 seconds,
     * stored only as a hash.
     */
    public static function handoffUrl(string $next = '/whm'): string
    {
        if (!preg_match('#^/whm(/[A-Za-z0-9/_.-]*)?$#', $next)) {
            $next = '/whm';
        }
        $token = bin2hex(random_bytes(32));
        $pdo = Database::app();
        $pdo->exec('DELETE FROM login_handoffs WHERE expires_at < NOW()');
        $pdo->prepare('INSERT INTO login_handoffs (token_hash, user_id, next_path, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 60 SECOND)')
            ->execute([hash('sha256', $token), (int) self::id(), $next]);
        return 'https://' . self::panelHost() . '/login/handoff?token=' . $token;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['uid']);
    }

    public static function id(): ?int
    {
        return $_SESSION['uid'] ?? null;
    }

    /** The role as it is now in the database, not as it was at login. */
    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    /**
     * Full fresh row from DB - status, role and ownership may have changed
     * since login. The session ends when the account isn't active any more,
     * its sessions were invalidated, or it was idle/old for too long.
     */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $now = time();
        $stmt = Database::app()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([self::id()]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'active'
            || (int) ($row['session_version'] ?? 1) !== (int) ($_SESSION['session_version'] ?? 1)
            || $now - (int) ($_SESSION['seen_at'] ?? 0) > self::IDLE_TIMEOUT
            || $now - (int) ($_SESSION['login_at'] ?? 0) > self::MAX_AGE) {
            self::logout();
            return null;
        }
        $_SESSION['seen_at'] = $now;
        $_SESSION['role'] = $row['role'];
        return $cache = $row;
    }

    public static function requireLogin(): void
    {
        if (!self::check() || self::user() === null) {
            header('Location: /login');
            exit;
        }
    }

    /** @param string[] $roles */
    public static function requireRole(array $roles): void
    {
        self::requireLogin();
        if (!in_array(self::role(), $roles, true)) {
            http_response_code(403);
            echo '403 Forbidden';
            exit;
        }
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function isReseller(): bool
    {
        return self::role() === 'reseller';
    }
}
