<?php
declare(strict_types=1);

/**
 * The signed-in user's own login security, for every role: change the
 * password and turn two-factor sign-in (TOTP) on or off. Served at
 * /whm/security for admins/resellers and /cpanel/security for accounts.
 */
final class SecurityController
{
    public static function index(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $setup = null;
        if (empty($me['totp_secret_enc']) && isset($_SESSION['totp_setup'])) {
            $secret = (string) $_SESSION['totp_setup'];
            $uri = Totp::uri($secret, $me['username'] . '@' . Config::SERVER_HOSTNAME, Config::APP_NAME);
            $setup = ['secret' => $secret, 'uri' => $uri, 'qr' => Totp::qrSvg($uri)];
        }
        View::render('account/security', [
            'title' => 'Login Security',
            'me' => $me,
            'setup' => $setup,
            'base' => self::base(),
            'accent' => $me['role'] === 'user' ? 'sky' : 'indigo',
        ], $me['role'] === 'user' ? 'cpanel' : 'whm');
    }

    public static function password(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $me = Auth::user();
        if (($wait = LoginThrottle::blockedFor($me['username'])) > 0) {
            self::back("Too many wrong passwords - try again in $wait minute(s).", true);
        }
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify($current, $me['password_hash'])) {
            LoginThrottle::fail($me['username']);
            self::back('Your current password is not right.', true);
        }
        if (($problem = Passwords::problem($new, $me['username'])) !== null) {
            self::back($problem, true);
        }
        if ($new !== (string) ($_POST['new_password_confirm'] ?? '')) {
            self::back('The new passwords do not match.', true);
        }
        Database::app()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_BCRYPT), $me['id']]);
        Auth::invalidateSessions((int) $me['id']);
        self::back('Password changed. Other signed-in sessions were signed out.');
    }

    public static function twoFactorStart(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $_SESSION['totp_setup'] = Totp::newSecret();
        header('Location: ' . self::base());
        exit;
    }

    public static function twoFactorConfirm(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $me = Auth::user();
        $secret = (string) ($_SESSION['totp_setup'] ?? '');
        if ($secret === '') {
            self::back('Start the two-factor setup again.', true);
        }
        $step = Totp::verify($secret, (string) ($_POST['code'] ?? ''));
        if ($step === null) {
            self::back('That code is not right - check the time on your phone and enter the current code.', true);
        }
        Database::app()->prepare('UPDATE users SET totp_secret_enc = ?, totp_last_step = ? WHERE id = ?')
            ->execute([Crypto::encrypt($secret), $step, $me['id']]);
        unset($_SESSION['totp_setup']);
        Auth::invalidateSessions((int) $me['id']);
        self::back('Two-factor sign-in is on. You will be asked for a code at every sign-in.');
    }

    public static function twoFactorDisable(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $me = Auth::user();
        if (($wait = LoginThrottle::blockedFor($me['username'])) > 0) {
            self::back("Too many wrong passwords - try again in $wait minute(s).", true);
        }
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $me['password_hash'])) {
            LoginThrottle::fail($me['username']);
            self::back('Your password is not right - two-factor sign-in stays on.', true);
        }
        Database::app()->prepare('UPDATE users SET totp_secret_enc = NULL, totp_last_step = NULL WHERE id = ?')->execute([$me['id']]);
        self::back('Two-factor sign-in is off.');
    }

    /** WHM: an admin turns off two-factor for someone who lost their phone. */
    public static function resetTwoFactor(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $s = Database::app()->prepare('SELECT id, username FROM users WHERE id = ?');
        $s->execute([(int) $params['id']]);
        $row = $s->fetch();
        if ($row) {
            Database::app()->prepare('UPDATE users SET totp_secret_enc = NULL, totp_last_step = NULL WHERE id = ?')->execute([$row['id']]);
            Auth::invalidateSessions((int) $row['id']);
            Audit::target((int) $row['id']);
            Flash::ok("Two-factor sign-in turned off for \"{$row['username']}\".");
        }
        header('Location: /whm/accounts');
        exit;
    }

    private static function base(): string
    {
        return Auth::role() === 'user' ? '/cpanel/security' : '/whm/security';
    }

    private static function back(string $message, bool $error = false): never
    {
        $error ? Flash::error($message) : Flash::ok($message);
        header('Location: ' . self::base());
        exit;
    }
}
