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
    public static function status(string $domain, string $sslMode): array
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
     * Encrypt (daily, from the mail/DNS sync timer).
     *
     * @return list<string> log lines
     */
    public static function upgradeAll(): array
    {
        $pdo = Database::app();
        $log = [];
        $changed = false;
        $upd = $pdo->prepare("UPDATE domains SET ssl_mode = 'letsencrypt' WHERE id = ?");
        foreach ($pdo->query("SELECT * FROM domains WHERE ssl_mode = 'self_signed' ORDER BY domain_name")->fetchAll() as $d) {
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
}
