<?php
declare(strict_types=1);

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(Config::SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        $stmt = Database::app()->prepare(
            'SELECT id, username, password_hash, role, status FROM users WHERE username = ? OR email = ? LIMIT 1'
        );
        $stmt->execute([$username, $username]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }
        if ($row['status'] !== 'active') {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $row['id'];
        $_SESSION['role'] = $row['role'];
        $_SESSION['username'] = $row['username'];
        return true;
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

    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    /** Full fresh row from DB (status may have changed since login). */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $stmt = Database::app()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([self::id()]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'active') {
            self::logout();
            return null;
        }
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
