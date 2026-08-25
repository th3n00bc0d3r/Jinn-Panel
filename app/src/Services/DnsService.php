<?php
declare(strict_types=1);

/**
 * Best-effort Knot DNS integration: writes a zone file with sane defaults
 * and hands off registering it with knotd to hostpanel-worker.php via
 * SystemWorkerService. Callers should treat failures here as non-fatal
 * (DNS is a nice-to-have on top of the vhost, which is already live by the
 * time this runs).
 *
 * Why a queue instead of calling knotc directly: FrankenPHP embeds PHP in a
 * multi-threaded Go process, and forking a child from it that connect()s to
 * Knot's Unix control socket reliably fails with EPERM - verified specific
 * to that exact combination (the identical command succeeds via php-cli,
 * via nsenter into the same process's namespaces, and whether foreground or
 * backgrounded - only a fork from FrankenPHP's own process fails).
 */
final class DnsService
{
    private const ZONE_DIR = '/var/lib/knot';

    public static function createZone(string $domain): void
    {
        $serial = date('Ymd') . '01';
        $ip = Config::SERVER_IP;

        $zone = <<<ZONE
        \$ORIGIN {$domain}.
        \$TTL 3600
        @   IN SOA  ns1.{$domain}. hostmaster.{$domain}. ( {$serial} 3600 900 604800 3600 )
        @   IN NS   ns1.{$domain}.
        ns1 IN A    {$ip}
        @   IN A    {$ip}
        www IN A    {$ip}
        @   IN MX 10 {$domain}.

        ZONE;

        file_put_contents(self::ZONE_DIR . "/{$domain}.zone", $zone);
        SystemWorkerService::enqueue("dns-{$domain}", ['type' => 'dns_create', 'domain' => $domain]);
    }

    public static function removeZone(string $domain): void
    {
        SystemWorkerService::enqueue("dns-{$domain}", ['type' => 'dns_remove', 'domain' => $domain]);
        $zoneFile = self::ZONE_DIR . "/{$domain}.zone";
        if (is_file($zoneFile)) {
            unlink($zoneFile);
        }
    }
}
