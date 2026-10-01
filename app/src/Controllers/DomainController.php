<?php
declare(strict_types=1);

final class DomainController
{
    public static function index(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$me['id']]);
        $domains = $stmt->fetchAll();
        foreach ($domains as &$d) {
            $d['ssl'] = SslService::status((string) $d['domain_name'], (string) $d['ssl_mode']);
        }
        unset($d);
        View::render('cpanel/domains', [
            'title' => 'Domains',
            'domains' => $domains,
            'usage' => Quota::usage($me['id']),
            'pkg' => Quota::package($me['id']),
            'phpVersions' => PhpVersionService::selectable(),
        ], 'cpanel');
    }

    public static function store(): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();

        $domain = strtolower(trim((string) ($_POST['domain_name'] ?? '')));
        $phpVersion = self::validPhpVersion((string) ($_POST['php_version'] ?? 'default'));
        $sslRequested = (string) ($_POST['ssl_mode'] ?? 'auto');

        if (!Quota::withinLimit($me['id'], 'domains')) {
            Flash::error('You have reached your package\'s domain limit.');
            header('Location: /cpanel/domains');
            exit;
        }
        if (($problem = DomainPolicy::problem($domain, (int) $me['id'])) !== null) {
            Flash::error($problem);
            header('Location: /cpanel/domains');
            exit;
        }

        $pdo = Database::app();
        $sslMode = SslService::resolveMode($sslRequested, $domain);
        $dnsOk = true;
        try {
            DnsService::createZone($domain);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $dnsOk = false;
        }

        // The row first: the vhost names the owning account's PHP pool.
        $stmt = $pdo->prepare('INSERT INTO domains (user_id, domain_name, docroot, dns_provisioned, php_version, php_port, ssl_mode) VALUES (?, ?, ?, ?, ?, NULL, ?)');
        $stmt->execute([$me['id'], $domain, VhostService::docroot($domain), $dnsOk ? 1 : 0, $phpVersion, $sslMode]);
        try {
            VhostService::create($domain, $phpVersion, $sslMode);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $pdo->prepare('DELETE FROM domains WHERE domain_name = ?')->execute([$domain]);
            Flash::error('Could not create the web vhost: ' . $e->getMessage());
            header('Location: /cpanel/domains');
            exit;
        }
        // Its folder (owned by the account, with a placeholder page) and PHP pool.
        AccountRuntime::sync((int) $me['id']);
        try {
            SftpService::syncAccountFolders((int) $me['id']);
        } catch (Throwable $e) {
            error_log('sftp folders: ' . $e->getMessage());
        }

        $sslNote = $sslMode === 'letsencrypt'
            ? ' A Let\'s Encrypt certificate is being issued.'
            : ($sslRequested === 'auto' ? ' It uses a self-signed certificate until its DNS points here, then switches to Let\'s Encrypt automatically.' : '');
        Flash::ok("Domain \"$domain\" is set up - it goes live within a few seconds" . ($dnsOk ? '.' : ', but DNS zone provisioning failed (web still works).') . $sslNote);
        header('Location: /cpanel/domains');
        exit;
    }

    public static function updateSettings(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $id = (int) $params['id'];

        $pdo = Database::app();
        $stmt = $pdo->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $me['id']]);
        $domain = $stmt->fetch();
        if (!$domain) {
            Flash::error('Domain not found.');
            header('Location: /cpanel/domains');
            exit;
        }

        $phpVersion = self::validPhpVersion((string) ($_POST['php_version'] ?? $domain['php_version']));
        $sslMode = SslService::resolveMode((string) ($_POST['ssl_mode'] ?? $domain['ssl_mode']), $domain['domain_name']);

        try {
            // Re-creating the vhost is safe/idempotent: it names the pool of
            // the chosen PHP version (the worker starts it), and the SSL mode.
            $pdo->prepare('UPDATE domains SET php_version = ? WHERE id = ?')->execute([$phpVersion, $id]);
            VhostService::create($domain['domain_name'], $phpVersion, $sslMode);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error('Could not update the vhost: ' . $e->getMessage());
            header('Location: /cpanel/domains');
            exit;
        }

        $upd = $pdo->prepare('UPDATE domains SET php_version = ?, php_port = NULL, ssl_mode = ? WHERE id = ?');
        $upd->execute([$phpVersion, $sslMode, $id]);
        AccountRuntime::sync((int) $me['id']); // a pool in the new PHP version's FPM

        Flash::ok("Settings updated for \"{$domain['domain_name']}\".");
        header('Location: /cpanel/domains');
        exit;
    }

    public static function destroy(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $id = (int) $params['id'];

        $pdo = Database::app();
        $stmt = $pdo->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $me['id']]);
        $domain = $stmt->fetch();
        if (!$domain) {
            Flash::error('Domain not found.');
            header('Location: /cpanel/domains');
            exit;
        }

        try { VhostService::remove($domain['domain_name'], $domain['php_version']); } catch (Throwable $e) { error_log($e->getMessage()); }
        try { DnsService::removeZone($domain['domain_name']); } catch (Throwable $e) { error_log($e->getMessage()); }
        // Its mailboxes, forwarders and mail domain - otherwise they'd keep receiving mail.
        if ($domain['mail_domain_id']) {
            try { MailService::deleteDomain((string) $domain['mail_domain_id']); } catch (Throwable $e) { error_log($e->getMessage()); }
        }
        PhpSettingsService::remove((string) $domain['domain_name']);
        DomainAliasService::removeAll($domain);
        CacheService::purgePages((string) $domain['domain_name']);
        $pdo->prepare('DELETE FROM email_accounts WHERE domain_id = ?')->execute([$id]);

        $del = $pdo->prepare('DELETE FROM domains WHERE id = ?');
        $del->execute([$id]);
        AccountRuntime::sync((int) $me['id']);
        try {
            SftpService::syncAccountFolders((int) $me['id']);
        } catch (Throwable $e) {
            error_log('sftp folders: ' . $e->getMessage());
        }

        Flash::ok("Domain \"{$domain['domain_name']}\" removed with its mailboxes and forwarders. Files were left in place on disk.");
        header('Location: /cpanel/domains');
        exit;
    }

    private static function validPhpVersion(string $version): string
    {
        if ($version === 'default' || in_array($version, PhpVersionService::selectable(), true)) {
            return $version;
        }
        return 'default';
    }

    /** cPanel > Domains > domain: URLs, SSL, document root, cache. */
    public static function show(array $params): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $d = self::owned($me, (int) ($params['id'] ?? 0));
        View::render('cpanel/domain', [
            'title' => $d['domain_name'],
            'd' => $d,
            'ssl' => SslService::status((string) $d['domain_name'], (string) $d['ssl_mode']),
            'cert' => SslService::certificate((string) $d['domain_name']),
            'addresses' => SslService::addresses((string) $d['domain_name']),
            'siteDir' => VhostService::siteDir((string) $d['domain_name']),
            'docroot' => VhostService::effectiveDocroot((string) $d['domain_name']),
            'routes' => is_file(VhostService::rulesFile((string) $d['domain_name'])),
            'php' => PhpSettingsService::get($d),
            'aliases' => DomainAliasService::forDomain((string) $d['domain_name']),
            'phpLog' => PhpSettingsService::logFile((string) $d['domain_name']),
        ], 'cpanel');
    }

    /** Document root: a directory inside /var/www/<domain>/ (e.g. public/, or public/app/public for Laravel). */
    public static function docroot(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $d = self::owned($me, (int) ($params['id'] ?? 0));
        $rel = trim(str_replace('\\', '/', (string) ($_POST['docroot'] ?? '')), '/');
        $siteDir = VhostService::siteDir((string) $d['domain_name']);
        if ($rel === '' || str_contains($rel, '..') || !preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $rel)) {
            self::backTo($d, 'Enter a folder inside ' . $siteDir . '/, e.g. public or public/app/public.');
        }
        $path = $siteDir . '/' . $rel;
        if (!VhostService::isInsideSite((string) $d['domain_name'], $path)) {
            self::backTo($d, "$path doesn't exist (create it in the File Manager first) or is outside the site's folder.");
        }
        $old = (string) $d['docroot'];
        $pdo = Database::app();
        $pdo->prepare('UPDATE domains SET docroot = ? WHERE id = ?')->execute([realpath($path), $d['id']]);
        try {
            VhostService::create((string) $d['domain_name'], (string) $d['php_version'], (string) $d['ssl_mode'], true, false);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE domains SET docroot = ? WHERE id = ?')->execute([$old, $d['id']]);
            self::backTo($d, 'Could not update the site: ' . $e->getMessage());
        }
        Flash::ok('Document root set to ' . realpath($path) . '.');
        self::backTo($d);
    }

    /**
     * "Run AutoSSL": re-checks DNS and makes Caddy (re)issue the Let's
     * Encrypt certificate now - after DNS was fixed, for an expired or
     * stuck certificate. Rewriting the site and reloading starts
     * certificate management for it afresh.
     */
    public static function autossl(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $d = self::owned($me, (int) ($params['id'] ?? 0));
        $name = (string) $d['domain_name'];
        if (!SslService::resolvesHere($name)) {
            $where = SslService::addresses($name);
            self::backTo($d, "$name resolves to " . ($where ? implode(', ', $where) : 'nothing') . ', not this server (' . Config::SERVER_IP
                . "). Let's Encrypt can only issue once its DNS points here.");
        }
        try {
            VhostService::create($name, (string) $d['php_version'], 'letsencrypt', true, false);
            Database::app()->prepare("UPDATE domains SET ssl_mode = 'letsencrypt' WHERE id = ?")->execute([$d['id']]);
        } catch (Throwable $e) {
            self::backTo($d, 'Could not update the site: ' . $e->getMessage());
        }
        Flash::ok("AutoSSL started for $name - the certificate normally arrives within a minute. Reload this page to see it.");
        self::backTo($d);
    }

    /** cPanel > Domains > domain > Routes: .htaccess, translated rules, rules in use. */
    public static function routes(array $params): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $d = self::owned($me, (int) ($params['id'] ?? 0));
        View::render('cpanel/routes', [
            'title' => 'Routes · ' . $d['domain_name'],
            'd' => $d,
            'o' => RoutesService::overview($d),
            'last' => RoutesService::lastResult((string) $d['domain_name']),
            'pending' => RoutesService::pending((string) $d['domain_name']),
        ], 'cpanel');
    }

    public static function routesSave(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $d = self::owned($me, (int) ($params['id'] ?? 0));
        $action = (string) ($_POST['action'] ?? 'save');
        $review = false;
        if ($action === 'generated') {
            $g = RoutesService::overview($d)['generated'];
            [$route, $site, $review] = [(string) $g['route'], (string) $g['site'], (bool) $g['needs_review']];
        } elseif ($action === 'reset') {
            [$route, $site] = ['', ''];
        } else {
            [$route, $site] = [(string) ($_POST['route'] ?? ''), (string) ($_POST['site'] ?? '')];
        }
        $errors = RoutesService::queueSave($d, $route, $site, $review);
        if ($errors) {
            Flash::error('Not saved: ' . implode(' ', array_slice($errors, 0, 5)));
        } else {
            Flash::ok($action === 'reset' ? 'Resetting to the default routing...' : 'Applying the rules - Caddy checks them first; the result shows below in a few seconds.');
        }
        header('Location: /cpanel/domains/' . (int) $d['id'] . '/routes');
        exit;
    }

    public static function aliasAdd(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $d = self::owned(Auth::user(), (int) ($params['id'] ?? 0));
        try {
            DomainAliasService::add($d, (string) ($_POST['alias'] ?? ''));
        } catch (Throwable $e) {
            self::backTo($d, $e->getMessage());
        }
        Flash::ok('Alias added - point its DNS here (nameservers or an A record) and it serves this site.');
        self::backTo($d);
    }

    public static function aliasRemove(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $d = self::owned(Auth::user(), (int) ($params['id'] ?? 0));
        try {
            DomainAliasService::remove($d, (string) ($_POST['alias'] ?? ''));
        } catch (Throwable $e) {
            self::backTo($d, $e->getMessage());
        }
        Flash::ok('Alias removed.');
        self::backTo($d);
    }

    /** Exposed files: archives, dumps, backups, logs reachable over the web. */
    public static function exposed(array $params): void
    {
        Auth::requireRole(['user']);
        $d = self::owned(Auth::user(), (int) ($params['id'] ?? 0));
        View::render('cpanel/exposed', [
            'title' => 'Exposed files · ' . $d['domain_name'],
            'd' => $d,
            'scan' => self::exposureScan($d),
            'docroot' => VhostService::effectiveDocroot((string) $d['domain_name']),
            'private' => VhostService::siteDir((string) $d['domain_name']) . '/private',
        ], 'cpanel');
    }

    /** @return array{items: list<array>, truncated: bool} */
    private static function exposureScan(array $d): array
    {
        try {
            return (array) PoolClient::call((string) $d['domain_name'], 'exposure_scan', ['docroot' => VhostService::effectiveDocroot((string) $d['domain_name'])])['scan'];
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            return ['items' => [], 'truncated' => false];
        }
    }

    public static function makePrivate(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $d = self::owned(Auth::user(), (int) ($params['id'] ?? 0));
        $moved = [];
        try {
            foreach (array_slice((array) ($_POST['paths'] ?? []), 0, 200) as $rel) {
                $moved[] = (string) PoolClient::call((string) $d['domain_name'], 'make_private', ['docroot' => VhostService::effectiveDocroot((string) $d['domain_name']), 'rel' => (string) $rel])['dest'];
            }
            Flash::ok(count($moved) . ' moved out of the web folder into ' . VhostService::siteDir((string) $d['domain_name']) . '/private/.');
        } catch (Throwable $e) {
            Flash::error(($moved ? count($moved) . ' moved, then: ' : '') . $e->getMessage());
        }
        header('Location: /cpanel/domains/' . (int) $d['id'] . '/exposed');
        exit;
    }

    public static function phpSettings(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $d = self::owned($me, (int) ($params['id'] ?? 0));
        try {
            PhpSettingsService::save($d, $_POST);
        } catch (Throwable $e) {
            self::backTo($d, $e->getMessage());
        }
        Flash::ok('PHP settings saved - they apply to the next request.');
        self::backTo($d);
    }

    /** Clears the domain's cached PHP code (OPcache), static file cache, and its page/object cache when enabled. */
    public static function clearCache(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $d = self::owned($me, (int) ($params['id'] ?? 0));
        $n = CacheService::clearDomain($d);
        $parts = ["{$n['opcache']} cached PHP files", "{$n['static']} cached static files"];
        if ($n['pages'] !== null) {
            $parts[] = "{$n['pages']} cached pages";
        }
        if ($n['objects'] !== null) {
            $parts[] = "{$n['objects']} object cache keys";
        }
        Flash::ok("Cache cleared for {$d['domain_name']}: " . implode(', ', $parts) . '.');
        self::backTo($d);
    }

    private static function owned(array $me, int $id): array
    {
        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $me['id']]);
        $d = $stmt->fetch();
        if (!$d) {
            Flash::error('Domain not found.');
            header('Location: /cpanel/domains');
            exit;
        }
        return $d;
    }

    private static function backTo(array $d, ?string $error = null): never
    {
        if ($error !== null) {
            Flash::error($error);
        }
        header('Location: /cpanel/domains/' . (int) $d['id']);
        exit;
    }
}
