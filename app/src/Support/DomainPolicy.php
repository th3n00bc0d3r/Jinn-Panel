<?php
declare(strict_types=1);

/**
 * Which domain names an account may add (cPanel > Domains, aliases, and the
 * cPanel migration). Not proof of ownership - the certificate is, Let's
 * Encrypt only issues once the name's DNS points here - but it stops the
 * names that would break other accounts or the server:
 *  - the server's own names (its hostname and anything under it, the panel
 *    hostname - a second site block for it breaks every Caddy reload);
 *  - a name inside another account's domain (shop.example.com when someone
 *    else hosts example.com), or a parent of another account's domain;
 *  - public suffixes (co.uk, github.io...) and a handful of the most
 *    impersonated domains.
 */
final class DomainPolicy
{
    /** Multi-label public suffixes people actually try (the rest of the list is TLDs, refused by needing a dot). */
    private const SUFFIXES = [
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'ac.uk', 'gov.uk', 'com.au', 'net.au', 'org.au', 'co.nz', 'co.za',
        'com.pk', 'net.pk', 'org.pk', 'edu.pk', 'gov.pk', 'com.br', 'com.tr', 'com.sa', 'com.eg', 'co.in', 'co.jp', 'com.cn', 'com.mx',
        'co.ae', 'com.ng', 'com.my', 'com.sg', 'com.ph', 'co.id', 'co.kr', 'com.ar', 'com.co', 'github.io', 'gitlab.io', 'herokuapp.com',
        'vercel.app', 'netlify.app', 'pages.dev', 'workers.dev', 'web.app', 'firebaseapp.com', 'blogspot.com', 'wordpress.com',
        'azurewebsites.net', 'cloudfront.net', 'amazonaws.com', 'appspot.com', 'duckdns.org', 'ngrok.io', 'ngrok-free.app',
    ];
    private const PROTECTED = [
        'google.com', 'gmail.com', 'youtube.com', 'facebook.com', 'instagram.com', 'whatsapp.com', 'microsoft.com', 'outlook.com',
        'live.com', 'office.com', 'apple.com', 'icloud.com', 'amazon.com', 'paypal.com', 'netflix.com', 'twitter.com', 'x.com',
        'linkedin.com', 'yahoo.com', 'cloudflare.com', 'github.com', 'stripe.com', 'binance.com', 'coinbase.com', 'dropbox.com',
    ];

    /** Why $domain can't be added by account $userId (null = it can). */
    public static function problem(string $domain, int $userId): ?string
    {
        $domain = strtolower(rtrim($domain, '.'));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain) || strlen($domain) > 190) {
            return 'Enter a valid domain name, e.g. example.com';
        }
        $server = strtolower(Config::SERVER_HOSTNAME);
        if ($domain === $server || str_ends_with($domain, ".$server") || $domain === Auth::panelHost()) {
            return 'That name belongs to this server itself.';
        }
        $bare = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
        if (in_array($bare, self::SUFFIXES, true)) {
            return "\"$bare\" is a public suffix - add a domain registered under it instead.";
        }
        foreach (array_merge(self::PROTECTED, self::SUFFIXES) as $p) {
            if ($bare === $p || (in_array($p, self::PROTECTED, true) && str_ends_with($bare, ".$p"))) {
                return 'That domain can\'t be hosted here.';
            }
        }
        if (DomainAliasService::nameTaken($domain)) {
            return 'That domain is already hosted on this server.';
        }
        // Another account's domain (or alias) above or below this name.
        $pdo = Database::app();
        $s = $pdo->query('SELECT d.domain_name AS name, d.user_id FROM domains d UNION ALL SELECT a.alias_name, d.user_id FROM domain_aliases a JOIN domains d ON d.id = a.domain_id');
        foreach ($s->fetchAll() as $row) {
            if ((int) $row['user_id'] === $userId) {
                continue;
            }
            $other = strtolower((string) $row['name']);
            if (str_ends_with($domain, ".$other")) {
                return "That name is under $other, which another account hosts.";
            }
            if (str_ends_with($other, ".$domain")) {
                return "Another account hosts $other, which is under that name.";
            }
        }
        return null;
    }
}
