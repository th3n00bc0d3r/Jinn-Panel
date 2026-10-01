<?php
declare(strict_types=1);

final class DnsController
{
    public static function index(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE user_id = ? ORDER BY domain_name');
        $stmt->execute([$me['id']]);
        $domains = $stmt->fetchAll();

        $current = null;
        foreach ($domains as $d) {
            if ((int) $d['id'] === (int) ($_GET['domain'] ?? 0) || $current === null) {
                $current = $d;
            }
        }
        $zone = $current ? DnsService::findZoneByName((string) $current['domain_name']) : null;
        $records = $zone ? DnsService::records((int) $zone['id']) : [];
        foreach ($records as &$r) {
            $r['locked'] = !empty($r['managed']) || DnsService::isServerName($zone, (string) $r['name']);
        }
        unset($r);

        View::render('cpanel/dns', [
            'title' => 'DNS Zones',
            'domains' => $domains,
            'current' => $current,
            'zone' => $zone,
            'records' => $records,
            'nsRecords' => $zone ? DnsService::managedRecords($zone, $records) : [],
            'types' => DnsService::TYPES,
            'preview' => $zone ? DnsService::renderZone((int) $zone['id']) : null,
        ], 'cpanel');
    }

    public static function addRecord(array $params): void
    {
        [$domain, $zone] = self::zone($params);
        try {
            self::guard($zone, (string) ($_POST['name'] ?? ''));
            DnsService::addRecord((int) $zone['id'], $_POST);
            Flash::ok('Record added. DNS servers worldwide pick it up within the record\'s TTL.');
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());
        }
        self::back($domain);
    }

    public static function updateRecord(array $params): void
    {
        [$domain, $zone] = self::zone($params);
        try {
            self::guard($zone, (string) ($_POST['name'] ?? ''));
            self::guardRecord($zone, (int) ($params['record'] ?? 0));
            DnsService::updateRecord((int) $zone['id'], (int) ($params['record'] ?? 0), $_POST);
            Flash::ok('Record updated.');
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());
        }
        self::back($domain);
    }

    public static function deleteRecord(array $params): void
    {
        [$domain, $zone] = self::zone($params);
        try {
            self::guardRecord($zone, (int) ($params['record'] ?? 0));
            DnsService::deleteRecord((int) $zone['id'], (int) ($params['record'] ?? 0));
            Flash::ok('Record deleted.');
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());
        }
        self::back($domain);
    }

    /** @return array{0:array,1:array} the customer's domain {id} and its zone (POST + CSRF checked) */
    private static function zone(array $params): array
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($params['id'] ?? 0), $me['id']]);
        $domain = $stmt->fetch();
        $zone = $domain ? DnsService::findZoneByName((string) $domain['domain_name']) : null;
        if (!$domain || !$zone) {
            Flash::error('Zone not found.');
            header('Location: /cpanel/dns');
            exit;
        }
        return [$domain, $zone];
    }

    /** The server's own names in its zone are WHM-only. */
    private static function guard(array $zone, string $rawName): void
    {
        $name = strtolower(trim(rtrim(trim($rawName), '.')));
        $zn = (string) $zone['zone_name'];
        if (str_ends_with($name, '.' . $zn)) {
            $name = substr($name, 0, -strlen($zn) - 1);
        }
        if (DnsService::isServerName($zone, $name)) {
            throw new InvalidArgumentException("\"$name\" belongs to this server (hostname, panel, nameservers) and can only be changed by the administrator.");
        }
    }

    private static function guardRecord(array $zone, int $recordId): void
    {
        $s = Database::app()->prepare('SELECT name FROM dns_records WHERE id = ? AND zone_id = ?');
        $s->execute([$recordId, $zone['id']]);
        $name = $s->fetchColumn();
        if ($name === false) {
            throw new InvalidArgumentException('Record not found.');
        }
        self::guard($zone, (string) $name);
    }

    private static function back(array $domain): never
    {
        header('Location: /cpanel/dns?domain=' . (int) $domain['id']);
        exit;
    }

    public static function reprovision(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $id = (int) $params['id'];

        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $me['id']]);
        $domain = $stmt->fetch();
        if (!$domain) {
            Flash::error('Domain not found.');
            header('Location: /cpanel/dns');
            exit;
        }

        try {
            DnsService::createZone($domain['domain_name']);
            $upd = Database::app()->prepare('UPDATE domains SET dns_provisioned = 1 WHERE id = ?');
            $upd->execute([$id]);
            Flash::ok('DNS zone re-published (existing records kept).');
            header('Location: /cpanel/dns?domain=' . $id);
            exit;
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error('DNS provisioning failed: ' . $e->getMessage());
        }

        header('Location: /cpanel/dns');
        exit;
    }
}
