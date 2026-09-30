<?php
declare(strict_types=1);

/**
 * One-shot DNS bootstrap, run by install.sh after the app is deployed (as
 * frankenphp, like the rest of the app - the root worker does the actual
 * zone-file writes a few seconds later):
 *
 *  1. Nameservers. Explicit JINNPANEL_NS1_HOST / _NS1_IP / _NS2_HOST /
 *     _NS2_IP always win. Otherwise the first run stores defaults
 *     (ns1/ns2.<server zone>, the primary IP, and for ns2 a second IPv4 on
 *     the box if install.sh found one) and later runs leave whatever was set
 *     in WHM > DNS Zones alone.
 *  2. Server zone. Creates / tops up the zone for the hostname's parent
 *     (server.example.com -> example.com; JINNPANEL_DNS_ZONE overrides) so
 *     ns1/ns2, the hostname and panel.<hostname> resolve publicly as soon as
 *     the registrar's glue points here - no hosts-file edits.
 *  3. Domains added before zones lived in the panel DB get their zone
 *     imported, so their records become editable instead of template-only.
 *
 * Prints one line for install.sh: "<zone|-> <ns1_host> <ns1_ip> <ns2_host> <ns2_ip>".
 */

require __DIR__ . '/../src/cli_bootstrap.php';

$env = static fn(string $k): string => trim((string) (getenv($k) ?: ''));

$zoneOverride = strtolower(rtrim($env('JINNPANEL_DNS_ZONE'), '.'));
if ($zoneOverride !== '') {
    DnsService::putSetting('dns_server_zone', $zoneOverride);
}

$explicit = array_filter([
    'ns1_host' => $env('JINNPANEL_NS1_HOST'),
    'ns1_ip' => $env('JINNPANEL_NS1_IP'),
    'ns2_host' => $env('JINNPANEL_NS2_HOST'),
    'ns2_ip' => $env('JINNPANEL_NS2_IP'),
], static fn(string $v) => $v !== '');

if ($explicit || !DnsService::hasStoredNameservers()) {
    $ns = DnsService::nameservers();
    if (!DnsService::hasStoredNameservers() && $env('JINNPANEL_NS2_IP_DETECTED') !== '') {
        $ns['ns2_ip'] = $env('JINNPANEL_NS2_IP_DETECTED');
    }
    try {
        DnsService::saveNameservers(array_merge($ns, $explicit), false);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, 'Nameserver settings not saved: ' . $e->getMessage() . "\n");
    }
}

DnsService::ensureServerZone();

$legacy = Database::app()->query(
    'SELECT d.domain_name FROM domains d LEFT JOIN dns_zones z ON z.zone_name = d.domain_name WHERE z.id IS NULL'
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($legacy as $domain) {
    try {
        DnsService::createZone((string) $domain);
    } catch (Throwable $e) {
        fwrite(STDERR, "Zone for $domain not imported: " . $e->getMessage() . "\n");
    }
}

$ns = DnsService::nameservers();
echo implode(' ', [DnsService::serverZoneName() ?? '-', $ns['ns1_host'], $ns['ns1_ip'], $ns['ns2_host'], $ns['ns2_ip']]), "\n";
