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
        View::render('cpanel/domains', [
            'title' => 'Domains',
            'domains' => $stmt->fetchAll(),
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
        $sslMode = ($_POST['ssl_mode'] ?? '') === 'letsencrypt' ? 'letsencrypt' : 'self_signed';

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
            ? ' Requesting a Let\'s Encrypt certificate - this only succeeds if the domain publicly resolves to this server on 80/443.'
            : '';
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
        $sslMode = ($_POST['ssl_mode'] ?? '') === 'letsencrypt' ? 'letsencrypt' : 'self_signed';

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

        $del = $pdo->prepare('DELETE FROM domains WHERE id = ?');
        $del->execute([$id]);

        Flash::ok("Domain \"{$domain['domain_name']}\" removed. Files were left in place on disk.");
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
