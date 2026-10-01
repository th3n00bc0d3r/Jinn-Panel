<?php
declare(strict_types=1);

/**
 * Tests for HtaccessTranslator - no dependencies, run with:
 *
 *     php tests/HtaccessTranslatorTest.php [case-name-filter]
 *
 * For every fixture under tests/fixtures/htaccess/<case>/ (a tree of
 * .htaccess files, like a docroot) it translates the files, checks that
 * the output passes HtaccessTranslator::validate() and `frankenphp adapt`,
 * then starts `frankenphp run` on 127.0.0.1:18999 with a temporary docroot
 * and checks with HTTP requests that it behaves like Apache would.
 *
 * The site block mirrors VhostService: the rules are imported inside
 * `route { }` after the same dotfile guard. It listens on any host name
 * (bound to 127.0.0.1) so host-based rules can be tested.
 *
 * Needs the frankenphp binary and PHP's curl extension; exits non-zero on
 * any failure.
 */

require dirname(__DIR__) . '/app/src/Services/HtaccessTranslator.php';

const PORT = 18999;
const FIXTURES = __DIR__ . '/fixtures/htaccess';

/** Every .php file in a test docroot prints which script ran and what it got. */
const PHP_PROBE = <<<'PHP'
<?php echo 'php:', $_SERVER['SCRIPT_NAME'], ' uri=', $_SERVER['REQUEST_URI'], ' qs=', $_SERVER['QUERY_STRING'] ?? '', ' pi=', $_SERVER['PATH_INFO'] ?? '', "\n";
PHP;

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static string $case = '';

    public static function ok(bool $cond, string $what, string $detail = ''): void
    {
        if ($cond) {
            self::$pass++;
            return;
        }
        self::$fail++;
        echo "  FAIL [" . self::$case . "] {$what}" . ($detail !== '' ? "\n       {$detail}" : '') . "\n";
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** @return array<string, string> folder relative to the docroot => .htaccess text */
function loadFixture(string $case): array
{
    $dir = FIXTURES . '/' . $case;
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getFilename() === '.htaccess') {
            $rel = trim(substr(dirname($f->getPathname()), strlen($dir)), '/');
            $files[$rel] = (string)file_get_contents($f->getPathname());
        }
    }
    ksort($files);
    return $files;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** @param array<int|string, string> $spec list of paths (dirs end in /) or path => content */
function makeDocroot(string $root, array $spec): void
{
    mkdir($root, 0755, true);
    foreach ($spec as $k => $v) {
        [$path, $content] = is_int($k) ? [$v, null] : [$k, $v];
        $full = $root . '/' . ltrim($path, '/');
        if (str_ends_with($path, '/')) {
            @mkdir($full, 0755, true);
            continue;
        }
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, $content ?? (str_ends_with($path, '.php') ? PHP_PROBE : 'static:/' . ltrim($path, '/') . "\n"));
    }
}

function caddyfile(string $docroot, string $routeFile, ?string $siteFile): string
{
    $site = $siteFile !== null ? "\timport {$siteFile}\n" : '';
    // Same dotfile guard as VhostService::create().
    return "{\n\tadmin off\n\tauto_https off\n}\n"
        . "http://:" . PORT . " {\n\tbind 127.0.0.1\n\troot * {$docroot}\n{$site}\troute {\n"
        . "\t\t@hidden {\n\t\t\tpath_regexp hidden (/\\.[^/]|/php\\.ini\$)\n\t\t\tnot path /.well-known/*\n\t\t}\n\t\trespond @hidden 404\n"
        . "\t\timport {$routeFile}\n\t}\n}\n";
}

function frankenEnv(string $tmp): array
{
    @mkdir($tmp . '/xdg', 0700, true);
    // Keep Caddy's autosave/data out of the real home directory.
    return ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $tmp . '/xdg', 'XDG_CONFIG_HOME' => $tmp . '/xdg', 'XDG_DATA_HOME' => $tmp . '/xdg'];
}

/** @return array{0:int, 1:string} exit code, output */
function run(array $cmd, array $env): array
{
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out];
}

/** @return array{proc: resource, log: string}|string the server, or an error */
function startServer(string $config, string $tmp)
{
    if (@fsockopen('127.0.0.1', PORT, $e, $s, 0.2)) {
        return 'port ' . PORT . ' is already in use';
    }
    $log = $tmp . '/frankenphp.log';
    $proc = proc_open(['frankenphp', 'run', '--config', $config, '--adapter', 'caddyfile'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, null, frankenEnv($tmp));
    for ($i = 0; $i < 100; $i++) {
        usleep(100000);
        $st = proc_get_status($proc);
        if (!$st['running']) {
            proc_close($proc);
            return 'frankenphp exited: ' . trim((string)file_get_contents($log));
        }
        // The listener opens before the PHP threads are ready; wait for the log line.
        if (!str_contains((string)@file_get_contents($log), 'serving initial configuration')) {
            continue;
        }
        $sock = @fsockopen('127.0.0.1', PORT, $e, $s, 0.2);
        if ($sock) {
            fclose($sock);
            return ['proc' => $proc, 'log' => $log];
        }
    }
    stopServer(['proc' => $proc, 'log' => $log]);
    return 'frankenphp did not start listening';
}

function stopServer(array $server): void
{
    proc_terminate($server['proc'], 15);
    for ($i = 0; $i < 30; $i++) {
        if (!proc_get_status($server['proc'])['running']) {
            break;
        }
        usleep(100000);
    }
    if (proc_get_status($server['proc'])['running']) {
        proc_terminate($server['proc'], 9);
    }
    proc_close($server['proc']);
}

/** @return array{status:int, headers:array<string, list<string>>, body:string} */
function request(string $method, string $path, string $host, array $headers = []): array
{
    $ch = curl_init('http://127.0.0.1:' . PORT . $path);
    $hdrs = ['Host: ' . $host];
    foreach ($headers as $k => $v) {
        $hdrs[] = "{$k}: {$v}";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_PATH_AS_IS => true,
        CURLOPT_NOBODY => $method === 'HEAD',
    ]);
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $out = ['status' => $status, 'headers' => [], 'body' => $status === 0 ? 'curl: ' . curl_error($ch) : substr($raw, $hsize)];
    foreach (preg_split('/\r?\n/', substr($raw, 0, $hsize)) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $out['headers'][strtolower(trim($k))][] = trim($v);
        }
    }
    return $out;
}

/**
 * One expectation: [ 'GET /path', status|[statuses], options ]
 * options: body (substring or list), nobody, location, headers (name => value|null = absent),
 * host, send (request headers).
 */
function check(array $e, string $defaultHost): void
{
    [$what, $status] = $e;
    $opt = $e[2] ?? [];
    [$method, $path] = explode(' ', $what, 2);
    $r = request($method, $path, $opt['host'] ?? $defaultHost, $opt['send'] ?? []);
    $label = $what . (isset($opt['host']) ? " (Host {$opt['host']})" : '');
    $statuses = (array)$status;
    $summary = "got {$r['status']}" . (isset($r['headers']['location']) ? ' -> ' . $r['headers']['location'][0] : '') . ': ' . trim(substr($r['body'], 0, 160));
    T::ok(in_array($r['status'], $statuses, true), "{$label}: status " . implode('/', $statuses), $summary);
    foreach ((array)($opt['body'] ?? []) as $needle) {
        T::ok(str_contains($r['body'], $needle), "{$label}: body contains \"{$needle}\"", $summary);
    }
    foreach ((array)($opt['nobody'] ?? []) as $needle) {
        T::ok(!str_contains($r['body'], $needle), "{$label}: body lacks \"{$needle}\"", $summary);
    }
    if (array_key_exists('location', $opt)) {
        $loc = $r['headers']['location'][0] ?? null;
        T::ok($loc === $opt['location'], "{$label}: Location {$opt['location']}", 'got ' . var_export($loc, true));
    }
    foreach ($opt['headers'] ?? [] as $name => $value) {
        $got = $r['headers'][strtolower($name)] ?? null;
        if ($value === null) {
            T::ok($got === null, "{$label}: no {$name} header", 'got ' . json_encode($got));
        } else {
            T::ok($got !== null && in_array($value, $got, true) && count($got) === 1, "{$label}: {$name}: {$value}", 'got ' . json_encode($got));
        }
    }
}

function notesWith(array $result, string $status): array
{
    return array_values(array_filter($result['notes'], static fn ($n) => $n['status'] === $status));
}

// ---------------------------------------------------------------------------
// Cases: fixture => docroot files, requests (expected as Apache answers them,
// differences that are inherent to Caddy are spelled out), extra checks.
// ---------------------------------------------------------------------------

$cases = [
    'wordpress' => [
        'docroot' => ['index.php', 'wp-config.php', 'wp-login.php', 'wp-content/themes/t/style.css', 'wp-admin/index.php'],
        'requests' => [
            ['GET /', 200, ['body' => 'php:/index.php uri=/ ']],
            ['GET /hello-world/?replytocom=5', 200, ['body' => ['php:/index.php uri=/hello-world/?replytocom=5', 'qs=replytocom=5']]],
            ['GET /wp-content/themes/t/style.css', 200, ['body' => 'static:/wp-content/themes/t/style.css']],
            ['GET /wp-login.php', 200, ['body' => 'php:/wp-login.php']],
            ['GET /wp-admin/', 200, ['body' => 'php:/wp-admin/index.php']],
            ['GET /wp-admin', [301, 308], ['location' => '/wp-admin/']],    // Apache 301, Caddy 308
            ['GET /wp-config.php', 403],
            ['GET /index.php', 200, ['body' => 'php:/index.php']],
            ['GET /missing.php', 200, ['body' => 'php:/index.php uri=/missing.php']],
            ['GET /.htaccess', 404],   // dotfile guard (Apache: 403)
        ],
        'extra' => function (array $r): void {
            T::ok(!$r['needs_review'], 'WordPress needs no review');
            T::ok(substr_count($r['route'], "\n") <= 25, 'WordPress route stays compact', $r['route']);
            T::ok(str_contains($r['route'], "rewrite * /index.php"), 'front controller rewrite present');
            T::ok($r['site'] === '', 'no site rules needed');
            T::ok((bool)array_filter($r['notes'], static fn ($n) => str_contains($n['directive'], 'BEGIN cPanel-generated') && $n['status'] === 'ignored'), 'cPanel php ini block ignored with a note');
        },
    ],
    'laravel' => [
        'docroot' => ['index.php', 'css/app.css', 'build/'],
        'requests' => [
            ['GET /', 200, ['body' => 'php:/index.php uri=/ ']],
            ['GET /users/5', 200, ['body' => 'php:/index.php uri=/users/5 ']],
            ['GET /users/5/?tab=a', 301, ['location' => '/users/5?tab=a']],
            ['POST /api/login', 200, ['body' => 'php:/index.php uri=/api/login']],
            ['GET /css/app.css', 200, ['body' => 'static:/css/app.css']],
            ['GET /build/', 404],      // a folder without index: Apache 403
        ],
        'extra' => function (array $r): void {
            T::ok(!$r['needs_review'], 'Laravel needs no review', json_encode(notesWith($r, 'unsupported')));
            T::ok(count(array_filter($r['notes'], static fn ($n) => $n['status'] === 'ignored' && str_contains($n['directive'], '[E=HTTP_'))) === 2, 'header-passing rules ignored');
        },
    ],
    'codeigniter' => [
        'docroot' => ['index.php', 'assets/app.js', 'robots.txt'],
        'requests' => [
            ['GET /welcome/index?x=1', 200, ['body' => ['php:/index.php', 'pi=/welcome/index', 'qs=x=1']]],
            ['GET /', 200, ['body' => 'php:/index.php']],
            ['GET /assets/app.js', 200, ['body' => 'static:/assets/app.js']],
            ['GET /assets/missing.js', 404],
            ['GET /robots.txt', 200, ['body' => 'static:/robots.txt']],
        ],
    ],
    'subfolder-deny' => [
        'docroot' => ['index.php', 'admin/secret.php', 'admin/public/logo.png', 'administrator.txt'],
        'requests' => [
            ['GET /admin/secret.php', 403],
            ['GET /admin/', 403],
            ['GET /admin', 403],
            ['GET /admin/missing', 403],
            ['GET /admin/public/logo.png', 200, ['body' => 'static:/admin/public/logo.png']],
            ['GET /administrator.txt', 200],
            ['GET /anything?a=b', 200, ['body' => ['php:/index.php', 'qs=a=b']]],
        ],
    ],
    'https-www' => [
        'docroot' => ['index.php', 'a.txt'],
        'host' => 'example.com',
        'requests' => [
            ['GET /x/y?q=1', 301, ['location' => 'https://www.example.com/x/y?q=1']],
            ['GET /', 301, ['host' => 'www.example.com', 'location' => 'https://www.example.com/']],
            ['GET /a.txt', 301, ['host' => 'shop.example.com', 'location' => 'https://www.shop.example.com/a.txt']],
        ],
    ],
    'errordocument' => [
        'docroot' => ['index.php', 'errors/404.html', 'errors/gone.php' => "<?php http_response_code(410); echo 'gone page';", 'sub/missing.html', 'secret/x.txt', 'retired/old.txt'],
        'requests' => [
            ['GET /nope', 404, ['body' => 'static:/errors/404.html']],
            ['GET /sub/nope', 404, ['body' => 'static:/sub/missing.html']],
            ['GET /secret/x.txt', 403, ['body' => 'Access denied']],
            ['GET /retired/old.txt', 410, ['body' => 'gone page']],
            ['GET /errors/404.html', 200],
        ],
        'extra' => function (array $r): void {
            T::ok($r['needs_review'], 'PHP error page is flagged (Caddy answers 200 unless the script sets the status)');
        },
    ],
    'filesmatch-header' => [
        'docroot' => ['index.php', 'style.css', 'robots.txt', 'backup.sql', 'assets/app.js', 'assets/evil.php',
            'page.php' => "<?php header('Cache-Control: private'); header('X-Powered-By: PHP'); echo 'page';"],
        'requests' => [
            ['GET /style.css', 200, ['headers' => ['Cache-Control' => 'public, max-age=31536000, immutable', 'X-Frame-Options' => 'SAMEORIGIN']]],
            ['GET /page.php', 200, ['body' => 'page', 'headers' => ['Cache-Control' => 'no-store', 'X-Powered-By' => null, 'X-Frame-Options' => 'SAMEORIGIN']]],
            ['GET /robots.txt', 200, ['headers' => ['X-Robots-Tag' => 'noindex']]],
            ['GET /backup.sql', 403],
            ['GET /assets/app.js', 200, ['headers' => ['X-Assets' => 'yes', 'Cache-Control' => 'public, max-age=31536000, immutable']]],
            ['GET /assets/evil.php', 403],
            ['GET /missing', 404, ['headers' => ['X-Frame-Options' => 'SAMEORIGIN']]],   // "always" reaches error pages
        ],
    ],
    'unsupported' => [
        'docroot' => ['index.php', 'about.php', 'private/index.php', 'old/x.txt'],
        'requests' => [
            ['GET /about', 200, ['body' => 'php:/about.php']],
            ['GET /private/', 403],                  // fails closed
            ['GET /old/x.txt', 200],                  // the lookahead redirect is not emitted
            ['GET /shop/x', 404],                     // [P] never proxied
        ],
        'extra' => function (array $r): void {
            T::ok($r['needs_review'], 'needs review');
            $lines = array_map(static fn ($n) => $n['file'] . ':' . $n['line'], notesWith($r, 'unsupported'));
            foreach (['.htaccess' => ':1', 'setenvif' => ':2', 'lookahead' => ':5', 'proxy' => ':7', 'if' => ':10', 'auth' => 'private:5'] as $what => $suffix) {
                T::ok((bool)array_filter($lines, static fn ($l) => str_ends_with($l, $suffix)), "unsupported note for {$what}", json_encode($lines));
            }
            T::ok(!str_contains($r['route'], '8080') && !str_contains($r['route'], 'reverse_proxy'), 'nothing of the proxy rule emitted');
        },
    ],
    'query-semantics' => [
        'docroot' => ['index.php'],
        'requests' => [
            ['GET /replace/abc?x=1', 200, ['body' => 'qs=p=abc pi']],
            ['GET /append/abc?x=1', 200, ['body' => 'qs=p=abc&x=1 pi']],
            ['GET /append/abc', 200, ['body' => 'qs=p=abc pi']],
            ['GET /keep?x=1', 200, ['body' => 'qs=x=1 pi']],
            ['GET /drop?x=1', 200, ['body' => 'qs= pi']],
            ['GET /discard?x=1', 200, ['body' => 'qs= pi']],
            ['GET /go-keep?x=1', 302, ['location' => '/landing?x=1']],
            ['GET /go-keep', 302, ['location' => '/landing']],
            ['GET /go-replace?x=1', 302, ['location' => '/landing?from=old']],
            ['GET /go-drop?x=1', 302, ['location' => '/landing']],
            ['GET /chain/abc?z=9', 200, ['body' => 'qs=step=abc&z=9 pi']],
        ],
    ],
    'or-conditions' => [
        'docroot' => ['index.php', 'preview.php', 'item.php'],
        'requests' => [
            ['GET /page?preview=1', 200, ['body' => 'php:/preview.php']],
            ['GET /page', 200, ['send' => ['X-Preview' => 'yes'], 'body' => 'php:/preview.php']],
            ['GET /page', 404],
            ['PUT /api/x', 403],
            ['DELETE /api/x', 403],
            ['GET /api/x', 404],
            ['GET /item?id=42', 200, ['body' => ['php:/item.php', 'qs=item=42']]],
            ['GET /item.php?id=42', 301, ['location' => '/item?id=42']],
        ],
    ],
    'mod-alias' => [
        'docroot' => ['index.php'],
        'requests' => [
            ['GET /old-page', 301, ['location' => '/new-page']],
            ['GET /old-page/sub?a=1', 301, ['location' => '/new-page/sub?a=1']],
            ['GET /old-pages', 404],
            ['GET /docs/guide', 301, ['location' => 'https://docs.example.com/guide']],
            ['GET /blog/2024/hello', 301, ['location' => '/articles/hello?year=2024']],
            ['GET /removed', 410],
            ['GET /x/.git', 404],
            ['GET /old/here', 302, ['location' => '/moved-here']],
        ],
        'extra' => function (array $r): void {
            T::ok((bool)array_filter($r['notes'], static fn ($n) => $n['file'] === 'old' && $n['line'] === 4 && str_contains($n['message'], 'never matches')), 'RedirectMatch that can never match is flagged');
        },
    ],
    'access-ip' => [
        'docroot' => ['index.php', 'local-only.php', 'staff/index.php'],
        'requests' => [
            ['GET /local-only.php', 200],                // from 127.0.0.1
            ['GET /staff/', 403],                        // 127.0.0.1 is not on the list
            ['GET /', 200],
            ['DELETE /', 403],
        ],
    ],
    'the-request' => [
        'docroot' => ['index.php', 'about.php', 'blog/post.php'],
        'requests' => [
            ['GET /about.php?x=1', 301, ['location' => '/about?x=1']],
            ['GET /blog/post.php', 301, ['location' => '/blog/post']],
            ['GET /about', 200, ['body' => 'php:/about.php uri=/about ']],
            ['GET /blog/post', 200, ['body' => 'php:/blog/post.php']],
            ['POST /about.php', 301],
        ],
    ],

    // --- Real sites (anonymised copies of the migrated accounts' files) ---
    'real-seo-redirects' => [
        'docroot' => ['index.php', 'post.php', 'blog.php', 'blog-index.php', 'sitemap.php', 'blog.html', 'terms.html', 'header.html', 'css/site.css', 'img/a.png'],
        'host' => 'www.example.org',
        'requests' => [
            ['GET /post/abc?ref=x', 200, ['body' => ['php:/post.php', 'qs=id=abc&ref=x']]],
            ['GET /about?x=1', 301, ['host' => 'example.org', 'location' => 'https://www.example.org/about?x=1']],
            ['GET /post/abc', 200, ['host' => 'example.org', 'body' => 'php:/post.php']],     // [END] rule before the host redirect
            ['GET /blog-view.html?slug=my-post', 301, ['location' => '/blog/my-post']],
            ['GET /blog-view.html', 301, ['location' => '/blog.html']],
            ['GET /blog/', 301, ['location' => '/blog.html']],
            ['GET /terms-and-conditions.html', 301, ['location' => '/terms.html']],
            ['GET /blood-donors-in-lahore.html', 301, ['location' => '/blood-donors/pakistan/lahore/']],
            ['GET /index.php', 301, ['location' => '/']],
            ['GET /', 200, ['body' => 'php:/index.php']],
            ['GET /blog.html', 200, ['body' => 'php:/blog-index.php']],
            ['GET /blog/my-post?x=1', 200, ['body' => ['php:/blog.php', 'qs=slug=my-post&x=1']]],
            ['GET /sitemap.xml', 200, ['body' => 'php:/sitemap.php']],
            ['GET /img/', 403],
            ['GET /css/site.css', 200, ['headers' => ['Cache-Control' => 'public, max-age=2592000, stale-while-revalidate=86400', 'Strict-Transport-Security' => 'max-age=63072000; includeSubDomains; preload']]],
            ['GET /header.html', 200, ['headers' => ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'public, max-age=3600, must-revalidate']]],
            ['GET /nope', 404, ['headers' => ['X-Frame-Options' => 'SAMEORIGIN']]],
        ],
    ],
    'real-clean-urls' => [
        'docroot' => ['index.php', 'about.php', 'services/index.php', 'services/air-export.php', 'handlers/contact.php', 'includes/config.php',
            'css/site.css', 'robots.txt', '404.php' => "<?php http_response_code(404); echo 'not found page';"],
        'requests' => [
            ['GET /index.php', 301, ['location' => '/']],
            ['GET /services/index.php', 301, ['location' => '/services/']],
            ['GET /about.php?x=1', 301, ['location' => '/about?x=1']],
            ['POST /about.php', 200, ['body' => 'php:/about.php']],
            ['GET /handlers/contact.php', 200, ['body' => 'php:/handlers/contact.php']],
            ['GET /about/', 301, ['location' => '/about']],
            ['GET /about', 200, ['body' => 'php:/about.php uri=/about ']],
            ['GET /services/air-export?service=x', 200, ['body' => ['php:/services/air-export.php', 'qs=service=x']]],
            ['GET /services/', 200, ['body' => 'php:/services/index.php']],
            ['GET /includes/config.php', 403],
            ['GET /tools/x', 403],
            ['GET /nope', 404, ['body' => 'not found page']],
            ['GET /robots.txt', 200, ['headers' => ['Content-Type' => 'text/plain', 'X-Content-Type-Options' => 'nosniff']]],
            ['GET /css/site.css', 200, ['headers' => ['Cache-Control' => 'public, max-age=31536000, immutable']]],
        ],
    ],
    'real-sef-routes' => [
        'docroot' => ['index.php', 'login.php', 'admin/project-detail.php', 'admin/notifications.php', 'member/tasks.php', 'includes/db.php',
            'uploads/a.png', 'uploads/x.php', 'landing/index.html', 'assets/app.js'],
        'requests' => [
            ['GET /login', 200, ['body' => 'php:/login.php']],
            ['GET /login/?a=1', 200, ['body' => ['php:/login.php', 'qs=a=1']]],
            ['GET /admin/project/abc/tasks/p1', 200, ['body' => ['php:/admin/project-detail.php', 'qs=project=abc&tab=tasks&phase=p1']]],
            ['GET /admin/notifications/unread?page=2', 200, ['body' => 'qs=filter=unread&page=2']],
            ['GET /member/tasks/links', 200, ['body' => ['php:/member/tasks.php', 'qs=tab=links']]],
            // /landing is a real folder: the first rule (-d, [L]) stops, then Apache's
            // DirectorySlash redirects to /landing/ (301; Caddy 308) - the rewrite never runs.
            ['GET /landing', [301, 308], ['location' => '/landing/']],
            ['GET /landing/', 200, ['body' => 'static:/landing/index.html']],
            ['GET /includes/db.php', 403],
            ['GET /uploads/x.php', 403],
            ['GET /uploads/a.png', 200],
            ['GET /assets/app.js', 200, ['body' => 'static:/assets/app.js']],
            ['GET /nope', 404],
        ],
    ],
    'real-shop-routes' => [
        'docroot' => ['index.php', 'page.php', 'product.php', 'collection.php', 'includes/api.php', 'myadmin/dashboard.php', 'myadmin/setup-files/setup.php',
            'css/a.css', '404.php' => "<?php http_response_code(404); echo 'shop 404';"],
        'requests' => [
            ['GET /collection', 200, ['body' => 'php:/collection.php']],
            ['GET /collection/categories/rings', 200, ['body' => ['php:/collection.php', 'qs=categories[]=rings']]],
            ['GET /collection/ring-1', 200, ['body' => ['php:/product.php', 'qs=slug=ring-1']]],
            ['GET /api/orders/5', 200, ['body' => 'php:/includes/api.php']],
            ['GET /myadmin/dashboard', 200, ['body' => 'php:/myadmin/dashboard.php']],
            ['GET /myadmin/setup-files/setup.php', 403],
            ['GET /about-us', 200, ['body' => ['php:/page.php', 'qs=slug=about-us']]],
            ['GET /a/b/c', 404, ['body' => 'shop 404']],
            ['GET /css/a.css', 200, ['headers' => ['Cache-Control' => 'public, max-age=31536000, immutable']]],
        ],
    ],
    'real-front-controller-deny' => [
        'docroot' => ['index.php', 'app/Kernel.php', 'storage/x.log', 'assets/app.css', 'README.md', '.env'],
        'requests' => [
            ['GET /app/Kernel.php', 403],
            ['GET /storage/x.log', 403],
            ['GET /README.md', 403],
            ['GET /.env', [403, 404]],
            ['GET /pay/123?x=1', 200, ['body' => ['php:/index.php', 'qs=x=1']]],
            ['GET /assets/app.css', 200, ['headers' => ['Cache-Control' => 'public, max-age=31536000, immutable']]],
        ],
    ],
    'real-spa-fallback' => [
        'docroot' => ['index.html', 'main.js', 'assets/logo.png'],
        'requests' => [
            ['GET /admin/dashboard', 200, ['body' => 'static:/index.html', 'headers' => ['Cache-Control' => 'no-cache, no-store, must-revalidate']]],
            ['GET /main.js', 200, ['body' => 'static:/main.js', 'headers' => ['Cache-Control' => null]]],
            ['GET /missing-chunk.js', 200, ['body' => 'static:/index.html']],   // the rules send everything to index.html
            ['GET /', 200, ['body' => 'static:/index.html']],
        ],
    ],
    'real-subfolder-app' => [
        'docroot' => ['index.php', 'demo/index.php', 'demo/config/app.php', 'demo/README.md', 'demo/css/a.css'],
        'requests' => [
            ['GET /demo/contact/', 301, ['location' => '/demo/contact']],
            ['GET /demo/contact', 200, ['body' => 'php:/demo/index.php uri=/demo/contact ']],
            ['GET /demo/', 200, ['body' => 'php:/demo/index.php']],
            ['GET /demo/css/a.css', 200, ['headers' => ['X-Frame-Options' => 'SAMEORIGIN']]],
            ['GET /demo/README.md', 404],
            ['GET /demo/config/app.php', 200],      // Apache doesn't block it either (RedirectMatch sees /demo/...)
            ['GET /', 200, ['headers' => ['X-Robots-Tag' => 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1']]],
            ['GET /missing', 404],                  // no front controller at the root
        ],
    ],
    'real-html-clean-urls' => [
        'docroot' => ['index.html', 'about.html', 'menu.html', 'x/y.html', '404.html'],
        'requests' => [
            ['GET /index.html', 301, ['location' => '/']],
            ['GET /old-about', 301, ['location' => '/about']],
            ['GET /old-menu.html', 301, ['location' => '/menu']],
            ['GET /about.html', 301, ['location' => '/about']],
            ['GET /gallery.html?x=1', 301, ['location' => '/gallery?x=1']],
            ['GET /x/y.html', 301, ['location' => '/x/y']],
            ['GET /about', 200, ['body' => 'static:/about.html']],
            ['GET /x/y', 200, ['body' => 'static:/x/y.html']],
            ['GET /', 200, ['body' => 'static:/index.html']],
            ['GET /nope', 404, ['body' => 'static:/404.html']],
        ],
    ],
    'real-public-subfolder' => [
        'docroot' => ['public/index.php', 'public/assets/app.css'],
        'requests' => [
            ['GET /en/blog', 200, ['body' => 'php:/public/index.php uri=/en/blog ']],
            ['GET /assets/app.css', 200, ['body' => 'static:/public/assets/app.css']],
            ['GET /', 200, ['body' => 'php:/public/index.php']],
        ],
        'extra' => function (array $r): void {
            T::ok($r['needs_review'], 'DirectoryMatch (a 500 on Apache) is flagged');
        },
    ],
    'real-deny-folders' => [
        'docroot' => ['index.php', 'database/schema.sql', 'config/app.php', 'src/a.php', 'images/a.png'],
        'requests' => [
            ['GET /database/schema.sql', 403],
            ['GET /config/app.php', 403],
            ['GET /src/', 403],
            ['GET /images/a.png', 200],
            ['GET /images/', 404],      // Apache: 403 (Options -Indexes)
            ['GET /nope', 404],         // no front controller in this site's .htaccess
        ],
    ],
    'real-sef-php-hiding' => [
        'docroot' => ['index.php', 'about.php', 'robots.php', 'sitemap.php', 'api/x.php', 'includes/db.php', 'data.json', 'config.php', 'docs/'],
        'requests' => [
            ['GET /robots.txt', 200, ['body' => 'php:/robots.php']],
            ['GET /index.php', 301, ['location' => '/']],
            ['GET /about.php', 301, ['location' => '/about']],
            ['GET /api/x.php', 200, ['body' => 'php:/api/x.php']],
            ['GET /about', 200, ['body' => 'php:/about.php']],
            ['GET /includes/db.php', 403],
            ['GET /data.json', 403],
            ['GET /config.php', 403],
            ['GET /', 200, ['headers' => ['X-Frame-Options' => 'SAMEORIGIN']]],
        ],
    ],
    'real-old-style-deny' => [
        'docroot' => ['index.php', 'data/x.json', 'uploads/a.png'],
        'requests' => [
            ['GET /data/x.json', 403],
            ['GET /uploads/a.png', 403],
            ['GET /', 200],
        ],
    ],
    'real-pretty-urls' => [
        'docroot' => ['index.php', 'comingsoon.php', 'preorder.php', 'contact.php'],
        'requests' => [
            ['GET /index.php', 301, ['location' => '/']],
            ['GET /gallery', 200, ['body' => ['php:/index.php', 'qs=section=gallery']]],
            ['GET /comingsoon/', 200, ['body' => 'php:/comingsoon.php']],
            ['GET /contact', 200, ['body' => 'php:/contact.php']],
            ['GET /nope', 404],
        ],
    ],
    'real-filesmatch-deny' => [
        'docroot' => ['index.php', 'composer.json', 'vendor/autoload.php', 'public.css'],
        'requests' => [
            ['GET /composer.json', 403],
            ['GET /vendor/autoload.php', 403],
            ['GET /whatever?x=1', 200, ['body' => ['php:/index.php', 'qs=x=1']]],
            ['GET /public.css', 200],
        ],
    ],
    'real-security-headers' => [
        'docroot' => ['index.php'],
        'requests' => [
            ['GET /', 200, ['headers' => ['X-Frame-Options' => 'SAMEORIGIN', 'Referrer-Policy' => 'strict-origin-when-cross-origin']]],
            ['GET /nope', 404, ['headers' => ['X-Content-Type-Options' => 'nosniff']]],
        ],
    ],
];

// ---------------------------------------------------------------------------
// Validator unit tests: [kind, caddy, error substring or null for "valid"]
// ---------------------------------------------------------------------------

$validatorCases = [
    ['route', "php_server\n", null],
    ['route', "@a path /x\nrewrite @a /index.php\nhandle {\n\troute {\n\t\tphp_server\n\t}\n}\n", null],
    ['route', "respond <<EOF\n\tclosing } brace\n\tEOF 200\nphp_server\n", null],
    ['route', "# import /etc/passwd\nheader X-A \"a # b { c\"\nphp_server\n", null],
    ['route', "@x expression `{path}.startsWith('/a')`\nrespond @x 403\nphp_server\n", null],
    ['route', "@f file {\n\troot /srv/site/sub\n\ttry_files {path}\n}\nphp_server\n", null],
    ['site', "header X-A b\nhandle_errors 404 {\n\trewrite * /404.php\n\tphp_server\n}\n", null],
    ['site', '', null],
    ['route', "import /etc/caddy/other\nphp_server\n", 'import'],
    ['route', "root * /\nphp_server\n", 'root'],
    ['route', "reverse_proxy 127.0.0.1:8080\nphp_server\n", 'reverse_proxy'],
    ['route', "php_fastcgi 127.0.0.1:9000\nphp_server\n", 'php_fastcgi'],
    ['route', "exec ls\nphp_server\n", 'exec'],
    ['route', "tls internal\nphp_server\n", 'tls'],
    ['route', "bind 0.0.0.0\nphp_server\n", 'bind'],
    ['route', "log\nphp_server\n", 'log'],
    ['route', "respond \"{env.DB_PASS}\" 200\nphp_server\n", 'placeholders'],
    ['route', "respond {file./etc/passwd} 200\nphp_server\n", 'placeholders'],
    ['route', "respond {\$HOME} 200\nphp_server\n", 'Environment'],
    ['route', "(snip) {\n\trespond 1\n}\nphp_server\n", 'snippet'],
    ['route', "handle {\n\tphp_server\n", 'missing }'],
    ['route', "php_server\n}\n", 'unexpected }'],
    ['route', "rewrite * /index.php\n", 'must end with php_server'],
    ['route', "php_server\nrespond 404\n", 'must end with php_server'],
    ['route', "handle /x {\n\tphp_server\n}\n", 'must end with php_server'],
    ['route', "@hidden path /x\nphp_server\n", 'reserved'],
    ['route', "file_server browse\nphp_server\n", 'browse'],
    ['route', "php_server {\n\troot /etc\n}\n", 'root'],
    ['route', "php_server {\n\tworker /srv/site/w.php\n}\n", 'worker'],
    ['route', "@f file {\n\troot /etc\n}\nphp_server\n", 'document root'],
    ['route', "try_files ../../../etc/passwd\nphp_server\n", '..'],
    ['route', "@f file ../x\nphp_server\n", '..'],
    ['route', "vars root /etc\nphp_server\n", 'site root'],
    ['route', "map {path} {root} {\n\t/x /etc\n}\nphp_server\n", 'site root'],
    ['route', "@x expression `file({'root': '/etc', 'try_files': ['/passwd']})`\nphp_server\n", 'expressions'],
    ['route', "handle_errors 404 {\n\trespond x\n}\nphp_server\n", 'handle_errors'],
    ['route', "templates\nphp_server\n", 'templates'],
    ['route', "example.com {\n\trespond hi\n}\nphp_server\n", 'example.com'],
    ['route', "{\n\tdebug\n}\nphp_server\n", 'directive or matcher'],
    ['route', "@m forward_auth x\nphp_server\n", 'matcher'],
    ['route', "respond \"unterminated\nphp_server\n", 'unterminated'],
    ['site', "php_server\n", 'php_server'],
    ['site', "route {\n\tphp_server\n}\n", 'route'],
    ['site', "handle_errors 404 {\n\treverse_proxy x\n}\n", 'reverse_proxy'],
];

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

$filter = $argv[1] ?? '';
$hasFranken = trim((string)shell_exec('command -v frankenphp 2>/dev/null')) !== '';
if (!$hasFranken) {
    echo "frankenphp is not installed: the adapt and behaviour checks can't run.\n";
    T::$fail++;
}
if (!function_exists('curl_init')) {
    echo "PHP's curl extension is missing: the behaviour checks can't run.\n";
    T::$fail++;
}

T::$case = 'validate';
if ($filter === '' || str_contains('validate', $filter)) {
    foreach ($validatorCases as [$kind, $caddy, $expect]) {
        $errors = HtaccessTranslator::validate($caddy, '/srv/site', $kind);
        $label = $kind . ': ' . json_encode(substr($caddy, 0, 60));
        if ($expect === null) {
            T::ok($errors === [], "{$label} is valid", json_encode($errors));
        } else {
            T::ok((bool)array_filter($errors, static fn ($e) => stripos($e, $expect) !== false), "{$label} rejected ({$expect})", json_encode($errors));
        }
    }
}

// Every fixture directory needs a case and vice versa.
$fixtureDirs = array_map('basename', glob(FIXTURES . '/*', GLOB_ONLYDIR) ?: []);
T::$case = 'fixtures';
T::ok(array_diff($fixtureDirs, array_keys($cases)) === [], 'every fixture has a test case', json_encode(array_values(array_diff($fixtureDirs, array_keys($cases)))));

$base = sys_get_temp_dir() . '/htaccess-translator-test-' . getmypid();
foreach ($cases as $name => $case) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    T::$case = $name;
    $before = T::$fail;
    $docroot = $base . '/' . $name . '/docroot';
    $files = loadFixture($name);
    $r = HtaccessTranslator::translate($files, $docroot);

    // Shape of the result.
    T::ok(is_string($r['route']) && is_string($r['site']) && is_array($r['notes']) && is_bool($r['needs_review']), 'result shape');
    foreach ($r['notes'] as $n) {
        if (!in_array($n['status'], ['translated', 'ignored', 'unsupported'], true) || !is_int($n['line']) || !isset($n['file'], $n['directive'], $n['message'])) {
            T::ok(false, 'note shape', json_encode($n));
            break;
        }
    }
    T::ok(notesWith($r, 'unsupported') === [] || $r['needs_review'], 'needs_review set when something is unsupported');
    T::ok(str_ends_with(rtrim($r['route']), "php_server {\n\ttry_files {path} {path}/index.php\n}"), 'route ends in php_server');
    T::ok(HtaccessTranslator::validate($r['route'], $docroot, 'route') === [], 'route passes validate()', json_encode(HtaccessTranslator::validate($r['route'], $docroot, 'route')));
    T::ok(HtaccessTranslator::validate($r['site'], $docroot, 'site') === [], 'site passes validate()', json_encode(HtaccessTranslator::validate($r['site'], $docroot, 'site')));
    // Deterministic output.
    $again = HtaccessTranslator::translate($files, $docroot);
    T::ok($again === $r, 'translation is deterministic');

    if (isset($case['extra'])) {
        ($case['extra'])($r);
    }

    if ($hasFranken && function_exists('curl_init')) {
        $tmp = $base . '/' . $name;
        makeDocroot($docroot, $case['docroot']);
        $routeFile = $tmp . '/rules.caddy';
        $siteFile = $tmp . '/rules.site.caddy';
        file_put_contents($routeFile, $r['route']);
        file_put_contents($siteFile, $r['site']);
        $config = $tmp . '/Caddyfile';
        file_put_contents($config, caddyfile($docroot, $routeFile, $r['site'] !== '' ? $siteFile : null));

        [$code, $out] = run(['frankenphp', 'adapt', '--config', $config, '--adapter', 'caddyfile'], frankenEnv($tmp));
        T::ok($code === 0, 'frankenphp adapt', trim($out));
        if ($code === 0) {
            $server = startServer($config, $tmp);
            if (is_string($server)) {
                T::ok(false, 'frankenphp run', $server);
            } else {
                try {
                    foreach ($case['requests'] as $e) {
                        check($e, $case['host'] ?? '127.0.0.1');
                    }
                } finally {
                    stopServer($server);
                }
            }
        }
        if (T::$fail > $before) {
            echo "  --- route ({$name}):\n" . preg_replace('/^/m', '  | ', $r['route']) . ($r['site'] !== '' ? "  --- site:\n" . preg_replace('/^/m', '  | ', $r['site']) : '');
        }
    }
    printf("%-28s %s\n", $name, T::$fail > $before ? 'FAIL' : 'ok');
}
rrmdir($base);

printf("\n%d passed, %d failed\n", T::$pass, T::$fail);
exit(T::$fail > 0 ? 1 : 0);
