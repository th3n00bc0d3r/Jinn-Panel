<?php
declare(strict_types=1);

/**
 * The provisioning API: a WHM API 1 compatible subset at /json-api/<function>,
 * so billing systems and order forms written for WHM (WHMCS-style
 * `createacct` callers) work against JinnPanel unchanged.
 *
 *   curl -H "Authorization: whm <username>:<token>" \
 *        "https://<panel host>/json-api/createacct?api.version=1&username=...&domain=...&plan=...&contactemail=...&password=..."
 *
 * Tokens: WHM > API Tokens (ApiTokenService). A token acts with its owner's
 * rights: an admin's sees every account, a reseller's only its own.
 * Parameters come from the query string or a form-encoded POST body.
 * Answers are always `{"metadata": {"result": 1|0, "reason": "...",
 * "command": "...", "version": 1}, "data": {...}}`. docs/API.md has the details.
 *
 * Also the WHM > API Tokens page (tokens, tokenCreate, tokenDelete).
 */
final class ApiController
{
    /** Bumped when a function's parameters or answers change incompatibly. */
    private const API_VERSION = '1.0';

    private const FUNCTIONS = [
        'version', 'applist', 'listpkgs', 'listaccts', 'accountsummary',
        'createacct', 'changepackage', 'suspendacct', 'unsuspendacct', 'removeacct',
    ];

    public static function handle(array $params): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        $fn = (string) ($params['fn'] ?? '');

        if (!Auth::onPanelHost()) {
            self::reply($fn, false, 'The API is only served on https://' . Auth::panelHost() . '/json-api/.', [], 404);
        }
        if (!in_array($fn, self::FUNCTIONS, true)) {
            self::reply($fn, false, "Unknown function \"$fn\". Available: " . implode(', ', self::FUNCTIONS) . '.', [], 404);
        }

        // Guessing tokens is throttled like guessing passwords, per address
        // (a shared key would let anyone lock every client out).
        $throttleKey = 'api-token@' . LoginThrottle::ip();
        if (($wait = LoginThrottle::blockedFor($throttleKey)) > 0) {
            self::reply($fn, false, "Too many failed attempts - try again in $wait minute" . ($wait === 1 ? '' : 's') . '.', [], 429);
        }
        $auth = ApiTokenService::authenticate((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), LoginThrottle::ip());
        if ($auth['user'] === null) {
            LoginThrottle::fail($throttleKey);
            self::reply($fn, false, $auth['error'], [], 401);
        }
        $me = $auth['user'];
        $in = array_merge($_GET, $_POST);
        unset($in['api_version'], $in['api.version']);

        try {
            match ($fn) {
                'version' => self::reply($fn, true, 'OK', ['version' => self::API_VERSION, 'product' => Config::APP_NAME]),
                'applist' => self::reply($fn, true, 'OK', ['app' => self::FUNCTIONS]),
                'listpkgs' => self::listpkgs($me),
                'listaccts' => self::listaccts($me, $in),
                'accountsummary' => self::accountsummary($me, $in),
                'createacct' => self::createacct($me, $in),
                'changepackage' => self::changepackage($me, $in),
                'suspendacct' => self::setStatus($me, $in, 'suspended', $fn),
                'unsuspendacct' => self::setStatus($me, $in, 'active', $fn),
                'removeacct' => self::removeacct($me, $in),
            };
        } catch (Throwable $e) {
            error_log("api $fn: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            self::reply($fn, false, 'Internal error - see the panel\'s app.log.', [], 500);
        }
    }

    private static function listpkgs(array $me): never
    {
        $pkgs = array_map(fn(array $p) => [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'QUOTA' => (int) $p['disk_quota_mb'],
            'BWLIMIT' => (int) $p['bandwidth_mb'],
            'MAXADDON' => (int) $p['max_domains'],
            'MAXSQL' => (int) $p['max_databases'],
            'MAXPOP' => (int) $p['max_email_accounts'],
            'MAXFTP' => (int) $p['max_ftp_accounts'],
            'global' => $p['owner_id'] === null,
        ], AccountService::availablePackages($me));
        self::reply('listpkgs', true, 'OK', ['pkg' => $pkgs]);
    }

    private static function listaccts(array $me, array $in): never
    {
        $sql = "SELECT u.*, p.name AS package_name,
                       (SELECT d.domain_name FROM domains d WHERE d.user_id = u.id ORDER BY d.id LIMIT 1) AS primary_domain
                FROM users u LEFT JOIN packages p ON p.id = u.package_id WHERE u.role = 'user'";
        $args = [];
        if ($me['role'] === 'reseller') {
            $sql .= ' AND u.parent_id = ?';
            $args[] = $me['id'];
        }
        if (($search = trim((string) ($in['search'] ?? ''))) !== '') {
            $sql .= ' AND (u.username LIKE ? OR u.email LIKE ? OR EXISTS (SELECT 1 FROM domains d WHERE d.user_id = u.id AND d.domain_name LIKE ?))';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($args, $like, $like, $like);
        }
        $s = Database::app()->prepare("$sql ORDER BY u.id");
        $s->execute($args);
        self::reply('listaccts', true, 'OK', ['acct' => array_map([self::class, 'acct'], $s->fetchAll())]);
    }

    private static function accountsummary(array $me, array $in): never
    {
        $target = self::target($me, $in, 'accountsummary');
        self::reply('accountsummary', true, 'OK', ['acct' => [self::acct($target)]]);
    }

    private static function createacct(array $me, array $in): never
    {
        $fn = 'createacct';
        $username = strtolower(trim((string) ($in['username'] ?? $in['user'] ?? '')));
        $domain = strtolower(rtrim(trim((string) ($in['domain'] ?? '')), '.'));
        $plan = trim((string) ($in['plan'] ?? $in['pkgname'] ?? ''));
        $email = trim((string) ($in['contactemail'] ?? $in['email'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $fullName = mb_substr(trim((string) ($in['contactname'] ?? $in['full_name'] ?? '')), 0, 120);

        if ($username === '' || $domain === '' || $plan === '' || $email === '' || $password === '') {
            self::reply($fn, false, 'username, domain, plan, contactemail and password are all required.', [], 400);
        }
        $package = self::package($me, $plan);
        if ($package === null) {
            self::reply($fn, false, "No package named \"$plan\" - listpkgs shows the ones this token can use.", [], 400);
        }
        // The domain is checked before anything is created, so a refused
        // domain leaves nothing behind. 0 = "nobody's yet": every existing
        // account's domains count as someone else's.
        if (($problem = DomainPolicy::problem($domain, 0)) !== null) {
            self::reply($fn, false, "Domain: $problem", [], 400);
        }
        $taken = Database::app()->prepare('SELECT 1 FROM domains WHERE domain_name = ?');
        $taken->execute([$domain]);
        if ($taken->fetchColumn()) {
            self::reply($fn, false, "The domain $domain is already hosted on this server.", [], 409);
        }

        $r = AccountService::create([
            'username' => $username,
            'email' => $email,
            'full_name' => $fullName,
            'password' => $password,
            'role' => 'user',
            'package_id' => (int) $package['id'],
        ], $me);
        if ($r['errors']) {
            self::reply($fn, false, implode(' ', $r['errors']), [], 400);
        }
        $id = (int) $r['id'];
        $d = DomainService::add($id, $domain);
        if ($d['error'] !== null) {
            // All or nothing, like WHM: remove the half-made account again.
            $row = AccountService::findScoped($id, $me);
            if ($row) {
                AccountService::delete($row);
            }
            self::reply($fn, false, "Domain: {$d['error']}", [], 400);
        }
        Audit::log('api.createacct', "Created $username ($domain, package {$package['name']}) through the API", $id, (int) $me['id']);
        $row = AccountService::findScoped($id, $me) ?? [];
        self::reply($fn, true, "Account $username created.", [
            'acct' => self::acct($row + ['package_name' => $package['name'], 'primary_domain' => $domain]),
            'login_url' => 'https://' . $domain . ':2083/',
            'panel_url' => 'https://' . Auth::panelHost() . '/',
            'dns' => $d['dns'],
            'ssl_mode' => $d['ssl_mode'],
            'nameservers' => [DnsService::nameservers()['ns1_host'], DnsService::nameservers()['ns2_host']],
            'server_ip' => Config::SERVER_IP,
        ]);
    }

    private static function changepackage(array $me, array $in): never
    {
        $target = self::target($me, $in, 'changepackage');
        $package = self::package($me, trim((string) ($in['pkg'] ?? $in['plan'] ?? '')));
        if ($package === null) {
            self::reply('changepackage', false, 'Name a package with pkg=<name> - listpkgs shows the ones this token can use.', [], 400);
        }
        if (($problem = AccountService::changePackage($target, (int) $package['id'], $me)) !== null) {
            self::reply('changepackage', false, $problem, [], 400);
        }
        Audit::log('api.changepackage', "Moved {$target['username']} to package {$package['name']} through the API", (int) $target['id'], (int) $me['id']);
        $target['package_name'] = $package['name'];
        $target['package_id'] = $package['id'];
        self::reply('changepackage', true, "Account {$target['username']} is now on package {$package['name']}.", ['acct' => [self::acct($target)]]);
    }

    /** A package $me may hand out, by name (case-insensitive) or id. */
    private static function package(array $me, string $nameOrId): ?array
    {
        if ($nameOrId === '') {
            return null;
        }
        foreach (AccountService::availablePackages($me) as $p) {
            if (strcasecmp((string) $p['name'], $nameOrId) === 0 || (ctype_digit($nameOrId) && (int) $p['id'] === (int) $nameOrId)) {
                return $p;
            }
        }
        return null;
    }

    private static function setStatus(array $me, array $in, string $status, string $fn): never
    {
        $target = self::target($me, $in, $fn);
        $problems = AccountService::setStatus($target, $status, mb_substr(trim((string) ($in['reason'] ?? '')), 0, 500));
        Audit::log("api.$fn", "Set {$target['username']} to $status through the API", (int) $target['id'], (int) $me['id']);
        self::reply($fn, true, "Account {$target['username']} is now $status.", ['problems' => $problems]);
    }

    private static function removeacct(array $me, array $in): never
    {
        $target = self::target($me, $in, 'removeacct');
        if (($problem = AccountService::delete($target)) !== null) {
            self::reply('removeacct', false, $problem, [], 400);
        }
        Audit::log('api.removeacct', "Removed {$target['username']} through the API", null, (int) $me['id']);
        self::reply('removeacct', true, "Account {$target['username']} and its resources were removed.");
    }

    /** The hosting account named by `user` (or `username`) that $me may manage; replies 404 otherwise. */
    private static function target(array $me, array $in, string $fn): array
    {
        $username = strtolower(trim((string) ($in['user'] ?? $in['username'] ?? '')));
        $s = Database::app()->prepare("SELECT id FROM users WHERE username = ? AND role = 'user'");
        $s->execute([$username]);
        $id = (int) $s->fetchColumn();
        $row = $id ? AccountService::findScoped($id, $me) : null;
        if (!$row) {
            self::reply($fn, false, $username === '' ? 'Name the account with user=<username>.' : "No hosting account \"$username\" that this token can manage.", [], 404);
        }
        if (!isset($row['primary_domain'])) {
            $d = Database::app()->prepare('SELECT domain_name FROM domains WHERE user_id = ? ORDER BY id LIMIT 1');
            $d->execute([$id]);
            $row['primary_domain'] = $d->fetchColumn() ?: null;
        }
        if (!isset($row['package_name'])) {
            $row['package_name'] = AccountService::packageRow((int) ($row['package_id'] ?? 0))['name'] ?? null;
        }
        return $row;
    }

    /** An account in WHM's listaccts shape (plus a few JinnPanel fields). */
    private static function acct(array $u): array
    {
        $created = isset($u['created_at']) ? strtotime((string) $u['created_at']) : false;
        return [
            'user' => $u['username'] ?? null,
            'domain' => $u['primary_domain'] ?? null,
            'email' => $u['email'] ?? null,
            'plan' => $u['package_name'] ?? null,
            'suspended' => ($u['status'] ?? '') === 'suspended' ? 1 : 0,
            'status' => $u['status'] ?? null,
            'unix_startdate' => $created ?: null,
            'startdate' => $created ? gmdate('Y-m-d H:i:s', $created) : null,
            'owner' => ($u['parent_id'] ?? null) !== null ? (int) $u['parent_id'] : null,
        ];
    }

    private static function reply(string $fn, bool $ok, string $reason, array $data = [], int $http = 200): never
    {
        http_response_code($ok ? 200 : $http);
        echo json_encode([
            'metadata' => ['version' => 1, 'command' => $fn, 'result' => $ok ? 1 : 0, 'reason' => $reason],
            'data' => (object) $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- WHM > API Tokens ------------------------------------------------

    public static function tokens(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $me = Auth::user();
        $new = $_SESSION['new_api_token'] ?? null;
        unset($_SESSION['new_api_token']);
        View::render('whm/api_tokens', [
            'title' => 'API Tokens',
            'me' => $me,
            'tokens' => ApiTokenService::list($me),
            'newToken' => is_array($new) ? $new : null,
            'endpoint' => 'https://' . Auth::panelHost() . '/json-api/',
            'functions' => self::FUNCTIONS,
        ], 'whm');
    }

    public static function tokenCreate(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $me = Auth::user();
        $r = ApiTokenService::create($me, (string) ($_POST['name'] ?? ''), (string) ($_POST['allowed_ips'] ?? ''));
        if ($r['errors']) {
            Flash::error(implode(' ', $r['errors']));
        } else {
            $_SESSION['new_api_token'] = ['name' => trim((string) $_POST['name']), 'token' => $r['token'], 'username' => $me['username']];
            Flash::ok('API token "' . trim((string) $_POST['name']) . '" created. Copy it now: it is not shown again.');
        }
        header('Location: /whm/api-tokens');
        exit;
    }

    public static function tokenDelete(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        if (ApiTokenService::delete(Auth::user(), (int) $params['id'])) {
            Flash::ok('API token deleted. Anything still using it is refused from now on.');
        } else {
            Flash::error('No such token.');
        }
        header('Location: /whm/api-tokens');
        exit;
    }
}
