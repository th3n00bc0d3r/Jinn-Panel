<?php
declare(strict_types=1);

/**
 * Reads the statistics StatsCollector gathers (migration 026), shaped for
 * the dashboards, the account page and each domain's Stats tab. Callers
 * check access; a scope limits what is counted:
 *
 *   []                  every site (admins)
 *   ['reseller' => id]  the sites of a reseller's accounts
 *   ['user' => id]      one account's sites
 *   ['domain' => id]    one site
 */
final class StatsService
{
    public const RANGES = ['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'];

    /**
     * Times are compared as PHP's local wall-clock strings, the way the
     * collector wrote them - never NOW() or CURDATE(), as the database's
     * time zone may differ from PHP's.
     */
    private static function ago(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() - $seconds);
    }

    public static function range(mixed $r): string
    {
        return is_string($r) && isset(self::RANGES[$r]) ? $r : '24h';
    }

    /**
     * The buckets of a range, oldest first: hours for 24h, 4 hours for 7d,
     * days for 30d. Ends with the bucket holding $now.
     *
     * @return array{keys:list<string>, labels:list<string>, from:string, to:string, per:string, sql:string}
     */
    public static function buckets(string $range, ?int $now = null): array
    {
        $now ??= time();
        $keys = $labels = [];
        if ($range === '30d') {
            $today = strtotime(date('Y-m-d', $now));
            for ($i = 29; $i >= 0; $i--) {
                $t = strtotime("-$i day", $today);
                $keys[] = date('Y-m-d', $t);
                $labels[] = date('j M', $t);
            }
            return ['keys' => $keys, 'labels' => $labels, 'from' => $keys[0] . ' 00:00:00', 'to' => date('Y-m-d H:i:s', $now), 'per' => 'day', 'sql' => "DATE_FORMAT(%s, '%%Y-%%m-%%d')"];
        }
        $step = $range === '7d' ? 4 : 1;
        $n = $range === '7d' ? 42 : 24;
        $hour = (int) date('G', $now);
        $end = strtotime(date('Y-m-d', $now) . sprintf(' %02d:00:00', $hour - $hour % $step));
        for ($i = $n - 1; $i >= 0; $i--) {
            $t = $end - $i * $step * 3600;
            // DST: realign to the step within the local day.
            $h = (int) date('G', $t);
            $t = strtotime(date('Y-m-d', $t) . sprintf(' %02d:00:00', $h - $h % $step));
            $keys[] = date('Y-m-d H:00', $t);
            $labels[] = date($step > 1 ? 'D H:00' : 'H:00', $t);
        }
        $sql = $step > 1
            ? "CONCAT(DATE_FORMAT(%s, '%%Y-%%m-%%d '), LPAD(HOUR(%s) - HOUR(%s) %% 4, 2, '0'), ':00')"
            : "DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:00')";
        return ['keys' => $keys, 'labels' => $labels, 'from' => $keys[0] . ':00', 'to' => date('Y-m-d H:i:s', $now), 'per' => $step > 1 ? '4 hours' : 'hour', 'sql' => $sql];
    }

    /** SQL for the bucket key of a datetime column. */
    private static function bucketSql(array $b, string $col): string
    {
        return sprintf($b['sql'], $col, $col, $col);
    }

    /** @return array{0:string, 1:list<mixed>} a WHERE fragment on stat_domain_hourly (alias h) and its parameters */
    private static function scopeSql(array $scope): array
    {
        if (isset($scope['domain'])) {
            return ['h.domain_id = ?', [(int) $scope['domain']]];
        }
        if (isset($scope['user'])) {
            return ['h.domain_id IN (SELECT id FROM domains WHERE user_id = ?)', [(int) $scope['user']]];
        }
        if (isset($scope['reseller'])) {
            return ['h.domain_id IN (SELECT d.id FROM domains d JOIN users u ON u.id = d.user_id WHERE u.parent_id = ?)', [(int) $scope['reseller']]];
        }
        return ['1 = 1', []];
    }

    /**
     * Requests per bucket for the four busiest sites of the last 30 days
     * (so a site keeps its colour on every range) and everything else as
     * "Other sites"; the total and the previous period's for the change;
     * an unusual spike to annotate; the busiest sites of the range.
     *
     * @return array<string,mixed>
     */
    public static function traffic(string $range, array $scope = [], string $metric = 'requests'): array
    {
        $metric = in_array($metric, ['requests', 'visitors', 'bytes'], true) ? $metric : 'requests';
        $pdo = Database::app();
        $b = self::buckets($range);
        [$where, $params] = self::scopeSql($scope);

        $top = $pdo->prepare("SELECT h.domain_id, d.domain_name, SUM(h.$metric) n FROM stat_domain_hourly h JOIN domains d ON d.id = h.domain_id
                              WHERE $where AND h.hour >= ? GROUP BY h.domain_id, d.domain_name ORDER BY n DESC, d.domain_name LIMIT 4");
        $top->execute(array_merge($params, [self::ago(30 * 86400)]));
        $sites = $top->fetchAll();
        $ids = array_map(fn($s) => (int) $s['domain_id'], $sites);
        $series = [];
        foreach ($sites as $k => $s) {
            $series[] = ['name' => (string) $s['domain_name'], 'series' => 's' . ($k + 1)];
        }
        $in = $ids ? implode(',', $ids) : '0';

        $q = $pdo->prepare('SELECT ' . self::bucketSql($b, 'h.hour') . " k, CASE WHEN h.domain_id IN ($in) THEN h.domain_id ELSE 0 END d, SUM(h.$metric) n
                            FROM stat_domain_hourly h WHERE $where AND h.hour >= ? GROUP BY k, d");
        $q->execute(array_merge($params, [$b['from']]));
        $cells = [];
        foreach ($q->fetchAll() as $r) {
            $cells[$r['k']][(int) $r['d']] = (float) $r['n'];
        }
        $hasOther = false;
        foreach ($cells as $c) {
            if (($c[0] ?? 0) > 0) {
                $hasOther = true;
            }
        }
        $cols = array_merge($ids, $hasOther ? [0] : []);
        if ($hasOther) {
            $series[] = ['name' => isset($scope['domain']) ? 'Other' : 'Other sites', 'series' => 'so'];
        }
        $stacks = [];
        foreach ($b['keys'] as $k) {
            $stacks[] = array_map(fn($d) => $cells[$k][$d] ?? 0.0, $cols);
        }
        $total = array_sum(array_map('array_sum', $stacks));

        $seconds = ['24h' => 86400, '7d' => 7 * 86400, '30d' => 30 * 86400][$range];
        $prevStart = date('Y-m-d H:i:s', strtotime($b['from']) - $seconds);
        $previous = 0.0;
        // Only compare with a previous period that was counted from its start.
        if (self::countedSince($where, $params) <= $prevStart) {
            $prev = $pdo->prepare("SELECT COALESCE(SUM(h.$metric), 0) FROM stat_domain_hourly h WHERE $where AND h.hour >= ? AND h.hour < ?");
            $prev->execute(array_merge($params, [$prevStart, $b['from']]));
            $previous = (float) $prev->fetchColumn();
        }

        // A spike: the busiest site at 3x or more of its usual (median) bucket.
        $spike = null;
        if ($ids && count($stacks) >= 6) {
            $vals = array_column($stacks, 0);
            $sorted = $vals;
            sort($sorted);
            $median = $sorted[intdiv(count($sorted), 2)];
            $max = max($vals);
            $at = array_search($max, $vals, true);
            if ($median > 0 && $max >= 3 * $median && $max >= 50) {
                $spike = [(int) $at, $series[0]['name'] . ' ' . number_format($max / $median, 1) . '× usual'];
            }
        }

        $busy = $pdo->prepare("SELECT d.id, d.domain_name, u.username, SUM(h.$metric) n FROM stat_domain_hourly h JOIN domains d ON d.id = h.domain_id JOIN users u ON u.id = d.user_id
                               WHERE $where AND h.hour >= ? GROUP BY d.id, d.domain_name, u.username ORDER BY n DESC LIMIT 7");
        $busy->execute(array_merge($params, [$b['from']]));

        return [
            'range' => $range, 'per' => $b['per'], 'labels' => $b['labels'], 'series' => $series, 'stacks' => $stacks,
            'total' => $total, 'previous' => $previous, 'spike' => $spike, 'busiest' => $busy->fetchAll(), 'metric' => $metric,
        ];
    }

    /** "12% more than the previous 7 days" style change, or null without a previous period. @return array{0:string,1:string}|null [direction up|down|same, words] */
    public static function change(float $now, float $before, string $rangeName): ?array
    {
        if ($before <= 0) {
            return null;
        }
        $pct = ($now - $before) / $before * 100;
        if (abs($pct) < 1) {
            return ['same', 'about the same as the previous ' . $rangeName];
        }
        return [$pct > 0 ? 'up' : 'down', round(abs($pct)) . '% ' . ($pct > 0 ? 'more' : 'less') . ' than the previous ' . $rangeName];
    }

    /**
     * The server over the last $hours in 15-minute steps (CPU %, memory %,
     * load), its latest sample, and web traffic sent per day this month.
     *
     * @return array<string,mixed>
     */
    public static function server(int $hours = 12): array
    {
        $pdo = Database::app();
        $q = $pdo->prepare('SELECT FLOOR(UNIX_TIMESTAMP(at) / 900) b, AVG(cpu_pct) cpu, AVG(mem_used / NULLIF(mem_total, 0)) * 100 mem, AVG(load1) load1
                            FROM stat_server WHERE at >= ? GROUP BY b ORDER BY b');
        $q->execute([self::ago($hours * 3600)]);
        $rows = $q->fetchAll();
        $latest = $pdo->query('SELECT * FROM stat_server ORDER BY at DESC LIMIT 1')->fetch() ?: null;
        $d = $pdo->prepare('SELECT day, SUM(bw_bytes) b FROM stat_account_daily WHERE day >= ? GROUP BY day ORDER BY day');
        $d->execute([date('Y-m-01')]);
        $days = $d->fetchAll();
        return [
            'cpu' => array_map(fn($r) => (float) $r['cpu'], $rows),
            'mem' => array_map(fn($r) => (float) $r['mem'], $rows),
            'load' => array_map(fn($r) => (float) $r['load1'], $rows),
            'latest' => $latest,
            'bwDays' => array_map(fn($r) => (float) $r['b'], $days),
            'bwMonth' => array_sum(array_map(fn($r) => (float) $r['b'], $days)),
        ];
    }

    /**
     * The server per bucket of a range: CPU and memory in percent (averages),
     * load (average) and network sent (sum).
     *
     * @return array{labels:list<string>, per:string, cpu:list<float>, mem:list<float>, load:list<float>, tx:list<float>, has:bool}
     */
    public static function serverRange(string $range): array
    {
        $b = self::buckets($range);
        $q = Database::app()->prepare('SELECT ' . self::bucketSql($b, 'at') . ' k, AVG(cpu_pct) cpu, AVG(mem_used / NULLIF(mem_total, 0)) * 100 mem, AVG(load1) load1, SUM(net_tx) tx
                                       FROM stat_server WHERE at >= ? GROUP BY k');
        $q->execute([$b['from']]);
        $by = [];
        foreach ($q->fetchAll() as $r) {
            $by[$r['k']] = $r;
        }
        $out = ['labels' => $b['labels'], 'per' => $b['per'], 'cpu' => [], 'mem' => [], 'load' => [], 'tx' => [], 'has' => $by !== []];
        foreach ($b['keys'] as $k) {
            $out['cpu'][] = (float) ($by[$k]['cpu'] ?? 0);
            $out['mem'][] = (float) ($by[$k]['mem'] ?? 0);
            $out['load'][] = (float) ($by[$k]['load1'] ?? 0);
            $out['tx'][] = (float) ($by[$k]['tx'] ?? 0);
        }
        return $out;
    }

    /**
     * Every site in scope over a range, for the analytics table.
     *
     * @return list<array{id:int, domain_name:string, user_id:int, username:string, requests:float, visitors:float, bytes:float, errors:float, avg_ms:?float}>
     */
    public static function sites(string $range, array $scope = [], string $sort = 'requests'): array
    {
        $b = self::buckets($range);
        [$where, $params] = self::scopeSql($scope);
        $order = match ($sort) {
            'visitors' => 'visitors DESC',
            'bytes' => 'bytes DESC',
            'errors' => 'errors DESC',
            'time' => 'avg_ms DESC',
            'name' => 'd.domain_name ASC',
            default => 'requests DESC',
        };
        $q = Database::app()->prepare("SELECT d.id, d.domain_name, u.id user_id, u.username, SUM(h.requests) requests, SUM(h.visitors) visitors, SUM(h.bytes) bytes,
                                              SUM(h.s5xx) errors, SUM(h.time_ms) / NULLIF(SUM(h.pages), 0) avg_ms
                                       FROM stat_domain_hourly h JOIN domains d ON d.id = h.domain_id JOIN users u ON u.id = d.user_id
                                       WHERE $where AND h.hour >= ? GROUP BY d.id, d.domain_name, u.id, u.username ORDER BY $order, d.domain_name LIMIT 500");
        $q->execute(array_merge($params, [$b['from']]));
        return array_map(fn($r) => [
            'id' => (int) $r['id'], 'domain_name' => (string) $r['domain_name'], 'user_id' => (int) $r['user_id'], 'username' => (string) $r['username'],
            'requests' => (float) $r['requests'], 'visitors' => (float) $r['visitors'], 'bytes' => (float) $r['bytes'],
            'errors' => (float) $r['errors'], 'avg_ms' => $r['avg_ms'] === null ? null : (float) $r['avg_ms'],
        ], $q->fetchAll());
    }

    /**
     * Each service over the last $hours in $cell-minute cells: 'up', 'slow'
     * (some checks failed or answered slowly), 'down', or null (no check).
     *
     * @return array<string, array{cells:list<?string>, pct:?float, down:string, checks:int}>
     */
    public static function uptime(int $hours = 24, int $cell = 30): array
    {
        // Cells keyed by local wall-clock time on both sides ("2026-11-14 09:30"), like the rows were written.
        $cell = max(1, min(60, $cell));
        $key = fn(int $t) => date('Y-m-d H:', $t) . sprintf('%02d', intdiv((int) date('i', $t), $cell) * $cell);
        $q = Database::app()->prepare("SELECT service, CONCAT(DATE_FORMAT(at, '%Y-%m-%d %H:'), LPAD(FLOOR(MINUTE(at) / ?) * ?, 2, '0')) b, COUNT(*) n, SUM(up) ups, MAX(ms) ms
                                       FROM stat_service_checks WHERE at >= ? GROUP BY service, b");
        $count = intdiv($hours * 60, $cell);
        $step = $cell * 60;
        $lastT = time() - time() % $step;
        $q->execute([$cell, $cell, date('Y-m-d H:i:s', $lastT - ($count - 1) * $step)]);
        $by = [];
        foreach ($q->fetchAll() as $r) {
            $by[$r['service']][$r['b']] = $r;
        }
        $last = intdiv($lastT, $step);
        $out = [];
        foreach (array_keys(ServerStatus::SERVICES) as $svc) {
            $cells = [];
            $n = $ups = 0;
            $runs = [];
            $run = null;
            for ($i = $count - 1; $i >= 0; $i--) {
                $b = $last - $i;
                $r = $by[$svc][$key($b * $step)] ?? null;
                $state = null;
                if ($r !== null) {
                    $n += (int) $r['n'];
                    $ups += (int) $r['ups'];
                    $state = (int) $r['ups'] === 0 ? 'down' : ((int) $r['ups'] < (int) $r['n'] || (int) $r['ms'] > 1000 ? 'slow' : 'up');
                }
                $cells[] = $state;
                $bad = $state === 'down' || ($state === 'slow' && (int) ($r['ups'] ?? 0) < (int) ($r['n'] ?? 0));
                if ($bad) {
                    $run ??= [$b, $b];
                    $run[1] = $b;
                } elseif ($run !== null) {
                    $runs[] = $run;
                    $run = null;
                }
            }
            if ($run !== null) {
                $runs[] = $run;
            }
            $down = '';
            if ($runs) {
                [$a, $z] = end($runs);
                $down = 'down from ' . date('H:i', $a * $cell * 60) . ($z === $last ? ', still down' : ' to ' . date('H:i', ($z + 1) * $cell * 60));
            }
            $out[$svc] = ['cells' => $cells, 'pct' => $n > 0 ? $ups / $n * 100 : null, 'down' => $down, 'checks' => $n];
        }
        return $out;
    }

    /** 99.95% / 100% / 93.8% */
    public static function pct(?float $p): string
    {
        if ($p === null) {
            return '—';
        }
        return $p >= 99.995 ? '100%' : rtrim(rtrim(number_format(floor($p * 100) / 100, 2), '0'), '.') . '%';
    }

    /**
     * Accounts at or near a limit, worst first: over a limit, then soonest
     * to reach one at the current rate, then highest share.
     *
     * @return list<array{id:int, username:string, what:string, pct:float, note:string, tone:string, over:bool, days:?int}>
     */
    public static function nearingLimits(?int $resellerId = null, int $max = 5): array
    {
        $pdo = Database::app();
        $sql = "SELECT u.id, u.username, p.disk_quota_mb, p.bandwidth_mb, a.files_bytes + a.db_bytes + a.mail_bytes used, a.bw_month, a.bw_bytes
                FROM users u JOIN packages p ON p.id = u.package_id LEFT JOIN account_usage a ON a.user_id = u.id
                WHERE u.role = 'user' AND u.status = 'active'" . ($resellerId ? ' AND u.parent_id = ?' : '');
        $q = $pdo->prepare($sql);
        $q->execute($resellerId ? [$resellerId] : []);
        $out = [];
        foreach ($q->fetchAll() as $u) {
            $id = (int) $u['id'];
            $disk = (int) ($u['used'] ?? 0);
            $diskLimit = Quota::bytes($u['disk_quota_mb']);
            if ($diskLimit > 0 && $disk > 0) {
                $pct = $disk / $diskLimit * 100;
                $p = $pct >= 50 ? self::diskProjection($id, $disk, $diskLimit) : null;
                if ($pct >= 100) {
                    $out[] = ['id' => $id, 'username' => $u['username'], 'what' => 'Disk', 'pct' => $pct, 'note' => $p['note'] ?? 'Over its disk quota', 'tone' => 'danger', 'over' => true, 'days' => 0];
                } elseif ($pct >= 85 || ($p['days'] ?? 999) <= 30) {
                    $out[] = ['id' => $id, 'username' => $u['username'], 'what' => 'Disk', 'pct' => $pct, 'note' => $p['note'] ?? round($pct) . '% used', 'tone' => $pct >= 90 ? 'warning' : 'accent', 'over' => false, 'days' => $p['days'] ?? null];
                }
            }
            $bwLimit = Quota::bytes($u['bandwidth_mb']);
            $bw = ($u['bw_month'] ?? '') === date('Y-m') ? (int) $u['bw_bytes'] : 0;
            if ($bwLimit > 0 && $bw > 0) {
                $pct = $bw / $bwLimit * 100;
                $p = self::bandwidthProjection($bw, $bwLimit);
                if ($pct >= 100) {
                    $out[] = ['id' => $id, 'username' => $u['username'], 'what' => 'Bandwidth', 'pct' => $pct, 'note' => 'Used up - its sites answer 509 until ' . date('j M', strtotime('first day of next month')), 'tone' => 'danger', 'over' => true, 'days' => 0];
                } elseif ($pct >= 85 || $p['days'] !== null) {
                    $out[] = ['id' => $id, 'username' => $u['username'], 'what' => 'Bandwidth', 'pct' => $pct, 'note' => $p['note'], 'tone' => $pct >= 90 || $p['days'] !== null ? 'warning' : 'accent', 'over' => false, 'days' => $p['days']];
                }
            }
        }
        usort($out, fn($a, $b) => [$b['over'], $a['days'] ?? 999, $b['pct']] <=> [$a['over'], $b['days'] ?? 999, $a['pct']]);
        return array_slice($out, 0, $max);
    }

    /**
     * When disk use reaches the limit at the rate of the last 14 days (a
     * least-squares line through the daily measures).
     *
     * @return array{days:?int, note:string, perDay:float}
     */
    public static function diskProjection(int $userId, int $used, int $limit): array
    {
        $q = Database::app()->prepare('SELECT day, disk_bytes FROM stat_account_daily WHERE user_id = ? AND day >= ? AND disk_bytes > 0 ORDER BY day');
        $q->execute([$userId, date('Y-m-d', strtotime('-14 day'))]);
        $rows = $q->fetchAll();
        if ($used >= $limit) {
            $since = null;
            $all = Database::app()->prepare('SELECT day FROM stat_account_daily WHERE user_id = ? AND disk_bytes > 0 AND disk_bytes <= ? ORDER BY day DESC LIMIT 1');
            $all->execute([$userId, $limit]);
            $lastUnder = $all->fetchColumn();
            if ($lastUnder) {
                $since = date('j M', strtotime($lastUnder . ' +1 day'));
            }
            return ['days' => 0, 'note' => $since ? "Over since $since" : 'Over its disk quota', 'perDay' => 0.0];
        }
        $slope = self::slope(array_map(fn($r) => [strtotime($r['day']) / 86400, (float) $r['disk_bytes']], $rows));
        if ($slope === null || $slope <= 0) {
            return ['days' => null, 'note' => 'Not growing', 'perDay' => (float) ($slope ?? 0)];
        }
        $days = (int) ceil(($limit - $used) / $slope);
        if ($days > 180) {
            return ['days' => null, 'note' => 'Growing ' . fmt_bytes((int) $slope) . ' a day', 'perDay' => $slope];
        }
        return ['days' => $days, 'note' => 'Reaches its limit around ' . date('j M', strtotime("+$days day")) . ' at this rate', 'perDay' => $slope];
    }

    /**
     * The day this month's bandwidth runs out at this month's pace so far,
     * if it does before the month ends.
     *
     * @return array{days:?int, note:string, projected:float}
     */
    public static function bandwidthProjection(int $used, int $limit, ?int $now = null): array
    {
        $now ??= time();
        $start = strtotime(date('Y-m-01', $now));
        $elapsed = max(1 / 24, ($now - $start) / 86400);
        $daysInMonth = (int) date('t', $now);
        $pace = $used / $elapsed;
        $projected = $pace * $daysInMonth;
        if ($used >= $limit) {
            return ['days' => 0, 'note' => 'Used up this month', 'projected' => $projected];
        }
        if ($projected <= $limit || $elapsed < 1) {
            return ['days' => null, 'note' => 'About ' . fmt_bytes((int) $projected) . ' by the end of the month', 'projected' => $projected];
        }
        $at = $start + (int) ($limit / $pace * 86400);
        return ['days' => max(0, (int) ceil(($at - $now) / 86400)), 'note' => 'Reaches its limit around ' . date('j M', $at) . ' at this pace', 'projected' => $projected];
    }

    /**
     * An account's last $days days: disk (with its parts) and bytes served
     * per day, for the account page.
     *
     * @return array{labels:list<string>, disk:list<float>, files:list<float>, db:list<float>, mail:list<float>, bw:list<float>, requests:list<float>, has:bool}
     */
    public static function accountHistory(int $userId, int $days = 30): array
    {
        $q = Database::app()->prepare('SELECT day, disk_bytes, files_bytes, db_bytes, mail_bytes, bw_bytes, requests FROM stat_account_daily WHERE user_id = ? AND day > ?');
        $q->execute([$userId, date('Y-m-d', strtotime("-$days day"))]);
        $by = [];
        foreach ($q->fetchAll() as $r) {
            $by[$r['day']] = $r;
        }
        $out = ['labels' => [], 'disk' => [], 'files' => [], 'db' => [], 'mail' => [], 'bw' => [], 'requests' => [], 'has' => $by !== []];
        $last = null;
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i day"));
            $r = $by[$day] ?? null;
            $out['labels'][] = date('j M', strtotime($day));
            // A day without a measure carries the last known disk use.
            if ($r !== null && (int) $r['disk_bytes'] > 0) {
                $last = $r;
            }
            $out['disk'][] = (float) ($last['disk_bytes'] ?? 0);
            $out['files'][] = (float) ($last['files_bytes'] ?? 0);
            $out['db'][] = (float) ($last['db_bytes'] ?? 0);
            $out['mail'][] = (float) ($last['mail_bytes'] ?? 0);
            $out['bw'][] = (float) ($r['bw_bytes'] ?? 0);
            $out['requests'][] = (float) ($r['requests'] ?? 0);
        }
        return $out;
    }

    /**
     * Requests per day over the last $days days for each account (the
     * accounts list's sparklines).
     *
     * @param list<int> $userIds
     * @return array<int, list<float>>
     */
    public static function accountSparklines(array $userIds, int $days = 14): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $q = Database::app()->prepare("SELECT user_id, day, requests FROM stat_account_daily WHERE day > ? AND user_id IN ($in)");
        $q->execute(array_merge([date('Y-m-d', strtotime("-$days day"))], $userIds));
        $by = [];
        foreach ($q->fetchAll() as $r) {
            $by[(int) $r['user_id']][$r['day']] = (float) $r['requests'];
        }
        $out = [];
        foreach ($userIds as $id) {
            if (!isset($by[$id])) {
                continue;
            }
            $row = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $row[] = $by[$id][date('Y-m-d', strtotime("-$i day"))] ?? 0.0;
            }
            $out[$id] = $row;
        }
        return $out;
    }

    /** One site over a range, for its Stats tab (see summary()). @return array<string,mixed> */
    public static function domain(int $domainId, string $range): array
    {
        return self::summary($range, ['domain' => $domainId]);
    }

    /**
     * A site (['domain' => id]) or an account's sites (['user' => id]) over a
     * range: totals and the previous period's; per bucket visitors,
     * requests, bytes, pages, page time and status classes; top pages and
     * referrers (one site only); when counting started.
     *
     * @return array<string,mixed>
     */
    public static function summary(string $range, array $scope): array
    {
        $pdo = Database::app();
        $b = self::buckets($range);
        [$where, $params] = self::scopeSql($scope);
        $cols = 'SUM(h.requests) requests, SUM(h.bytes) bytes, SUM(h.visitors) visitors, SUM(h.pages) pages, SUM(h.s2xx) s2xx, SUM(h.s3xx) s3xx, SUM(h.s4xx) s4xx, SUM(h.s5xx) s5xx, SUM(h.time_ms) time_ms, SUM(h.slow) slow';
        $q = $pdo->prepare('SELECT ' . self::bucketSql($b, 'h.hour') . " k, $cols FROM stat_domain_hourly h WHERE $where AND h.hour >= ? GROUP BY k");
        $q->execute(array_merge($params, [$b['from']]));
        $by = [];
        foreach ($q->fetchAll() as $r) {
            $by[$r['k']] = $r;
        }
        $series = ['requests' => [], 'visitors' => [], 'bytes' => [], 'pages' => [], 'time' => [], 'status' => []];
        $sum = array_fill_keys(['requests', 'bytes', 'visitors', 'pages', 's2xx', 's3xx', 's4xx', 's5xx', 'time_ms', 'slow'], 0.0);
        foreach ($b['keys'] as $k) {
            $r = $by[$k] ?? [];
            foreach ($sum as $c => $_) {
                $sum[$c] += (float) ($r[$c] ?? 0);
            }
            $series['requests'][] = (float) ($r['requests'] ?? 0);
            $series['visitors'][] = (float) ($r['visitors'] ?? 0);
            $series['bytes'][] = (float) ($r['bytes'] ?? 0);
            $series['pages'][] = (float) ($r['pages'] ?? 0);
            $series['time'][] = ($r['pages'] ?? 0) > 0 ? (float) $r['time_ms'] / (float) $r['pages'] : 0.0;
            $series['status'][] = [(float) ($r['s2xx'] ?? 0), (float) ($r['s3xx'] ?? 0), (float) ($r['s4xx'] ?? 0), (float) ($r['s5xx'] ?? 0)];
        }
        $seconds = ['24h' => 86400, '7d' => 7 * 86400, '30d' => 30 * 86400][$range];
        $prevStart = date('Y-m-d H:i:s', strtotime($b['from']) - $seconds);
        $since = self::countedSince($where, $params);
        $prev = [];
        if ($since <= $prevStart) {
            $p = $pdo->prepare("SELECT $cols FROM stat_domain_hourly h WHERE $where AND h.hour >= ? AND h.hour < ?");
            $p->execute(array_merge($params, [$prevStart, $b['from']]));
            $prev = array_map('floatval', $p->fetch() ?: []);
        }

        $pages = $refs = [];
        if (isset($scope['domain'])) {
            $top = $pdo->prepare('SELECT kind, value, SUM(hits) hits FROM stat_domain_top WHERE domain_id = ? AND day >= ? GROUP BY kind, value ORDER BY hits DESC');
            $top->execute([(int) $scope['domain'], substr($b['from'], 0, 10)]);
            foreach ($top->fetchAll() as $r) {
                if ($r['value'] === AccessLogStats::OTHER) {
                    continue;
                }
                if ($r['kind'] === 'page' && count($pages) < 10) {
                    $pages[] = [(string) $r['value'], (float) $r['hits']];
                } elseif ($r['kind'] === 'referrer' && count($refs) < 10) {
                    $refs[] = [(string) $r['value'], (float) $r['hits']];
                }
            }
        }
        return [
            'range' => $range, 'per' => $b['per'], 'labels' => $b['labels'], 'series' => $series, 'sum' => $sum,
            'prev' => $prev, 'pages' => $pages, 'referrers' => $refs, 'since' => $since === '9999' ? null : $since,
        ];
    }

    /**
     * Visitors per day over the last $days days for each site, and its
     * visitors and requests in total (the customer's list of sites).
     *
     * @param list<int> $domainIds
     * @return array<int, array{days:list<float>, visitors:float, requests:float}>
     */
    public static function domainSparklines(array $domainIds, int $days = 14): array
    {
        $domainIds = array_values(array_unique(array_map('intval', $domainIds)));
        if (!$domainIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($domainIds), '?'));
        $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));
        $q = Database::app()->prepare("SELECT domain_id, DATE(hour) d, SUM(visitors) v, SUM(requests) r FROM stat_domain_hourly
                                       WHERE domain_id IN ($in) AND hour >= ? GROUP BY domain_id, d");
        $q->execute(array_merge($domainIds, [$since . ' 00:00:00']));
        $by = [];
        foreach ($q->fetchAll() as $r) {
            $by[(int) $r['domain_id']][$r['d']] = [(float) $r['v'], (float) $r['r']];
        }
        $out = [];
        foreach ($domainIds as $id) {
            $row = ['days' => [], 'visitors' => 0.0, 'requests' => 0.0];
            for ($i = $days - 1; $i >= 0; $i--) {
                [$v, $r] = $by[$id][date('Y-m-d', strtotime("-$i day"))] ?? [0.0, 0.0];
                $row['days'][] = $v;
                $row['visitors'] += $v;
                $row['requests'] += $r;
            }
            $out[$id] = $row;
        }
        return $out;
    }

    /** The first hour counted in a scope ('9999' for none yet), so a change is only shown against a fully counted period. */
    private static function countedSince(string $where, array $params): string
    {
        $q = Database::app()->prepare("SELECT MIN(h.hour) FROM stat_domain_hourly h WHERE $where");
        $q->execute($params);
        return (string) ($q->fetchColumn() ?: '9999');
    }

    /** @param list<array{0:float,1:float}> $pts */
    private static function slope(array $pts): ?float
    {
        $n = count($pts);
        if ($n < 3) {
            return null;
        }
        $mx = array_sum(array_column($pts, 0)) / $n;
        $my = array_sum(array_column($pts, 1)) / $n;
        $num = $den = 0.0;
        foreach ($pts as [$x, $y]) {
            $num += ($x - $mx) * ($y - $my);
            $den += ($x - $mx) ** 2;
        }
        return $den > 0 ? $num / $den : null;
    }
}
