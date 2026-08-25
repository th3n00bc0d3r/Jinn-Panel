<?php
declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function verify(): bool
    {
        $sent = $_POST['csrf_token'] ?? '';
        return is_string($sent) && hash_equals(self::token(), $sent);
    }

    public static function requireValid(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !self::verify()) {
            http_response_code(419);
            echo 'Invalid or expired form submission (CSRF check failed). Go back and try again.';
            exit;
        }
    }
}
