<?php
declare(strict_types=1);

/**
 * First-run production setup wizard. Shown automatically (see
 * bootstrap-time check in public/index.php) whenever no admin account
 * exists yet - i.e. right after install.sh has finished provisioning
 * infrastructure but before anyone has ever logged in. The installer
 * intentionally does NOT seed an admin account itself; real credentials
 * for a production box should be chosen by whoever is actually deploying
 * it, not baked into a script or checked into a repo.
 */
final class SetupController
{
    public static function index(): void
    {
        if (self::isComplete()) {
            header('Location: /login');
            exit;
        }
        View::render('auth/setup', [
            'server' => self::serverInfo(),
            'errors' => [],
            'old' => [],
        ], 'auth');
    }

    public static function store(): void
    {
        if (self::isComplete()) {
            header('Location: /login');
            exit;
        }
        Csrf::requireValid();

        // The page is reachable by anyone until the admin exists: only the
        // person who ran install.sh has the token it printed.
        if (($wait = LoginThrottle::blockedFor('setup')) > 0) {
            self::fail(["Too many wrong setup tokens. Try again in $wait minute(s)."], []);
            return;
        }
        $expected = self::tokenHash();
        $token = trim((string) ($_POST['setup_token'] ?? ''));
        if ($expected === null) {
            self::fail(['No setup token is set on this server - re-run installer/install.sh as root; it prints one.'], $_POST);
            return;
        }
        if (!hash_equals($expected, hash('sha256', $token))) {
            LoginThrottle::fail('setup');
            Audit::log('setup.token_failed');
            self::fail(['That setup token is not right. It was printed at the end of install.sh, and is saved in /root/.jinnpanel/setup_token.'], $_POST);
            return;
        }

        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $email = trim((string) ($_POST['email'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $username)) {
            $errors[] = 'Username must be 3-32 characters: lowercase letters, numbers, underscore, starting with a letter.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid admin email address.';
        }
        if (($problem = Passwords::problem($password, $username)) !== null) {
            $errors[] = $problem . ' This account controls the whole server.';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        }

        if ($errors) {
            View::render('auth/setup', [
                'server' => self::serverInfo(),
                'errors' => $errors,
                'old' => ['username' => $username, 'email' => $email, 'full_name' => $fullName],
            ], 'auth');
            return;
        }

        $stmt = Database::app()->prepare(
            'INSERT INTO users (username, email, password_hash, full_name, role, status) VALUES (?, ?, ?, ?, \'admin\', \'active\')'
        );
        $stmt->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT), $fullName ?: null]);
        $adminId = (int) Database::app()->lastInsertId();
        Database::app()->exec("DELETE FROM panel_settings WHERE setting_key = 'setup_token_hash'");
        LoginThrottle::clear('setup');
        Audit::log('setup.admin_created', $username, $adminId, $adminId);

        Flash::ok('JinnPanel is set up. Sign in with the administrator account you just created.');
        header('Location: /login');
        exit;
    }

    /** sha256 of the one-time setup token install.sh generated, if any. */
    private static function tokenHash(): ?string
    {
        try {
            $s = Database::app()->query("SELECT setting_value FROM panel_settings WHERE setting_key = 'setup_token_hash'");
            $v = $s->fetchColumn();
            return is_string($v) && preg_match('/^[0-9a-f]{64}$/', $v) ? $v : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $old */
    private static function fail(array $errors, array $old): void
    {
        View::render('auth/setup', [
            'server' => self::serverInfo(),
            'errors' => $errors,
            'old' => ['username' => (string) ($old['username'] ?? ''), 'email' => (string) ($old['email'] ?? ''), 'full_name' => (string) ($old['full_name'] ?? '')],
        ], 'auth');
    }

    /** True once at least one admin account exists - the wizard never runs again after that. */
    public static function isComplete(): bool
    {
        try {
            $stmt = Database::app()->query("SELECT COUNT(*) c FROM users WHERE role = 'admin'");
            return ((int) $stmt->fetch()['c']) > 0;
        } catch (Throwable $e) {
            // Database not reachable/migrated yet - treat as "not complete"
            // rather than crashing every request; the wizard page itself
            // will surface the real error if it tries to submit.
            return false;
        }
    }

    private static function serverInfo(): array
    {
        $services = [
            'MariaDB' => 3306, 'FrankenPHP' => 443, 'Stalwart Mail' => 8080,
            'SFTPGo' => 8090, 'Knot DNS' => 53,
        ];
        $status = [];
        foreach ($services as $name => $port) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
            $status[$name] = $conn ? true : false;
            if ($conn) fclose($conn);
        }
        return [
            'hostname' => Config::SERVER_HOSTNAME,
            'ip' => Config::SERVER_IP,
            'services' => $status,
        ];
    }
}
