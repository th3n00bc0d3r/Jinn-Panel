<?php
declare(strict_types=1);

final class AuthController
{
    public static function showLogin(): void
    {
        if (Auth::check() && Auth::user() !== null) {
            self::redirectHome();
        }
        View::render('auth/login', ['error' => null], 'auth');
    }

    public static function login(): void
    {
        Csrf::requireValid();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '' || !Auth::attempt($username, $password)) {
            View::render('auth/login', ['error' => 'Invalid username/email or password.'], 'auth');
            return;
        }

        self::redirectHome();
    }

    public static function logout(): void
    {
        Auth::logout();
        header('Location: /login');
        exit;
    }

    private static function redirectHome(): void
    {
        $role = Auth::role();
        header('Location: ' . ($role === 'user' ? '/cpanel' : '/whm'));
        exit;
    }
}
