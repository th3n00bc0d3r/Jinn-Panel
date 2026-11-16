<?php
declare(strict_types=1);

/**
 * What needs an administrator's attention, gathered from what the panel
 * already knows: the hourly health check, services, disk space, accounts
 * over quota, failed backups and migrations, certificates about to expire,
 * routes that need a review. Shown in WHM > Alerts and on the dashboard;
 * new problems are emailed hourly by the root worker (notify()).
 */
final class AlertService
{
    /**
     * @return list<array{key:string, level:string, title:string, detail:string, href:?string}>
     *         level: danger | warning | info; most serious first
     */
    public static function collect(): array
    {
        $alerts = [];
        $add = function (string $key, string $level, string $title, string $detail = '', ?string $href = null) use (&$alerts): void {
            $alerts[] = compact('key', 'level', 'title', 'detail', 'href');
        };

        // The hourly health check (jinnpanel-after-upgrade check, copied by the worker).
        $health = SystemWorkerService::output('health.txt');
        if ($health !== null && str_starts_with($health, 'FAIL')) {
            foreach (preg_split('/\R/', $health) ?: [] as $line) {
                if (str_starts_with($line, ' - ')) {
                    $p = trim(substr($line, 3));
                    $add('health:' . md5($p), 'danger', 'Health check: ' . mb_strimwidth($p, 0, 120, '…'), $p !== mb_strimwidth($p, 0, 120, '…') ? $p : 'Re-check on the server with: jinnpanel-after-upgrade check');
                }
            }
        }

        foreach (self::safely(fn() => ServerStatus::services(), []) as $svc => $state) {
            if ($state !== 'active') {
                $add("service:$svc", 'danger', ucfirst($svc) . ' is not answering', "Nothing accepts connections on its port. Check: systemctl status $svc", '/whm');
            }
        }

        $total = (int) (@disk_total_space('/') ?: 0);
        if ($total > 0) {
            $pct = ($total - (int) @disk_free_space('/')) / $total * 100;
            if ($pct >= 90) {
                $add('disk:root', $pct >= 97 ? 'danger' : 'warning', 'Disk ' . round($pct) . '% full', 'Free space on / is ' . fmt_bytes((int) @disk_free_space('/')) . '. Backups and mail stop working when it runs out.', '/whm');
            }
        }

        $pdo = Database::app();
        foreach (self::safely(fn() => $pdo->query("SELECT u.id, u.username, a.over_disk, a.over_bandwidth FROM account_usage a JOIN users u ON u.id = a.user_id WHERE a.over_disk = 1 OR a.over_bandwidth = 1 ORDER BY u.username")->fetchAll(), []) as $r) {
            if ((int) $r['over_disk']) {
                $add("quota:disk:{$r['id']}", 'warning', "{$r['username']} is over its disk quota", 'Uploads, new databases, mailboxes and domains are refused until it frees space or gets a bigger package.', "/whm/accounts/{$r['id']}");
            }
            if ((int) $r['over_bandwidth']) {
                $add("quota:bw:{$r['id']}", 'warning', "{$r['username']} used up its bandwidth", 'Its sites answer 509 until next month or a bigger package.', "/whm/accounts/{$r['id']}");
            }
        }

        foreach (self::safely(fn() => $pdo->query("SELECT id, kind, username, finished_at FROM backups WHERE status = 'failed' AND created_at > NOW() - INTERVAL 2 DAY ORDER BY id DESC LIMIT 20")->fetchAll(), []) as $b) {
            $add("backup:{$b['id']}", 'danger', 'Backup failed: ' . ($b['kind'] === 'server' ? 'server' : $b['username']), 'Backup #' . $b['id'] . ' - see its log in Backups.', '/whm/backups');
        }

        foreach (self::safely(fn() => $pdo->query("SELECT id, source_host, status FROM migrations WHERE status IN ('failed','completed_with_errors') AND COALESCE(finished_at, created_at) > NOW() - INTERVAL 7 DAY ORDER BY id DESC LIMIT 10")->fetchAll(), []) as $m) {
            $add("migration:{$m['id']}:{$m['status']}", $m['status'] === 'failed' ? 'danger' : 'warning', 'Migration #' . $m['id'] . ($m['status'] === 'failed' ? ' failed' : ' finished with errors'), (string) ($m['source_host'] ?? ''), "/whm/migrations/{$m['id']}");
        }

        foreach (self::safely(fn() => $pdo->query("SELECT d.domain_name, d.user_id FROM domains d WHERE d.ssl_mode = 'letsencrypt' ORDER BY d.domain_name LIMIT 1000")->fetchAll(), []) as $d) {
            $cert = SslService::certificate((string) $d['domain_name']);
            if ($cert !== null && $cert['valid_to'] < time() + 14 * 86400) {
                $expired = $cert['valid_to'] < time();
                $add('cert:' . $d['domain_name'], $expired ? 'danger' : 'warning', ($expired ? 'Certificate expired: ' : 'Certificate expires soon: ') . $d['domain_name'],
                    ($expired ? 'Expired ' : 'Expires ') . gmdate('j M Y', $cert['valid_to']) . '. Renewal normally happens 30 days ahead - check that the domain still points here.', "/whm/accounts/{$d['user_id']}");
            }
        }

        $review = (int) self::safely(fn() => $pdo->query('SELECT COUNT(*) FROM domains WHERE routes_review = 1')->fetchColumn(), 0);
        if ($review > 0) {
            $add('routes:review', 'info', $review . ' domain' . ($review === 1 ? ' has' : 's have') . ' routing rules to review', 'Some .htaccess rules couldn\'t be translated automatically; the customer sees this on the domain\'s Routes tab.');
        }

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);
        return $alerts;
    }

    /**
     * Hourly, from the root worker: emails the problems (danger/warning)
     * that weren't there at the last run. Remembers what it reported, so
     * an ongoing problem is mailed once, and again if it comes back.
     */
    public static function notify(callable $log): void
    {
        $current = array_values(array_filter(self::collect(), fn($a) => $a['level'] !== 'info'));
        $keys = array_column($current, 'key');
        $before = json_decode(Settings::get('alerts_notified', '[]'), true);
        $before = is_array($before) ? $before : [];
        $new = array_values(array_filter($current, fn($a) => !in_array($a['key'], $before, true)));
        Settings::set('alerts_notified', (string) json_encode($keys));
        if (!$new) {
            return;
        }
        if (Settings::get('notify_alerts', '1') !== '1') {
            $log(count($new) . ' new alert(s); emails are off');
            return;
        }
        $log(count($new) . ' new alert(s) emailed to ' . NotificationService::alerts($new) . ' recipient(s)');
    }

    /** @template T @param callable():T $fn @param T $default @return T */
    /**
     * How many alerts there are and the worst level, kept in panel_settings
     * for the top bar's bell (refreshed by the worker every minute and by
     * every page that collects alerts), so pages don't check services.
     *
     * @param list<array<string,mixed>>|null $alerts collect()'s result, if at hand
     * @return array{count:int, level:string, at:int}
     */
    public static function remember(?array $alerts = null): array
    {
        $alerts ??= self::collect();
        $levels = array_column($alerts, 'level');
        $summary = [
            'count' => count($alerts),
            'level' => in_array('danger', $levels, true) ? 'danger' : (in_array('warning', $levels, true) ? 'warning' : ($alerts ? 'info' : '')),
            'at' => time(),
        ];
        self::safely(fn() => Settings::set('alerts_summary', (string) json_encode($summary)), null);
        return $summary;
    }

    /** The last remembered summary, or null before the first one. @return array{count:int, level:string, at:int}|null */
    public static function summary(): ?array
    {
        $s = json_decode(Settings::get('alerts_summary'), true);
        return is_array($s) && isset($s['count']) ? $s : null;
    }

    private static function safely(callable $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            error_log('alerts: ' . $e->getMessage());
            return $default;
        }
    }
}
