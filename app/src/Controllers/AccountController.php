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

        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $email = trim((string) ($_POST['email'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = $me['role'] === 'admin' ? (string) ($_POST['role'] ?? 'user') : 'user';
        $packageId = (int) ($_POST['package_id'] ?? 0) ?: null;

        if (!in_array($role, ['reseller', 'user'], true)) {
            $role = 'user';
        }

        $errors = [];
        if (($problem = $role === 'user' ? Usernames::problem($username) : Usernames::loginProblem($username)) !== null) {
            $errors[] = $problem;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (($problem = Passwords::problem($password, $username)) !== null) {
            $errors[] = $problem;
        }

        $pdo = Database::app();
        if (!$errors) {
            $chk = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $chk->execute([$username, $email]);
            if ($chk->fetch()) {
                $errors[] = 'That username or email is already taken.';
            }
        }

        // A reseller may create as many accounts as its own package allows.
        if (!$errors && $me['role'] === 'reseller') {
            $pkg = Quota::package((int) $me['id']);
            $limit = (int) ($pkg['max_accounts'] ?? 0);
            $n = $pdo->prepare("SELECT COUNT(*) FROM users WHERE parent_id = ? AND role = 'user'");
            $n->execute([$me['id']]);
            if ((int) $n->fetchColumn() >= $limit) {
                $errors[] = $limit > 0 ? "Your reseller package allows $limit accounts - you've reached that." : 'Your reseller package doesn\'t allow creating accounts - ask the server administrator.';
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

        $newId = (int) $pdo->lastInsertId();
        Audit::target($newId);
        if ($role === 'user') {
            AccountRuntime::sync($newId); // its Linux user, PHP home and (once it has domains) pools
        }
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
        // DB delete. Shared with the cPanel migration rollback. The worker
        // then removes its pools and Linux user, and moves its site folders
        // aside (root-only) - a later account must never inherit them.
        AccountCleanupService::purge((int) $target['id']);
        Audit::target((int) $target['id']);

        Flash::ok("Account \"{$target['username']}\" and its resources have been removed.");
        header('Location: /whm/accounts');
        exit;
    }

    /** Whether the account's PHP may run programs (exec, proc_open, ...): off by default. */
    public static function phpExec(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $target = self::findScopedAccount((int) $params['id'], Auth::user());
        if (!$target || $target['role'] !== 'user') {
            Flash::error('Account not found.');
            header('Location: /whm/accounts');
            exit;
        }
        $on = !empty($_POST['allow']);
        Database::app()->prepare('UPDATE users SET php_exec = ? WHERE id = ?')->execute([$on ? 1 : 0, $target['id']]);
        AccountRuntime::sync((int) $target['id']);
        Audit::target((int) $target['id']);
        Flash::ok("\"{$target['username']}\": PHP " . ($on ? 'may now run programs (exec, proc_open, ...).' : 'may no longer run programs.'));
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
        Audit::target((int) $target['id']);
        $problems = $target['role'] === 'user' ? SuspensionService::apply((int) $target['id'], $status === 'suspended') : [];
        if ($target['role'] !== 'user') {
            Auth::invalidateSessions((int) $target['id']);
        }

        $what = $status === 'suspended'
            ? ' Its sites, mail logins, SFTP, MySQL users and cron jobs are switched off.'
            : ' Its sites, mail logins, SFTP, MySQL users and cron jobs are back on.';
        Flash::ok("Account \"{$target['username']}\" is now $status." . ($target['role'] === 'user' ? $what : ''));
        if ($problems) {
            Flash::error('Not everything could be switched: ' . implode('; ', array_slice($problems, 0, 5)));
        }
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
