<?php
declare(strict_types=1);

/**
 * Accounts as WHM manages them: create, change, suspend, delete, sign in
 * as. Controllers (and later an API) call this; it enforces who may do
 * what:
 *  - an admin manages resellers, hosting accounts and other admins, never
 *    itself, and never the last active admin away;
 *  - a reseller manages only the hosting accounts it created.
 */
final class AccountService
{
    /** The account $me may manage, or null. @return array<string,mixed>|null */
    public static function findScoped(int $id, array $me): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['id'] === (int) $me['id']) {
            return null;
        }
        if ($me['role'] === 'admin') {
            return $row;
        }
        return ($row['role'] === 'user' && (int) $row['parent_id'] === (int) $me['id']) ? $row : null;
    }

    /** Packages $me may give an account. @return list<array<string,mixed>> */
    public static function availablePackages(array $me): array
    {
        $pdo = Database::app();
        if ($me['role'] === 'admin') {
            return $pdo->query('SELECT * FROM packages ORDER BY owner_id IS NOT NULL, id')->fetchAll();
        }
        $stmt = $pdo->prepare('SELECT * FROM packages WHERE owner_id IS NULL OR owner_id = ? ORDER BY owner_id IS NOT NULL, id');
        $stmt->execute([$me['id']]);
        return $stmt->fetchAll();
    }

    /** Roles $me may create. @return list<string> */
    public static function creatableRoles(array $me): array
    {
        return $me['role'] === 'admin' ? ['user', 'reseller', 'admin'] : ['user'];
    }

    /**
     * Creates an account. $in: username, email, full_name, password, role, package_id.
     *
     * @param array<string,mixed> $in
     * @return array{id:?int, errors:list<string>}
     */
    public static function create(array $in, array $me): array
    {
        $username = strtolower(trim((string) ($in['username'] ?? '')));
        $email = trim((string) ($in['email'] ?? ''));
        $fullName = trim((string) ($in['full_name'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $role = (string) ($in['role'] ?? 'user');
        $packageId = (int) ($in['package_id'] ?? 0) ?: null;
        if (!in_array($role, self::creatableRoles($me), true)) {
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
            if (!Quota::unlimited($limit) && (int) $n->fetchColumn() >= $limit) {
                $errors[] = $limit > 0 ? "Your reseller package allows $limit accounts - you've reached that." : 'Your reseller package doesn\'t allow creating accounts - ask the server administrator.';
            }
        }

        // Limits come only from the package (Quota, UsageService): a hosting
        // account without one could create nothing and would have no quota.
        if ($role === 'admin') {
            $packageId = null;
        } elseif (!$errors && $role === 'user' && !$packageId) {
            $errors[] = 'Choose a hosting package for the account.';
        }
        if (!$errors && $packageId && !in_array($packageId, array_map('intval', array_column(self::availablePackages($me), 'id')), true)) {
            $errors[] = 'Invalid package selected.';
        }
        // A reseller hands out only what its own package holds.
        if (!$errors && $me['role'] === 'reseller' && $role === 'user') {
            array_push($errors, ...ResellerService::problems((int) $me['id'], ['add' => self::packageRow((int) $packageId)]));
        }
        if ($errors) {
            return ['id' => null, 'errors' => $errors];
        }

        $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, full_name, role, parent_id, package_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'active\')'
        )->execute([
            $username,
            $email,
            password_hash($password, PASSWORD_BCRYPT),
            $fullName ?: null,
            $role,
            $role === 'user' && $me['role'] === 'reseller' ? $me['id'] : null,
            $packageId,
        ]);
        $id = (int) $pdo->lastInsertId();
        if ($role === 'user') {
            AccountRuntime::sync($id); // its Linux user, PHP home and (once it has domains) pools
        }
        return ['id' => $id, 'errors' => []];
    }

    /**
     * Changes an account's contact details, package or password.
     * $in: email, full_name, package_id, password (empty = unchanged).
     *
     * @param array<string,mixed> $target the row from findScoped()
     * @param array<string,mixed> $in
     * @return list<string> errors (empty = saved)
     */
    public static function update(array $target, array $in, array $me): array
    {
        $email = trim((string) ($in['email'] ?? $target['email']));
        $fullName = trim((string) ($in['full_name'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $packageId = $target['role'] === 'admin' ? null : ((int) ($in['package_id'] ?? 0) ?: null);

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        $pdo = Database::app();
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
        $chk->execute([$email, $target['id']]);
        if ($chk->fetch()) {
            $errors[] = 'Another account already uses that email address.';
        }
        if ($password !== '' && ($problem = Passwords::problem($password, (string) $target['username'])) !== null) {
            $errors[] = $problem;
        }
        if ($target['role'] === 'user' && !$packageId) {
            $errors[] = 'A hosting account needs a package.';
        }
        if ($packageId && $packageId !== (int) $target['package_id']
            && !in_array($packageId, array_map('intval', array_column(self::availablePackages($me), 'id')), true)) {
            $errors[] = 'Invalid package selected.';
        }
        if (!$errors && $me['role'] === 'reseller' && $target['role'] === 'user' && $packageId !== (int) $target['package_id']) {
            array_push($errors, ...ResellerService::problems((int) $me['id'], ['swap' => [(int) $target['id'] => self::packageRow((int) $packageId)]]));
        }
        if ($errors) {
            return $errors;
        }

        $pdo->prepare('UPDATE users SET email = ?, full_name = ?, package_id = ? WHERE id = ?')
            ->execute([$email, $fullName ?: null, $packageId, $target['id']]);
        if ($password !== '') {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_BCRYPT), $target['id']]);
            Auth::invalidateSessions((int) $target['id']);
        }
        if ($target['role'] === 'user' && $packageId !== (int) $target['package_id']) {
            self::applyPackage((int) $target['id']);
        }
        return [];
    }

    /**
     * After a package change: SFTP logins carry the disk quota, and the
     * account's runtime is re-synced. Disk/bandwidth enforcement follows
     * at the next hourly usage run.
     */
    private static function applyPackage(int $userId): void
    {
        $quotaBytes = Quota::bytes(Quota::package($userId)['disk_quota_mb'] ?? 0);
        $s = Database::app()->prepare('SELECT username FROM ftp_accounts WHERE user_id = ?');
        $s->execute([$userId]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $login) {
            try {
                SftpService::updateUser((string) $login, ['quota_size' => $quotaBytes]);
            } catch (Throwable $e) {
                error_log("package change, sftp $login: " . $e->getMessage());
            }
        }
        AccountRuntime::sync($userId);
    }

    /**
     * Moves an account to another package (the account page's quick
     * switch). Returns an error, or null.
     */
    public static function changePackage(array $target, int $packageId, array $me): ?string
    {
        if ($target['role'] === 'admin') {
            return 'Administrators don\'t have a package.';
        }
        if ($target['role'] === 'user' && $packageId <= 0) {
            return 'A hosting account needs a package.';
        }
        if ($packageId > 0 && $packageId !== (int) $target['package_id']
            && !in_array($packageId, array_map('intval', array_column(self::availablePackages($me), 'id')), true)) {
            return 'Invalid package selected.';
        }
        if ($me['role'] === 'reseller' && $target['role'] === 'user' && $packageId !== (int) $target['package_id']) {
            $problems = ResellerService::problems((int) $me['id'], ['swap' => [(int) $target['id'] => self::packageRow($packageId)]]);
            if ($problems) {
                return implode(' ', $problems);
            }
        }
        Database::app()->prepare('UPDATE users SET package_id = ? WHERE id = ?')->execute([$packageId ?: null, $target['id']]);
        if ($target['role'] === 'user' && $packageId !== (int) $target['package_id']) {
            self::applyPackage((int) $target['id']);
        }
        Features::forget();
        return null;
    }

    /**
     * An account's own feature switches on top of its package
     * (override[feature] = '' package | '1' on | '0' off).
     */
    public static function setFeatureOverrides(array $target, array $post): void
    {
        Database::app()->prepare('UPDATE users SET feature_overrides = ? WHERE id = ?')
            ->execute([Features::overridesFromForm($post), $target['id']]);
        Features::forget();
    }

    /** @return array<string,mixed>|null */
    /**
     * What a hosting account already has beyond a package's limits, and the
     * features it would lose: shown before moving it to a smaller package
     * (or making its package smaller), so nobody is surprised.
     *
     * @param array<string,mixed> $pkg the package row as it would be
     * @return list<array{what:string, used:string, limit:string, effect:string}>
     */
    public static function overages(array $target, array $pkg): array
    {
        if ($target['role'] !== 'user') {
            return [];
        }
        $id = (int) $target['id'];
        $m = Quota::measured($id);
        $u = Quota::usage($id);
        $out = [];
        $disk = Quota::bytes($pkg['disk_quota_mb'] ?? 0);
        if ($disk > 0 && $m['used'] > $disk) {
            $out[] = ['what' => 'Disk', 'used' => fmt_bytes($m['used']), 'limit' => fmt_bytes($disk),
                'effect' => 'Uploads and new mailboxes, databases and domains are refused until it frees space.'];
        }
        $bw = Quota::bytes($pkg['bandwidth_mb'] ?? 0);
        if ($bw > 0 && $m['bw_used'] > $bw) {
            $out[] = ['what' => 'Bandwidth this month', 'used' => fmt_bytes($m['bw_used']), 'limit' => fmt_bytes($bw),
                'effect' => 'Its sites answer "bandwidth exceeded" until ' . date('j M', strtotime('first day of next month')) . '.'];
        }
        foreach ([['Domains', 'domains', 'max_domains'], ['Databases', 'databases', 'max_databases'], ['Mailboxes', 'email_accounts', 'max_email_accounts'], ['SFTP logins', 'ftp_accounts', 'max_ftp_accounts']] as [$label, $k, $col]) {
            $lim = (int) ($pkg[$col] ?? 0);
            if (!Quota::unlimited($lim) && $u[$k] > $lim) {
                $out[] = ['what' => $label, 'used' => (string) $u[$k], 'limit' => (string) $lim, 'effect' => 'It keeps them all, but can\'t add more until it is under the limit.'];
            }
        }
        $now = array_replace(Features::forPackage(Quota::package($id)), Features::overrides($target));
        $next = array_replace(Features::forPackage($pkg), Features::overrides($target));
        foreach (Features::LIST as $k => [$label]) {
            if (($now[$k] ?? true) && !($next[$k] ?? true)) {
                $out[] = ['what' => $label, 'used' => 'On', 'limit' => 'Off', 'effect' => 'Switched off for this account.'];
            }
        }
        return $out;
    }

    public static function packageRow(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $s = Database::app()->prepare('SELECT * FROM packages WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** Whether removing/suspending $target would leave no active admin. */
    public static function isLastAdmin(array $target): bool
    {
        if ($target['role'] !== 'admin' || $target['status'] !== 'active') {
            return false;
        }
        return (int) Database::app()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn() <= 1;
    }

    /**
     * Suspends or reactivates. Returns what couldn't be switched (hosting
     * accounts: sites, mail, SFTP, MySQL, cron - SuspensionService).
     *
     * @return list<string>
     */
    public static function setStatus(array $target, string $status, string $reason = ''): array
    {
        if ($status === 'suspended' && self::isLastAdmin($target)) {
            return ['the last active administrator can\'t be suspended'];
        }
        if ($status === $target['status']) {
            return [];
        }
        Database::app()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $target['id']]);
        if ($target['role'] !== 'user') {
            Auth::invalidateSessions((int) $target['id']);
            return [];
        }
        $problems = SuspensionService::apply((int) $target['id'], $status === 'suspended');
        NotificationService::suspension($target, $status === 'suspended', $reason);
        return $problems;
    }

    /** One feature switch of an account: true/false, or null = follow its package. */
    public static function setFeatureOverride(array $target, string $feature, ?bool $on): void
    {
        if (!isset(Features::LIST[$feature])) {
            throw new InvalidArgumentException('Unknown feature.');
        }
        $o = Features::overrides($target);
        if ($on === null) {
            unset($o[$feature]);
        } else {
            $o[$feature] = $on;
        }
        Database::app()->prepare('UPDATE users SET feature_overrides = ? WHERE id = ?')
            ->execute([$o ? (string) json_encode($o) : null, $target['id']]);
        Features::forget();
    }

    /**
     * Deletes an account and what it owns (sites, DNS, databases, mail,
     * SFTP - AccountCleanupService). A reseller's accounts stay, owned by
     * no reseller (they then belong to the admins).
     */
    public static function delete(array $target): ?string
    {
        if (self::isLastAdmin($target)) {
            return 'The last active administrator can\'t be deleted.';
        }
        AccountCleanupService::purge((int) $target['id']);
        return null;
    }

    /**
     * Signs the current admin/reseller in as a hosting account, remembering
     * who they are so they can come back (stopImpersonating).
     */
    public static function impersonate(array $target, array $me): void
    {
        $back = ['id' => (int) $me['id'], 'username' => (string) $me['username'], 'session_version' => (int) ($me['session_version'] ?? 1), 'target' => (int) $target['id']];
        Auth::loginAs($target);
        $_SESSION['impersonator'] = $back;
        Audit::log('impersonate.start', "{$me['username']} signed in as {$target['username']}", (int) $target['id'], (int) $me['id']);
    }

    /**
     * Back to the admin/reseller who used "Log in as". Returns the account
     * they were looking at, or null when the original login is no longer
     * valid (password changed, suspended...) - then they sign in again.
     */
    public static function stopImpersonating(): ?int
    {
        $back = $_SESSION['impersonator'] ?? null;
        if (!is_array($back)) {
            return null;
        }
        $s = Database::app()->prepare("SELECT * FROM users WHERE id = ? AND status = 'active' AND role IN ('admin','reseller')");
        $s->execute([(int) $back['id']]);
        $row = $s->fetch();
        $as = (string) ($_SESSION['username'] ?? '');
        if (!$row || (int) ($row['session_version'] ?? 1) !== (int) $back['session_version']) {
            Auth::logout();
            return null;
        }
        Auth::loginAs($row);
        unset($_SESSION['impersonator']);
        Audit::log('impersonate.stop', "{$row['username']} left $as", (int) $back['target'], (int) $row['id']);
        return (int) $back['target'];
    }

    /**
     * One account with what it holds and uses, for its WHM page.
     *
     * @return array<string,mixed>
     */
    public static function overview(array $target): array
    {
        $pdo = Database::app();
        $id = (int) $target['id'];
        $count = function (string $sql) use ($pdo, $id): int {
            $s = $pdo->prepare($sql);
            $s->execute([$id]);
            return (int) $s->fetchColumn();
        };
        $s = $pdo->prepare('SELECT * FROM account_usage WHERE user_id = ?');
        $s->execute([$id]);
        $usage = $s->fetch() ?: null;
        $s = $pdo->prepare('SELECT id, domain_name, php_version, ssl_mode, created_at FROM domains WHERE user_id = ? ORDER BY domain_name');
        $s->execute([$id]);
        $domains = $s->fetchAll();
        $pkg = $target['package_id'] ? Quota::package($id) : null;
        $parent = null;
        if ($target['parent_id']) {
            $s = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $s->execute([$target['parent_id']]);
            $parent = $s->fetchColumn() ?: null;
        }
        $s = $pdo->prepare('SELECT a.created_at, a.action, a.detail, actor.username AS actor_name FROM activity_log a LEFT JOIN users actor ON actor.id = a.actor_id WHERE a.target_user_id = ? OR a.actor_id = ? ORDER BY a.id DESC LIMIT 10');
        $s->execute([$id, $id]);
        $activity = $s->fetchAll();
        return [
            'package' => $pkg,
            'parent' => $parent,
            'usage' => $usage,
            'domains' => $domains,
            'counts' => [
                'domains' => count($domains),
                'databases' => $count('SELECT COUNT(*) FROM db_instances WHERE user_id = ?'),
                'mailboxes' => $count('SELECT COUNT(*) FROM email_accounts WHERE user_id = ?'),
                'sftp' => $count('SELECT COUNT(*) FROM ftp_accounts WHERE user_id = ?'),
                'accounts' => $target['role'] === 'reseller' ? $count("SELECT COUNT(*) FROM users WHERE parent_id = ? AND role = 'user'") : 0,
            ],
            'activity' => $activity,
        ];
    }
}
