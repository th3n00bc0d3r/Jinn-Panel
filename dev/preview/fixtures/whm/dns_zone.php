<?php
require_once __DIR__ . '/../_sample_dns.php';

$zone = sample_dns_zone('acme.test', 4, ['domain_id' => 1, 'owner_username' => 'acme']);
$records = sample_dns_records();

$serverZone = sample_dns_zone('example.com', 1, ['is_server_zone' => 1, 'domain_id' => null, 'owner_username' => null]);
$serverRecords = sample_dns_server_records();
$glue = array_merge(sample_dns_ns(), [
    ['name' => 'ns1', 'ttl' => 3600, 'type' => 'A', 'priority' => null, 'content' => '203.0.113.10', 'managed' => true],
    ['name' => 'ns2', 'ttl' => 3600, 'type' => 'A', 'priority' => null, 'content' => '203.0.113.10', 'managed' => true],
]);

$standalone = sample_dns_zone('parked-brand.example', 8, ['domain_id' => null, 'owner_username' => null]);
$standaloneRecords = [
    sample_dns_record(301, '@', 'A', '203.0.113.10'),
    sample_dns_record(302, 'www', 'A', '203.0.113.10'),
];
$ago = fn(int $s) => date('c', time() - $s);

return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/dns/4',
    'data' => [
        'title' => 'DNS Zone · acme.test',
        'zone' => $zone,
        'records' => $records,
        'managed' => sample_dns_ns(),
        'preview' => sample_dns_zone_file($zone, sample_dns_ns(), $records),
        'types' => DnsService::TYPES,
        'lastLog' => '[' . $ago(240) . '] OK',
    ],
    'variants' => [
        'server-zone' => [
            'title' => 'DNS Zone · example.com',
            'zone' => $serverZone,
            'records' => $serverRecords,
            'managed' => $glue,
            'preview' => sample_dns_zone_file($serverZone, $glue, $serverRecords),
        ],
        'standalone-failed' => [
            'title' => 'DNS Zone · parked-brand.example',
            'zone' => $standalone,
            'records' => $standaloneRecords,
            'preview' => sample_dns_zone_file($standalone, sample_dns_ns(), $standaloneRecords),
            'lastLog' => '[' . $ago(90) . '] FAILED: knotc zone-check: parked-brand.example: record www: CNAME and other data',
        ],
        'empty' => [
            'title' => 'DNS Zone · parked-brand.example',
            'zone' => $standalone,
            'records' => [],
            'preview' => sample_dns_zone_file($standalone, sample_dns_ns(), []),
            'lastLog' => null,
        ],
    ],
];
