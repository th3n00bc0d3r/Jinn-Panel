<?php
declare(strict_types=1);

/**
 * Fills a local panel (dev/local) with statistics, through the same code the
 * server runs: a synthetic access log for the local domains goes through
 * StatsCollector::ingest, and the server, service checks and daily disk use
 * get sample rows for the last 30 days. Run after dev/local/setup.sh:
 *
 *   php dev/stats/seed-local.php [lines=300000] [days=30]
 *
 * Replaces earlier statistics. Never run this against a real server.
 */

require __DIR__ . '/../../app/src/Config.php';
if (!str_contains((string) file_get_contents(__DIR__ . '/../../app/src/Config.php'), 'LOCAL DEV')) {
    fwrite(STDERR, "app/src/Config.php is not a local one (dev/local/setup.sh) - stopping.\n");
    exit(1);
}
require __DIR__ . '/../../app/src/cli_bootstrap.php';
require __DIR__ . '/../../app/src/View.php';

$lines = (int) ($argv[1] ?? 300000);
$days = (int) ($argv[2] ?? 30);
$pdo = Database::app();
$tmp = sys_get_temp_dir() . '/jp-stats-' . getmypid();
mkdir($tmp, 0700, true);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($tmp)));

foreach (['stat_server', 'stat_service_checks', 'stat_account_daily', 'stat_domain_hourly', 'stat_domain_top', 'stat_visitors'] as $t) {
    $pdo->exec("DELETE FROM $t");
}

// 1. Traffic: a log for the local domains, read by the real collector.
$domains = $pdo->query('SELECT domain_name FROM domains ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
if (!$domains) {
    fwrite(STDERR, "No domains - run dev/local/setup.sh first.\n");
    exit(1);
}
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/make-log.php') . " $tmp/access.log $lines $days " . escapeshellarg(implode(',', $domains)) . ' 7');
StatsCollector::$stateDir = $tmp;
StatsCollector::$saltFile = "$tmp/salt";
StatsCollector::$accessLog = "$tmp/access.log";
$r = StatsCollector::ingest(fn($m) => print("  $m\n"), 600.0);
printf("ingest: %d lines in %.1f s (parse + database)\n", $r['lines'], $r['seconds']);
// What the hourly usage run would add to this month's bandwidth.
$bw = StatsCollector::takeBandwidth();
foreach ($pdo->query("SELECT u.id, GROUP_CONCAT(d.domain_name) names FROM users u JOIN domains d ON d.user_id = u.id GROUP BY u.id")->fetchAll() as $u) {
    $bytes = array_sum(array_map(fn($n) => $bw[$n] ?? 0, explode(',', (string) $u['names'])));
    $m = $pdo->prepare('SELECT COALESCE(SUM(bw_bytes), 0) FROM stat_account_daily WHERE user_id = ? AND day >= ?');
    $m->execute([$u['id'], date('Y-m-01')]);
    $month = (int) $m->fetchColumn();
    $pdo->prepare("INSERT INTO account_usage (user_id, bw_month, bw_bytes, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE bw_month = VALUES(bw_month), bw_bytes = VALUES(bw_bytes), updated_at = NOW()")
        ->execute([$u['id'], date('Y-m'), $month]);
}

// 2. The server, per minute: a daily rhythm with noise, disk slowly filling.
mt_srand(11);
$noise = fn(float $a) => (mt_rand() / mt_getrandmax() * 2 - 1) * $a;
$start = strtotime(date('Y-m-d H:i:00')) - $days * 86400;
$rows = [];
$checks = [];
$memTotal = 8 * 1073741824;
$diskTotal = 200 * 1073741824;
for ($t = $start, $i = 0; $t <= time(); $t += 60, $i++) {
    $day = 0.5 + 0.5 * sin((((int) date('G', $t)) - 9) / 24 * 2 * M_PI);
    $cpu = max(1, min(100, 8 + 30 * $day + $noise(6) + ($i % 997 < 6 ? 45 : 0)));
    $rows[] = [date('Y-m-d H:i:00', $t), round($cpu / 25 + $noise(0.1), 2), round($cpu, 2), (int) ($memTotal * (0.48 + 0.15 * $day + $noise(0.03))), $memTotal,
        (int) ($diskTotal * (0.36 + 0.06 * ($t - $start) / ($days * 86400))), $diskTotal, (int) (2e6 + 9e6 * $day * (1 + $noise(0.3))), (int) (8e6 + 4e7 * $day * (1 + $noise(0.3)))];
    foreach (ServerStatus::SERVICES as $svc => $port) {
        $down = $svc === 'sftpgo' && $t > time() - 3 * 3600 && $t < time() - 2 * 3600;
        $checks[] = [$svc, date('Y-m-d H:i:00', $t), $down ? 0 : 1, $down ? null : mt_rand(0, 3)];
    }
}
foreach (array_chunk($rows, 1000) as $c) {
    $pdo->prepare('INSERT INTO stat_server (at, load1, cpu_pct, mem_used, mem_total, disk_used, disk_total, net_rx, net_tx) VALUES ' . implode(',', array_fill(0, count($c), '(?,?,?,?,?,?,?,?,?)')))->execute(array_merge(...$c));
}
foreach (array_chunk($checks, 1000) as $c) {
    $pdo->prepare('INSERT INTO stat_service_checks (service, at, up, ms) VALUES ' . implode(',', array_fill(0, count($c), '(?,?,?,?)')))->execute(array_merge(...$c));
}
printf("server: %d minutes, %d service checks\n", count($rows), count($checks));

// 3. Disk per account per day: growing towards (or past) the package limit.
foreach ($pdo->query("SELECT u.id, u.username, COALESCE(p.disk_quota_mb, 0) q FROM users u LEFT JOIN packages p ON p.id = u.package_id WHERE u.role = 'user'")->fetchAll() as $k => $u) {
    $limit = (int) $u['q'] > 0 ? (int) $u['q'] * 1048576 : 4 * 1073741824;
    $from = $limit * (0.35 + 0.15 * $k);
    $to = $limit * (0.6 + 0.25 * $k);
    for ($d = $days * 2; $d >= 0; $d--) {
        $f = 1 - $d / ($days * 2);
        $disk = (int) ($from + ($to - $from) * $f + $noise($limit * 0.01));
        $pdo->prepare('INSERT INTO stat_account_daily (user_id, day, disk_bytes, files_bytes, db_bytes, mail_bytes) VALUES (?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE disk_bytes = VALUES(disk_bytes), files_bytes = VALUES(files_bytes), db_bytes = VALUES(db_bytes), mail_bytes = VALUES(mail_bytes)')
            ->execute([$u['id'], date('Y-m-d', strtotime("-$d day")), $disk, (int) ($disk * 0.7), (int) ($disk * 0.2), (int) ($disk * 0.1)]);
    }
    $pdo->prepare('INSERT INTO account_usage (user_id, files_bytes, db_bytes, mail_bytes, updated_at) VALUES (?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE files_bytes = VALUES(files_bytes), db_bytes = VALUES(db_bytes), mail_bytes = VALUES(mail_bytes), updated_at = NOW()')
        ->execute([$u['id'], (int) ($to * 0.7), (int) ($to * 0.2), (int) ($to * 0.1)]);
    echo "disk history: {$u['username']}\n";
}
echo "Done.\n";
