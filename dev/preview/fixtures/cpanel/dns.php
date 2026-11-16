<?php
require_once __DIR__ . '/../_sample_dns.php';

$domains = sample_domains();
$zone = sample_dns_zone();
// DnsController::index marks what the customer can't change.
$locked = fn(array $rows) => array_map(fn(array $r) => $r + ['locked' => !empty($r['managed'])], $rows);
$records = $locked(sample_dns_records());

// The customer's domain is the server's own zone: its hostname, panel and nameserver names are the admin's.
$serverDomain = ['id' => 4, 'domain_name' => 'example.com', 'docroot' => '/var/www/example.com/public', 'dns_provisioned' => 1] + $domains[0];
$serverZone = sample_dns_zone('example.com', 1, ['is_server_zone' => 1]);
$serverRecords = array_map(fn(array $r) => $r + ['locked' => !empty($r['managed']) || in_array($r['name'], ['server', 'panel.server', 'ns1', 'ns2'], true)], sample_dns_server_records());
$serverNs = sample_dns_ns();
$serverNs[] = ['name' => 'ns1', 'ttl' => 3600, 'type' => 'A', 'priority' => null, 'content' => '203.0.113.10', 'managed' => true];
$serverNs[] = ['name' => 'ns2', 'ttl' => 3600, 'type' => 'A', 'priority' => null, 'content' => '203.0.113.10', 'managed' => true];

return [
    'layout' => 'cpanel',
    'role' => 'user',
    'path' => '/cpanel/dns',
    'flash' => [['success', 'Record added. DNS servers worldwide pick it up within the record\'s TTL.']],
    'data' => [
        'title' => 'DNS Zones',
        'domains' => $domains,
        'current' => $domains[0],
        'zone' => $zone,
        'records' => $records,
        'nsRecords' => sample_dns_ns(),
        'types' => DnsService::TYPES,
        'preview' => sample_dns_zone_file($zone, sample_dns_ns(), $records),
    ],
    'variants' => [
        'read-only' => ['features' => ['file_manager' => false, 'cron' => false, 'backups' => false, 'dns_editor' => false, 'php_versions' => false, 'aliases' => false, 'routes' => false, 'object_cache' => false, 'database_access' => false, 'ssh_keys' => false]],
        // Only the NS records: nothing of the customer's own yet.
        'no-records' => ['records' => [], 'preview' => sample_dns_zone_file($zone, sample_dns_ns(), [])],
        // A domain whose zone isn't on this server (yet).
        'no-zone' => ['current' => $domains[2], 'zone' => null, 'records' => [], 'nsRecords' => [], 'preview' => null],
        'server-zone' => [
            'domains' => array_merge($domains, [$serverDomain]),
            'current' => $serverDomain,
            'zone' => $serverZone,
            'records' => $serverRecords,
            'nsRecords' => $serverNs,
            'preview' => sample_dns_zone_file($serverZone, $serverNs, $serverRecords),
        ],
        'no-domains' => ['domains' => [], 'current' => null, 'zone' => null, 'records' => [], 'nsRecords' => [], 'preview' => null],
    ],
];
