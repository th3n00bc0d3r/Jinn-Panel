<?php
declare(strict_types=1);

/** The password rule for panel logins (accounts, resellers, admins). */
final class Passwords
{
    public const MIN_LENGTH = 10;

    private const COMMON = [
        'password', 'password1', 'password12', 'password123', 'passw0rd', 'qwerty', 'qwertyuiop', 'qwerty123',
        '1234567890', '0123456789', '123456789', '12345678', '1q2w3e4r5t', 'iloveyou', 'letmein', 'welcome',
        'welcome1', 'admin123', 'administrator', 'changeme', 'trustno1', 'abc123456', 'football', 'baseball',
    ];

    /** Error message for an unacceptable new password, or null when it's fine. */
    public static function problem(string $password, string $username = ''): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (strlen($password) > 1024) {
            return 'Password is too long.';
        }
        $lower = strtolower($password);
        if (in_array($lower, self::COMMON, true) || count(array_unique(str_split($password))) < 4) {
            return 'That password is too easy to guess - pick a less common one.';
        }
        if ($username !== '' && str_contains($lower, strtolower($username))) {
            return 'The password must not contain the username.';
        }
        return null;
    }
}
