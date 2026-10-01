<?php
declare(strict_types=1);

/**
 * WHM > DNS Zones (admin only): the server's nameservers, every zone on this
 * server (the server's own, hosted domains', standalone ones) and their
 * records. All writes go through DnsService, which re-renders the zone and
 * queues the worker to publish it to Knot.
 */
final class WhmDnsController
{
    public static function index(): void
    {
        Auth::requireRole(['admin']);
        View::render('whm/dns', [
            'title' => 'DNS Zones',
            'zones' => DnsService::listZones(),
            'ns' => DnsService::nameservers(),
            'serverZone' => DnsService::serverZoneName(),
        ], 'whm');
    }

    public static function show(array $params): void
    {
        Auth::requireRole(['admin']);
        $zone = DnsService::findZone((int) $params['id']);
        if (!$zone) {
            Flash::error('Zone not found.');
            self::redirect('/whm/dns');
        }
        $records = DnsService::records((int) $zone['id']);
        View::render('whm/dns_zone', [
            'title' => 'DNS Zone · ' . $zone['zone_name'],
            'zone' => $zone,
            'records' => $records,
            'managed' => DnsService::managedRecords($zone, $records),
            'preview' => DnsService::renderZone((int) $zone['id']),
            'types' => DnsService::TYPES,
            'lastLog' => SystemWorkerService::lastLog('dns-' . $zone['zone_name']),
        ], 'whm');
    }

    public static function storeZone(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        try {
            $id = DnsService::createStandaloneZone((string) ($_POST['zone_name'] ?? ''), !empty($_POST['point_to_server']));
            Flash::ok('Zone created and queued for publishing.');
            self::redirect('/whm/dns/' . $id);
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());
            self::redirect('/whm/dns');
        }
    }

    public static function ensureServerZone(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $id = DnsService::ensureServerZone();
        if ($id === null) {
            Flash::error('This server\'s hostname (' . Config::SERVER_HOSTNAME . ') isn\'t a public name, so there is no server zone to create.');
            self::redirect('/whm/dns');
        }
        Flash::ok('Server zone is in place and queued for publishing.');
        self::redirect('/whm/dns/' . $id);
    }

    public static function saveNameservers(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        try {
            DnsService::saveNameservers($_POST);
            Flash::ok('Nameservers saved. Every zone was re-published with the new SOA/NS records.');
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());
        }
        self::redirect('/whm/dns');
    }

    public static function addRecord(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $id = (int) $params['id'];
        try {
            DnsService::addRecord($id, $_POST);
            Flash::ok('Record added and queued for publishing.');
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());
        }
        self::redirect('/whm/dns/' . $id);
    }

    public static function deleteRecord(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $id = (int) $params['id'];
        try {
            DnsService::deleteRecord($id, (int) $params['record']);
            Flash::ok('Record deleted.');
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());
        }
        self::redirect('/whm/dns/' . $id);
    }

    public static function republish(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $id = (int) $params['id'];
        try {
            DnsService::publish($id);
            Flash::ok('Zone re-published.');
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());
        }
        self::redirect('/whm/dns/' . $id);
    }

    public static function destroyZone(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        try {
            DnsService::deleteZone((int) $params['id']);
            Flash::ok('Zone deleted.');
            self::redirect('/whm/dns');
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());
            self::redirect('/whm/dns/' . (int) $params['id']);
        }
    }

    private static function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }
}
