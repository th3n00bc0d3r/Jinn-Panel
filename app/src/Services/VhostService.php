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

    public static function create(string $domain, string $phpVersion = 'default', string $sslMode = 'self_signed', bool $reload = true): string
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

        $indexFile = $docroot . '/index.php';
        if (!is_file($indexFile)) {
            file_put_contents($indexFile, self::defaultIndex($domain));
        }

        // AutoSSL: self-signed uses Caddy's local dev CA; Let's Encrypt mode
        // just omits the tls line entirely - Caddy's automatic HTTPS then
        // manages a real ACME certificate on its own, PROVIDED the domain
        // actually resolves here publicly and 80/443 are internet-reachable
        // (a real requirement of how ACME HTTP-01 validation works, not
        // something any panel can bypass).
        $tlsLine = $sslMode === 'letsencrypt' ? '' : "\ttls internal\n";

        if ($phpVersion === 'default') {
            $phpBlock = "root * {$docroot}\n\ttry_files {path} /index.php\n\tphp_server";
        } else {
            $port = PhpVersionService::port($phpVersion);
            $phpBlock = "reverse_proxy 127.0.0.1:{$port}";
            self::writeAltInstanceFragment($domain, $phpVersion, $docroot);
        }

        $safeName = preg_replace('/[^a-z0-9.-]/i', '_', $domain);
        $confPath = Config::VHOSTS_CADDY_DIR . "/$safeName.caddyfile";

        // www.<domain> is served too: every DNS zone the panel creates has a
        // www record, and a name with no site block fails the TLS handshake
        // outright (browsers show ERR_SSL_PROTOCOL_ERROR, not a cert warning).
        $conf = <<<CADDY
        https://{$domain}, https://www.{$domain} {
        {$tlsLine}
        	encode zstd br gzip

        	{$phpBlock}
        }

        http://{$domain}, http://www.{$domain} {
        	root * {$docroot}
        	encode zstd br gzip

        	try_files {path} /index.php
        	php_server
        }
        CADDY;

        file_put_contents($confPath, $conf);
        if ($reload) { // false when rewriting many sites; the caller reloads once
            self::reload();
        }

        return $docroot;
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
