<?php
declare(strict_types=1);

/**
 * Which certificate a site gets, and what state it's in.
 *
 * Let's Encrypt can only issue for a name that publicly resolves to this
 * server (HTTP-01/TLS-ALPN on 80/443); for anything else Caddy retries
 * forever and the site has no working certificate. So "automatic" (the
 * default) means: Let's Encrypt when the domain already resolves here,
 * self-signed otherwise - and upgradeAll() (daily) moves self-signed sites
 * to Let's Encrypt once their DNS points here, and Let's Encrypt sites that
 * point elsewhere without a certificate back to self-signed.
 */
final class SslService
{
    private const CADDY_CERTS = '/var/lib/frankenphp/.local/share/caddy/certificates';

    /** Whether a requested mode is an explicit "self-signed only" (domains.ssl_pinned). */
    public static function pinned(string $requested): bool
    {
        return $requested === 'self_signed';
    }

    /** 'auto' from a form becomes the concrete mode for $domain now. */
    public static function resolveMode(string $requested, string $domain): string
    {
        return match ($requested) {
            'letsencrypt' => 'letsencrypt',
            'self_signed' => 'self_signed',
            default => self::resolvesHere($domain) ? 'letsencrypt' : 'self_signed',
        };
    }

    /** Public A/AAAA records of $domain (via the system resolver, i.e. public DNS). */
    public static function addresses(string $domain): array
    {
        $out = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $key) {
            foreach (@dns_get_record($domain, $type) ?: [] as $r) {
                if (isset($r[$key])) {
                    $out[] = strtolower((string) $r[$key]);
                }
            }
        }
        return array_values(array_unique($out));
    }

    public static function resolvesHere(string $domain): bool
    {
        $mine = array_filter([Config::SERVER_IP, DnsService::serverIpv6()]);
        $addrs = self::addresses($domain);
        return $addrs !== [] && array_diff($addrs, $mine) === [];
    }

    /**
     * @return array{state:string, label:string, detail:string}
     *   state: ok | pending | self_signed | no_dns
     */
    public static function status(string $domain, string $sslMode, bool $pinned = false): array
    {
        $cert = self::certificate($domain);
        $addrs = self::addresses($domain);
        $here = $addrs !== [] && array_diff($addrs, array_filter([Config::SERVER_IP, DnsService::serverIpv6()])) === [];
        $where = $addrs ? implode(', ', $addrs) : 'nothing (no A record)';

        if ($sslMode === 'letsencrypt') {
            if ($cert !== null && $cert['valid_to'] > time()) {
                return ['state' => 'ok', 'label' => "Let's Encrypt", 'detail' => 'Valid until ' . gmdate('j M Y', $cert['valid_to'])];
            }
            return $here
                ? ['state' => 'pending', 'label' => "Let's Encrypt - issuing", 'detail' => 'The domain points here; the certificate normally arrives within a minute or two.']
                : ['state' => 'no_dns', 'label' => "Let's Encrypt - can't issue", 'detail' => "$domain resolves to $where, not this server. Point its DNS here (or switch to self-signed)."];
        }
        if ($pinned) {
            return ['state' => 'self_signed', 'label' => 'Self-signed only', 'detail' => "Browsers warn. Chosen on purpose, so it stays self-signed - pick Automatic or run AutoSSL to switch to Let's Encrypt."];
        }
        return $here
            ? ['state' => 'self_signed', 'label' => 'Self-signed', 'detail' => "The domain points here now - it moves to Let's Encrypt automatically within a day, or switch now."]
            : ['state' => 'self_signed', 'label' => 'Self-signed', 'detail' => "Browsers warn. $domain resolves to $where; once it points here it moves to Let's Encrypt automatically."];
    }

    /** Caddy's Let's Encrypt certificate for $domain, if it has one. */
    public static function certificate(string $domain): ?array
    {
        if (!preg_match('/^[a-z0-9.-]+$/', $domain)) {
            return null;
        }
        foreach (glob(self::CADDY_CERTS . "/acme*/$domain/$domain.crt") ?: [] as $file) {
            $x = @openssl_x509_parse((string) @file_get_contents($file));
            if (is_array($x)) {
                return ['valid_to' => (int) $x['validTo_time_t'], 'issuer' => (string) ($x['issuer']['O'] ?? '')];
            }
        }
        return null;
    }

    /**
     * Self-signed sites whose domain now resolves here switch to Let's
     * Encrypt (daily, from the mail/DNS sync timer) - unless the customer
     * chose "self-signed only" (ssl_pinned).
     *
     * @return list<string> log lines
     */
    public static function upgradeAll(): array
    {
        $pdo = Database::app();
        $log = [];
        $changed = false;
        $upd = $pdo->prepare("UPDATE domains SET ssl_mode = 'letsencrypt' WHERE id = ?");
        foreach ($pdo->query("SELECT * FROM domains WHERE ssl_mode = 'self_signed' AND ssl_pinned = 0 ORDER BY domain_name")->fetchAll() as $d) {
            if (!self::resolvesHere((string) $d['domain_name'])) {
                continue;
            }
            try {
                VhostService::create((string) $d['domain_name'], (string) ($d['php_version'] ?: 'default'), 'letsencrypt', false, false);
                $upd->execute([$d['id']]);
                $changed = true;
                $log[] = "{$d['domain_name']}: now points here - switched to Let's Encrypt";
            } catch (Throwable $e) {
                $log[] = "{$d['domain_name']}: FAILED to switch to Let's Encrypt - " . $e->getMessage();
            }
        }
        // And back: a Let's Encrypt site that has no valid certificate and
        // doesn't point here only makes Caddy retry ACME (until Let's Encrypt
        // rate-limits the server) - self-signed until its DNS moves.
        $down = $pdo->prepare("UPDATE domains SET ssl_mode = 'self_signed' WHERE id = ?");
        foreach ($pdo->query("SELECT * FROM domains WHERE ssl_mode = 'letsencrypt' ORDER BY domain_name")->fetchAll() as $d) {
            $cert = self::certificate((string) $d['domain_name']);
            if (($cert !== null && $cert['valid_to'] > time()) || self::resolvesHere((string) $d['domain_name'])) {
                continue;
            }
            try {
                VhostService::create((string) $d['domain_name'], (string) ($d['php_version'] ?: 'default'), 'self_signed', false, false);
                $down->execute([$d['id']]);
                $changed = true;
                $log[] = "{$d['domain_name']}: doesn't point here and has no certificate - self-signed until it does";
            } catch (Throwable $e) {
                $log[] = "{$d['domain_name']}: FAILED to switch to self-signed - " . $e->getMessage();
            }
        }
        if ($changed) {
            VhostService::reload();
        }
        return $log ?: ['SSL: nothing to change'];
    }

    /** A certificate with fewer days left than this needs attention: Caddy renews ~30 days ahead. */
    public const EXPIRING_DAYS = 14;

    /**
     * The certificate Caddy holds for $name: its Let's Encrypt one, else one
     * from its local CA (self-signed sites). Null when it has none.
     *
     * @return array{ca:string, issuer:string, valid_from:int, valid_to:int}|null
     */
    public static function certInfo(string $name): ?array
    {
        if (!preg_match('/^[a-z0-9.-]+$/', $name)) {
            return null;
        }
        foreach (['letsencrypt' => self::CADDY_CERTS . "/acme*/$name/$name.crt", 'local' => self::CADDY_CERTS . "/local/$name/$name.crt"] as $ca => $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $x = @openssl_x509_parse((string) @file_get_contents($file));
                if (is_array($x)) {
                    return [
                        'ca' => $ca,
                        'issuer' => trim(($x['issuer']['O'] ?? '') . ' ' . ($x['issuer']['CN'] ?? '')),
                        'valid_from' => (int) $x['validFrom_time_t'],
                        'valid_to' => (int) $x['validTo_time_t'],
                    ];
                }
            }
        }
        return null;
    }

    /** valid | expiring | expired | missing for one certificate (null = none). */
    public static function certState(?array $cert, ?int $now = null): string
    {
        $now ??= time();
        if ($cert === null) {
            return 'missing';
        }
        if ($cert['valid_to'] <= $now) {
            return 'expired';
        }
        return $cert['valid_to'] - $now < self::EXPIRING_DAYS * 86400 ? 'expiring' : 'valid';
    }

    /**
     * WHM > SSL/TLS: every hosted domain with each name it serves (the
     * domain, www., its aliases), the certificate behind each name and
     * whether its DNS points here.
     *
     * state: ok | expiring | problem (a Let's Encrypt name without a valid
     * certificate) | self_signed | pinned
     *
     * @return list<array<string,mixed>>
     */
    public static function overview(): array
    {
        $rows = [];
        $sql = 'SELECT d.*, u.username FROM domains d JOIN users u ON u.id = d.user_id ORDER BY d.domain_name';
        foreach (Database::app()->query($sql)->fetchAll() as $d) {
            $domain = (string) $d['domain_name'];
            $le = $d['ssl_mode'] === 'letsencrypt';
            $names = [];
            foreach (array_merge([$domain], DomainAliasService::forDomain($domain)) as $base) {
                foreach ([$base, "www.$base"] as $name) {
                    $cert = self::certInfo($name);
                    // A self-signed site's names are fine without a Let's Encrypt cert.
                    $state = $le ? ($cert !== null && $cert['ca'] === 'letsencrypt' ? self::certState($cert) : 'missing') : 'self_signed';
                    // Only names with a problem are looked up (keeps the page quick).
                    $where = $state === 'valid' || $state === 'self_signed' ? null : self::addresses($name);
                    $names[] = ['name' => $name, 'cert' => $cert, 'state' => $state, 'addresses' => $where,
                        'points_here' => $where === null ? null : ($where !== [] && array_diff($where, array_filter([Config::SERVER_IP, DnsService::serverIpv6()])) === [])];
                }
            }
            $addrs = self::addresses($domain);
            $here = $addrs !== [] && array_diff($addrs, array_filter([Config::SERVER_IP, DnsService::serverIpv6()])) === [];
            $states = array_column($names, 'state');
            $state = match (true) {
                !$le => (int) $d['ssl_pinned'] === 1 ? 'pinned' : 'self_signed',
                in_array('missing', $states, true) || in_array('expired', $states, true) => 'problem',
                in_array('expiring', $states, true) => 'expiring',
                default => 'ok',
            };
            $apex = $names[0]['cert'];
            $rows[] = $d + [
                'names' => $names,
                'addresses' => $addrs,
                'points_here' => $here,
                'state' => $state,
                'expires' => $apex !== null && $le ? $apex['valid_to'] : null,
            ];
        }
        return $rows;
    }

    /**
     * WHM > SSL/TLS > Run AutoSSL: the daily switch (upgradeAll), then every
     * Let's Encrypt site that points here but has a name without a valid
     * certificate (or one close to expiry) gets its config rewritten and
     * Caddy reloaded, which makes Caddy try to issue or renew right away.
     *
     * @return list<string> log lines
     */
    public static function autoSslAll(): array
    {
        $log = array_values(array_filter(self::upgradeAll(), static fn (string $l): bool => $l !== 'SSL: nothing to change'));
        $nudged = false;
        foreach (self::overview() as $row) {
            if ($row['ssl_mode'] !== 'letsencrypt' || !in_array($row['state'], ['problem', 'expiring'], true)) {
                continue;
            }
            $domain = (string) $row['domain_name'];
            if (!$row['points_here']) {
                $log[] = "$domain: its DNS doesn't point here, so Let's Encrypt can't issue - point it here or switch it to self-signed";
                continue;
            }
            $bad = array_map(static fn (array $n): string => $n['name'], array_filter($row['names'], static fn (array $n): bool => $n['state'] !== 'valid'));
            try {
                VhostService::create($domain, (string) ($row['php_version'] ?: 'default'), 'letsencrypt', false, false);
                $nudged = true;
                $log[] = "$domain: asked for a new certificate (" . implode(', ', $bad) . ')';
            } catch (Throwable $e) {
                $log[] = "$domain: FAILED to rewrite its config - " . $e->getMessage();
            }
        }
        if ($nudged) {
            VhostService::reload();
        }
        return $log;
    }
}
