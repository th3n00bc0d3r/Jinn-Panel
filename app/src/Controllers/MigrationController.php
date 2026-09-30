<?php
declare(strict_types=1);

/**
 * WHM > Migrations: one-click move of accounts from a cPanel & WHM server.
 *
 *   1. create/connect  - source server + credentials; the accounts that
 *                        login can see are listed (nothing is copied yet)
 *   2. select/start    - pick accounts + options, one click to start
 *   3. show/status     - live progress (polled), then a per-account report
 *
 * All the heavy lifting happens in the background runner
 * (worker/migration-runner.php via MigrationService); this controller only
 * validates input and records what should happen.
 *
 * Admins can migrate from a single cPanel account, a WHM reseller, or WHM
 * root. Resellers can use the first two, and everything they migrate ends
 * up owned by them.
 */
final class MigrationController
{
    public static function index(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $me = Auth::user();
        try {
            MigrationService::purgeStaleSecrets();
            $migrations = MigrationService::listFor($me);
        } catch (PDOException $e) {
            error_log($e->getMessage());
            Flash::error('The migration tables are missing. Re-run install.sh (or apply app/migrations/003_cpanel_migration.sql) and reload this page.');
            $migrations = [];
        }
        View::render('whm/migrations/index', [
            'title' => 'Migrations',
            'migrations' => $migrations,
            'me' => $me,
        ], 'whm');
    }

    public static function create(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        self::renderCreate([], []);
    }

    public static function connect(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $me = Auth::user();

        $type = (string) ($_POST['source_type'] ?? 'cpanel');
        $hostRaw = trim((string) ($_POST['host'] ?? ''));
        $portRaw = trim((string) ($_POST['port'] ?? ''));
        $user = trim((string) ($_POST['user'] ?? ''));
        $authType = (string) ($_POST['auth_type'] ?? 'token');
        $secret = trim((string) ($_POST['secret'] ?? ''));
        $verifyTls = !empty($_POST['verify_tls']);
        $mode = (string) ($_POST['transfer_mode'] ?? 'auto');
        $old = compact('type', 'hostRaw', 'portRaw', 'user', 'authType', 'verifyTls', 'mode');

        $errors = [];
        $allowedTypes = $me['role'] === 'admin' ? ['cpanel', 'reseller', 'root'] : ['cpanel', 'reseller'];
        if (!in_array($type, $allowedTypes, true)) {
            $errors[] = 'Choose what kind of source login this is.';
        }
        if (!in_array($authType, ['token', 'password'], true)) {
            $authType = 'token';
        }
        if (!in_array($mode, ['auto', 'pull', 'push'], true)) {
            $mode = 'auto';
        }

        [$host, $portFromHost] = self::parseHost($hostRaw);
        if ($host === null) {
            $errors[] = 'Enter the source server as a hostname or IP address, e.g. server.example.com.';
        }
        $port = $portRaw !== '' ? (int) $portRaw : ($portFromHost ?? CpanelApiClient::defaultPort($type === 'cpanel' ? 'cpanel' : 'reseller'));
        if ($port < 1 || $port > 65535) {
            $errors[] = 'Port must be between 1 and 65535.';
        }
        if ($type === 'root' && $user === '') {
            $user = 'root';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/i', $user)) {
            $errors[] = 'Enter the source username.';
        }
        if ($secret === '') {
            $errors[] = $authType === 'token' ? 'Paste the API token.' : 'Enter the password.';
        }
        if ($host !== null && !$errors) {
            $hostProblem = self::hostProblem($host, $me['role'] === 'admin');
            if ($hostProblem) {
                $errors[] = $hostProblem;
            }
        }

        // cPanel API tokens can't open a download session, so a single
        // account connected with a token can only push its backup to us.
        if ($type === 'cpanel' && $authType === 'token') {
            if ($mode === 'pull') {
                $errors[] = 'Pull mode needs the cPanel password (API tokens can\'t download files). Choose Push or Automatic, or connect with the password.';
            }
            $transfer = 'push';
        } else {
            $transfer = $mode === 'push' ? 'push' : 'pull';
        }

        if ($errors) {
            self::renderCreate($errors, $old);
            return;
        }

        try {
            $client = new CpanelApiClient($type, $host, $port, $user, $authType, $secret, $verifyTls);
            $accounts = $client->discoverAccounts();
        } catch (Throwable $e) {
            self::renderCreate(['Could not connect: ' . $e->getMessage()], $old);
            return;
        }
        if (!$accounts) {
            self::renderCreate(['Connected, but this login has no cPanel accounts to migrate.'], $old);
            return;
        }

        try {
            $id = MigrationService::createDraft($me, [
                'type' => $type, 'host' => $host, 'port' => $port, 'user' => $user,
                'auth_type' => $authType, 'verify_tls' => $verifyTls, 'transfer_mode' => $transfer,
            ], $secret, $accounts);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            self::renderCreate(['Connected, but the migration could not be saved: ' . $e->getMessage()], $old);
            return;
        }

        Flash::ok('Connected to ' . $host . ' - ' . count($accounts) . ' account' . (count($accounts) === 1 ? '' : 's') . ' found.');
        header("Location: /whm/migrations/$id/select");
        exit;
    }

    public static function select(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $me = Auth::user();
        $m = self::findOr404($params, $me);
        if ($m['status'] !== 'draft') {
            header("Location: /whm/migrations/{$m['id']}");
            exit;
        }
        $items = MigrationService::items((int) $m['id']);
        View::render('whm/migrations/select', [
            'title' => 'Choose accounts to migrate',
            'm' => $m,
            'items' => $items,
            'conflicts' => MigrationService::conflicts($items),
            'packages' => self::availablePackages($me),
            'resellers' => $me['role'] === 'admin' ? Database::app()->query("SELECT id, username FROM users WHERE role = 'reseller' ORDER BY username")->fetchAll() : [],
            'opt' => MigrationService::options($m),
            'me' => $me,
        ], 'whm');
    }

    public static function start(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $me = Auth::user();
        $m = self::findOr404($params, $me);
        if ($m['status'] !== 'draft') {
            Flash::error('This migration has already been started.');
            header("Location: /whm/migrations/{$m['id']}");
            exit;
        }

        $items = MigrationService::items((int) $m['id']);
        $conflicts = MigrationService::conflicts($items);
        $wanted = array_map('strval', (array) ($_POST['accounts'] ?? []));
        $usernames = [];
        foreach ($items as $it) {
            if (in_array($it['source_username'], $wanted, true) && !isset($conflicts[(int) $it['id']])) {
                $usernames[] = $it['source_username'];
            }
        }
        $back = "/whm/migrations/{$m['id']}/select";
        if (!$usernames) {
            Flash::error('Select at least one account that can be migrated.');
            header("Location: $back");
            exit;
        }

        $opt = [
            'files' => !empty($_POST['files']),
            'databases' => !empty($_POST['databases']),
            'email_accounts' => !empty($_POST['email_accounts']),
            'email_data' => !empty($_POST['email_accounts']) && !empty($_POST['email_data']),
            'match_packages' => !empty($_POST['match_packages']),
            'mail_passwords' => ($_POST['mail_passwords'] ?? 'preserve') === 'generate' ? 'generate' : 'preserve',
            'ssl_mode' => ($_POST['ssl_mode'] ?? '') === 'letsencrypt' ? 'letsencrypt' : 'self_signed',
            'timeout_hours' => max(1, min(72, (int) ($_POST['timeout_hours'] ?? 12))),
            'first_byte_minutes' => max(10, min(720, (int) ($_POST['first_byte_minutes'] ?? 120))),
            'package_id' => null,
            'owner' => 'none',
        ];
        if (!$opt['files'] && !$opt['databases'] && !$opt['email_accounts']) {
            Flash::error('Choose at least one thing to migrate (files, databases or email).');
            header("Location: $back");
            exit;
        }

        $packageId = (int) ($_POST['package_id'] ?? 0);
        if ($packageId > 0) {
            if (!in_array($packageId, array_map('intval', array_column(self::availablePackages($me), 'id')), true)) {
                Flash::error('Invalid package selected.');
                header("Location: $back");
                exit;
            }
            $opt['package_id'] = $packageId;
        }

        if ($me['role'] === 'admin') {
            $owner = (string) ($_POST['owner'] ?? 'none');
            if ($owner === 'recreate' && $m['source_type'] === 'root') {
                $opt['owner'] = 'recreate';
            } elseif (preg_match('/^reseller:(\d+)$/', $owner, $om)) {
                $chk = Database::app()->prepare("SELECT id FROM users WHERE id = ? AND role = 'reseller'");
                $chk->execute([(int) $om[1]]);
                if (!$chk->fetch()) {
                    Flash::error('Invalid reseller selected.');
                    header("Location: $back");
                    exit;
                }
                $opt['owner'] = 'reseller:' . (int) $om[1];
            }
        }

        if ($m['transfer_mode'] === 'push') {
            $publicHost = trim((string) ($_POST['public_host'] ?? ''));
            [$ph] = self::parseHost($publicHost);
            $opt['public_host'] = $ph ?? Config::SERVER_IP;
            $pp = (int) ($_POST['public_port'] ?? 0);
            $opt['public_port'] = $pp >= 1 && $pp <= 65535 ? $pp : MigrationService::defaultOptions()['public_port'];
        }

        try {
            MigrationService::start((int) $m['id'], $opt, $usernames);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error('Could not start the migration: ' . $e->getMessage());
            header("Location: $back");
            exit;
        }
        Flash::ok('Migration started for ' . count($usernames) . ' account' . (count($usernames) === 1 ? '' : 's') . '. It runs in the background - you can leave this page.');
        header("Location: /whm/migrations/{$m['id']}");
        exit;
    }

    public static function show(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $me = Auth::user();
        $m = self::findOr404($params, $me);
        if ($m['status'] === 'draft') {
            header("Location: /whm/migrations/{$m['id']}/select");
            exit;
        }
        $items = MigrationService::items((int) $m['id'], true);
        foreach ($items as &$it) {
            $it['report_data'] = json_decode((string) $it['report'], true) ?: [];
        }
        unset($it);
        View::render('whm/migrations/show', [
            'title' => 'Migration #' . (int) $m['id'],
            'm' => $m,
            'items' => $items,
            'stalled' => MigrationService::isStalled($m),
            'log' => MigrationService::tailLog((int) $m['id']),
            'me' => $me,
        ], 'whm');
    }

    /** JSON polled by the progress page every few seconds. */
    public static function status(array $params): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $m = MigrationService::find((int) ($params['id'] ?? 0), Auth::user());
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if (!$m) {
            http_response_code(404);
            echo json_encode(['error' => 'not found']);
            exit;
        }
        $items = array_map(fn($it) => [
            'id' => (int) $it['id'],
            'status' => $it['status'],
            'step' => (string) $it['step'],
            'progress' => (int) $it['progress'],
            'error' => $it['error'],
        ], MigrationService::items((int) $m['id'], true));
        echo json_encode([
            'status' => $m['status'],
            'stalled' => MigrationService::isStalled($m),
            'finished' => in_array($m['status'], MigrationService::FINISHED, true),
            'items' => $items,
            'log' => MigrationService::tailLog((int) $m['id']),
        ]);
        exit;
    }

    public static function cancel(array $params): void
    {
        [$m, $me] = self::mutating($params);
        if (in_array($m['status'], MigrationService::FINISHED, true)) {
            Flash::error('This migration has already finished.');
        } else {
            $stalled = MigrationService::isStalled($m);
            MigrationService::requestCancel($m);
            if ($stalled) {
                // Nothing is updating the heartbeat - make sure no hung runner is left behind.
                SystemWorkerService::enqueue("migration-{$m['id']}-stop", ['type' => 'migration_stop', 'migration_id' => (int) $m['id']]);
            }
            Flash::ok($m['status'] === 'running' && !$stalled
                ? 'Cancelling - the runner stops at the next safe point and rolls back the account it was working on.'
                : 'Migration cancelled.');
        }
        self::redirectTo($m);
    }

    public static function retry(array $params): void
    {
        [$m] = self::mutating($params);
        if (!in_array($m['status'], MigrationService::FINISHED, true)) {
            Flash::error('Wait for the migration to finish (or cancel it) before retrying.');
            self::redirectTo($m);
        }
        try {
            $n = MigrationService::retry($m);
            $n > 0 ? Flash::ok("Retrying $n account" . ($n === 1 ? '' : 's') . '.') : Flash::error('There are no failed or cancelled accounts to retry.');
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        self::redirectTo($m);
    }

    public static function destroy(array $params): void
    {
        [$m] = self::mutating($params);
        try {
            MigrationService::delete($m);
            Flash::ok('Migration record deleted. Migrated accounts are not affected.');
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            self::redirectTo($m);
        }
        header('Location: /whm/migrations');
        exit;
    }

    public static function discardSecret(array $params): void
    {
        [$m] = self::mutating($params);
        if (in_array($m['status'], MigrationService::ACTIVE, true) && !MigrationService::isStalled($m)) {
            Flash::error('The running migration still needs the source credentials. Cancel it first.');
        } else {
            MigrationService::discardSecret((int) $m['id']);
            Flash::ok('Source credentials deleted from this server. Remember to revoke the API token on the source too.');
        }
        self::redirectTo($m);
    }

    // ------------------------------------------------------------------

    /** @return array{0:array,1:array} */
    private static function mutating(array $params): array
    {
        Auth::requireRole(['admin', 'reseller']);
        Csrf::requireValid();
        $me = Auth::user();
        return [self::findOr404($params, $me), $me];
    }

    private static function findOr404(array $params, array $me): array
    {
        $m = MigrationService::find((int) ($params['id'] ?? 0), $me);
        if (!$m) {
            Flash::error('Migration not found.');
            header('Location: /whm/migrations');
            exit;
        }
        return $m;
    }

    private static function redirectTo(array $m): never
    {
        header('Location: /whm/migrations/' . (int) $m['id']);
        exit;
    }

    private static function renderCreate(array $errors, array $old): void
    {
        View::render('whm/migrations/create', [
            'title' => 'New migration',
            'errors' => $errors,
            'old' => $old + [
                'type' => 'cpanel', 'hostRaw' => '', 'portRaw' => '', 'user' => '',
                'authType' => 'token', 'verifyTls' => true, 'mode' => 'auto',
            ],
            'canRoot' => Auth::isAdmin(),
            'serverIp' => Config::SERVER_IP,
        ], 'whm');
    }

    /**
     * Accepts "host", "host:port", "https://host:port/..." and "[v6]:port".
     *
     * @return array{0:?string,1:?int}
     */
    private static function parseHost(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, null];
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        $parts = parse_url($raw);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host, $port];
        }
        if (strlen($host) <= 190 && preg_match('/^(?=.{1,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
            return [$host, $port];
        }
        return [null, null];
    }

    /**
     * Keeps the migration client from being pointed back at this server's
     * own internal services. Admins may migrate from a private-LAN source;
     * resellers only from public addresses.
     */
    private static function hostProblem(string $host, bool $isAdmin): ?string
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips && !filter_var($host, FILTER_VALIDATE_IP)) {
            $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
            $ips = array_column($aaaa, 'ipv6');
        }
        if (!$ips) {
            return "Could not resolve $host from this server.";
        }
        foreach ($ips as $ip) {
            $isLoopback = str_starts_with($ip, '127.') || $ip === '::1' || $ip === '0.0.0.0' || $ip === '::';
            $isLinkLocal = str_starts_with($ip, '169.254.') || str_starts_with(strtolower($ip), 'fe80:');
            if ($isLoopback || $isLinkLocal || $ip === Config::SERVER_IP) {
                return "$host points at this server itself ($ip) - enter the address of the old cPanel server.";
            }
            if (!$isAdmin && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return "$host resolves to a private address ($ip). Ask the server administrator to run this migration.";
            }
        }
        return null;
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
