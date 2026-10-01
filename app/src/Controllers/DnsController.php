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

        foreach ($domains as &$d) {
            // Rendered from the panel DB (the source of truth for zones).
            $zone = DnsService::findZoneByName($d['domain_name']);
            $d['zone_content'] = $zone ? DnsService::renderZone((int) $zone['id']) : null;
        }
        unset($d);

        View::render('cpanel/dns', ['title' => 'DNS Zones', 'domains' => $domains], 'cpanel');
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
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error('DNS provisioning failed: ' . $e->getMessage());
        }

        header('Location: /cpanel/dns');
        exit;
    }
}
