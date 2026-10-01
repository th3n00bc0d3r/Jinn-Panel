<?php
declare(strict_types=1);

/**
 * Imports the records of a cPanel zone file (dnszones/<domain>.db in a
 * cPanel backup) into a panel DNS zone, keeping what the domain actually
 * uses and dropping what only made sense on cPanel:
 *
 *  - always skipped: SOA and apex NS (generated from the nameserver
 *    settings), cPanel service hosts (cpanel, whm, webmail, webdisk,
 *    cpcontacts, cpcalendars, autodiscover/autoconfig pointing at the old
 *    server), CalDAV/CardDAV/autodiscover SRV+TXT, _cpanel-dcv and
 *    _acme-challenge tokens, cPanel's own DKIM key (default._domainkey),
 *    the internal names of addon domains (<addon>.<main>), and records the
 *    panel already has (apex/www A to this server, ns1/ns2 glue);
 *  - mail hosted on the old cPanel server (apex MX -> the domain itself or
 *    mail.<domain>): its MX, SPF and DMARC are skipped too, so the panel's
 *    own mail records apply;
 *  - mail hosted elsewhere (Google, Forward Email, ...): the panel's default
 *    apex MX is replaced with the imported MX, and SPF/DKIM/DMARC/
 *    verification records are kept.
 *
 * Zone files are untrusted input: every record goes through
 * DnsService::addRecord()'s validation.
 */
final class CpanelZoneImporter
{
    private const CPANEL_HOSTS = ['cpanel', 'whm', 'webmail', 'webdisk', 'cpcontacts', 'cpcalendars'];
    private const CPANEL_PREFIXES = ['_cpanel-dcv-test-record', '_acme-challenge', '_caldav._tcp', '_caldavs._tcp', '_carddav._tcp', '_carddavs._tcp', '_autodiscover._tcp'];

    /**
     * Parses cPanel's zone-file dialect: one record per line, owner names
     * absolute (trailing dot) or relative to the zone, optional TTL/class,
     * comments after ';', multi-line parentheses (only SOA uses them).
     *
     * @return array<int, array{name:string, ttl:?int, type:string, rdata:string}>
     */
    public static function parse(string $text, string $zone): array
    {
        $zone = strtolower(rtrim($zone, '.'));
        $out = [];
        $buffer = '';
        $depth = 0;
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = self::stripComment($line);
            $depth += substr_count($line, '(') - substr_count($line, ')');
            $buffer .= ' ' . $line;
            if ($depth > 0) {
                continue;
            }
            $entry = trim(str_replace(['(', ')'], ' ', $buffer));
            $buffer = '';
            $depth = 0;
            if ($entry === '' || $entry[0] === '$') {
                continue;
            }
            if (!preg_match('/^(\S+)\s+(?:(\d+)\s+)?(?:IN\s+)?(A|AAAA|CNAME|MX|TXT|SRV|CAA|NS|SOA)\s+(.+)$/i', $entry, $m)) {
                continue;
            }
            $owner = strtolower($m[1]);
            if ($owner === '@' || $owner === "$zone.") {
                $name = '@';
            } elseif (str_ends_with($owner, ".$zone.")) {
                $name = substr($owner, 0, -strlen($zone) - 2);
            } elseif (str_ends_with($owner, '.')) {
                continue; // outside this zone
            } else {
                $name = $owner;
            }
            $out[] = ['name' => $name, 'ttl' => $m[2] !== '' ? (int) $m[2] : null, 'type' => strtoupper($m[3]), 'rdata' => trim($m[4])];
        }
        return $out;
    }

    /**
     * Imports $text into the panel zone $zoneId (no publish - the caller
     * publishes). $otherDomains: every domain hosted on the panel, used to
     * drop cPanel's internal addon-domain names.
     *
     * @return array{external_mail:bool, added:string[], skipped:string[], failed:string[]}
     */
    public static function import(int $zoneId, string $text, array $otherDomains): array
    {
        $zone = DnsService::findZone($zoneId) ?? throw new InvalidArgumentException('Zone not found.');
        $zoneName = (string) $zone['zone_name'];
        $records = self::parse($text, $zoneName);
        $serverIp = Config::SERVER_IP;

        $apexMx = array_map(fn($r) => strtolower(rtrim(preg_split('/\s+/', $r['rdata'])[1] ?? '', '.')),
            array_filter($records, fn($r) => $r['type'] === 'MX' && $r['name'] === '@'));
        $local = static fn(string $host) => $host === $zoneName || $host === "mail.$zoneName";
        $externalMail = $apexMx !== [] && array_filter($apexMx, fn($h) => !$local($h)) !== [];

        $ns = DnsService::nameservers();
        $glue = array_filter([self::relative($ns['ns1_host'], $zoneName), self::relative($ns['ns2_host'], $zoneName)]);
        $others = array_filter(array_map('strtolower', $otherDomains), fn($d) => $d !== $zoneName);

        $report = ['external_mail' => (bool) $externalMail, 'added' => [], 'skipped' => [], 'failed' => []];
        $pdo = Database::app();
        if ($externalMail) {
            // The panel seeds "@ MX <domain>." for every new zone; external mail replaces it.
            $pdo->prepare("DELETE FROM dns_records WHERE zone_id = ? AND name = '@' AND type = 'MX' AND content = ?")
                ->execute([$zoneId, "$zoneName."]);
        }

        foreach ($records as $r) {
            $label = "{$r['name']} {$r['type']}";
            $reason = self::skipReason($r, $zoneName, $serverIp, $externalMail, $glue, $others);
            if ($reason !== null) {
                $report['skipped'][] = "$label ($reason)";
                continue;
            }
            [$content, $priority] = self::content($r);
            try {
                DnsService::addRecord($zoneId, [
                    'name' => $r['name'], 'type' => $r['type'], 'ttl' => (string) max(60, min(604800, $r['ttl'] ?? DnsService::DEFAULT_TTL)),
                    'priority' => $priority, 'content' => $content,
                ], false);
                $report['added'][] = $label;
            } catch (InvalidArgumentException $e) {
                if (str_contains($e->getMessage(), 'already exists')) {
                    $report['skipped'][] = "$label (already present)";
                } else {
                    $report['failed'][] = "$label: " . $e->getMessage();
                }
            }
        }
        return $report;
    }

    private static function skipReason(array $r, string $zone, string $serverIp, bool $externalMail, array $glue, array $others): ?string
    {
        $name = $r['name'];
        $type = $r['type'];
        $first = explode('.', $name)[0];
        $value = strtolower(trim($r['rdata']));

        if ($type === 'SOA' || ($type === 'NS' && $name === '@')) {
            return 'managed by the panel';
        }
        foreach ($others as $d) {
            if ($name === $d || str_ends_with($name, ".$d")) {
                return "cPanel's internal name for addon domain $d";
            }
        }
        if (in_array($name, $glue, true)) {
            return 'nameserver glue is managed by the panel';
        }
        foreach (self::CPANEL_PREFIXES as $p) {
            if ($name === $p || str_starts_with($name, "$p.")) {
                return 'cPanel service record';
            }
        }
        if (in_array($first, self::CPANEL_HOSTS, true) && in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            return 'cPanel service host';
        }
        // Subdomain sites carry the same cPanel boilerplate under their own
        // name (autoconfig.<sub>, default._domainkey.<sub>, ...).
        if (in_array($first, ['autodiscover', 'autoconfig'], true) && ($type === 'A' || rtrim($value, '.') === $zone)) {
            return 'cPanel mail autoconfig';
        }
        if ($name === 'default._domainkey' || str_starts_with($name, 'default._domainkey.')) {
            return "the old server's DKIM key";
        }
        if ($name !== '@' && $type === 'TXT' && str_contains($value, 'v=spf1') && str_contains($value, "ip4:$serverIp") && !str_contains($value, 'include:')) {
            return "cPanel's default SPF for a subdomain";
        }
        if (in_array($name, ['@', 'www'], true) && ($type === 'A' && $value === $serverIp || $type === 'CNAME' && rtrim($value, '.') === $zone)) {
            return 'the panel already points it at this server';
        }
        if (!$externalMail) {
            $isSpf = $type === 'TXT' && $name === '@' && str_contains($value, 'v=spf1');
            if (($type === 'MX' && $name === '@') || $isSpf || $name === '_dmarc') {
                return 'mail is hosted on this server';
            }
        }
        return null;
    }

    /** @return array{0:string, 1:string} content, priority */
    private static function content(array $r): array
    {
        $rdata = $r['rdata'];
        switch ($r['type']) {
            case 'TXT':
                preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $rdata, $m);
                $joined = $m[1] ? implode('', $m[1]) : $rdata;
                return [preg_replace('/\\\\(.)/', '$1', $joined), ''];
            case 'MX':
                $p = preg_split('/\s+/', $rdata, 2);
                return [$p[1] ?? '', $p[0]];
            case 'SRV':
                $p = preg_split('/\s+/', $rdata, 2);
                return [$p[1] ?? '', $p[0]];
            default:
                return [$rdata, ''];
        }
    }

    private static function stripComment(string $line): string
    {
        $inQuote = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === '"') {
                $inQuote = !$inQuote;
            } elseif ($c === ';' && !$inQuote) {
                return substr($line, 0, $i);
            }
        }
        return $line;
    }

    private static function relative(string $host, string $zone): ?string
    {
        $host = strtolower(rtrim($host, '.'));
        return str_ends_with($host, ".$zone") ? substr($host, 0, -strlen($zone) - 1) : null;
    }
}
