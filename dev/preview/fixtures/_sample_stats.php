<?php
declare(strict_types=1);

/** Deterministic sample statistics for the preview (charts, dashboards) (same numbers on every load). */

function stats_rand(int $seed): callable
{
    mt_srand($seed);
    return fn(float $lo = 0, float $hi = 1): float => $lo + mt_rand() / mt_getrandmax() * ($hi - $lo);
}

/** Traffic per bucket for the busiest sites, with a spike on one of them. @return array{labels:list<string>, series:list<array>, stacks:list<list<float>>, spike:int} */
function stats_traffic(string $range): array
{
    $r = stats_rand(42);
    [$n, $step, $fmt] = match ($range) {
        '7d' => [42, 4 * 3600, 'D H:00'],
        '30d' => [30, 86400, 'j M'],
        default => [24, 3600, 'H:00'],
    };
    $end = strtotime('2026-11-14 16:00:00');
    $sites = [
        ['name' => 'shop.acme.test', 'series' => 's1', 'base' => 2400],
        ['name' => 'acme.test', 'series' => 's2', 'base' => 1700],
        ['name' => 'bakery.test', 'series' => 's3', 'base' => 900],
        ['name' => 'clinic-north.example', 'series' => 's4', 'base' => 650],
        ['name' => 'Other sites', 'series' => 'so', 'base' => 1300],
    ];
    $mult = $range === '30d' ? 24 : ($range === '7d' ? 4 : 1);
    $spike = $range === '24h' ? 14 : ($range === '7d' ? 38 : 27);
    $labels = [];
    $stacks = [];
    for ($i = 0; $i < $n; $i++) {
        $ts = $end - ($n - 1 - $i) * $step;
        $labels[] = date($fmt, $ts);
        $hour = (int) date('G', $ts);
        $day = $range === '30d' ? 1.0 : 0.55 + 0.45 * sin(($hour - 8) / 24 * 2 * M_PI + M_PI / 2) ** 2;
        $col = [];
        foreach ($sites as $k => $s) {
            $v = $s['base'] * $mult * $day * $r(0.82, 1.15);
            if ($k === 0 && $i === $spike) {
                $v *= 3.4;
            }
            $col[] = round($v);
        }
        $stacks[] = $col;
    }
    return ['labels' => $labels, 'series' => array_map(fn($s) => ['name' => $s['name'], 'series' => $s['series']], $sites), 'stacks' => $stacks, 'spike' => $spike];
}

/** A smooth daily-ish series for sparklines and small charts. @return list<float> */
function stats_series(int $seed, int $n, float $base, float $wiggle = 0.25, float $trend = 0.0): array
{
    $r = stats_rand($seed);
    $out = [];
    $v = $base;
    for ($i = 0; $i < $n; $i++) {
        $v = max(0, $v * (1 + $r(-$wiggle, $wiggle) / 3) + $base * $trend / $n);
        $out[] = round($v * (0.9 + 0.2 * sin($i / 3)), 2);
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function stats_accounts(): array
{
    $names = [
        ['acme', 'Acme Ltd', 'owner@acme.test', 'Business', 'resell'], ['bakery', 'The Bakery', 'hello@bakery.test', 'Starter', null],
        ['clinicnorth', 'Clinic North', 'it@clinic-north.example', 'Business', null], ['dunmore', 'Dunmore Law', 'office@dunmore.example', 'Business', 'resell'],
        ['eastgate', 'Eastgate School', 'web@eastgate.example', 'Unlimited', null], ['fernhill', 'Fern Hill Farm', 'shop@fernhill.example', 'Starter', 'resell'],
        ['grayson', 'Grayson Studio', 'hi@grayson.example', 'Business', null], ['harbour', 'Harbour Cafe', 'menu@harbour.example', 'Starter', null],
        ['ivyprint', 'Ivy Print', 'orders@ivyprint.example', 'Business', 'resell'], ['juno', 'Juno Yoga', 'class@juno.example', 'Starter', null],
        ['kestrel', 'Kestrel Motors', 'sales@kestrel.example', 'Business', null], ['lumen', 'Lumen Photo', 'studio@lumen.example', 'Starter', 'resell'],
    ];
    $out = [];
    foreach ($names as $i => [$u, $full, $email, $pkg, $res]) {
        $r = stats_rand(100 + $i);
        $limit = match ($pkg) { 'Starter' => 1024, 'Unlimited' => -1, default => 5120 };
        $used = $u === 'bakery' ? $limit * 1.08 : ($limit > 0 ? $limit * $r(0.12, 0.94) : $r(2000, 9000));
        $out[] = [
            'id' => 10 + $i, 'username' => $u, 'full_name' => $full, 'email' => $email, 'package' => $pkg, 'reseller' => $res,
            'disk_used_mb' => round($used), 'disk_limit_mb' => $limit, 'domains' => (int) round($r(1, 5)),
            'traffic' => stats_series(200 + $i, 14, $r(200, 4000), 0.35, $u === 'acme' ? 0.8 : 0),
            'status' => $u === 'harbour' ? 'suspended' : 'active',
            'created' => date('Y-m-d', strtotime('2026-11-14') - (int) $r(5, 400) * 86400),
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// The shapes StatsService returns, from the samples above (fixtures).

function stats_range(): string
{
    return in_array($_GET['range'] ?? '', ['24h', '7d', '30d'], true) ? $_GET['range'] : '24h';
}

/** StatsService::traffic() */
function stats_fx_traffic(?string $range = null, string $metric = 'requests'): array
{
    $range ??= stats_range();
    $t = stats_traffic($range);
    $mult = $metric === 'visitors' ? 0.18 : ($metric === 'bytes' ? 52000 : 1);
    $stacks = array_map(fn($c) => array_map(fn($v) => round($v * $mult), $c), $t['stacks']);
    $total = array_sum(array_map('array_sum', $stacks));
    $usual = array_sum(array_column($stacks, 0)) / count($stacks);
    $busiest = [];
    foreach ($t['series'] as $k => $s) {
        if ($s['series'] !== 'so') {
            $busiest[] = ['id' => $k + 1, 'domain_name' => $s['name'], 'username' => $k < 2 ? 'acme' : 'bakery', 'n' => array_sum(array_column($stacks, $k))];
        }
    }
    foreach ([['lumen.example', 0.09], ['juno.example', 0.06], ['harbour.example', 0.04]] as $i => [$name, $f]) {
        $busiest[] = ['id' => 10 + $i, 'domain_name' => $name, 'username' => explode('.', $name)[0], 'n' => round($total * $f)];
    }
    usort($busiest, fn($a, $b) => $b['n'] <=> $a['n']);
    return [
        'range' => $range, 'per' => ['24h' => 'hour', '7d' => '4 hours', '30d' => 'day'][$range], 'labels' => $t['labels'], 'series' => $t['series'], 'stacks' => $stacks,
        'total' => $total, 'previous' => $total / 1.12, 'spike' => [$t['spike'], 'shop.acme.test ' . number_format($stacks[$t['spike']][0] / $usual, 1) . '× usual'],
        'busiest' => array_slice($busiest, 0, 7), 'metric' => $metric,
    ];
}

/** StatsService::server() */
function stats_fx_server(): array
{
    $bw = stats_series(9, 14, 120e9, 0.3, 0.4);
    return [
        'cpu' => array_map(fn($v) => $v * 40, stats_series(7, 48, 0.6, 0.5)),
        'mem' => stats_series(8, 48, 58, 0.08),
        'load' => stats_series(7, 48, 0.6, 0.5),
        'latest' => null,
        'bwDays' => $bw,
        'bwMonth' => array_sum($bw),
    ];
}

/** StatsService::serverRange() */
function stats_fx_server_range(?string $range = null): array
{
    $range ??= stats_range();
    $n = ['24h' => 24, '7d' => 42, '30d' => 30][$range];
    $labels = stats_traffic($range)['labels'];
    return [
        'labels' => $labels, 'per' => ['24h' => 'hour', '7d' => '4 hours', '30d' => 'day'][$range],
        'cpu' => array_map(fn($v) => min(100, $v * 40), stats_series(17, $n, 0.6, 0.5)),
        'mem' => stats_series(18, $n, 58, 0.08),
        'load' => stats_series(17, $n, 0.6, 0.5),
        'tx' => stats_series(19, $n, 4e9, 0.3),
        'has' => true,
    ];
}

/** StatsService::uptime() */
function stats_fx_uptime(): array
{
    $up = array_fill(0, 48, 'up');
    $sftp = array_merge(array_fill(0, 40, 'up'), ['down', 'down', 'down'], array_fill(0, 5, 'up'));
    $mail = array_merge(array_fill(0, 30, 'up'), ['slow'], array_fill(0, 17, 'up'));
    return [
        'mariadb' => ['cells' => $up, 'pct' => 100.0, 'down' => '', 'checks' => 1440],
        'frankenphp' => ['cells' => $up, 'pct' => 100.0, 'down' => '', 'checks' => 1440],
        'stalwart' => ['cells' => $mail, 'pct' => 99.93, 'down' => '', 'checks' => 1440],
        'sftpgo' => ['cells' => $sftp, 'pct' => 93.75, 'down' => 'down from 12:00 to 13:30', 'checks' => 1440],
        'knot' => ['cells' => $up, 'pct' => 100.0, 'down' => '', 'checks' => 1440],
    ];
}

/** StatsService::nearingLimits() */
function stats_fx_limits(): array
{
    return [
        ['id' => 12, 'username' => 'bakery', 'what' => 'Disk', 'pct' => 108.0, 'note' => 'Over since 9 Nov', 'tone' => 'danger', 'over' => true, 'days' => 0],
        ['id' => 10, 'username' => 'acme', 'what' => 'Bandwidth', 'pct' => 82.4, 'note' => 'Runs out around 24 Nov at this pace', 'tone' => 'warning', 'over' => false, 'days' => 10],
        ['id' => 14, 'username' => 'clinicnorth', 'what' => 'Disk', 'pct' => 86.0, 'note' => 'Full around 2 Dec', 'tone' => 'accent', 'over' => false, 'days' => 18],
    ];
}

/** StatsService::sites() */
function stats_fx_sites(): array
{
    $out = [];
    foreach (stats_fx_traffic()['busiest'] as $i => $b) {
        $out[] = ['id' => $b['id'], 'domain_name' => $b['domain_name'], 'username' => $b['username'], 'requests' => $b['n'], 'visitors' => round($b['n'] * 0.18),
            'bytes' => $b['n'] * 52000, 'errors' => $i === 1 ? 312 : round($b['n'] * 0.002), 'avg_ms' => [182, 640, 95, 240, 130, 75, 410][$i] ?? 150];
    }
    return $out;
}

/** StatsService::accountHistory() */
function stats_fx_account_history(float $limitBytes, float $from = 0.55, float $to = 0.82): array
{
    $labels = $disk = $files = $db = $mail = $bw = $req = [];
    $r = stats_rand(77);
    for ($i = 29; $i >= 0; $i--) {
        $labels[] = date('j M', strtotime('2026-11-14') - $i * 86400);
        $f = 1 - $i / 29;
        $d = $limitBytes * ($from + ($to - $from) * $f) * $r(0.99, 1.01);
        $disk[] = round($d);
        $files[] = round($d * 0.7);
        $db[] = round($d * 0.2);
        $mail[] = round($d * 0.1);
        $bw[] = round(1.4e9 * $r(0.6, 1.4) * (1 + $f * 0.5));
        $req[] = round(9000 * $r(0.6, 1.4));
    }
    return ['labels' => $labels, 'disk' => $disk, 'files' => $files, 'db' => $db, 'mail' => $mail, 'bw' => $bw, 'requests' => $req, 'has' => true];
}

/** StatsService::domain() */
function stats_fx_domain(?string $range = null): array
{
    $range ??= stats_range();
    $t = stats_traffic($range);
    $req = array_map(fn($c) => $c[0] + $c[1] * 0.3, $t['stacks']);
    $vis = array_map(fn($v) => round($v * 0.17), $req);
    $pages = array_map(fn($v) => round($v * 0.32), $req);
    $r = stats_rand(5);
    $time = array_map(fn($v) => round(160 + 90 * $r(0, 1) + ($v > 6000 ? 300 : 0)), $req);
    $status = array_map(fn($v) => [round($v * 0.91), round($v * 0.05), round($v * 0.035), round($v * ($r(0, 1) > 0.9 ? 0.02 : 0.003))], $req);
    $sum = ['requests' => array_sum($req), 'bytes' => array_sum($req) * 48000, 'visitors' => array_sum($vis), 'pages' => array_sum($pages),
        's2xx' => array_sum(array_column($status, 0)), 's3xx' => array_sum(array_column($status, 1)), 's4xx' => array_sum(array_column($status, 2)), 's5xx' => array_sum(array_column($status, 3)),
        'time_ms' => array_sum(array_map(fn($p, $t) => $p * $t, $pages, $time)), 'slow' => round(array_sum($pages) * 0.012)];
    $prev = array_map(fn($v) => $v / 1.09, $sum);
    return [
        'range' => $range, 'per' => ['24h' => 'hour', '7d' => '4 hours', '30d' => 'day'][$range], 'labels' => $t['labels'],
        'series' => ['requests' => $req, 'visitors' => $vis, 'bytes' => array_map(fn($v) => $v * 48000, $req), 'pages' => $pages, 'time' => $time, 'status' => $status],
        'sum' => $sum, 'prev' => $prev,
        'pages' => [['/', 18200], ['/shop', 9400], ['/shop/cart', 3100], ['/products/sourdough', 2650], ['/about', 1640], ['/blog/opening-hours', 980], ['/contact', 620]],
        'referrers' => [['google.com', 5300], ['facebook.com', 1500], ['duckduckgo.com', 1350], ['news.example.org', 790], ['bing.com', 310]],
        'since' => '2026-08-01 00:00:00',
    ];
}
