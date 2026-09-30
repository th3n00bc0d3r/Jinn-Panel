<?php
declare(strict_types=1);

/**
 * Knot DNS zones, with the panel database as the source of truth.
 *
 * Every zone lives in dns_zones and every editable record in dns_records.
 * The zone file is rendered from those rows on each change and handed to
 * hostpanel-worker.php (root) to write, check and reload. Two reasons:
 *  - FrankenPHP can't write /var/lib/knot (knot:knot 0755), so writing the
 *    file from the web process silently produced no zone file at all.
 *  - Rendering from rows means "Re-provision" re-publishes what's there
 *    instead of resetting a zone to a template and dropping records someone
 *    added by hand.
 *
 * The SOA and the apex NS records are NOT stored per zone: they're generated
 * from the server's nameserver settings (WHM > DNS Zones), together with
 * in-zone glue for ns1/ns2, so changing nameservers is one save instead of an
 * edit in every zone.
 */
final class DnsService
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];
    public const DEFAULT_TTL = 3600;

    private const DOMAIN_RE = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/';
    private const LABEL_RE = '[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?';
    private const NS_KEYS = ['ns1_host', 'ns1_ip', 'ns2_host', 'ns2_ip'];

    // ------------------------------------------------------------------
    // Server zone + nameservers
    // ------------------------------------------------------------------

    /**
     * The zone that holds the server's own names (ns1/ns2, the hostname and
     * panel.<hostname>): the parent of the hostname, e.g. server.jinnhost.com
     * -> jinnhost.com. Overridable (dns_server_zone setting) for hostnames
     * where "drop the first label" is wrong, like host.example.co.uk being
     * the zone itself. Null for hostnames that can't be public (.local etc.).
     */
    public static function serverZoneName(): ?string
    {
        $override = self::setting('dns_server_zone');
        if ($override !== null && preg_match(self::DOMAIN_RE, $override)) {
            return $override;
        }
        $host = strtolower(rtrim(Config::SERVER_HOSTNAME, '.'));
        if (!preg_match(self::DOMAIN_RE, $host) || preg_match('/\.(local|localhost|test|internal|lan|invalid)$/', $host)) {
            return null;
        }
        $labels = explode('.', $host);
        return count($labels) >= 3 ? implode('.', array_slice($labels, 1)) : $host;
    }

    /** @return array{ns1_host:string, ns1_ip:string, ns2_host:string, ns2_ip:string} */
    public static function nameservers(): array
    {
        $base = self::serverZoneName() ?? strtolower(Config::SERVER_HOSTNAME);
        $ns = [
            'ns1_host' => 'ns1.' . $base,
            'ns1_ip' => Config::SERVER_IP,
            'ns2_host' => 'ns2.' . $base,
            'ns2_ip' => Config::SERVER_IP,
        ];
        foreach (self::NS_KEYS as $k) {
            $v = self::setting('dns_' . $k);
            if ($v !== null) {
                $ns[$k] = $v;
            }
        }
        return $ns;
    }

    public static function hasStoredNameservers(): bool
    {
        return self::setting('dns_ns1_host') !== null;
    }

    /**
     * @param array<string,mixed> $input keys ns1_host, ns1_ip, ns2_host, ns2_ip
     * @param bool $publish re-render every zone (their SOA/NS come from these)
     */
    public static function saveNameservers(array $input, bool $publish = true): void
    {
        $clean = [];
        foreach (['ns1', 'ns2'] as $n) {
            $host = self::normalizeHostname((string) ($input[$n . '_host'] ?? ''));
            if ($host === null) {
                throw new InvalidArgumentException(strtoupper($n) . ' hostname is not a valid hostname.');
            }
            $ip = trim((string) ($input[$n . '_ip'] ?? ''));
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new InvalidArgumentException(strtoupper($n) . ' IP must be an IPv4 address.');
            }
            $clean[$n . '_host'] = $host;
            $clean[$n . '_ip'] = $ip;
        }
        foreach ($clean as $k => $v) {
            self::putSetting('dns_' . $k, $v);
        }
        if ($publish) {
            self::publishAll();
        }
    }

    /**
     * Create (or top up) the server's own zone so the hostname, the panel
     * and the in-zone nameservers resolve as soon as the registrar's glue
     * points here - no hosts-file edits. Idempotent: existing records are
     * never touched, only missing required ones are added.
     */
    public static function ensureServerZone(): ?int
    {
        $zoneName = self::serverZoneName();
        if ($zoneName === null) {
            return null;
        }
        $ip = Config::SERVER_IP;
        $host = strtolower(rtrim(Config::SERVER_HOSTNAME, '.'));
        $hostRel = self::relativeName($host, $zoneName) ?? '@';
        $panelRel = $hostRel === '@' ? 'panel' : 'panel.' . $hostRel;

        $zone = self::findZoneByName($zoneName);
        $isNew = $zone === null;
        $zoneId = $isNew ? self::insertZone($zoneName, true) : (int) $zone['id'];
        if (!$isNew && (int) $zone['is_server_zone'] !== 1) {
            Database::app()->prepare('UPDATE dns_zones SET is_server_zone = 1 WHERE id = ?')->execute([$zoneId]);
        }

        $wanted = [[$hostRel, 'A', $ip, null], [$panelRel, 'A', $ip, null]];
        if ($isNew) {
            if ($hostRel !== '@') {
                $wanted[] = ['@', 'A', $ip, null];
            }
            $wanted[] = ['@', 'MX', $host . '.', 10];
        }
        self::seedMissing($zoneId, $wanted);
        self::publish($zoneId);
        return $zoneId;
    }

    // ------------------------------------------------------------------
    // Zones
    // ------------------------------------------------------------------

    /**
     * Zone for a hosted domain (cPanel > Domains, migrations, re-provision).
     * A new zone gets the same starting records the old template wrote; an
     * existing one is just re-published as it is.
     */
    public static function createZone(string $domain): void
    {
        $domain = self::normalizeZoneName($domain);
        $zone = self::findZoneByName($domain);
        if ($zone === null) {
            $zoneId = self::insertZone($domain, false);
            self::seedMissing($zoneId, [
                ['@', 'A', Config::SERVER_IP, null],
                ['www', 'A', Config::SERVER_IP, null],
                ['@', 'MX', $domain . '.', 10],
            ]);
        } else {
            $zoneId = (int) $zone['id'];
        }
        self::publish($zoneId);
    }

    /** A zone not tied to any hosting account (WHM > DNS Zones > Add zone). */
    public static function createStandaloneZone(string $name, bool $pointToServer): int
    {
        $name = self::normalizeZoneName($name);
        if (self::findZoneByName($name) !== null) {
            throw new InvalidArgumentException("A zone for $name already exists.");
        }
        $zoneId = self::insertZone($name, false);
        if ($pointToServer) {
            self::seedMissing($zoneId, [
                ['@', 'A', Config::SERVER_IP, null],
                ['www', 'A', Config::SERVER_IP, null],
            ]);
        }
        self::publish($zoneId);
        return $zoneId;
    }

    /** Called when a hosted domain is removed. The server's own zone is never removed this way. */
    public static function removeZone(string $domain): void
    {
        $domain = strtolower(trim($domain));
        $zone = self::findZoneByName($domain);
        if ($zone !== null && (int) $zone['is_server_zone'] === 1) {
            return;
        }
        if ($zone !== null) {
            Database::app()->prepare('DELETE FROM dns_zones WHERE id = ?')->execute([(int) $zone['id']]);
        }
        SystemWorkerService::enqueue("dns-{$domain}", ['type' => 'dns_remove', 'domain' => $domain]);
    }

    /** WHM delete: only standalone zones - a hosted domain's zone goes when the domain does. */
    public static function deleteZone(int $zoneId): void
    {
        $zone = self::findZone($zoneId) ?? throw new InvalidArgumentException('Zone not found.');
        if ((int) $zone['is_server_zone'] === 1) {
            throw new InvalidArgumentException("{$zone['zone_name']} is this server's own zone (nameservers, hostname, panel) and can't be deleted.");
        }
        if (!empty($zone['domain_id'])) {
            throw new InvalidArgumentException("{$zone['zone_name']} belongs to a hosted domain - remove the domain from its account instead.");
        }
        Database::app()->prepare('DELETE FROM dns_zones WHERE id = ?')->execute([$zoneId]);
        SystemWorkerService::enqueue('dns-' . $zone['zone_name'], ['type' => 'dns_remove', 'domain' => $zone['zone_name']]);
    }

    /** @return array<string,mixed>|null */
    public static function findZone(int $id): ?array
    {
        $stmt = Database::app()->prepare(
            'SELECT z.*, d.id AS domain_id, u.username AS owner_username
               FROM dns_zones z
               LEFT JOIN domains d ON d.domain_name = z.zone_name
               LEFT JOIN users u ON u.id = d.user_id
              WHERE z.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public static function findZoneByName(string $name): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM dns_zones WHERE zone_name = ?');
        $stmt->execute([strtolower(trim($name))]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function listZones(): array
    {
        return Database::app()->query(
            'SELECT z.*, d.id AS domain_id, u.username AS owner_username,
                    (SELECT COUNT(*) FROM dns_records r WHERE r.zone_id = z.id) AS record_count
               FROM dns_zones z
               LEFT JOIN domains d ON d.domain_name = z.zone_name
               LEFT JOIN users u ON u.id = d.user_id
              ORDER BY z.is_server_zone DESC, z.zone_name'
        )->fetchAll();
    }

    // ------------------------------------------------------------------
    // Records
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public static function records(int $zoneId): array
    {
        $stmt = Database::app()->prepare(
            "SELECT * FROM dns_records WHERE zone_id = ?
              ORDER BY name = '@' DESC, name, FIELD(type, 'NS', 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'), id"
        );
        $stmt->execute([$zoneId]);
        return $stmt->fetchAll();
    }

    /**
     * Records generated from the nameserver settings: apex NS plus glue for
     * nameservers that live inside this zone. Shown read-only in WHM.
     *
     * @param list<array<string,mixed>> $records the zone's stored records
     * @return list<array<string,mixed>>
     */
    public static function managedRecords(array $zone, array $records): array
    {
        $ns = self::nameservers();
        $out = [];
        $hosts = array_values(array_unique([$ns['ns1_host'], $ns['ns2_host']]));
        foreach ($hosts as $h) {
            $out[] = ['name' => '@', 'ttl' => self::DEFAULT_TTL, 'type' => 'NS', 'priority' => null, 'content' => $h . '.', 'managed' => true];
        }
        $taken = array_column($records, 'name');
        $glueDone = [];
        foreach ([['ns1_host', 'ns1_ip'], ['ns2_host', 'ns2_ip']] as [$hk, $ik]) {
            $rel = self::relativeName($ns[$hk], $zone['zone_name']);
            if ($rel === null || $rel === '@' || in_array($rel, $taken, true) || isset($glueDone[$rel])) {
                continue;
            }
            $glueDone[$rel] = true;
            $out[] = ['name' => $rel, 'ttl' => self::DEFAULT_TTL, 'type' => 'A', 'priority' => null, 'content' => $ns[$ik], 'managed' => true];
        }
        return $out;
    }

    /** @param array<string,mixed> $in name, type, ttl, priority, content */
    public static function addRecord(int $zoneId, array $in): void
    {
        $zone = self::findZone($zoneId) ?? throw new InvalidArgumentException('Zone not found.');
        $zoneName = (string) $zone['zone_name'];

        $type = strtoupper(trim((string) ($in['type'] ?? '')));
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported record type.');
        }
        $name = self::normalizeRecordName((string) ($in['name'] ?? ''), $zoneName);

        $ttlRaw = trim((string) ($in['ttl'] ?? ''));
        $ttl = $ttlRaw === '' ? self::DEFAULT_TTL : (int) $ttlRaw;
        if ($ttl < 60 || $ttl > 604800) {
            throw new InvalidArgumentException('TTL must be between 60 and 604800 seconds.');
        }

        $raw = trim((string) ($in['content'] ?? ''));
        if ($raw === '') {
            throw new InvalidArgumentException('Value is required.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $raw)) {
            throw new InvalidArgumentException('Value can\'t contain line breaks or control characters.');
        }

        $priority = null;
        switch ($type) {
            case 'A':
                if (!filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    throw new InvalidArgumentException('An A record needs an IPv4 address.');
                }
                $content = $raw;
                break;
            case 'AAAA':
                if (!filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    throw new InvalidArgumentException('An AAAA record needs an IPv6 address.');
                }
                $content = strtolower($raw);
                break;
            case 'CNAME':
            case 'NS':
                $content = self::normalizeTarget($raw, $zoneName)
                    ?? throw new InvalidArgumentException("A $type record needs a hostname.");
                break;
            case 'MX':
                $priority = self::priority($in['priority'] ?? '');
                $content = self::normalizeTarget($raw, $zoneName)
                    ?? throw new InvalidArgumentException('An MX record needs a mail server hostname.');
                break;
            case 'SRV':
                $priority = self::priority($in['priority'] ?? '');
                $parts = preg_split('/\s+/', $raw) ?: [];
                if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])
                    || (int) $parts[0] > 65535 || (int) $parts[1] < 1 || (int) $parts[1] > 65535) {
                    throw new InvalidArgumentException('SRV value is "weight port target", e.g. 5 5060 sip.example.com');
                }
                $target = $parts[2] === '.' ? '.' : self::normalizeTarget($parts[2], $zoneName);
                if ($target === null) {
                    throw new InvalidArgumentException('SRV target must be a hostname.');
                }
                $content = (int) $parts[0] . ' ' . (int) $parts[1] . ' ' . $target;
                break;
            case 'TXT':
                if (strlen($raw) >= 2 && $raw[0] === '"' && substr($raw, -1) === '"') {
                    $raw = substr($raw, 1, -1);
                }
                if ($raw === '' || strlen($raw) > 2048) {
                    throw new InvalidArgumentException('TXT value must be 1-2048 characters.');
                }
                $content = $raw;
                break;
            case 'CAA':
                if (!preg_match('/^(?:(\d{1,3})\s+)?(issue|issuewild|iodef)\s+"?([^"\s]+)"?$/i', $raw, $m) || (int) ($m[1] ?: 0) > 255) {
                    throw new InvalidArgumentException('CAA value is e.g. 0 issue "letsencrypt.org"');
                }
                $content = sprintf('%d %s "%s"', (int) ($m[1] ?: 0), strtolower($m[2]), $m[3]);
                break;
            default:
                throw new InvalidArgumentException('Unsupported record type.');
        }

        if ($type === 'NS' && $name === '@') {
            throw new InvalidArgumentException('The zone\'s own NS records come from the nameserver settings on WHM > DNS Zones. Use NS here only to delegate a subdomain.');
        }
        if ($type === 'CNAME' && $name === '@') {
            throw new InvalidArgumentException('A CNAME can\'t sit at the zone apex (@) - use an A or AAAA record instead.');
        }

        $pdo = Database::app();
        $stmt = $pdo->prepare('SELECT type, content, priority FROM dns_records WHERE zone_id = ? AND name = ?');
        $stmt->execute([$zoneId, $name]);
        $existing = $stmt->fetchAll();
        $existingTypes = array_column($existing, 'type');
        if ($type === 'CNAME' && $existing) {
            throw new InvalidArgumentException("\"$name\" already has other records - a CNAME must be the only record for its name.");
        }
        if ($type !== 'CNAME' && in_array('CNAME', $existingTypes, true)) {
            throw new InvalidArgumentException("\"$name\" is a CNAME, so it can't have other records.");
        }
        foreach ($existing as $e) {
            if ($e['type'] === $type && $e['content'] === $content && (int) $e['priority'] === (int) $priority) {
                throw new InvalidArgumentException('That exact record already exists.');
            }
        }

        $pdo->prepare('INSERT INTO dns_records (zone_id, name, type, ttl, priority, content) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$zoneId, $name, $type, $ttl, $priority, $content]);
        self::publish($zoneId);
    }

    public static function deleteRecord(int $zoneId, int $recordId): void
    {
        $stmt = Database::app()->prepare('DELETE FROM dns_records WHERE id = ? AND zone_id = ?');
        $stmt->execute([$recordId, $zoneId]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Record not found.');
        }
        self::publish($zoneId);
    }

    // ------------------------------------------------------------------
    // Publishing
    // ------------------------------------------------------------------

    /** Bump the serial, render, and queue the worker to write + reload. */
    public static function publish(int $zoneId): void
    {
        $zone = self::findZone($zoneId) ?? throw new InvalidArgumentException('Zone not found.');
        $serial = self::nextSerial((int) $zone['serial']);
        Database::app()->prepare('UPDATE dns_zones SET serial = ? WHERE id = ?')->execute([$serial, $zoneId]);
        $zone['serial'] = $serial;
        SystemWorkerService::enqueue('dns-' . $zone['zone_name'], [
            'type' => 'dns_write',
            'domain' => $zone['zone_name'],
            'content' => self::render($zone, self::records($zoneId)),
        ]);
    }

    public static function publishAll(): void
    {
        foreach (self::listZones() as $z) {
            self::publish((int) $z['id']);
        }
    }

    /** Zone file text as it is currently published (for previews). */
    public static function renderZone(int $zoneId): ?string
    {
        $zone = self::findZone($zoneId);
        return $zone ? self::render($zone, self::records($zoneId)) : null;
    }

    /** @param list<array<string,mixed>> $records */
    private static function render(array $zone, array $records): string
    {
        $name = (string) $zone['zone_name'];
        $ns = self::nameservers();
        $lines = [
            '; Generated by JinnPanel from its database. Edit records in WHM > DNS Zones;',
            '; direct edits to this file are overwritten on the next publish.',
            "\$ORIGIN {$name}.",
            '$TTL ' . self::DEFAULT_TTL,
            sprintf('@ IN SOA %s. hostmaster.%s. ( %d 3600 900 604800 3600 )', $ns['ns1_host'], $name, (int) $zone['serial']),
        ];
        foreach (self::managedRecords($zone, $records) as $r) {
            $lines[] = self::formatLine($r);
        }
        foreach ($records as $r) {
            $lines[] = self::formatLine($r);
        }
        return implode("\n", $lines) . "\n";
    }

    /** @param array<string,mixed> $r */
    private static function formatLine(array $r): string
    {
        $rdata = match ($r['type']) {
            'MX', 'SRV' => (int) $r['priority'] . ' ' . $r['content'],
            'TXT' => self::quoteTxt((string) $r['content']),
            default => (string) $r['content'],
        };
        return sprintf('%-24s %6d IN %-5s %s', $r['name'], (int) $r['ttl'], $r['type'], $rdata);
    }

    private static function quoteTxt(string $text): string
    {
        $chunks = str_split($text, 255);
        return implode(' ', array_map(fn(string $c) => '"' . addcslashes($c, '"\\') . '"', $chunks));
    }

    private static function nextSerial(int $current): int
    {
        return max($current + 1, (int) date('Ymd') * 100 + 1);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function insertZone(string $name, bool $isServerZone): int
    {
        $pdo = Database::app();
        $pdo->prepare('INSERT INTO dns_zones (zone_name, is_server_zone, serial) VALUES (?, ?, 0)')
            ->execute([$name, $isServerZone ? 1 : 0]);
        return (int) $pdo->lastInsertId();
    }

    /** @param list<array{0:string,1:string,2:string,3:?int}> $records [name, type, content, priority] */
    private static function seedMissing(int $zoneId, array $records): void
    {
        $pdo = Database::app();
        $check = $pdo->prepare('SELECT COUNT(*) FROM dns_records WHERE zone_id = ? AND name = ? AND (type = ? OR type = \'CNAME\')');
        $insert = $pdo->prepare('INSERT INTO dns_records (zone_id, name, type, ttl, priority, content) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($records as [$name, $type, $content, $priority]) {
            $check->execute([$zoneId, $name, $type]);
            if ((int) $check->fetchColumn() > 0) {
                continue;
            }
            $insert->execute([$zoneId, $name, $type, self::DEFAULT_TTL, $priority, $content]);
        }
    }

    private static function normalizeZoneName(string $name): string
    {
        $name = strtolower(rtrim(trim($name), '.'));
        if (!preg_match(self::DOMAIN_RE, $name)) {
            throw new InvalidArgumentException('Invalid domain name.');
        }
        return $name;
    }

    private static function normalizeHostname(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        return preg_match(self::DOMAIN_RE, $host) ? $host : null;
    }

    private static function normalizeRecordName(string $raw, string $zone): string
    {
        $n = strtolower(trim($raw));
        $absolute = str_ends_with($n, '.');
        $n = rtrim($n, '.');
        if ($n === '' || $n === '@' || $n === $zone) {
            return '@';
        }
        if (str_ends_with($n, '.' . $zone)) {
            $n = substr($n, 0, -strlen($zone) - 1);
        } elseif ($absolute) {
            throw new InvalidArgumentException("$raw is outside the $zone zone.");
        }
        if (strlen($n) > 200 || !preg_match('/^(\*|' . self::LABEL_RE . ')(\.' . self::LABEL_RE . ')*$/', $n)) {
            throw new InvalidArgumentException('Name must be @, a label like "www" or "panel.server", or a wildcard like "*".');
        }
        return $n;
    }

    /** Hostname target as an absolute name. A bare label ("mail") means inside this zone. */
    private static function normalizeTarget(string $raw, string $zone): ?string
    {
        $t = strtolower(trim($raw));
        if ($t === '@') {
            return $zone . '.';
        }
        $absolute = str_ends_with($t, '.');
        $t = rtrim($t, '.');
        if ($t === '' || strlen($t) > 253 || !preg_match('/^' . self::LABEL_RE . '(\.' . self::LABEL_RE . ')*$/', $t)) {
            return null;
        }
        if (!$absolute && !str_contains($t, '.')) {
            $t .= '.' . $zone;
        }
        return $t . '.';
    }

    private static function relativeName(string $fqdn, string $zone): ?string
    {
        $fqdn = strtolower(rtrim($fqdn, '.'));
        if ($fqdn === $zone) {
            return '@';
        }
        return str_ends_with($fqdn, '.' . $zone) ? substr($fqdn, 0, -strlen($zone) - 1) : null;
    }

    private static function priority(mixed $raw): int
    {
        $raw = trim((string) $raw);
        if ($raw === '' || !ctype_digit($raw) || (int) $raw > 65535) {
            throw new InvalidArgumentException('Priority (0-65535) is required for MX and SRV records.');
        }
        return (int) $raw;
    }

    private static function setting(string $key): ?string
    {
        $stmt = Database::app()->prepare('SELECT setting_value FROM panel_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null || $v === '') ? null : (string) $v;
    }

    public static function putSetting(string $key, string $value): void
    {
        Database::app()->prepare(
            'INSERT INTO panel_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, $value]);
    }
}
