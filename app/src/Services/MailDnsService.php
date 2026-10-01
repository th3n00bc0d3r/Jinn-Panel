<?php
declare(strict_types=1);

/**
 * DNS, web endpoints and TLS for domains whose mail lives on this server.
 *
 * Stalwart knows which records a mail domain needs (DKIM keys it generates
 * and rotates, SPF, DMARC, MTA-STS, TLS reporting, SRV, autoconfig) and
 * publishes them as a zone-file snippet per domain. syncDomain() copies
 * that set into the panel's zone as records tagged managed='mail', so key
 * rotations are picked up on the next sync, with these adjustments:
 *  - only when the domain's MX points at this server (mail hosted elsewhere,
 *    e.g. Google, gets nothing - and loses records an earlier sync added);
 *  - the customer's own SPF/DMARC/records always win over Stalwart's;
 *  - SPF is "mx a ~all" and DMARC starts at p=none rather than Stalwart's
 *    strict defaults, so existing senders don't bounce on day one.
 *
 * Also: the Caddy site that answers mta-sts./autoconfig./autodiscover.
 * <domain> (proxied to Stalwart, only the paths those services use), and
 * Stalwart's own TLS certificate - copied from the Let's Encrypt one Caddy
 * keeps for the server hostname, so IMAP/SMTP clients get a trusted cert.
 *
 * Runs as frankenphp (mail-dns-sync.php: daily timer, install.sh, and
 * after a mail domain is created).
 */
final class MailDnsService
{
    public const SERVICES_FILE = 'zz-mail-services.caddyfile';
    private const CADDY_CERTS = '/var/lib/frankenphp/.local/share/caddy/certificates';
    /** Subdomains served by mail-services.caddyfile (they CNAME to the server hostname). */
    private const SERVICE_LABELS = ['mta-sts', 'autoconfig', 'autodiscover', 'ua-auto-config'];

    /** @return list<string> one line per domain / step, for logs */
    public static function syncAll(): array
    {
        $log = [];
        $rows = Database::app()->query('SELECT * FROM domains WHERE mail_domain_id IS NOT NULL ORDER BY domain_name')->fetchAll();
        foreach ($rows as $d) {
            try {
                $log[] = "{$d['domain_name']}: " . self::syncDomain($d);
            } catch (Throwable $e) {
                $log[] = "{$d['domain_name']}: FAILED - " . $e->getMessage();
            }
        }
        try {
            $log[] = self::writeServicesSite() ? 'mail services site: updated' : 'mail services site: unchanged';
        } catch (Throwable $e) {
            $log[] = 'mail services site: FAILED - ' . $e->getMessage();
        }
        try {
            $log = array_merge($log, MailService::retryPendingDomainDeletes());
        } catch (Throwable $e) {
            $log[] = 'pending mail domain deletes: FAILED - ' . $e->getMessage();
        }
        try {
            $log[] = 'mail listeners: ' . MailService::ensureSubmissionListener();
        } catch (Throwable $e) {
            $log[] = 'mail listeners: FAILED - ' . $e->getMessage();
        }
        try {
            $log[] = 'mail TLS: ' . self::syncTls();
        } catch (Throwable $e) {
            $log[] = 'mail TLS: FAILED - ' . $e->getMessage();
        }
        return $log;
    }

    /**
     * @param array<string,mixed> $domain a domains row with mail_domain_id
     */
    public static function syncDomain(array $domain): string
    {
        $name = strtolower((string) $domain['domain_name']);
        $zone = DnsService::findZoneByName($name);
        if ($zone === null) {
            return 'no zone on this server';
        }
        $zoneId = (int) $zone['id'];
        $host = strtolower(rtrim(Config::SERVER_HOSTNAME, '.'));

        if (!self::mailIsLocal($zoneId, $name, $host)) {
            $changed = DnsService::syncManaged($zoneId, 'mail', []);
            return $changed ? 'MX points elsewhere - removed the mail records' : 'MX points elsewhere - skipped';
        }
        // The default MX the zone template (or a cPanel import) wrote points
        // at the domain itself; Stalwart's MX - the hostname its certificate
        // and MTA-STS policy name - replaces it.
        Database::app()->prepare("DELETE FROM dns_records WHERE zone_id = ? AND name = '@' AND type = 'MX' AND managed IS NULL")->execute([$zoneId]);

        $customer = DnsService::customerNames($zoneId, 'mail');
        $taken = function (string $rel, string $type) use ($customer): bool {
            $types = $customer[$rel] ?? [];
            return $type === 'CNAME' ? $types !== [] : (in_array('CNAME', $types, true) || in_array($type, $types, true));
        };
        $hasTxt = function (string $rel, string $prefix) use ($zoneId): bool {
            $stmt = Database::app()->prepare("SELECT content FROM dns_records WHERE zone_id = ? AND name = ? AND type = 'TXT' AND managed IS NULL");
            $stmt->execute([$zoneId, $rel]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) {
                if (stripos((string) $c, $prefix) === 0) {
                    return true;
                }
            }
            return false;
        };

        $desired = [];
        foreach (self::parseZoneSnippet(MailService::dnsZoneFile((string) $domain['mail_domain_id'])) as [$fqdn, $type, $content, $priority]) {
            $rel = self::relative($fqdn, $name);
            if ($rel === null) {
                continue; // e.g. the hostname's own SPF, in another zone
            }
            if ($type === 'MX') {
                $desired[] = ['@', 'MX', $content, $priority];
                continue;
            }
            if ($type === 'TXT' && $rel === '@' && stripos($content, 'v=spf1') === 0) {
                if (!$hasTxt('@', 'v=spf1')) {
                    $desired[] = ['@', 'TXT', 'v=spf1 mx a ~all', null];
                }
                continue;
            }
            if ($type === 'TXT' && $rel === '_dmarc') {
                if (!$hasTxt('_dmarc', 'v=DMARC1')) {
                    $desired[] = ['_dmarc', 'TXT', "v=DMARC1; p=none; rua=mailto:postmaster@$name", null];
                }
                continue;
            }
            if ($taken($rel, $type)) {
                continue;
            }
            $desired[] = [$rel, $type, $content, $priority];
        }
        // mail.<domain> for people (and webmail) who expect it.
        if (!isset($customer['mail'])) {
            $desired[] = ['mail', 'A', Config::SERVER_IP, null];
        }

        $changed = DnsService::syncManaged($zoneId, 'mail', $desired);
        return ($changed ? 'updated' : 'unchanged') . ' (' . count($desired) . ' records)';
    }

    /**
     * Whether every MX of the zone's apex is this server: the hostname, or a
     * name in the zone whose A record (or CNAME chain inside the zone) is
     * this server. No MX at all counts as local (the domain has mailboxes).
     */
    private static function mailIsLocal(int $zoneId, string $zoneName, string $host): bool
    {
        $stmt = Database::app()->prepare("SELECT content FROM dns_records WHERE zone_id = ? AND name = '@' AND type = 'MX' AND managed IS NULL");
        $stmt->execute([$zoneId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $target) {
            if (!self::pointsHere($zoneId, $zoneName, rtrim(strtolower((string) $target), '.'), $host, 0)) {
                return false;
            }
        }
        return true;
    }

    private static function pointsHere(int $zoneId, string $zoneName, string $fqdn, string $host, int $depth): bool
    {
        if ($fqdn === $host) {
            return true;
        }
        $rel = self::relative($fqdn . '.', $zoneName);
        if ($rel === null || $depth > 3) {
            return false;
        }
        $stmt = Database::app()->prepare("SELECT type, content FROM dns_records WHERE zone_id = ? AND name = ? AND type IN ('A', 'CNAME')");
        $stmt->execute([$zoneId, $rel]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            if ($r['type'] === 'A' && $r['content'] !== Config::SERVER_IP) {
                return false;
            }
            if ($r['type'] === 'CNAME' && !self::pointsHere($zoneId, $zoneName, rtrim(strtolower((string) $r['content']), '.'), $host, $depth + 1)) {
                return false;
            }
        }
        return $rows !== [];
    }

    /**
     * Parses Stalwart's zone-file snippet (one record per line, TXT values
     * possibly split over a parenthesised multi-line group).
     *
     * @return list<array{0:string,1:string,2:string,3:?int}> fqdn, type, content (as DnsService stores it), priority
     */
    public static function parseZoneSnippet(string $text): array
    {
        // Join "( ... )" groups onto one line.
        $text = preg_replace_callback('/\(\s*(.*?)\s*\)/s', fn($m) => preg_replace('/\s*\n\s*/', ' ', $m[1]), $text);
        $out = [];
        foreach (preg_split('/\r?\n/', (string) $text) as $line) {
            if (!preg_match('/^(\S+)\s+(?:\d+\s+)?IN\s+(A|AAAA|CNAME|MX|TXT|SRV|CAA)\s+(.+?)\s*$/i', trim($line), $m)) {
                continue;
            }
            [$fqdn, $type, $rdata] = [strtolower($m[1]), strtoupper($m[2]), $m[3]];
            $priority = null;
            switch ($type) {
                case 'TXT':
                    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $rdata, $q);
                    $rdata = stripcslashes(implode('', $q[1]));
                    break;
                case 'MX':
                    [$p, $t] = preg_split('/\s+/', $rdata, 2) + [1 => ''];
                    $priority = (int) $p;
                    $rdata = strtolower($t);
                    break;
                case 'SRV':
                    $parts = preg_split('/\s+/', $rdata);
                    if (count($parts) !== 4) {
                        continue 2;
                    }
                    $priority = (int) $parts[0];
                    $rdata = (int) $parts[1] . ' ' . (int) $parts[2] . ' ' . strtolower($parts[3]);
                    break;
                case 'CNAME':
                    $rdata = strtolower($rdata);
                    break;
            }
            $out[] = [$fqdn, $type, $rdata, $priority];
        }
        return $out;
    }

    private static function relative(string $fqdn, string $zone): ?string
    {
        $fqdn = rtrim(strtolower($fqdn), '.');
        if ($fqdn === $zone) {
            return '@';
        }
        return str_ends_with($fqdn, '.' . $zone) ? substr($fqdn, 0, -strlen($zone) - 1) : null;
    }

    /** Syncs one domain right after its mail domain was created, then the services site. */
    public static function syncAfterMailDomain(int $domainId): void
    {
        try {
            $stmt = Database::app()->prepare('SELECT * FROM domains WHERE id = ? AND mail_domain_id IS NOT NULL');
            $stmt->execute([$domainId]);
            if ($d = $stmt->fetch()) {
                self::syncDomain($d);
                self::writeServicesSite();
            }
        } catch (Throwable $e) {
            error_log('mail DNS sync for domain ' . $domainId . ': ' . $e->getMessage()); // the daily sync retries
        }
    }

    /**
     * Writes the Caddy site for the mta-sts./autoconfig./autodiscover. names
     * the mail sync manages, and reloads if it changed. Only the paths those
     * services use reach Stalwart; everything else is a 404 (not Stalwart's
     * admin or JMAP).
     */
    public static function writeServicesSite(): bool
    {
        $in = implode(',', array_fill(0, count(self::SERVICE_LABELS), '?'));
        $stmt = Database::app()->prepare("SELECT CONCAT(r.name, '.', z.zone_name) FROM dns_records r JOIN dns_zones z ON z.id = r.zone_id
                                           WHERE r.managed = 'mail' AND r.type = 'CNAME' AND r.name IN ($in) ORDER BY 1");
        $stmt->execute(self::SERVICE_LABELS);
        $hosts = array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), fn($h) => preg_match('/^[a-z0-9.-]+$/', (string) $h)));
        $webmail = self::webmailHosts();
        $file = Config::VHOSTS_CADDY_DIR . '/' . self::SERVICES_FILE;
        $conf = '';
        if ($hosts) {
            $sites = implode(', ', array_map(fn($h) => "https://$h", $hosts));
            $conf = <<<CADDY
            # Generated by JinnPanel (MailDnsService) - mail client autoconfig and
            # MTA-STS for domains whose mail is on this server.
            $sites {
            	@mail path /.well-known/mta-sts.txt /mail/config-v1.1.xml /.well-known/autoconfig/* /autodiscover/* /Autodiscover/* /.well-known/ua-auto-config*
            	handle @mail {
            		reverse_proxy 127.0.0.1:8080
            	}
            	respond 404
            }

            CADDY;
        }
        if ($webmail) {
            $sites = implode(', ', array_map(fn($h) => "https://$h", $webmail));
            $conf .= <<<CADDY

            # Webmail (Cypht, its own FrankenPHP instance - see install.sh).
            $sites {
            	encode zstd br gzip
            	reverse_proxy 127.0.0.1:8009
            }

            CADDY;
        }
        $old = is_file($file) ? (string) file_get_contents($file) : '';
        if ($old === $conf) {
            return false;
        }
        if ($conf === '') {
            @unlink($file);
        } else {
            file_put_contents($file, $conf);
        }
        VhostService::reload();
        return true;
    }

    /**
     * mail.<domain> for every domain with a mailbox, when that name
     * resolves here (otherwise Caddy would retry its certificate forever)
     * and isn't a hosted site of its own.
     *
     * @return list<string>
     */
    public static function webmailHosts(): array
    {
        $rows = Database::app()->query(
            "SELECT DISTINCT CONCAT('mail.', d.domain_name) FROM domains d JOIN email_accounts e ON e.domain_id = d.id
              WHERE CONCAT('mail.', d.domain_name) NOT IN (SELECT domain_name FROM domains) ORDER BY 1"
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter($rows, fn($h) => preg_match('/^[a-z0-9.-]+$/', (string) $h) && SslService::resolvesHere((string) $h)));
    }

    /**
     * Gives Stalwart the Let's Encrypt certificate Caddy keeps for the server
     * hostname (its own is a self-signed placeholder), and replaces it after
     * each renewal.
     */
    public static function syncTls(): string
    {
        $host = strtolower(rtrim(Config::SERVER_HOSTNAME, '.'));
        $dir = null;
        foreach (glob(self::CADDY_CERTS . '/acme*/' . $host, GLOB_ONLYDIR) ?: [] as $d) {
            $dir = $d;
        }
        $crtFile = $dir !== null ? "$dir/$host.crt" : null;
        if ($crtFile === null || !is_readable($crtFile) || !is_readable("$dir/$host.key")) {
            return "no Let's Encrypt certificate for $host yet (Caddy gets one once $host resolves here)";
        }
        $pem = (string) file_get_contents($crtFile);
        $x509 = openssl_x509_parse($pem);
        if (!is_array($x509) || ($x509['validTo_time_t'] ?? 0) < time()) {
            return "the certificate in $crtFile is unreadable or expired";
        }
        return MailService::installCertificate($host, $pem, (string) file_get_contents("$dir/$host.key"), (int) $x509['validTo_time_t']);
    }
}
