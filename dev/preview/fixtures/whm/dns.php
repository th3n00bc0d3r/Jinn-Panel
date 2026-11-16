<?php
require_once __DIR__ . '/../_sample_dns.php';

$zones = sample_dns_zones();
$ns = ['ns1_host' => 'ns1.example.com', 'ns1_ip' => '203.0.113.10', 'ns2_host' => 'ns2.example.com', 'ns2_ip' => '203.0.113.11'];

// Enough zones to get the filter box.
$many = $zones;
foreach (['clinic', 'dentist', 'florist', 'garage', 'law-firm'] as $i => $n) {
    $many[] = sample_dns_zone("$n.test", 20 + $i, ['domain_id' => 20 + $i, 'owner_username' => $n, 'record_count' => 4 + $i]);
}

return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/dns',
    'flash' => [['success', 'Nameservers saved. Every zone was re-published with the new SOA/NS records.']],
    'data' => [
        'title' => 'DNS Zones',
        'zones' => $zones,
        'ns' => $ns,
        'serverZone' => 'example.com',
    ],
    'variants' => [
        // The server's own zone is missing: the panel and ns1/ns2 don't resolve.
        'no-server-zone' => ['zones' => array_slice($zones, 1)],
        'many' => ['zones' => $many],
        'empty' => ['zones' => []],
        // A hostname that can't be public (server.local): no server zone to offer.
        'local-hostname' => ['zones' => array_slice($zones, 1), 'serverZone' => null],
    ],
];
