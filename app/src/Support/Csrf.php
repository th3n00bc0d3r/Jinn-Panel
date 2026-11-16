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
        // `hidden` too, so space-y-* layouts don't count it as the first child.
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '" hidden>';
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
            // Back to the page the form was on, if it was on this panel.
            $host = fn(mixed $h): string => strtolower((string) preg_replace('/:\d+$/', '', (string) $h));
            $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            $back = null;
            if ($ref !== '' && $host(parse_url($ref, PHP_URL_HOST)) === $host($_SERVER['HTTP_HOST'] ?? '')) {
                $q = parse_url($ref, PHP_URL_QUERY);
                $back = '/' . ltrim((string) parse_url($ref, PHP_URL_PATH), '/') . ($q ? "?$q" : '');
            }
            View::render('errors/419', ['title' => 'Form expired', 'back' => $back], 'blank');
            exit;
        }
    }
}
