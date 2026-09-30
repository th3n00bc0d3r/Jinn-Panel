<?php
declare(strict_types=1);

final class AccountController
{
    public static function index(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $user = Auth::user();
        $pdo = Database::app();

        if ($user['role'] === 'admin') {
            $accounts = $pdo->query(
                "SELECT u.*, p.name AS package_name, r.username AS reseller_username
                 FROM users u
                 LEFT JOIN packages p ON p.id = u.package_id
                 LEFT JOIN users r ON r.id = u.parent_id
                 WHERE u.role IN ('reseller','user')
                 ORDER BY u.role, u.created_at DESC"
            )->fetchAll();
        } else {
            $stmt = $pdo->prepare(
                "SELECT u.*, p.name AS package_name, NULL AS reseller_username
                 FROM users u LEFT JOIN packages p ON p.id = u.package_id
                 WHERE u.role = 'user' AND u.parent_id = ?
                 ORDER BY u.created_at DESC"
            );
            $stmt->execute([$user['id']]);
            $accounts = $stmt->fetchAll();
        }

        View::render('whm/accounts', ['title' => 'Accounts', 'accounts' => $accounts, 'me' => $user], 'whm');
    }

    public static function create(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $packages = self::availablePackages(Auth::user());
        View::render('whm/account_form', [
            'title' => 'Create Account',
            'packages' => $packages,
            'canCreateReseller' => Auth::isAdmin(),
        ], 'whm');
    }

    public static function store(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $me = Auth::user();

        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = $me['role'] === 'admin' ? (string) ($_POST['role'] ?? 'user') : 'user';
        $packageId = (int) ($_POST['package_id'] ?? 0) ?: null;

        if (!in_array($role, ['reseller', 'user'], true)) {
            $role = 'user';
        }

        $errors = [];
        if ($username === '' || !preg_match('/^[a-z][a-z0-9_]{2,31}$/i', $username)) {
            $errors[] = 'Username must be 3-32 characters, letters/numbers/underscore, starting with a letter.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        $pdo = Database::app();
        if (!$errors) {
            $chk = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $chk->execute([$username, $email]);
            if ($chk->fetch()) {
                $errors[] = 'That username or email is already taken.';
            }
        }

        if (!$errors && $packageId) {
            $allowedIds = array_column(self::availablePackages($me), 'id');
            if (!in_array($packageId, $allowedIds, true)) {
                $errors[] = 'Invalid package selected.';
            }
        }

        if ($errors) {
            View::render('whm/account_form', [
                'title' => 'Create Account',
                'packages' => self::availablePackages($me),
                'canCreateReseller' => Auth::isAdmin(),
                'errors' => $errors,
            ], 'whm');
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, full_name, role, parent_id, package_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'active\')'
        );
        $stmt->execute([
            $username,
            $email,
            password_hash($password, PASSWORD_BCRYPT),
            $fullName ?: null,
            $role,
            $role === 'user' ? ($me['role'] === 'admin' ? null : $me['id']) : null,
            $packageId,
        ]);

        Flash::ok("Account \"$username\" created as $role.");
        header('Location: /whm/accounts');
        exit;
    }

    public static function suspend(array $params): void
    {
        self::setStatus($params, 'suspended');
    }

    public static function unsuspend(array $params): void
    {
        self::setStatus($params, 'active');
    }

    public static function destroy(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $me = Auth::user();
        $target = self::findScopedAccount((int) $params['id'], $me);
        if (!$target) {
            Flash::error('Account not found.');
            header('Location: /whm/accounts');
            exit;
        }

        // Best-effort external cleanup (vhosts, DNS, databases + every MySQL
        // user the account owns, mailboxes, SFTP users), then the cascading
        // DB delete. Shared with the cPanel migration rollback.
        AccountCleanupService::purge((int) $target['id']);

        Flash::ok("Account \"{$target['username']}\" and its resources have been removed.");
        header('Location: /whm/accounts');
        exit;
    }

    private static function setStatus(array $params, string $status): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $me = Auth::user();
        $target = self::findScopedAccount((int) $params['id'], $me);
        if (!$target) {
            Flash::error('Account not found.');
            header('Location: /whm/accounts');
            exit;
        }

        $stmt = Database::app()->prepare('UPDATE users SET status = ? WHERE id = ?');
        $stmt->execute([$status, $target['id']]);

        Flash::ok("Account \"{$target['username']}\" is now $status.");
        header('Location: /whm/accounts');
        exit;
    }

    private static function findScopedAccount(int $id, array $me): ?array
    {
        $pdo = Database::app();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ($row['id'] === $me['id']) {
            return null; // cannot act on yourself here
        }
        if ($me['role'] === 'admin') {
            return $row['role'] === 'admin' ? null : $row;
        }
        // reseller: only their own directly-created users
        return ($row['role'] === 'user' && (int) $row['parent_id'] === $me['id']) ? $row : null;
    }

    private static function availablePackages(array $me): array
    {
        $pdo = Database::app();
        if ($me['role'] === 'admin') {
            return $pdo->query('SELECT * FROM packages ORDER BY owner_id IS NOT NULL, id')->fetchAll();
        }
        $stmt = $pdo->prepare('SELECT * FROM packages WHERE owner_id IS NULL OR owner_id = ? ORDER BY owner_id IS NOT NULL, id');
        $stmt->execute([$me['id']]);
        return $stmt->fetchAll();
    }
}
