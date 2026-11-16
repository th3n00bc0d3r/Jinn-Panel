<?php
declare(strict_types=1);

/**
 * How long the access log takes to read: parses a log (make-log.php) with
 * AccessLogStats, no database, and prints lines per second and memory.
 *
 *   php dev/stats/make-log.php /tmp/a.log 500000
 *   php dev/stats/bench.php /tmp/a.log [hosts=acme.test,shop.acme.test,bakery.test]
 */

require __DIR__ . '/../../app/src/Support/AccessLogStats.php';

$file = $argv[1] ?? '';
if (!is_file($file)) {
    fwrite(STDERR, "usage: php dev/stats/bench.php LOG [hosts]\n");
    exit(1);
}
$hosts = [];
foreach (explode(',', $argv[2] ?? 'acme.test,shop.acme.test,bakery.test') as $i => $h) {
    $hosts[$h] = $hosts["www.$h"] = $i + 1;
}
$salt = bin2hex(random_bytes(16));
$agg = new AccessLogStats($hosts, fn(string $ip, string $ua, string $day) => substr(hash('sha256', "$salt\n$ip\n$ua", true), 0, 8));

$t0 = hrtime(true);
$fh = fopen($file, 'rb');
while (($line = fgets($fh)) !== false) {
    $agg->add($line);
}
fclose($fh);
$s = (hrtime(true) - $t0) / 1e9;

$visitors = array_sum(array_map(fn($days) => array_sum(array_map('count', $days)), $agg->visitors()));
printf("%d lines (%d counted) in %.2f s: %s lines/s, %.1f MB of log/s, peak memory %.0f MB\n",
    $agg->lines, $agg->counted, $s, number_format($agg->lines / max($s, 1e-9)), filesize($file) / 1048576 / max($s, 1e-9), memory_get_peak_usage(true) / 1048576);
printf("domains: %d, hour rows: %d, distinct visitors: %d, top values: %d\n",
    count($agg->hourly()), array_sum(array_map('count', $agg->hourly())), $visitors,
    array_sum(array_map(fn($d) => array_sum(array_map(fn($k) => array_sum(array_map('count', $k)), $d)), $agg->top())));
