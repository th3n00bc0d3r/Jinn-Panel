<?php
declare(strict_types=1);

final class PackageController
{
    public static function index(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $user = Auth::user();
        $pdo = Database::app();

        if ($user['role'] === 'admin') {
            $packages = $pdo->query('SELECT p.*, u.username AS owner_username, (SELECT COUNT(*) FROM users a WHERE a.package_id = p.id) AS account_count FROM packages p LEFT JOIN users u ON u.id = p.owner_id ORDER BY p.owner_id IS NOT NULL, p.id')->fetchAll();
        } else {
            $stmt = $pdo->prepare('SELECT p.*, u.username AS owner_username, (SELECT COUNT(*) FROM users a WHERE a.package_id = p.id) AS account_count FROM packages p LEFT JOIN users u ON u.id = p.owner_id WHERE p.owner_id IS NULL OR p.owner_id = ? ORDER BY p.owner_id IS NOT NULL, p.id');
            $stmt->execute([$user['id']]);
            $packages = $stmt->fetchAll();
        }

        View::render('whm/packages', ['title' => 'Packages', 'packages' => $packages], 'whm');
    }

    /** One package: its limits and features, the accounts on it, and moving them. */
    public static function show(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $user = Auth::user();
        $pkg = self::visibleOrBack((int) $params['id']);
        $pdo = Database::app();
        $sql = "SELECT u.id, u.username, u.email, u.role, u.status, u.parent_id, r.username AS reseller_username,
                       a.files_bytes + a.db_bytes + a.mail_bytes AS used_bytes, a.bw_month, a.bw_bytes, a.over_disk, a.over_bandwidth,
                       (SELECT COUNT(*) FROM domains d WHERE d.user_id = u.id) AS domain_count
                FROM users u LEFT JOIN users r ON r.id = u.parent_id LEFT JOIN account_usage a ON a.user_id = u.id
                WHERE u.package_id = ?" . ($user['role'] === 'admin' ? '' : " AND u.role = 'user' AND u.parent_id = ?") . ' ORDER BY u.username';
        $s = $pdo->prepare($sql);
        $s->execute($user['role'] === 'admin' ? [$pkg['id']] : [$pkg['id'], $user['id']]);
        $accounts = $s->fetchAll();
        $n = $pdo->prepare('SELECT COUNT(*) FROM users WHERE package_id = ?');
        $n->execute([$pkg['id']]);
        $editable = $user['role'] === 'admin' || (int) $pkg['owner_id'] === (int) $user['id'];
        View::render('whm/package', [
            'title' => $pkg['name'],
            'pkg' => $pkg,
            'accounts' => $accounts,
            'total' => (int) $n->fetchColumn(),
            'editable' => $editable,
            'others' => array_values(array_filter(AccountService::availablePackages($user), fn($p) => (int) $p['id'] !== (int) $pkg['id'])),
        ], 'whm');
    }

    /** A copy to start a new package from ("<name> copy", owned by whoever copies it). */
    public static function duplicate(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $user = Auth::user();
        $pkg = self::visibleOrBack((int) $params['id']);
        $cols = array_keys(self::LIMITS);
        $values = array_map(fn($c) => (int) $pkg[$c], $cols);
        if ($user['role'] !== 'admin') {
            $values[array_search('max_accounts', $cols, true)] = 0;
        }
        Database::app()->prepare('INSERT INTO packages (owner_id, name, ' . implode(', ', $cols) . ', features) VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)')
            ->execute([$user['role'] === 'admin' ? $pkg['owner_id'] : $user['id'], mb_substr($pkg['name'] . ' copy', 0, 64), ...$values, $pkg['features']]);
        $id = (int) Database::app()->lastInsertId();
        Flash::ok("Copied \"{$pkg['name']}\" - rename it and adjust what's different.");
        header("Location: /whm/packages/$id/edit");
        exit;
    }

    /** Moves the accounts on a package (that the user manages) to another one. */
    public static function move(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $user = Auth::user();
        $pkg = self::visibleOrBack((int) $params['id']);
        $to = (int) ($_POST['package_id'] ?? 0);
        $s = Database::app()->prepare('SELECT id FROM users WHERE package_id = ?' . ($user['role'] === 'admin' ? '' : " AND role = 'user' AND parent_id = ?"));
        $s->execute($user['role'] === 'admin' ? [$pkg['id']] : [$pkg['id'], $user['id']]);
        $done = 0;
        $failed = [];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $t = AccountService::findScoped((int) $id, $user);
            if (!$t) {
                continue;
            }
            $problem = AccountService::changePackage($t, $to, $user);
            if ($problem === null) {
                $done++;
            } else {
                $failed[] = "{$t['username']}: $problem";
            }
        }
        if ($done) {
            Flash::ok(Ui::plural($done, 'account') . " moved off \"{$pkg['name']}\".");
        }
        if ($failed) {
            Flash::error('Not moved: ' . implode(' · ', array_slice($failed, 0, 6)));
        }
        if (!$done && !$failed) {
            Flash::error('No accounts to move.');
        }
        header("Location: /whm/packages/{$pkg['id']}");
        exit;
    }

    /** A package the current user may see: admins all; resellers global ones and their own. */
    private static function visibleOrBack(int $id): array
    {
        $user = Auth::user();
        $s = Database::app()->prepare('SELECT p.*, o.username AS owner_username FROM packages p LEFT JOIN users o ON o.id = p.owner_id WHERE p.id = ?');
        $s->execute([$id]);
        $pkg = $s->fetch();
        if (!$pkg || ($user['role'] !== 'admin' && $pkg['owner_id'] !== null && (int) $pkg['owner_id'] !== (int) $user['id'])) {
            Flash::error('Package not found.');
            header('Location: /whm/packages');
            exit;
        }
        return $pkg;
    }

    public static function create(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        View::render('whm/package_form', ['title' => 'Create Package', 'pkg' => null, 'accounts' => 0], 'whm');
    }

    public static function edit(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $pkg = self::ownedOrBack((int) $params['id']);
        $n = Database::app()->prepare('SELECT COUNT(*) FROM users WHERE package_id = ?');
        $n->execute([$pkg['id']]);
        View::render('whm/package_form', ['title' => 'Edit ' . $pkg['name'], 'pkg' => $pkg, 'accounts' => (int) $n->fetchColumn()], 'whm');
    }

    /** Changes a package's limits: they apply to every account on it at once (hard disk quotas within a minute). */
    public static function update(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $user = Auth::user();
        $pkg = self::ownedOrBack((int) $params['id']);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::error('Package name is required.');
            header("Location: /whm/packages/{$pkg['id']}/edit");
            exit;
        }
        $v = self::limits($user);
        // A reseller's own package can't grow past what the reseller itself holds.
        if ($user['role'] === 'reseller') {
            $edited = array_combine(array_keys(self::LIMITS), $v) + $pkg;
            $problems = ResellerService::problems((int) $user['id'], ['pkg' => [(int) $pkg['id'] => $edited]]);
            if ($problems) {
                Flash::error('Not saved. ' . implode(' ', $problems));
                header("Location: /whm/packages/{$pkg['id']}/edit");
                exit;
            }
        }
        $features = Features::fromForm($_POST);
        $members = Database::app()->prepare("SELECT * FROM users WHERE package_id = ? AND role = 'user' ORDER BY username");
        $members->execute([$pkg['id']]);
        $members = $members->fetchAll();
        // Smaller limits: first show which of its accounts already have more.
        if (($_POST['confirm'] ?? '') !== '1') {
            $next = array_combine(array_keys(self::LIMITS), $v) + ['features' => $features, 'name' => $name];
            $rows = [];
            foreach ($members as $m) {
                if ($over = AccountService::overages($m, $next)) {
                    $rows[] = ['account' => $m, 'items' => $over];
                }
            }
            if ($rows) {
                $hidden = $_POST;
                unset($hidden['csrf_token'], $hidden['confirm']);
                View::render('whm/package_change', [
                    'title' => "Save {$name} with smaller limits?",
                    'intro' => Ui::plural(count($rows), 'account on it already has', 'accounts on it already have') . ' more than the new limits allow. Nothing is deleted; this is what happens:',
                    'rows' => $rows,
                    'action' => "/whm/packages/{$pkg['id']}",
                    'hidden' => $hidden,
                    'confirm' => 'Save anyway',
                    'back' => "/whm/packages/{$pkg['id']}/edit",
                ], 'whm');
                return;
            }
        }
        Database::app()->prepare(
            'UPDATE packages SET name = ?, disk_quota_mb = ?, bandwidth_mb = ?, max_domains = ?, max_databases = ?, max_email_accounts = ?, max_ftp_accounts = ?, max_accounts = ?, features = ? WHERE id = ?'
        )->execute([$name, ...$v, $features, $pkg['id']]);
        // SFTP logins carry the disk quota; the account runtime picks up the rest.
        $quotaBytes = Quota::bytes($v[0]);
        foreach (array_map(fn($m) => (int) $m['id'], $members) as $uid) {
            $logins = Database::app()->prepare('SELECT username FROM ftp_accounts WHERE user_id = ?');
            $logins->execute([$uid]);
            foreach ($logins->fetchAll(PDO::FETCH_COLUMN) as $login) {
                try {
                    SftpService::updateUser((string) $login, ['quota_size' => $quotaBytes]);
                } catch (Throwable $e) {
                    error_log("package update, sftp $login: " . $e->getMessage());
                }
            }
        }
        UsageService::applySoon(array_map(fn($m) => (int) $m['id'], $members));
        Flash::ok("Package \"$name\" saved. Its accounts have the new limits and features now, disk quotas within a minute.");
        header('Location: /whm/packages');
        exit;
    }

    /** The limit columns, in the order limits() returns them, with their minimum. */
    public const LIMITS = [
        'disk_quota_mb' => 1, 'bandwidth_mb' => 1, 'max_domains' => 1, 'max_databases' => 1,
        'max_email_accounts' => 0, 'max_ftp_accounts' => 0, 'max_accounts' => 0,
    ];

    /**
     * The limits from the form, in column order. A ticked unlimited[col]
     * box stores Quota::UNLIMITED.
     *
     * @return list<int>
     */
    private static function limits(array $user): array
    {
        $defaults = ['disk_quota_mb' => 1024, 'bandwidth_mb' => 10240, 'max_domains' => 1, 'max_databases' => 1, 'max_email_accounts' => 5, 'max_ftp_accounts' => 1, 'max_accounts' => 0];
        $unlimited = is_array($_POST['unlimited'] ?? null) ? $_POST['unlimited'] : [];
        $out = [];
        foreach (self::LIMITS as $col => $min) {
            // Only an admin's packages can carry reseller account limits.
            if ($col === 'max_accounts' && $user['role'] !== 'admin') {
                $out[] = 0;
                continue;
            }
            $out[] = !empty($unlimited[$col]) ? Quota::UNLIMITED : max($min, (int) ($_POST[$col] ?? $defaults[$col]));
        }
        return $out;
    }

    /** A package the current user may change (admins: all; resellers: their own). */
    private static function ownedOrBack(int $id): array
    {
        $user = Auth::user();
        $s = Database::app()->prepare('SELECT * FROM packages WHERE id = ?');
        $s->execute([$id]);
        $pkg = $s->fetch();
        if (!$pkg || ($user['role'] !== 'admin' && (int) $pkg['owner_id'] !== (int) $user['id'])) {
            Flash::error($pkg ? 'Global packages can only be changed by the server administrator.' : 'Package not found.');
            header('Location: /whm/packages');
            exit;
        }
        return $pkg;
    }

    public static function store(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $user = Auth::user();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::error('Package name is required.');
            header('Location: /whm/packages/create');
            exit;
        }

        $stmt = Database::app()->prepare(
            'INSERT INTO packages (owner_id, name, disk_quota_mb, bandwidth_mb, max_domains, max_databases, max_email_accounts, max_ftp_accounts, max_accounts, features)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$user['role'] === 'admin' ? null : $user['id'], $name, ...self::limits($user), Features::fromForm($_POST)]);

        Flash::ok("Package \"$name\" created.");
        header('Location: /whm/packages');
        exit;
    }

    public static function destroy(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $user = Auth::user();
        $id = (int) $params['id'];

        $stmt = Database::app()->prepare('SELECT * FROM packages WHERE id = ?');
        $stmt->execute([$id]);
        $pkg = $stmt->fetch();

        if (!$pkg || ($user['role'] !== 'admin' && (int) $pkg['owner_id'] !== $user['id'])) {
            Flash::error('Package not found.');
            header('Location: /whm/packages');
            exit;
        }

        // users.package_id is ON DELETE SET NULL: deleting a package in use
        // would leave its accounts with no limits at all (Quota denies
        // everything, UsageService stops enforcing disk/bandwidth).
        $inUse = Database::app()->prepare('SELECT COUNT(*) FROM users WHERE package_id = ?');
        $inUse->execute([$id]);
        $count = (int) $inUse->fetchColumn();
        if ($count > 0) {
            Flash::error("Package \"{$pkg['name']}\" is used by $count account" . ($count === 1 ? '' : 's') . '; it can only be deleted once no account uses it.');
            header('Location: /whm/packages');
            exit;
        }

        $del = Database::app()->prepare('DELETE FROM packages WHERE id = ?');
        $del->execute([$id]);

        Flash::ok('Package deleted.');
        header('Location: /whm/packages');
        exit;
    }
}
