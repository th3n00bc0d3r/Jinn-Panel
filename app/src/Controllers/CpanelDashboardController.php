<?php
declare(strict_types=1);

final class CpanelDashboardController
{
    public static function index(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $id = (int) $me['id'];
        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE user_id = ? ORDER BY domain_name');
        $stmt->execute([$id]);
        $domains = $stmt->fetchAll();
        foreach ($domains as &$d) {
            $d['ssl'] = SslService::status((string) $d['domain_name'], (string) $d['ssl_mode'], (bool) $d['ssl_pinned']);
        }
        unset($d);

        $range = StatsService::range($_GET['range'] ?? null);
        $measured = Quota::measured($id);
        $summary = null;
        $sparks = [];
        $projection = [];
        try {
            $summary = StatsService::summary($range, ['user' => $id]);
            $sparks = StatsService::domainSparklines(array_map(fn($d) => (int) $d['id'], $domains));
            if ($measured['limit'] > 0) {
                $projection['disk'] = StatsService::diskProjection($id, $measured['used'], $measured['limit']);
            }
            if ($measured['bw_limit'] > 0) {
                $projection['bw'] = StatsService::bandwidthProjection($measured['bw_used'], $measured['bw_limit']);
            }
        } catch (PDOException $e) {
            error_log('statistics: ' . $e->getMessage());
        }
        // Busiest first, then by name.
        usort($domains, fn($a, $b) => [-($sparks[(int) $a['id']]['visitors'] ?? 0), $a['domain_name']] <=> [-($sparks[(int) $b['id']]['visitors'] ?? 0), $b['domain_name']]);

        View::render('cpanel/dashboard', [
            'title' => 'Dashboard',
            'me' => $me,
            'pkg' => Quota::package($id),
            'usage' => Quota::usage($id),
            'measured' => $measured,
            'projection' => $projection,
            'range' => $range,
            'summary' => $summary,
            'sparks' => $sparks,
            'domains' => $domains,
        ], 'cpanel');
    }
}
