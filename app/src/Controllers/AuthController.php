<?php
declare(strict_types=1);

final class AuthController
{
    /** How long the second step (the authenticator code) may take after the password was right. */
    private const PENDING_2FA_TTL = 300;

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

        if ($username === '' || $password === '') {
            View::render('auth/login', ['error' => 'Enter your username or email and password.'], 'auth');
            return;
        }
        if (($wait = LoginThrottle::blockedFor($username)) > 0) {
            Audit::log('login.blocked', $username);
            View::render('auth/login', ['error' => "Too many failed sign-ins. Try again in $wait minute" . ($wait === 1 ? '' : 's') . '.'], 'auth');
            return;
        }

        $row = Auth::verifyPassword($username, $password);
        if ($row === null) {
            LoginThrottle::fail($username);
            Audit::log('login.failed', $username);
            View::render('auth/login', ['error' => 'Invalid username/email or password.'], 'auth');
            return;
        }

        if (!empty($row['totp_secret_enc'])) {
            session_regenerate_id(true);
            $_SESSION['pending_2fa'] = ['uid' => (int) $row['id'], 'at' => time(), 'name' => $username];
            header('Location: /login/2fa');
            exit;
        }

        self::complete($row, $username);
    }

    public static function showTwoFactor(): void
    {
        if (self::pending() === null) {
            header('Location: /login');
            exit;
        }
        View::render('auth/two_factor', ['error' => null, 'recovery' => false], 'auth');
    }

    public static function twoFactor(): void
    {
        Csrf::requireValid();
        $pending = self::pending();
        if ($pending === null) {
            header('Location: /login');
            exit;
        }
        $name = (string) $pending['name'];
        if (($wait = LoginThrottle::blockedFor($name)) > 0) {
            unset($_SESSION['pending_2fa']);
            View::render('auth/login', ['error' => "Too many failed sign-ins. Try again in $wait minute" . ($wait === 1 ? '' : 's') . '.'], 'auth');
            return;
        }
        $s = Database::app()->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
        $s->execute([$pending['uid']]);
        $row = $s->fetch();
        if (!$row || empty($row['totp_secret_enc'])) {
            unset($_SESSION['pending_2fa']);
            header('Location: /login');
            exit;
        }
        // Lost phone: one of the recovery codes signs in instead (each works once).
        $recovery = trim((string) ($_POST['recovery_code'] ?? ''));
        if ($recovery !== '') {
            if (!RecoveryCodes::use((int) $row['id'], $recovery)) {
                LoginThrottle::fail($name);
                Audit::log('login.recovery_failed', $row['username'], (int) $row['id']);
                View::render('auth/two_factor', ['error' => 'That recovery code is not right, or was already used.', 'recovery' => true], 'auth');
                return;
            }
            $left = RecoveryCodes::remaining((int) $row['id']);
            Audit::log('login.recovery_code', $row['username'] . " ($left codes left)", (int) $row['id']);
            Flash::error("You signed in with a recovery code; $left left. If your phone is gone, set up two-factor again under Login security.");
            self::complete($row, $name);
            return;
        }

        $step = Totp::verify(Crypto::decrypt((string) $row['totp_secret_enc']), (string) ($_POST['code'] ?? ''), $row['totp_last_step'] !== null ? (int) $row['totp_last_step'] : null);
        if ($step === null) {
            LoginThrottle::fail($name);
            Audit::log('login.2fa_failed', $row['username'], (int) $row['id']);
            View::render('auth/two_factor', ['error' => 'That code is not right (or was already used). Enter the current code from your authenticator app.', 'recovery' => false], 'auth');
            return;
        }
        // A code works once: an observed code can't be replayed in its 90-second window.
        $u = Database::app()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)');
        $u->execute([$step, $row['id'], $step]);
        if ($u->rowCount() !== 1) {
            View::render('auth/two_factor', ['error' => 'That code was already used. Wait for the next one.', 'recovery' => false], 'auth');
            return;
        }
        self::complete($row, $name);
    }

    public static function showForgot(): void
    {
        View::render('auth/forgot', ['title' => 'Reset your password', 'sent' => false, 'error' => null], 'auth');
    }

    /**
     * Mails a reset link to the account's email address. The answer is the
     * same whether or not the account exists, and requests are throttled
     * like failed sign-ins (so it can't be used to flood someone's inbox).
     */
    public static function forgot(): void
    {
        Csrf::requireValid();
        $who = trim((string) ($_POST['username'] ?? ''));
        if ($who === '') {
            View::render('auth/forgot', ['title' => 'Reset your password', 'sent' => false, 'error' => 'Enter your username or email address.'], 'auth');
            return;
        }
        if (($wait = LoginThrottle::blockedFor($who)) > 0) {
            View::render('auth/forgot', ['title' => 'Reset your password', 'sent' => false, 'error' => "Too many attempts. Try again in $wait minute" . ($wait === 1 ? '' : 's') . '.'], 'auth');
            return;
        }
        LoginThrottle::fail($who);
        $s = Database::app()->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active' LIMIT 1");
        $s->execute([strtolower($who), $who]);
        $row = $s->fetch();
        if ($row) {
            $sent = NotificationService::passwordReset($row, LoginThrottle::ip());
            Audit::log($sent ? 'password.reset_requested' : 'password.reset_mail_failed', $row['username'], (int) $row['id'], (int) $row['id']);
        }
        View::render('auth/forgot', ['title' => 'Check your email', 'sent' => true, 'error' => null], 'auth');
    }

    public static function showReset(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $row = PasswordResetService::find($token);
        header('Referrer-Policy: no-referrer');
        View::render('auth/reset', ['title' => 'Choose a new password', 'token' => $row ? $token : null, 'row' => $row, 'error' => null], 'auth');
    }

    public static function reset(): void
    {
        Csrf::requireValid();
        $token = (string) ($_POST['token'] ?? '');
        $row = PasswordResetService::find($token);
        if (!$row) {
            View::render('auth/reset', ['title' => 'Choose a new password', 'token' => null, 'row' => null, 'error' => null], 'auth');
            return;
        }
        $new = (string) ($_POST['new_password'] ?? '');
        $error = Passwords::problem($new, (string) $row['username'])
            ?? ($new !== (string) ($_POST['new_password_confirm'] ?? '') ? 'The two passwords do not match.' : null);
        if ($error !== null) {
            View::render('auth/reset', ['title' => 'Choose a new password', 'token' => $token, 'row' => $row, 'error' => $error], 'auth');
            return;
        }
        if (!PasswordResetService::consume((int) $row['reset_id'])) {
            View::render('auth/reset', ['title' => 'Choose a new password', 'token' => null, 'row' => null, 'error' => null], 'auth');
            return;
        }
        Database::app()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_BCRYPT), $row['id']]);
        Auth::invalidateSessions((int) $row['id']);
        LoginThrottle::clear((string) $row['username']);
        Audit::log('password.reset', $row['username'] . ($row['purpose'] === 'welcome' ? ' (welcome link)' : ''), (int) $row['id'], (int) $row['id']);
        Flash::ok('Your password is changed. Sign in with it now.');
        header('Location: /login');
        exit;
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

    /** POST only (with the CSRF token): a cross-site link or image can't sign anyone out. */
    public static function logout(): void
    {
        Csrf::requireValid();
        if (Auth::check()) {
            Audit::log('logout', (string) ($_SESSION['username'] ?? ''));
        }
        Auth::logout();
        header('Location: /login');
        exit;
    }

    private static function complete(array $row, string $name): void
    {
        LoginThrottle::clear($name);
        Auth::loginAs($row);
        Audit::log('login', $row['username'] . (!empty($row['totp_secret_enc']) ? ' (with two-factor)' : ''), null, (int) $row['id']);
        self::redirectHome();
    }

    /** @return array{uid:int,at:int,name:string}|null */
    private static function pending(): ?array
    {
        $p = $_SESSION['pending_2fa'] ?? null;
        if (!is_array($p) || time() - (int) ($p['at'] ?? 0) > self::PENDING_2FA_TTL) {
            unset($_SESSION['pending_2fa']);
            return null;
        }
        return $p;
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
