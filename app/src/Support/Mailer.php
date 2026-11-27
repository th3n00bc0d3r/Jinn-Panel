<?php
declare(strict_types=1);

/**
 * Mail the panel sends itself (welcome, password reset, alerts).
 *
 * Goes through PHP's mail(): install.sh points sendmail_path at msmtp,
 * which hands it to Stalwart on 127.0.0.1:25. Stalwart relays loopback
 * mail without a login only when the sender's domain is a mail domain on
 * this server (MailService::ensureMailPolicy) and DKIM-signs it - so the
 * From address (WHM > Notifications) must be on one of those domains.
 */
final class Mailer
{
    /** The From address: the configured one, or noreply@<server hostname>. */
    public static function from(): string
    {
        $from = trim(Settings::get('notify_from'));
        return filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : 'noreply@' . strtolower(Config::SERVER_HOSTNAME);
    }

    public static function fromName(): string
    {
        $name = trim(Settings::get('notify_from_name'));
        return $name !== '' ? $name : Config::APP_NAME;
    }

    /**
     * Sends a plain-text mail (with a simple HTML version). Returns false
     * (and logs why) when it couldn't be handed over.
     */
    public static function send(string $to, string $subject, string $text): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject)) {
            error_log("mailer: refusing to send to \"$to\"");
            return false;
        }
        $from = self::from();
        $boundary = 'jp-' . bin2hex(random_bytes(12));
        $host = strtolower(Config::SERVER_HOSTNAME);
        $headers = [
            'From' => self::encodeName(self::fromName()) . " <$from>",
            'Date' => date(DATE_RFC2822),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . "@$host>",
            'MIME-Version' => '1.0',
            'Content-Type' => "multipart/alternative; boundary=\"$boundary\"",
            'Auto-Submitted' => 'auto-generated',
            'X-Mailer' => Config::APP_NAME,
        ];
        $body = "--$boundary\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text))) . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(self::html($subject, $text)) . "\r\n"
            . "--$boundary--\r\n";
        try {
            // No -f here: sendmail_path already runs msmtp with
            // --read-envelope-from (the envelope sender is taken from the
            // From header, i.e. $from), and msmtp refuses both at once
            // ("cannot use both --from and --read-envelope-from", exit 64):
            // every panel mail failed while -f was passed.
            $ok = mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'Q'), $body, $headers);
        } catch (Throwable $e) {
            error_log('mailer: ' . $e->getMessage());
            return false;
        }
        if (!$ok) {
            error_log("mailer: mail() refused the message to $to");
        }
        return $ok;
    }

    private static function encodeName(string $name): string
    {
        return preg_match('/^[A-Za-z0-9 ._-]+$/', $name) ? '"' . $name . '"' : mb_encode_mimeheader($name, 'UTF-8', 'Q');
    }

    /** The text, escaped, with links made clickable, in a minimal layout. */
    private static function html(string $subject, string $text): string
    {
        $esc = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $esc = (string) preg_replace('#https://[^\s<]+#', '<a href="$0" style="color:#4f46e5">$0</a>', $esc);
        $paras = array_map(fn($p) => '<p style="margin:0 0 14px">' . nl2br(trim($p)) . '</p>', preg_split("/\n{2,}/", $esc) ?: []);
        return '<!doctype html><html><body style="margin:0;padding:24px;background:#f6f7f9;font:15px/1.55 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:28px">'
            . '<p style="margin:0 0 20px;font-weight:600;color:#4f46e5">' . htmlspecialchars(Config::APP_NAME, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<h1 style="margin:0 0 16px;font-size:18px">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h1>'
            . implode('', $paras)
            . '</div><p style="max-width:560px;margin:16px auto 0;font-size:12px;color:#64748b">Sent by the hosting panel on '
            . htmlspecialchars(Config::SERVER_HOSTNAME, ENT_QUOTES, 'UTF-8') . '.</p></body></html>';
    }
}
