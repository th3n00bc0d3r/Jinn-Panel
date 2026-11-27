<?php
declare(strict_types=1);

/** WHM > Accounts. The rules (who may manage whom) live in AccountService. */
final class AccountController
{
    public const PER_PAGE = 25;

    /**
     * WHM > Accounts: searched, filtered, sorted and paged in the database,
     * so it stays quick with thousands of accounts. Every choice is in the
     * query string (links and a GET form - works without JavaScript).
     */
    public static function index(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $user = Auth::user();
        $admin = $user['role'] === 'admin';
        $pdo = Database::app();
        $f = self::filters($admin);

        $hasStats = true;
        try {
            $pdo->query('SELECT 1 FROM stat_account_daily LIMIT 1');
        } catch (PDOException) {
            $hasStats = false;
        }
        $traffic = $hasStats
            ? '(SELECT COALESCE(SUM(s.requests), 0) FROM stat_account_daily s WHERE s.user_id = u.id AND s.day > ' . $pdo->quote(date('Y-m-d', strtotime('-14 day'))) . ')'
            : '0';
        $from = "FROM users u
                 LEFT JOIN packages p ON p.id = u.package_id
                 LEFT JOIN users r ON r.id = u.parent_id
                 LEFT JOIN account_usage a ON a.user_id = u.id";
        $where = [];
        $params = [];
        if (!$admin) {
            $where[] = "u.role = 'user' AND u.parent_id = ?";
            $params[] = $user['id'];
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ? OR EXISTS (SELECT 1 FROM domains dq WHERE dq.user_id = u.id AND dq.domain_name LIKE ?))';
            array_push($params, $like, $like, $like, $like);
        }
        if ($f['role'] !== '') {
            $where[] = 'u.role = ?';
            $params[] = $f['role'];
        }
        if ($f['package'] > 0) {
            $where[] = 'u.package_id = ?';
            $params[] = $f['package'];
        }
        if ($f['reseller'] === 'none') {
            $where[] = "u.role = 'user' AND u.parent_id IS NULL";
        } elseif ($f['reseller'] !== '') {
            $where[] = 'u.parent_id = ?';
            $params[] = (int) $f['reseller'];
        }
        // Counts per status for the filter links, before the status filter itself.
        $base = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $c = $pdo->prepare("SELECT COUNT(*) total, SUM(u.status = 'active') active, SUM(u.status = 'suspended') suspended,
                                   SUM(COALESCE(a.over_disk, 0) = 1 OR COALESCE(a.over_bandwidth, 0) = 1) over_limits $from$base");
        $c->execute($params);
        $counts = array_map('intval', $c->fetch() ?: []);

        if ($f['status'] === 'active' || $f['status'] === 'suspended') {
            $where[] = 'u.status = ?';
            $params[] = $f['status'];
        } elseif ($f['status'] === 'over') {
            $where[] = '(a.over_disk = 1 OR a.over_bandwidth = 1)';
        }
        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $n = $pdo->prepare("SELECT COUNT(*) $from$sql");
        $n->execute($params);
        $total = (int) $n->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($f['page'], $pages);

        $dir = $f['dir'] === 'asc' ? 'ASC' : 'DESC';
        $order = match ($f['sort']) {
            'name' => "u.username $dir",
            'created' => "u.created_at $dir, u.username",
            'disk' => "disk_pct $dir, used_bytes $dir, u.username",
            'domains' => "domain_count $dir, u.username",
            'traffic' => "traffic_14d $dir, u.username",
            default => "FIELD(u.role, 'admin', 'reseller', 'user'), u.username",
        };
        $q = $pdo->prepare("SELECT u.*, p.name AS package_name, p.disk_quota_mb, r.username AS reseller_username,
                                   a.files_bytes + a.db_bytes + a.mail_bytes AS used_bytes, a.over_disk, a.over_bandwidth,
                                   CASE WHEN p.disk_quota_mb > 0 THEN (a.files_bytes + a.db_bytes + a.mail_bytes) / (p.disk_quota_mb * 1048576) ELSE NULL END AS disk_pct,
                                   (SELECT COUNT(*) FROM domains d WHERE d.user_id = u.id) AS domain_count,
                                   $traffic AS traffic_14d
                            $from$sql ORDER BY $order LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE));
        $q->execute($params);
        $accounts = $q->fetchAll();

        $sparks = [];
        if ($hasStats) {
            $sparks = StatsService::accountSparklines(array_map(fn($a) => (int) $a['id'], array_filter($accounts, fn($a) => $a['role'] === 'user')));
        }
        $resellers = $admin ? $pdo->query("SELECT id, username FROM users WHERE role = 'reseller' ORDER BY username")->fetchAll() : [];

        View::render('whm/accounts', [
            'title' => 'Accounts',
            'accounts' => $accounts,
            'me' => $user,
            'packages' => AccountService::availablePackages($user),
            'filters' => $f + ['page' => $page],
            'counts' => $counts,
            'total' => $total,
            'pages' => $pages,
            'sparks' => $sparks,
            'resellers' => $resellers,
        ], 'whm');
    }

    /** @return array{q:string, status:string, role:string, package:int, reseller:string, sort:string, dir:string, page:int} */
    private static function filters(bool $admin): array
    {
        $g = $_GET;
        $sort = in_array($g['sort'] ?? '', ['name', 'created', 'disk', 'domains', 'traffic'], true) ? $g['sort'] : '';
        $reseller = (string) ($g['reseller'] ?? '');
        return [
            'q' => mb_substr(trim((string) ($g['q'] ?? '')), 0, 100),
            'status' => in_array($g['status'] ?? '', ['active', 'suspended', 'over'], true) ? $g['status'] : '',
            'role' => $admin && in_array($g['role'] ?? '', ['admin', 'reseller', 'user'], true) ? $g['role'] : '',
            'package' => max(0, (int) ($g['package'] ?? 0)),
            'reseller' => $admin && ($reseller === 'none' || ctype_digit($reseller)) ? $reseller : '',
            'sort' => $sort,
            // Names read A to Z first; numbers biggest first.
            'dir' => in_array($g['dir'] ?? '', ['asc', 'desc'], true) ? $g['dir'] : ($sort === 'name' ? 'asc' : 'desc'),
            'page' => max(1, (int) ($g['page'] ?? 1)),
        ];
    }

    public static function show(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $me = Auth::user();
        $target = self::scopedOrBack((int) $params['id'], $me);
        $o = AccountService::overview($target);
        [$history, $projection] = self::history($target, $o);
        View::render('whm/account', [
            'history' => $history,
            'projection' => $projection,
            'title' => $target['username'],
            'a' => $target,
            'me' => $me,
            'o' => $o,
            'lastAdmin' => AccountService::isLastAdmin($target),
            'packages' => $target['role'] === 'admin' ? [] : AccountService::availablePackages($me),
            'pkgFeatures' => Features::forPackage($o['package']),
            'overrides' => Features::overrides($target),
            'alloc' => $target['role'] === 'reseller' ? ResellerService::allocation((int) $target['id']) : null,
        ], 'whm');
    }

    /**
     * A hosting account's last 30 days and when it reaches its disk and
     * bandwidth limits at the current rate (null without statistics).
     *
     * @return array{0:?array, 1:array<string,array>}
     */
    private static function history(array $target, array $o): array
    {
        if ($target['role'] !== 'user') {
            return [null, []];
        }
        try {
            $id = (int) $target['id'];
            $history = StatsService::accountHistory($id, 30);
            $projection = [];
            $u = $o['usage'];
            if ($o['package'] && $u) {
                $used = (int) $u['files_bytes'] + (int) $u['db_bytes'] + (int) $u['mail_bytes'];
                $limit = Quota::bytes($o['package']['disk_quota_mb']);
                if ($limit > 0) {
                    $projection['disk'] = StatsService::diskProjection($id, $used, $limit);
                }
                $bwLimit = Quota::bytes($o['package']['bandwidth_mb']);
                if ($bwLimit > 0) {
                    $projection['bw'] = StatsService::bandwidthProjection($u['bw_month'] === date('Y-m') ? (int) $u['bw_bytes'] : 0, $bwLimit);
                }
            }
            return [$history, $projection];
        } catch (PDOException $e) {
            error_log('account history: ' . $e->getMessage());
            return [null, []];
        }
    }

    public static function create(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $me = Auth::user();
        View::render('whm/account_form', [
            'title' => 'Create Account',
            'account' => null,
            'packages' => AccountService::availablePackages($me),
            'roles' => AccountService::creatableRoles($me),
            'welcome' => NotificationService::settings()['welcome'],
        ], 'whm');
    }

    /** A typed or pasted domain: lower case, no scheme, path or trailing dot. */
    private static function domainInput(string $raw): string
    {
        $d = strtolower(trim($raw));
        $d = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $d);
        $d = (string) preg_replace('#[/?\#].*$#', '', $d);

        return rtrim($d, '.');
    }

    public static function store(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $me = Auth::user();

        // Optional main domain (hosting accounts only), like WHM's Create a
        // New Account. Checked before anything is created, so a refused
        // domain leaves nothing behind (same rules as the API's createacct).
        $domain = self::domainInput((string) ($_POST['domain'] ?? ''));
        if ($domain !== '') {
            $problem = ($_POST['role'] ?? 'user') !== 'user'
                ? 'Only hosting accounts get a domain here - leave it empty for resellers and administrators.'
                : DomainPolicy::problem($domain, 0);
            if ($problem === null) {
                $taken = Database::app()->prepare('SELECT 1 FROM domains WHERE domain_name = ?');
                $taken->execute([$domain]);
                $problem = $taken->fetchColumn() ? "$domain is already hosted on this server." : null;
            }
            if ($problem !== null) {
                Flash::error("Domain: $problem");
                header('Location: /whm/accounts/create');
                exit;
            }
        }

        $result = AccountService::create($_POST, $me);
        if ($result['errors']) {
            Flash::error(implode(' ', $result['errors']));
            header('Location: /whm/accounts/create');
            exit;
        }
        $id = (int) $result['id'];
        Audit::target($id);
        if ($domain !== '') {
            $d = DomainService::add($id, $domain);
            if ($d['error'] !== null) {
                // All or nothing: remove the half-made account again.
                if ($half = AccountService::findScoped($id, $me)) {
                    AccountService::delete($half);
                }
                Flash::error("Domain: {$d['error']} The account was not created.");
                header('Location: /whm/accounts/create');
                exit;
            }
        }
        $s = Database::app()->prepare('SELECT * FROM users WHERE id = ?');
        $s->execute([$id]);
        $row = $s->fetch();
        $note = '';
        if (!empty($_POST['send_welcome'])) {
            $note = NotificationService::welcome($row, (string) ($_POST['password'] ?? ''))
                ? " A welcome email went to {$row['email']}."
                : ' The welcome email could not be sent - check WHM > Notifications.';
        }
        $withDomain = $domain !== '' ? " with $domain" : '';
        Flash::ok("Account \"{$row['username']}\" created as {$row['role']}{$withDomain}.$note");
        header("Location: /whm/accounts/$id");
        exit;
    }

    public static function edit(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $me = Auth::user();
        $target = self::scopedOrBack((int) $params['id'], $me);
        View::render('whm/account_form', [
            'title' => 'Edit ' . $target['username'],
            'account' => $target,
            'packages' => AccountService::availablePackages($me),
            'roles' => [$target['role']],
            'welcome' => false,
        ], 'whm');
    }

    public static function update(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $me = Auth::user();
        $target = self::scopedOrBack((int) $params['id'], $me);
        Audit::target((int) $target['id']);
        $errors = AccountService::update($target, $_POST, $me);
        if ($errors) {
            Flash::error(implode(' ', $errors));
            header("Location: /whm/accounts/{$target['id']}/edit");
            exit;
        }
        Flash::ok("Account \"{$target['username']}\" saved." . ((string) ($_POST['password'] ?? '') !== '' ? ' Its new password is in effect and its other sessions were signed out.' : ''));
        header("Location: /whm/accounts/{$target['id']}");
        exit;
    }

    /** The account page's package switch. */
    public static function package(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $me = Auth::user();
        $target = self::scopedOrBack((int) $params['id'], $me);
        $pkgId = (int) ($_POST['package_id'] ?? 0);
        // Moving to a smaller package: first show what it already has beyond the new limits.
        $pkg = self::offered($pkgId, $me);
        if ($pkg && ($_POST['confirm'] ?? '') !== '1' && $pkgId !== (int) $target['package_id']) {
            $over = AccountService::overages($target, $pkg);
            if ($over) {
                View::render('whm/package_change', [
                    'title' => "Move {$target['username']} to {$pkg['name']}?",
                    'intro' => "\"{$target['username']}\" already has more than {$pkg['name']} allows. Nothing is deleted; this is what happens:",
                    'rows' => [['account' => $target, 'items' => $over]],
                    'action' => "/whm/accounts/{$target['id']}/package",
                    'hidden' => ['package_id' => $pkgId],
                    'confirm' => 'Move anyway',
                    'back' => "/whm/accounts/{$target['id']}#features",
                ], 'whm');
                return;
            }
        }
        Audit::target((int) $target['id']);
        $problem = AccountService::changePackage($target, $pkgId, $me);
        if ($problem === null) {
            UsageService::applySoon([(int) $target['id']]);
            Flash::ok("\"{$target['username']}\" is on the new package. Its limits and features apply now, its disk quota within a minute.");
        } else {
            Flash::error($problem);
        }
        header("Location: /whm/accounts/{$target['id']}");
        exit;
    }

    /** The account page's feature switches (on top of its package). */
    public static function features(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $target = self::scopedOrBack((int) $params['id'], Auth::user());
        if ($target['role'] !== 'user') {
            self::back('Only hosting accounts have features.', true, (int) $target['id']);
        }
        Audit::target((int) $target['id']);
        AccountService::setFeatureOverrides($target, $_POST);
        Flash::ok("Features of \"{$target['username']}\" saved.");
        header("Location: /whm/accounts/{$target['id']}#features");
        exit;
    }

    /**
     * WHM > Accounts, several at once: ids[] and op = package | suspend |
     * unsuspend | feature. Each account is checked like a single action
     * (scope, last admin, reseller limits); the result says what failed.
     */
    public static function bulk(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $me = Auth::user();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
        $op = (string) ($_POST['op'] ?? '');
        if (!$ids || !in_array($op, ['package', 'suspend', 'unsuspend', 'feature'], true)) {
            self::back($ids ? 'Choose what to do with the selected accounts.' : 'Select at least one account.', true);
        }
        $feature = (string) ($_POST['feature'] ?? '');
        $value = (string) ($_POST['feature_value'] ?? '');
        if ($op === 'feature' && !isset(Features::LIST[$feature])) {
            self::back('Choose a feature.', true);
        }
        // Moving to a smaller package: first show which accounts already have more than it allows.
        if ($op === 'package' && ($_POST['confirm'] ?? '') !== '1' && ($pkg = self::offered((int) ($_POST['package_id'] ?? 0), $me))) {
            $rows = [];
            foreach (array_slice($ids, 0, 500) as $id) {
                $t = AccountService::findScoped($id, $me);
                if ($t && (int) $t['package_id'] !== (int) $pkg['id'] && ($over = AccountService::overages($t, $pkg))) {
                    $rows[] = ['account' => $t, 'items' => $over];
                }
            }
            if ($rows) {
                View::render('whm/package_change', [
                    'title' => 'Move ' . Ui::plural(count($ids), 'account') . " to {$pkg['name']}?",
                    'intro' => Ui::plural(count($rows), 'of them already has', 'of them already have') . " more than {$pkg['name']} allows. Nothing is deleted; this is what happens:",
                    'rows' => $rows,
                    'action' => '/whm/accounts/bulk',
                    'hidden' => ['op' => 'package', 'package_id' => (int) $pkg['id'], 'ids' => $ids],
                    'confirm' => 'Move all ' . count($ids),
                    'back' => '/whm/accounts',
                ], 'whm');
                return;
            }
        }
        $done = 0;
        $moved = [];
        $failed = [];
        foreach (array_slice($ids, 0, 500) as $id) {
            $t = AccountService::findScoped($id, $me);
            if (!$t) {
                $failed[] = "#$id: not yours to manage";
                continue;
            }
            try {
                $problem = match ($op) {
                    'package' => AccountService::changePackage($t, (int) ($_POST['package_id'] ?? 0), $me),
                    'suspend', 'unsuspend' => implode('; ', AccountService::setStatus($t, $op === 'suspend' ? 'suspended' : 'active')) ?: null,
                    'feature' => $t['role'] !== 'user' ? 'only hosting accounts have features' : (function () use ($t, $feature, $value) {
                        AccountService::setFeatureOverride($t, $feature, $value === '' ? null : $value === '1');
                        return null;
                    })(),
                };
            } catch (Throwable $e) {
                error_log("bulk $op #{$t['id']}: " . $e->getMessage());
                $problem = 'failed';
            }
            if ($problem !== null) {
                $failed[] = "{$t['username']}: $problem";
            } else {
                $done++;
                $moved[] = (int) $t['id'];
                Audit::log("whm.accounts.bulk.$op", $t['username'], (int) $t['id']);
            }
        }
        if ($op === 'package') {
            UsageService::applySoon($moved);
        }
        $what = match ($op) {
            'package' => 'moved to the new package',
            'suspend' => 'suspended',
            'unsuspend' => 'unsuspended',
            default => 'updated (' . Features::LIST[$feature][0] . ': ' . ($value === '' ? 'as the package' : ($value === '1' ? 'on' : 'off')) . ')',
        };
        if ($done) {
            Flash::ok(Ui::plural($done, 'account') . " $what.");
        }
        if ($failed) {
            Flash::error('Not changed: ' . implode(' · ', array_slice($failed, 0, 8)) . (count($failed) > 8 ? ' · and ' . (count($failed) - 8) . ' more' : ''));
        }
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
        $target = self::scopedOrBack((int) $params['id'], Auth::user());
        Audit::target((int) $target['id']);
        // Best-effort external cleanup (vhosts, DNS, databases + every MySQL
        // user the account owns, mailboxes, SFTP users), then the cascading
        // DB delete. The worker then removes its pools and Linux user and
        // moves its site folders aside (root-only).
        if (($problem = AccountService::delete($target)) !== null) {
            Flash::error($problem);
            header("Location: /whm/accounts/{$target['id']}");
            exit;
        }
        Flash::ok("Account \"{$target['username']}\" and its resources have been removed.");
        header('Location: /whm/accounts');
        exit;
    }

    /** Whether the account's PHP may run programs (exec, proc_open, ...): off by default. */
    public static function phpExec(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $target = self::scopedOrBack((int) $params['id'], Auth::user());
        if ($target['role'] !== 'user') {
            self::back('Only hosting accounts run PHP.', true);
        }
        $on = !empty($_POST['allow']);
        Database::app()->prepare('UPDATE users SET php_exec = ? WHERE id = ?')->execute([$on ? 1 : 0, $target['id']]);
        AccountRuntime::sync((int) $target['id']);
        Audit::target((int) $target['id']);
        Flash::ok("\"{$target['username']}\": PHP " . ($on ? 'may now run programs (exec, proc_open, ...).' : 'may no longer run programs.'));
        header("Location: /whm/accounts/{$target['id']}");
        exit;
    }

    /** "Log in as": open a hosting account's own panel, then come back with the banner's button. */
    public static function loginAs(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $me = Auth::user();
        $target = self::scopedOrBack((int) $params['id'], $me);
        if ($target['role'] !== 'user' || $target['status'] !== 'active') {
            Flash::error('Only active hosting accounts can be opened.');
            header("Location: /whm/accounts/{$target['id']}");
            exit;
        }
        AccountService::impersonate($target, $me);
        header('Location: /cpanel');
        exit;
    }

    /** POST /impersonate/stop - back to the admin/reseller session. */
    public static function stopImpersonating(): void
    {
        Csrf::requireValid();
        $id = AccountService::stopImpersonating();
        header('Location: ' . ($id !== null ? "/whm/accounts/$id" : '/login'));
        exit;
    }

    private static function setStatus(array $params, string $status): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $target = self::scopedOrBack((int) $params['id'], Auth::user());
        Audit::target((int) $target['id']);
        $problems = AccountService::setStatus($target, $status, (string) ($_POST['reason'] ?? ''));
        if ($target['role'] !== 'user' && $problems) {
            self::back(ucfirst($problems[0]) . '.', true, (int) $target['id']);
        }
        $what = $status === 'suspended'
            ? ' Its sites, mail logins, SFTP, MySQL users and cron jobs are switched off.'
            : ' Its sites, mail logins, SFTP, MySQL users and cron jobs are back on.';
        Flash::ok("Account \"{$target['username']}\" is now $status." . ($target['role'] === 'user' ? $what : ''));
        if ($problems) {
            Flash::error('Not everything could be switched: ' . implode('; ', array_slice($problems, 0, 5)));
        }
        header('Location: ' . (($_POST['back'] ?? '') === 'list' ? '/whm/accounts' : "/whm/accounts/{$target['id']}"));
        exit;
    }

    /** @return array<string,mixed> */
    /** A package the viewer may give, or null. */
    private static function offered(int $id, array $me): ?array
    {
        foreach (AccountService::availablePackages($me) as $p) {
            if ((int) $p['id'] === $id) {
                return AccountService::packageRow($id);
            }
        }
        return null;
    }

    private static function scopedOrBack(int $id, array $me): array
    {
        $target = AccountService::findScoped($id, $me);
        if (!$target) {
            self::back('Account not found.', true);
        }
        return $target;
    }

    private static function back(string $message, bool $error = false, ?int $id = null): never
    {
        $error ? Flash::error($message) : Flash::ok($message);
        header('Location: ' . ($id ? "/whm/accounts/$id" : '/whm/accounts'));
        exit;
    }
}
