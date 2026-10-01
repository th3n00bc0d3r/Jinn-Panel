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

    /** Consumes a login handoff token (see Auth::handoffUrl). */
    public static function handoff(): void
    {
        header('Referrer-Policy: no-referrer');
        $token = (string) ($_GET['token'] ?? '');
        $next = null;
        if (Auth::onPanelHost() && preg_match('/^[0-9a-f]{64}$/', $token)) {
            $pdo = Database::app();
            $s = $pdo->prepare('SELECT h.next_path, u.* FROM login_handoffs h JOIN users u ON u.id = h.user_id WHERE h.token_hash = ? AND h.expires_at > NOW()');
            $s->execute([hash('sha256', $token)]);
            $row = $s->fetch();
            $del = $pdo->prepare('DELETE FROM login_handoffs WHERE token_hash = ?');
            $del->execute([hash('sha256', $token)]);
            if ($row && $del->rowCount() === 1 && $row['status'] === 'active' && $row['role'] !== 'user') {
                Auth::loginAs($row);
                $next = (string) $row['next_path'];
            }
        }
        header('Location: ' . ($next ?? '/login'));
        exit;
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
        // WHM only runs on the panel hostname: carry the login over there.
        if ($role !== 'user' && !Auth::onPanelHost()) {
            header('Location: ' . Auth::handoffUrl('/whm'));
            exit;
        }
        header('Location: ' . ($role === 'user' ? '/cpanel' : '/whm'));
        exit;
    }
}
