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

        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/i', $username)) {
            $errors[] = 'Username must be 3-32 characters: letters, numbers, underscore, starting with a letter.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid admin email address.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters - this account controls the whole server.';
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

        Flash::ok('JinnPanel is set up. Sign in with the administrator account you just created.');
        header('Location: /login');
        exit;
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
