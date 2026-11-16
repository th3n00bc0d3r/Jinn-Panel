<?php
declare(strict_types=1);

/**
 * Turns lines of the web server's JSON access log (Caddy's "format json",
 * one file for every site - VhostService) into per-domain counts: requests,
 * bytes, pages, status classes, page response time, visitors, top pages and
 * referring sites. Pure: no database, no files; StatsCollector feeds it lines
 * and writes what it adds up.
 *
 * Fields read (Caddy 2): ts, status, size, duration, request.host,
 * request.uri, request.method, request.client_ip / remote_ip,
 * request.headers.User-Agent / Referer, resp_headers.Content-Type. A line
 * without a known host is skipped. No address is kept: a visitor is the
 * 8-byte hash $visitorHash makes from the address and browser.
 */
final class AccessLogStats
{
    /** A request for one of these is a file (image, script...), not a page. */
    private const FILE_EXT = [
        'css', 'js', 'mjs', 'map', 'json', 'xml', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'ogg', 'wav', 'pdf', 'zip', 'gz', 'tar', 'rar', '7z',
        'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'apk', 'exe', 'dmg', 'iso', 'webmanifest',
    ];
    private const BOT = '/bot|crawl|spider|slurp|fetch|scan|curl|wget|python|go-http|java\/|okhttp|httpclient|libwww|monitor|uptime|headless|lighthouse|preview|facebookexternalhit|feed/i';
    public const OTHER = '(other)';

    /** @var array<int, array<int, array<string,int>>> domain id => hour start (unix) => counters */
    private array $hourly = [];
    /** @var array<int, array<string, array<string, array<string,int>>>> domain id => day => kind => value => hits */
    private array $top = [];
    /** @var array<int, array<string, array<string,int>>> domain id => day => visitor hash => hour first seen in this batch */
    private array $visitors = [];
    /** @var array<int,int> domain id => bytes */
    private array $bytes = [];
    private int $bucketMinute = -1;
    /** @var array{0:int,1:string} */
    private array $bucketValue = [0, ''];
    public int $lines = 0;
    public int $counted = 0;

    /**
     * @param array<string,int> $hosts lower-case host (with and without www., aliases too) => domain id
     * @param callable(string $ip, string $agent, string $day): string $visitorHash
     */
    public function __construct(private array $hosts, private $visitorHash)
    {
    }

    /** One log line; false when it isn't a request to a known site. */
    public function add(string $line): bool
    {
        $this->lines++;
        $e = json_decode($line, true);
        if (!is_array($e) || !isset($e['request']) || !is_array($e['request'])) {
            return false;
        }
        $r = $e['request'];
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($r['host'] ?? '')));
        $id = $this->hosts[$host] ?? null;
        if ($id === null) {
            return false;
        }
        [$hour, $day] = $this->bucket((int) ($e['ts'] ?? time()));
        $status = (int) ($e['status'] ?? 0);
        $size = max(0, (int) ($e['size'] ?? 0));
        $path = self::path((string) ($r['uri'] ?? '/'));
        $type = strtolower((string) ($e['resp_headers']['Content-Type'][0] ?? ''));
        $method = strtoupper((string) ($r['method'] ?? 'GET'));
        $page = in_array($method, ['GET', 'HEAD', 'POST'], true) && ($type !== '' ? str_starts_with($type, 'text/html') : !self::isFile($path));

        $c = &$this->hourly[$id][$hour];
        $c ??= ['requests' => 0, 'bytes' => 0, 'pages' => 0, 's2xx' => 0, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0, 'time_ms' => 0, 'slow' => 0];
        $c['requests']++;
        $c['bytes'] += $size;
        $class = intdiv($status, 100);
        if ($class >= 2 && $class <= 5) {
            $c['s' . $class . 'xx']++;
        }
        $this->bytes[$id] = ($this->bytes[$id] ?? 0) + $size;

        if ($page) {
            $c['pages']++;
            $ms = self::durationMs($e['duration'] ?? 0);
            $c['time_ms'] += $ms;
            if ($ms > 1000) {
                $c['slow']++;
            }
            $agent = (string) ($r['headers']['User-Agent'][0] ?? '');
            if ($status < 400) {
                $this->top[$id][$day]['page'][$path] = ($this->top[$id][$day]['page'][$path] ?? 0) + 1;
                $ref = self::referrer((string) ($r['headers']['Referer'][0] ?? ''));
                if ($ref !== '' && ($this->hosts[$ref] ?? null) !== $id && ($this->hosts['www.' . $ref] ?? null) !== $id) {
                    $this->top[$id][$day]['referrer'][$ref] = ($this->top[$id][$day]['referrer'][$ref] ?? 0) + 1;
                }
                if ($agent !== '' && !preg_match(self::BOT, $agent)) {
                    $ip = (string) ($r['client_ip'] ?? $r['remote_ip'] ?? '');
                    $hash = ($this->visitorHash)($ip, $agent, $day);
                    $this->visitors[$id][$day][$hash] ??= $hour;
                }
            }
        }
        unset($c);
        $this->counted++;
        return true;
    }

    /** The start of the local hour (as a timestamp) and the local day of $ts, cached per minute. @return array{0:int,1:string} */
    private function bucket(int $ts): array
    {
        $min = intdiv($ts, 60);
        if ($min !== $this->bucketMinute) {
            $this->bucketMinute = $min;
            $this->bucketValue = [(int) strtotime(date('Y-m-d H:00:00', $ts)), date('Y-m-d', $ts)];
        }
        return $this->bucketValue;
    }

    /** @return array<int, array<int, array<string,int>>> */
    public function hourly(): array
    {
        return $this->hourly;
    }

    /**
     * Top pages and referrers, at most $keep values per domain, day and
     * kind; the rest add up under "(other)".
     *
     * @return array<int, array<string, array<string, array<string,int>>>>
     */
    public function top(int $keep = 100): array
    {
        $out = $this->top;
        foreach ($out as $id => $days) {
            foreach ($days as $day => $kinds) {
                foreach ($kinds as $kind => $values) {
                    if (count($values) > $keep) {
                        arsort($values);
                        $rest = array_sum(array_slice($values, $keep, null, true));
                        $values = array_slice($values, 0, $keep, true);
                        $values[self::OTHER] = ($values[self::OTHER] ?? 0) + $rest;
                    }
                    $out[$id][$day][$kind] = $values;
                }
            }
        }
        return $out;
    }

    /** @return array<int, array<string, array<string,int>>> domain id => day => hash => hour first seen */
    public function visitors(): array
    {
        return $this->visitors;
    }

    /** @return array<int,int> domain id => bytes served */
    public function bytes(): array
    {
        return $this->bytes;
    }

    /** The path without its query string, at most 190 characters, valid UTF-8. */
    public static function path(string $uri): string
    {
        $p = strtok($uri, '?#');
        $p = ($p === false || $p === '') ? '/' : rawurldecode($p);
        $p = mb_scrub($p, 'UTF-8');
        $p = preg_replace('/[\x00-\x1f\x7f]/', '', $p) ?? '/';
        return mb_substr($p, 0, 190);
    }

    public static function isFile(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, self::FILE_EXT, true);
    }

    /** The referring site's host, without www.; '' for none or not a web address. */
    public static function referrer(string $ref): string
    {
        if ($ref === '' || !preg_match('#^https?://#i', $ref)) {
            return '';
        }
        $host = strtolower((string) parse_url($ref, PHP_URL_HOST));
        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            return '';
        }
        return mb_substr(str_starts_with($host, 'www.') ? substr($host, 4) : $host, 0, 190);
    }

    /**
     * Caddy logs the duration in seconds (a float) by default; with
     * duration_format it can be nanoseconds (an integer) or a string like "12.5ms".
     */
    public static function durationMs(mixed $d): int
    {
        if (is_string($d) && preg_match('/^([\d.]+)\s*(ns|µs|us|ms|s|m)$/u', trim($d), $m)) {
            $f = ['ns' => 1e-6, 'µs' => 1e-3, 'us' => 1e-3, 'ms' => 1, 's' => 1000, 'm' => 60000][$m[2]];
            return (int) round((float) $m[1] * $f);
        }
        if (is_int($d) && $d > 100000) {
            return (int) round($d / 1e6); // nanoseconds
        }
        return (int) round(max(0, (float) $d) * 1000);
    }
}
