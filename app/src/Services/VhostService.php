<?php
declare(strict_types=1);

/**
 * Manages FrankenPHP/Caddy vhosts. Vhost fragments are written to
 * Config::VHOSTS_CADDY_DIR (a dedicated writable+SELinux-labelled dir,
 * imported by the main Caddyfile) so the app never has to touch /etc.
 *
 * A domain on the default PHP version is served directly by the main
 * FrankenPHP instance. A domain on an alt version (installed via
 * PhpVersionService) gets a matching fragment in that version's OWN
 * instance (loopback-only), and the main instance's vhost for it becomes a
 * reverse_proxy to that port - see PhpVersionService for why alt versions
 * are separate instances rather than something FrankenPHP can just switch
 * per-request.
 */
final class VhostService
{
    public static function docroot(string $domain): string
    {
        return Config::VHOSTS_DOCROOT_BASE . '/' . $domain . '/public';
    }

    public static function create(string $domain, string $phpVersion = 'default', string $sslMode = 'self_signed', bool $reload = true, bool $seedIndex = true): string
    {
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i', $domain)) {
            throw new InvalidArgumentException('Invalid domain name.');
        }

        $docroot = self::docroot($domain);
        if (!is_dir($docroot)) {
            mkdir($docroot, 02775, true);
        }
        chgrp(dirname($docroot), 'webusers');
        chgrp($docroot, 'webusers');

        // Only for a brand-new site: rewriting an existing site's config must
        // not drop a placeholder index.php in front of its own index.html.
        $indexFile = $docroot . '/index.php';
        if ($seedIndex && !is_file($indexFile)) {
            file_put_contents($indexFile, self::defaultIndex($domain));
        }

        // AutoSSL: self-signed uses Caddy's local dev CA; Let's Encrypt mode
        // just omits the tls line entirely - Caddy's automatic HTTPS then
        // manages a real ACME certificate on its own, PROVIDED the domain
        // actually resolves here publicly and 80/443 are internet-reachable
        // (a real requirement of how ACME HTTP-01 validation works, not
        // something any panel can bypass).
        $tlsLine = $sslMode === 'letsencrypt' ? '' : "\ttls internal\n";

        $safeName = preg_replace('/[^a-z0-9.-]/i', '_', $domain);
        $confPath = Config::VHOSTS_CADDY_DIR . "/$safeName.caddyfile";
        $rulesFile = self::rulesFile($domain);
        // Site-level part of those rules (headers, handle_errors).
        $siteRules = is_file(self::siteRulesFile($domain)) && $phpVersion === 'default'
            ? "\timport " . self::siteRulesFile($domain) . "\n" : '';

        if ($phpVersion === 'default') {
            // A site's own routing (translated from its .htaccess) replaces
            // the default front-controller fallback. It ends in php_server.
            $phpBlock = is_file($rulesFile)
                ? "import {$rulesFile}"
                : "try_files {path} /index.php\n\t\t@nophp {\n\t\t\tpath *.php\n\t\t\tnot file {path}\n\t\t}\n\t\terror @nophp 404\n\t\tphp_server";
        } else {
            $port = PhpVersionService::port($phpVersion);
            $phpBlock = "reverse_proxy 127.0.0.1:{$port}";
            self::writeAltInstanceFragment($domain, $phpVersion, $docroot);
        }

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
            . "\tredir /jpanel/* https://{host}:2083/ 302\n";

        // www.<domain> is served too: every DNS zone the panel creates has a
        // www record, and a name with no site block fails the TLS handshake
        // outright (browsers show ERR_SSL_PROTOCOL_ERROR, not a cert warning).
        $conf = <<<CADDY
        https://{$domain}, https://www.{$domain} {
        {$tlsLine}
        	encode zstd br gzip
        	root * {$docroot}
        {$panelHooks}
        {$siteRules}
        	route {
        		{$guard}
        		{$phpBlock}
        	}
        }

        https://{$domain}:2083, https://www.{$domain}:2083 {
        {$tlsLine}
        	import jinnpanel_app
        }

        CADDY;
        if ($sslMode !== 'letsencrypt') {
            // Caddy's local CA isn't trusted by browsers, so keep plain HTTP
            // usable. With Let's Encrypt there is no http:// block: Caddy then
            // redirects HTTP to HTTPS on its own (and still answers ACME).
            $conf .= <<<CADDY

            http://{$domain}, http://www.{$domain} {
            	encode zstd br gzip
            	root * {$docroot}
            {$panelHooks}
            {$siteRules}
            	route {
            		{$guard}
            		{$phpBlock}
            	}
            }

            CADDY;
        }

        file_put_contents($confPath, $conf);
        if ($reload) { // false when rewriting many sites; the caller reloads once
            self::reload();
        }

        return $docroot;
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
        $safeName = preg_replace('/[^a-z0-9.-]/i', '_', $domain);
        $confPath = Config::VHOSTS_CADDY_DIR . "/$safeName.caddyfile";
        if (is_file($confPath)) {
            unlink($confPath);
        }
        self::reload();

        if ($phpVersion !== 'default') {
            $altFragment = Config::PHP_VERSIONS_DIR . "/$phpVersion/sites-enabled/$safeName.caddyfile";
            if (is_file($altFragment)) {
                unlink($altFragment);
            }
            self::reloadAltInstance($phpVersion);
        }
    }

    /** Writes the domain's real serving block into its alt PHP version's own instance. */
    private static function writeAltInstanceFragment(string $domain, string $phpVersion, string $docroot): void
    {
        $safeName = preg_replace('/[^a-z0-9.-]/i', '_', $domain);
        $dir = Config::PHP_VERSIONS_DIR . "/$phpVersion/sites-enabled";
        if (!is_dir($dir)) {
            throw new RuntimeException("PHP $phpVersion instance is not installed.");
        }
        // Named-host address (not a bare IP:port) so multiple domains on
        // this version cleanly share the port with Host-based routing -
        // the exact same mechanism already proven for the main instance's
        // sites-enabled, just on an alt port. `bind 127.0.0.1` keeps the
        // raw (non-TLS) listener off the public interface; the main
        // instance's reverse_proxy is the only intended way in.
        // Explicit http:// scheme: without it, Caddy sees what looks like a
        // real domain name and tries to auto-provision TLS for it too. This
        // listener is loopback-only and plain HTTP on purpose - the main
        // instance already terminates real TLS before reverse_proxy'ing here.
        $port = PhpVersionService::port($phpVersion);
        $conf = <<<CADDY
        http://{$domain}:{$port}, http://www.{$domain}:{$port} {
        	bind 127.0.0.1
        	root * {$docroot}
        	encode zstd br gzip
        	try_files {path} /index.php
        	php_server
        }
        CADDY;
        file_put_contents("$dir/$safeName.caddyfile", $conf);
        self::reloadAltInstance($phpVersion);
    }

    public static function reload(): void
    {
        self::reloadInstance(Config::FRANKENPHP_CONFIG, null, 'frankenphp', __DIR__ . '/../../storage/logs/reload.log');
    }

    public static function reloadAltInstance(string $phpVersion): void
    {
        $dir = Config::PHP_VERSIONS_DIR . "/$phpVersion";
        self::reloadInstance(
            "$dir/Caddyfile",
            PhpVersionService::adminPort($phpVersion),
            "$dir/frankenphp",
            __DIR__ . "/../../storage/logs/reload-php{$phpVersion}.log"
        );
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
    private static function reloadInstance(string $configPath, ?int $adminPort, string $binary, string $logFile): void
    {
        $addressFlag = $adminPort !== null ? ' --address 127.0.0.1:' . $adminPort : '';
        $cmd = 'nohup ' . escapeshellarg($binary) . ' reload --config ' . escapeshellarg($configPath)
            . $addressFlag . ' --force >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        exec($cmd);
    }

    private static function defaultIndex(string $domain): string
    {
        return <<<PHP
        <?php
        echo "<h1>{$domain}</h1><p>This domain is live. Replace this file to get started.</p>";
        PHP;
    }
}
