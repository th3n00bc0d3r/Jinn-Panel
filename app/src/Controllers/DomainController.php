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
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain)) {
            Flash::error('Enter a valid domain name, e.g. example.com');
            header('Location: /cpanel/domains');
            exit;
        }

        $pdo = Database::app();
        $chk = $pdo->prepare('SELECT id FROM domains WHERE domain_name = ?');
        $chk->execute([$domain]);
        if ($chk->fetch()) {
            Flash::error('That domain is already registered on this server.');
            header('Location: /cpanel/domains');
            exit;
        }

        $sslMode = SslService::resolveMode($sslRequested, $domain);
        try {
            $docroot = VhostService::create($domain, $phpVersion, $sslMode);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error('Could not create the web vhost: ' . $e->getMessage());
            header('Location: /cpanel/domains');
            exit;
        }

        $dnsOk = true;
        try {
            DnsService::createZone($domain);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $dnsOk = false;
        }

        $stmt = $pdo->prepare('INSERT INTO domains (user_id, domain_name, docroot, dns_provisioned, php_version, php_port, ssl_mode) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $me['id'], $domain, $docroot, $dnsOk ? 1 : 0,
            $phpVersion, $phpVersion === 'default' ? null : PhpVersionService::port($phpVersion),
            $sslMode,
        ]);

        $sslNote = $sslMode === 'letsencrypt'
            ? ' A Let\'s Encrypt certificate is being issued.'
            : ($sslRequested === 'auto' ? ' It uses a self-signed certificate until its DNS points here, then switches to Let\'s Encrypt automatically.' : '');
        Flash::ok("Domain \"$domain\" is live" . ($dnsOk ? '.' : ', but DNS zone provisioning failed (web still works).') . $sslNote);
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
            // Re-creating the vhost fragment(s) is safe/idempotent and
            // handles every combination (version changed, SSL mode
            // changed, moving off an alt version back to default, ...).
            if ($domain['php_version'] !== 'default' && $domain['php_version'] !== $phpVersion) {
                VhostService::remove($domain['domain_name'], $domain['php_version']);
            }
            VhostService::create($domain['domain_name'], $phpVersion, $sslMode);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error('Could not update the vhost: ' . $e->getMessage());
            header('Location: /cpanel/domains');
            exit;
        }

        $upd = $pdo->prepare('UPDATE domains SET php_version = ?, php_port = ?, ssl_mode = ? WHERE id = ?');
        $upd->execute([$phpVersion, $phpVersion === 'default' ? null : PhpVersionService::port($phpVersion), $sslMode, $id]);

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
        $pdo->prepare('DELETE FROM email_accounts WHERE domain_id = ?')->execute([$id]);

        $del = $pdo->prepare('DELETE FROM domains WHERE id = ?');
        $del->execute([$id]);

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
}
