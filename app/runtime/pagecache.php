<?php
/**
 * JinnPanel page cache (cPanel > Cache > Page cache), Batcache-style: full
 * pages for anonymous visitors, stored as files under
 * /var/lib/jinnpanel-pagecache/<domain>/ for the site's TTL.
 *
 * Only GET/HEAD without credentials or session/login cookies is served from
 * or stored in the cache, and only a 200 text/html response that sets no
 * cookie and isn't marked private/no-store/no-cache is stored - so logged-in
 * users, carts and forms never see someone else's page.
 */
function jinnpanel_page_cache(string $domain, int $ttl): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (($method !== 'GET' && $method !== 'HEAD') || isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['PHP_AUTH_USER'])) {
        return;
    }
    foreach (array_keys($_COOKIE) as $c) {
        // Analytics cookies don't change the page; anything else might.
        if (!preg_match('/^(_ga|_gid|_gat|_gcl_|_fbp|_fbc|__utm|_hj|_clck|_clsk|cookielawinfo|cookie_notice|cmplz_)/i', (string) $c)) {
            header('X-JinnPanel-Cache: BYPASS');
            return;
        }
    }
    if (stripos((string) ($_SERVER['HTTP_CACHE_CONTROL'] ?? ''), 'no-cache') !== false) {
        header('X-JinnPanel-Cache: BYPASS');
        return;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string) parse_url($uri, PHP_URL_PATH);
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
    $q = array_filter($q, fn($k) => !preg_match('/^(utm_\w+|fbclid|gclid|msclkid|mc_cid|mc_eid)$/i', (string) $k), ARRAY_FILTER_USE_KEY);
    ksort($q);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $domain));
    $key = hash('sha256', "$scheme|$host|$path?" . http_build_query($q));
    $dir = '/var/lib/jinnpanel-pagecache/' . $domain . '/' . substr($key, 0, 2);
    $file = "$dir/$key";

    if (is_file($file) && ($age = time() - (int) filemtime($file)) < $ttl) {
        $data = @file_get_contents($file);
        $nl = $data === false ? false : strpos($data, "\n");
        if ($nl !== false) {
            $meta = json_decode(substr($data, 0, $nl), true);
            if (is_array($meta)) {
                foreach ((array) ($meta['headers'] ?? []) as $h) {
                    header($h);
                }
                header('X-JinnPanel-Cache: HIT');
                header('Age: ' . $age);
                if ($method === 'GET') {
                    echo substr($data, $nl + 1);
                }
                exit;
            }
        }
    }

    header('X-JinnPanel-Cache: MISS');
    ob_start(static function (string $body, int $phase) use ($dir, $file): string {
        if (!($phase & PHP_OUTPUT_HANDLER_FINAL) || http_response_code() !== 200 || strlen($body) > 4194304 || $body === '') {
            return $body;
        }
        $keep = [];
        foreach (headers_list() as $h) {
            [$name, $value] = array_map('trim', explode(':', $h, 2)) + [1 => ''];
            $n = strtolower($name);
            if ($n === 'set-cookie' || ($n === 'cache-control' && preg_match('/private|no-store|no-cache/i', $value)) || ($n === 'pragma' && stripos($value, 'no-cache') !== false)) {
                return $body;
            }
            if ($n === 'content-type' && stripos($value, 'text/html') === false) {
                return $body;
            }
            if (in_array($n, ['content-type', 'content-language', 'link', 'x-robots-tag', 'vary', 'content-security-policy', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'strict-transport-security', 'permissions-policy'], true)) {
                $keep[] = "$name: $value";
            }
        }
        if (!preg_grep('/^content-type:/i', $keep)) {
            // No explicit type: PHP sends its default (normally text/html).
            $mime = (string) ini_get('default_mimetype') ?: 'text/html';
            if (stripos($mime, 'text/html') !== 0) {
                return $body;
            }
            $charset = (string) ini_get('default_charset');
            $keep[] = 'Content-Type: ' . $mime . ($charset !== '' ? "; charset=$charset" : '');
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $tmp = "$file." . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['headers' => $keep]) . "\n" . $body) !== false) {
            @rename($tmp, $file);
        }
        return $body;
    });
}
