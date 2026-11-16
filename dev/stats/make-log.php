<?php
declare(strict_types=1);

/**
 * Writes a synthetic web access log in Caddy's JSON format (the fields
 * StatsCollector reads), for timing the parser and filling a local panel
 * with statistics. Deterministic for a given seed.
 *
 *   php dev/stats/make-log.php OUT.log [lines=200000] [days=1] [hosts=acme.test,shop.acme.test,bakery.test] [seed=1]
 *
 * Lines are spread evenly over the last `days` days up to now, busier by
 * day than at night; about 70% are files (images, scripts), the rest pages.
 */

$out = $argv[1] ?? '';
if ($out === '') {
    fwrite(STDERR, "usage: php dev/stats/make-log.php OUT.log [lines] [days] [hosts] [seed]\n");
    exit(1);
}
$lines = (int) ($argv[2] ?? 200000);
$days = max(1, (int) ($argv[3] ?? 1));
$hosts = explode(',', $argv[4] ?? 'acme.test,shop.acme.test,bakery.test');
mt_srand((int) ($argv[5] ?? 1));

$pick = function (array $weighted) {
    $r = mt_rand() / mt_getrandmax() * array_sum($weighted);
    foreach ($weighted as $k => $w) {
        if (($r -= $w) <= 0) {
            return $k;
        }
    }
    return array_key_last($weighted);
};
$pages = ['/' => 40, '/shop' => 18, '/shop/cart' => 6, '/about' => 5, '/contact' => 4, '/blog' => 6, '/blog/opening-hours' => 3, '/blog/new-menu?utm_source=news' => 2, '/products/sourdough' => 5, '/wp-login.php' => 3, '/search?q=bread' => 2];
$files = ['/assets/app.css' => 20, '/assets/app.js' => 20, '/img/logo.svg' => 15, '/img/hero.webp' => 12, '/favicon.ico' => 8, '/fonts/inter.woff2' => 10, '/img/product-1.jpg' => 6];
$refs = ['' => 60, 'https://www.google.com/' => 20, 'https://duckduckgo.com/' => 5, 'https://www.facebook.com/' => 6, 'https://news.example.org/article' => 3, 'https://SELF/' => 6];
$agents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' => 40,
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1' => 30,
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15' => 15,
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' => 8,
    'curl/8.5.0' => 2,
];
$hostWeight = [];
foreach ($hosts as $i => $h) {
    $hostWeight[$h] = max(1, 10 - 3 * $i);
}

$end = time();
$start = $end - $days * 86400;
$fh = fopen($out, 'wb');
$t0 = microtime(true);
for ($i = 0; $i < $lines; $i++) {
    // Evenly spaced, then pushed towards daytime.
    $ts = $start + ($end - $start) * $i / max(1, $lines);
    $hour = (int) date('G', (int) $ts);
    if ($hour < 7 && mt_rand(0, 2) > 0) {
        $ts += 3600 * mt_rand(7, 10);
        if ($ts > $end) {
            $ts = $end - mt_rand(1, 600);
        }
    }
    $host = $pick($hostWeight);
    $isFile = mt_rand(1, 100) <= 70;
    $uri = $isFile ? $pick($files) : $pick($pages);
    $status = $uri === '/wp-login.php' ? 404 : $pick([200 => 90, 304 => 5, 301 => 2, 404 => 2, 500 => 1]);
    $ref = $pick($refs);
    $ref = str_replace('SELF', $host, $ref);
    $ip = mt_rand(1, 223) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
    if (mt_rand(1, 100) <= 60) {
        $ip = '198.51.100.' . mt_rand(1, 120); // returning visitors
    }
    $headers = ['User-Agent' => [$pick($agents)], 'Accept-Encoding' => ['gzip, br']];
    if ($ref !== '') {
        $headers['Referer'] = [$ref];
    }
    $e = [
        'level' => 'info',
        'ts' => round($ts + mt_rand(0, 999) / 1000, 3),
        'logger' => 'http.log.access',
        'msg' => 'handled request',
        'request' => [
            'remote_ip' => $ip, 'remote_port' => (string) mt_rand(1024, 65535), 'client_ip' => $ip, 'proto' => 'HTTP/2.0',
            'method' => 'GET', 'host' => (mt_rand(0, 9) === 0 ? 'www.' : '') . $host, 'uri' => $uri, 'headers' => $headers,
            'tls' => ['resumed' => false, 'version' => 772, 'cipher_suite' => 4865, 'proto' => 'h2', 'server_name' => $host],
        ],
        'bytes_read' => 0, 'user_id' => '',
        'duration' => $isFile ? mt_rand(1, 8) / 1000 : (mt_rand(1, 100) <= 3 ? mt_rand(1100, 3500) / 1000 : mt_rand(40, 420) / 1000),
        'size' => $status === 304 ? 0 : ($isFile ? mt_rand(2000, 380000) : mt_rand(8000, 90000)),
        'status' => $status,
        'resp_headers' => ['Content-Type' => [$isFile ? 'application/octet-stream' : 'text/html; charset=utf-8'], 'Server' => ['Caddy']],
    ];
    fwrite($fh, json_encode($e, JSON_UNESCAPED_SLASHES) . "\n");
}
fclose($fh);
fprintf(STDERR, "%s: %d lines, %.1f MB, %.1f s\n", $out, $lines, filesize($out) / 1048576, microtime(true) - $t0);
