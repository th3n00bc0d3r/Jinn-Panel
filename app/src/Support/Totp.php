<?php
declare(strict_types=1);

/** RFC 6238 time-based one-time passwords (6 digits, 30 s, SHA-1): what every authenticator app speaks. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;

    public static function newSecret(): string
    {
        return self::base32(random_bytes(20));
    }

    /**
     * The time step $code is valid for (one step of clock drift either way),
     * or null. Callers store the step and refuse it again, so a code can be
     * used only once.
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $key = self::unbase32($secret);
        $step = intdiv($now ?? time(), self::PERIOD);
        foreach ([0, -1, 1] as $drift) {
            $s = $step + $drift;
            if (($lastStep === null || $s > $lastStep) && hash_equals(self::code($key, $s), $code)) {
                return $s;
            }
        }
        return null;
    }

    public static function code(string $key, int $step): string
    {
        $mac = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($mac[19]) & 0x0f;
        $bin = ((ord($mac[$offset]) & 0x7f) << 24) | (ord($mac[$offset + 1]) << 16) | (ord($mac[$offset + 2]) << 8) | ord($mac[$offset + 3]);
        return str_pad((string) ($bin % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** otpauth:// URI for authenticator apps (QR code or tap-to-add on a phone). */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&period=' . self::PERIOD . '&digits=6&algorithm=SHA1';
    }

    /** The URI as an inline SVG QR code (qrencode, installed by install.sh), or null without it. */
    public static function qrSvg(string $uri): ?string
    {
        if (!is_executable('/usr/bin/qrencode')) {
            return null;
        }
        $proc = proc_open(['/usr/bin/qrencode', '-t', 'SVG', '--rle', '-m', '1', '-s', '6', '-o', '-', $uri], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        $svg = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($proc) !== 0 || !str_contains($svg, '<svg')) {
            return null;
        }
        return substr($svg, strpos($svg, '<svg'));
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function unbase32(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($text, '='))) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i !== false) {
                $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
