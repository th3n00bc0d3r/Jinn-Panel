<?php
declare(strict_types=1);

/**
 * Mail client self-configuration for domains whose mail is on this server:
 *  - Thunderbird / many phones: GET autoconfig.<domain>/mail/config-v1.1.xml
 *    (and /.well-known/autoconfig/mail/config-v1.1.xml on the domain);
 *  - Outlook: POST autodiscover.<domain>/autodiscover/autodiscover.xml.
 * Both name mail.<domain> (MailDnsService::mailHost) rather than the server
 * hostname, so the settings a customer sees carry their own domain.
 * Reached through the mail services Caddy site (MailDnsService), no login.
 */
final class AutoconfigController
{
    /** Thunderbird autoconfig (clientConfig 1.1). */
    public static function thunderbird(): void
    {
        $email = strtolower(trim((string) ($_GET['emailaddress'] ?? '')));
        $domain = self::domainFor($email);
        if ($domain === null) {
            self::notFound();
        }
        $host = MailDnsService::mailHost($domain);
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? e($email) : '%EMAILADDRESS%';
        $d = e($domain);
        $h = e($host);
        $server = fn(string $tag, string $type, int $port, string $socket): string => <<<XML
                <$tag type="$type">
                    <hostname>$h</hostname>
                    <port>$port</port>
                    <socketType>$socket</socketType>
                    <username>$user</username>
                    <authentication>password-cleartext</authentication>
                </$tag>

        XML;
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<clientConfig version=\"1.1\">\n    <emailProvider id=\"$d\">\n"
            . "        <domain>$d</domain>\n        <displayName>$d</displayName>\n        <displayShortName>$d</displayShortName>\n"
            . $server('incomingServer', 'imap', 993, 'SSL')
            . $server('incomingServer', 'pop3', 995, 'SSL')
            . $server('outgoingServer', 'smtp', 465, 'SSL')
            . $server('outgoingServer', 'smtp', 587, 'STARTTLS')
            . "    </emailProvider>\n</clientConfig>\n";
        exit;
    }

    /** Outlook autodiscover (POX "outlookacc" response schema). */
    public static function outlook(): void
    {
        $email = '';
        $body = (string) file_get_contents('php://input', false, null, 0, 65536);
        if (preg_match('#<EMailAddress>\s*([^<\s]+)\s*</EMailAddress>#i', $body, $m)) {
            $email = strtolower(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
        } elseif (isset($_GET['emailaddress']) || isset($_GET['Email'])) {
            $email = strtolower(trim((string) ($_GET['emailaddress'] ?? $_GET['Email'])));
        }
        $domain = self::domainFor($email);
        if ($domain === null) {
            self::notFound();
        }
        $h = e(MailDnsService::mailHost($domain));
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? e($email) : '';
        $protocol = fn(string $type, int $port, string $ssl, string $encryption = ''): string =>
            "            <Protocol>\n                <Type>$type</Type>\n                <Server>$h</Server>\n                <Port>$port</Port>\n"
            . "                <LoginName>$user</LoginName>\n                <DomainRequired>off</DomainRequired>\n                <SPA>off</SPA>\n"
            . "                <SSL>$ssl</SSL>\n" . ($encryption !== '' ? "                <Encryption>$encryption</Encryption>\n" : '')
            . "                <AuthRequired>on</AuthRequired>\n            </Protocol>\n";
        header('Content-Type: application/xml; charset=utf-8');
        echo "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
            . "<Autodiscover xmlns=\"http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006\">\n"
            . "    <Response xmlns=\"http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a\">\n"
            . "        <Account>\n            <AccountType>email</AccountType>\n            <Action>settings</Action>\n"
            . $protocol('IMAP', 993, 'on', 'SSL')
            . $protocol('POP3', 995, 'on', 'SSL')
            . $protocol('SMTP', 465, 'on', 'SSL')
            . "        </Account>\n    </Response>\n</Autodiscover>\n";
        exit;
    }

    /**
     * The hosted mail domain for an address, or else for the Host header
     * (autoconfig.<domain>, autodiscover.<domain> or the domain itself).
     */
    private static function domainFor(string $email): ?string
    {
        if (($at = strrpos($email, '@')) !== false) {
            $candidates = [substr($email, $at + 1)]; // an address on another domain gets nothing
        } else {
            $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
            $candidates = [preg_replace('/^(autoconfig|autodiscover)\./', '', $host)];
        }
        foreach ($candidates as $d) {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', (string) $d)) {
                continue;
            }
            $stmt = Database::app()->prepare('SELECT domain_name FROM domains WHERE domain_name = ? AND mail_domain_id IS NOT NULL');
            $stmt->execute([$d]);
            if (($found = $stmt->fetchColumn()) !== false) {
                return (string) $found;
            }
        }
        return null;
    }

    private static function notFound(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found\n";
        exit;
    }
}
