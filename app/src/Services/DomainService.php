<?php
declare(strict_types=1);

/**
 * Adds a domain to a hosting account: DNS zone, the domains row, the vhost,
 * its folder and PHP pool, SFTP folders. Used by cPanel > Domains and by the
 * provisioning API (createacct).
 */
final class DomainService
{
    /**
     * @return array{error: ?string, dns: bool, ssl_mode: string}
     *         error = why nothing was added (null when the domain is set up)
     */
    public static function add(int $userId, string $domain, string $phpVersion = 'default', string $sslRequested = 'auto'): array
    {
        $domain = strtolower(trim($domain));
        if (!Quota::withinLimit($userId, 'domains')) {
            return ['error' => 'You have reached your package\'s domain limit.', 'dns' => false, 'ssl_mode' => ''];
        }
        if (($problem = DomainPolicy::problem($domain, $userId)) !== null) {
            return ['error' => $problem, 'dns' => false, 'ssl_mode' => ''];
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
        $stmt = $pdo->prepare('INSERT INTO domains (user_id, domain_name, docroot, dns_provisioned, php_version, php_port, ssl_mode, ssl_pinned) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)');
        $stmt->execute([$userId, $domain, VhostService::docroot($domain), $dnsOk ? 1 : 0, $phpVersion, $sslMode, SslService::pinned($sslRequested) ? 1 : 0]);
        try {
            VhostService::create($domain, $phpVersion, $sslMode);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $pdo->prepare('DELETE FROM domains WHERE domain_name = ?')->execute([$domain]);
            return ['error' => 'Could not create the web vhost: ' . $e->getMessage(), 'dns' => $dnsOk, 'ssl_mode' => $sslMode];
        }
        // Its folder (owned by the account, with a placeholder page) and PHP pool.
        AccountRuntime::sync($userId);
        try {
            SftpService::syncAccountFolders($userId);
        } catch (Throwable $e) {
            error_log('sftp folders: ' . $e->getMessage());
        }
        return ['error' => null, 'dns' => $dnsOk, 'ssl_mode' => $sslMode];
    }
}
