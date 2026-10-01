<?php
declare(strict_types=1);

/**
 * Manages FrankenPHP/Caddy vhosts. Vhost fragments are written to
 * Config::VHOSTS_CADDY_DIR (a dedicated writable+SELinux-labelled dir,
 * imported by the main Caddyfile) so the app never has to touch /etc.
 *
 * Caddy serves a site's static files itself and hands its PHP to the
 * owning account's PHP-FPM pool (php_fastcgi to the pool's socket, see
 * AccountRuntime), so a site's code runs as its account's own Linux user -
 * never as the web server, which also runs the panel. The PHP version only
 * picks which FPM master's pool that is. A suspended account's (or
 * domain's) sites answer 503 instead.
 */
final class VhostService
{
    public const ADMIN_SOCKET = 'unix//run/frankenphp/admin.sock';

    public static function docroot(string $domain): string
    {
        return Config::VHOSTS_DOCROOT_BASE . '/' . $domain . '/public';
    }

    /** The site directory: everything a domain's document root may be inside. */
    public static function siteDir(string $domain): string
    {
        return Config::VHOSTS_DOCROOT_BASE . '/' . $domain;
    }

    /**
     * The domain's document root as stored (cPanel > Domains > domain), when
     * it's a real directory inside the site directory; else the default.
     */
    public static function effectiveDocroot(string $domain): string
    {
        try {
            $s = Database::app()->prepare('SELECT docroot FROM domains WHERE domain_name = ?');
            $s->execute([$domain]);
            $stored = $s->fetchColumn();
        } catch (Throwable) {
            $stored = false;
        }
        if (is_string($stored) && $stored !== '' && self::isInsideSite($domain, $stored)) {
            return rtrim($stored, '/');
        }
        return self::docroot($domain);
    }

    /** Whether $path is an existing directory inside the domain's site directory (no symlink escapes). */
    public static function isInsideSite(string $domain, string $path): bool
    {
        $base = realpath(self::siteDir($domain));
        $real = realpath($path);
        return $base !== false && $real !== false && is_dir($real) && str_starts_with($real . '/', $base . '/') && $real !== $base;
    }

    public static function create(string $domain, string $phpVersion = 'default', string $sslMode = 'self_signed', bool $reload = true, bool $seedIndex = true): string
    {
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i', $domain)) {
            throw new InvalidArgumentException('Invalid domain name.');
        }
        // The site folder itself (and its placeholder index.php) is created
        // by the root worker, owned by the account (AccountRuntime::sync);
        // $seedIndex is kept for callers, the worker seeds new folders.
        $docroot = self::effectiveDocroot($domain);

        // AutoSSL: self-signed uses Caddy's local dev CA; Let's Encrypt mode
        // just omits the tls line entirely - Caddy's automatic HTTPS then
        // manages a real ACME certificate on its own, PROVIDED the domain
        // actually resolves here publicly and 80/443 are internet-reachable
        // (a real requirement of how ACME HTTP-01 validation works, not
        // something any panel can bypass).
        $tlsLine = $sslMode === 'letsencrypt' ? '' : "\ttls internal\n";

        $safeName = preg_replace('/[^a-z0-9.-]/i', '_', $domain);
        $confPath = Config::VHOSTS_CADDY_DIR . "/$safeName.caddyfile";
        [$owner, $offline] = self::ownerState($domain);
        $sock = 'unix/' . AccountRuntime::socket((string) $owner, $phpVersion);

        // A site's own routing (translated from its .htaccess, cPanel >
        // Domains > Routes) replaces the default front-controller fallback.
        // The rules say php_server ("run PHP here"); here that becomes the
        // account's pool. Site-level part: headers, handle_errors.
        $rulesFile = self::rulesFile($domain);
        $siteRulesFile = self::siteRulesFile($domain);
        $siteRules = is_file($siteRulesFile) ? self::indent(self::fpmRules((string) file_get_contents($siteRulesFile), $sock), 1) . "\n" : '';
        $phpBlock = is_file($rulesFile)
            ? self::indent(self::fpmRules((string) file_get_contents($rulesFile), $sock), 2)
            : "\t\ttry_files {path} /index.php\n\t\t@nophp {\n\t\t\tpath *.php\n\t\t\tnot file {path}\n\t\t}\n\t\terror @nophp 404\n\t\t" . self::phpHandler($sock, '', [], "\t\t");

        // (@nophp: a request for a missing .php file - e.g. the /index.php
        // fallback on a site served from index.html - is a 404, not a 500.)
        // Apache (cPanel) never serves dotfiles; Caddy does unless told not
        // to - and sites ship .env, .git, .htaccess. cPanel also leaves a
        // php.ini/.user.ini in docroots. `route` keeps this ahead of PHP.
        $guard = "@hidden {\n\t\t\tpath_regexp hidden (/\\.[^/]|/php\\.ini\$)\n\t\t\tnot path /.well-known/*\n\t\t}\n\t\trespond @hidden 404";

        // <domain>/jpanel takes the customer to their own panel at
        // https://<domain>:2083 - a separate origin from the site, so the
        // site's scripts can't drive the panel. Cookies aren't per port
        // though: strip the panel's session cookie before the site's PHP
        // could read it (the panel issues a new session id at login, so a
        // cookie the site plants is useless too).
        $sid = preg_quote(Config::SESSION_NAME, '/');
        $panelHooks = "\trequest_header Cookie \"(^|;\\s*){$sid}=[^;]*\" \"\"\n"
            . "\trequest_header Cookie \"^;\\s*\" \"\"\n"
            . "\tredir /jpanel https://{host}:2083/ 302\n"
            . "\tredir /jpanel/* https://{host}:2083/ 302\n"
            // Browser caching for static files ('?' = only when the site
            // didn't set its own). CSS/JS short: they're often edited in
            // place without a version in the file name.
            . "\t@jp_media path_regexp jp_media (?i)\\.(png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|pdf)$\n"
            . "\theader @jp_media ?Cache-Control \"public, max-age=2592000\"\n"
            . "\t@jp_assets path_regexp jp_assets (?i)\\.(css|js|mjs)$\n"
            . "\theader @jp_assets ?Cache-Control \"public, max-age=86400\"\n";

        // Every site's requests go to one JSON access log: UsageService
        // counts each account's monthly bandwidth from it.
        $log = "\tlog {\n\t\toutput file " . UsageService::ACCESS_LOG . " {\n\t\t\troll_size 100MiB\n\t\t\troll_keep 3\n\t\t}\n\t\tformat json\n\t}\n";
        $body = match ($offline) {
            'suspended' => "{$log}\theader Retry-After 3600\n\trespond \"This website is temporarily unavailable.\" 503\n",
            'bandwidth' => "{$log}\theader Retry-After 86400\n\trespond \"This website has used up its bandwidth for this month.\" 509\n",
            default => "{$log}\tencode zstd br gzip\n\troot * {$docroot}\n{$panelHooks}\n{$siteRules}\n\troute {\n\t\t{$guard}\n{$phpBlock}\n\t}\n",
        };

        // The domain, its aliases (parked domains) and www. of each.
        $names = array_merge([$domain], DomainAliasService::forDomain($domain));
        $addrs = fn(string $scheme, string $port) => implode(', ', array_merge(...array_map(fn($n) => ["$scheme$n$port", "{$scheme}www.$n$port"], $names)));

        // www.<domain> is served too: every DNS zone the panel creates has a
        // www record, and a name with no site block fails the TLS handshake
        // outright (browsers show ERR_SSL_PROTOCOL_ERROR, not a cert warning).
        $conf = "{$addrs('https://', '')} {\n{$tlsLine}{$body}}\n\n"
            . "{$addrs('https://', ':2083')} {\n{$tlsLine}\timport jinnpanel_app\n}\n";
        if ($sslMode !== 'letsencrypt') {
            // Caddy's local CA isn't trusted by browsers, so keep plain HTTP
            // usable. With Let's Encrypt there is no http:// block: Caddy then
            // redirects HTTP to HTTPS on its own (and still answers ACME).
            $conf .= "\n{$addrs('http://', '')} {\n{$body}}\n";
        }

        file_put_contents($confPath, $conf);
        if ($reload) { // false when rewriting many sites; the caller reloads once
            self::reload();
        }

        return $docroot;
    }

    /** @return array{0:string,1:?string} owning account's username, and why its sites are off ('suspended', 'bandwidth') or null */
    private static function ownerState(string $domain): array
    {
        // No try/catch: on a database error the site must keep its current
        // config rather than be rewritten to point at no pool at all.
        $s = Database::app()->prepare('SELECT u.username, u.status AS user_status, d.status AS domain_status, COALESCE(au.over_bandwidth, 0) AS over_bw
            FROM domains d JOIN users u ON u.id = d.user_id LEFT JOIN account_usage au ON au.user_id = u.id WHERE d.domain_name = ?');
        $s->execute([$domain]);
        $row = $s->fetch();
        if (!$row) {
            throw new RuntimeException("$domain is not hosted here - no site config written.");
        }
        if ($row['user_status'] !== 'active' || $row['domain_status'] !== 'active') {
            return [(string) $row['username'], 'suspended'];
        }
        return [(string) $row['username'], (int) $row['over_bw'] === 1 ? 'bandwidth' : null];
    }

    /**
     * php_server ("run PHP here", like FrankenPHP's own directive) as the
     * account's pool: php_fastcgi (same try_files/index/split semantics)
     * followed by file_server for everything that isn't PHP.
     *
     * @param list<string> $sub subdirective lines (try_files, index, split)
     */
    private static function phpHandler(string $sock, string $matcher, array $sub, string $indent): string
    {
        $m = $matcher !== '' ? " $matcher" : '';
        $out = "php_fastcgi{$m} {$sock}";
        if ($sub) {
            $out .= " {\n" . implode('', array_map(fn($l) => "$indent\t" . trim($l) . "\n", $sub)) . "$indent}";
        }
        return $out . "\n{$indent}file_server{$m}";
    }

    /**
     * Rewrites every php_server directive in validated routing rules
     * (HtaccessTranslator::validate) into the pool handler. Directive
     * position only: a line that is `php_server [matcher] [{`.
     */
    public static function fpmRules(string $rules, string $sock): string
    {
        $lines = preg_split('/\r?\n/', $rules);
        $out = [];
        for ($i = 0; $i < count($lines); $i++) {
            if (!preg_match('/^(\s*)php_server((?:\s+[^\s{]+)?)\s*(\{)?\s*$/', $lines[$i], $m)) {
                $out[] = $lines[$i];
                continue;
            }
            $sub = [];
            if (!empty($m[3])) {
                $depth = 1;
                while (++$i < count($lines)) {
                    $depth += substr_count($lines[$i], '{') - substr_count($lines[$i], '}');
                    if ($depth <= 0) {
                        break;
                    }
                    $sub[] = $lines[$i];
                }
            }
            $out[] = $m[1] . self::phpHandler($sock, trim($m[2]), $sub, $m[1]);
        }
        return implode("\n", $out);
    }

    private static function indent(string $text, int $tabs): string
    {
        $pad = str_repeat("\t", $tabs);
        return implode("\n", array_map(fn($l) => $l === '' ? '' : $pad . $l, preg_split('/\r?\n/', rtrim($text))));
    }

    /** Site-level companion of rulesFile(): header and handle_errors directives. */
    public static function siteRulesFile(string $domain): string
    {
        return substr(self::rulesFile($domain), 0, -strlen('.caddy')) . '.site.caddy';
    }

    /** Optional per-site Caddy routing, e.g. translated from the site's .htaccess. */
    public static function rulesFile(string $domain): string
    {
        return dirname(Config::VHOSTS_CADDY_DIR) . '/site-rules/' . preg_replace('/[^a-z0-9.-]/i', '_', $domain) . '.caddy';
    }

    public static function remove(string $domain, string $phpVersion = 'default'): void
    {
        // Its routing rules are root-owned (the worker writes them): the worker removes them.
        if (is_file(self::rulesFile($domain)) || is_file(self::siteRulesFile($domain))) {
            SystemWorkerService::enqueue('routes-' . $domain, ['type' => 'routes_remove', 'domain' => $domain]);
        }
        $safeName = preg_replace('/[^a-z0-9.-]/i', '_', $domain);
        $confPath = Config::VHOSTS_CADDY_DIR . "/$safeName.caddyfile";
        if (is_file($confPath)) {
            unlink($confPath);
        }
        self::reload();
    }

    public static function reload(): void
    {
        self::reloadInstance(Config::FRANKENPHP_CONFIG, 'frankenphp', __DIR__ . '/../../storage/logs/reload.log');
    }

    /**
     * IMPORTANT: this runs from inside a request handled by the very
     * FrankenPHP process being asked to reload (or a sibling process for
     * alt versions). Waiting synchronously here can deadlock (the reload
     * can't complete listener/goroutine handoff until this request
     * finishes, but this request is blocked waiting on the reload). Fire it
     * detached instead - we don't need to wait for it, config takes effect
     * within a moment either way.
     */
    private static function reloadInstance(string $configPath, string $binary, string $logFile): void
    {
        // Caddy's admin API is a Unix socket only frankenphp (and root) can
        // open - on a TCP port, any local process could rewrite the config.
        $cmd = 'nohup ' . escapeshellarg($binary) . ' reload --config ' . escapeshellarg($configPath)
            . ' --address ' . escapeshellarg(self::ADMIN_SOCKET) . ' --force >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        exec($cmd);
    }
}
