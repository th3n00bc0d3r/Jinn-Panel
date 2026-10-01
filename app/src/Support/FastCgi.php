<?php
declare(strict_types=1);

/**
 * Minimal FastCGI client (responder role, one request per connection) for
 * talking to a hosting account's PHP-FPM pool over its Unix socket - see
 * PoolClient. Streams the request body from a resource and the response
 * body to a callback, so large uploads/downloads never sit in memory.
 */
final class FastCgi
{
    private const VERSION = 1;
    private const BEGIN_REQUEST = 1;
    private const END_REQUEST = 3;
    private const PARAMS = 4;
    private const STDIN = 5;
    private const STDOUT = 6;
    private const STDERR = 7;
    private const RESPONDER = 1;
    private const CHUNK = 65535;

    /**
     * @param array<string,string> $params
     * @param resource|string|null $body
     * @param callable(string):void|null $onBody gets the response body in chunks (after the headers); null = collect
     * @param callable(array<string,string>,int):void|null $onHeaders called once the headers are in, before any body
     * @return array{status:int, headers:array<string,string>, body:string, stderr:string}
     */
    public static function request(string $socket, array $params, $body = null, ?callable $onBody = null, int $timeout = 300, ?callable $onHeaders = null): array
    {
        $conn = @stream_socket_client('unix://' . $socket, $errno, $errstr, 5);
        if ($conn === false) {
            throw new RuntimeException("Can't reach the account's PHP ($errstr)");
        }
        stream_set_timeout($conn, $timeout);
        try {
            $id = 1;
            self::write($conn, self::BEGIN_REQUEST, $id, pack('nCx5', self::RESPONDER, 0));
            // PHP-FPM parses each PARAMS record on its own: a name/value pair
            // must never be split across records (so none may exceed one).
            $p = '';
            foreach ($params as $k => $v) {
                $pair = self::nameValue((string) $k, (string) $v);
                if (strlen($pair) > self::CHUNK) {
                    throw new InvalidArgumentException("FastCGI parameter $k is too long.");
                }
                if (strlen($p) + strlen($pair) > self::CHUNK) {
                    self::write($conn, self::PARAMS, $id, $p);
                    $p = '';
                }
                $p .= $pair;
            }
            if ($p !== '') {
                self::write($conn, self::PARAMS, $id, $p);
            }
            self::write($conn, self::PARAMS, $id, '');
            if (is_resource($body)) {
                while (!feof($body)) {
                    $chunk = (string) fread($body, self::CHUNK);
                    if ($chunk !== '') {
                        self::write($conn, self::STDIN, $id, $chunk);
                    }
                }
            } elseif (is_string($body) && $body !== '') {
                foreach (str_split($body, self::CHUNK) as $chunk) {
                    self::write($conn, self::STDIN, $id, $chunk);
                }
            }
            self::write($conn, self::STDIN, $id, '');

            $head = '';
            $headersDone = false;
            $headers = [];
            $status = 200;
            $out = '';
            $err = '';
            while (true) {
                [$type, $content] = self::read($conn);
                if ($type === self::STDOUT) {
                    if (!$headersDone) {
                        $head .= $content;
                        $pos = strpos($head, "\r\n\r\n");
                        if ($pos === false) {
                            continue;
                        }
                        foreach (explode("\r\n", substr($head, 0, $pos)) as $line) {
                            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
                            if (strcasecmp($name, 'Status') === 0) {
                                $status = (int) $value;
                            } elseif ($name !== '') {
                                $headers[strtolower($name)] = $value;
                            }
                        }
                        $content = substr($head, $pos + 4);
                        $headersDone = true;
                        if ($onHeaders !== null) {
                            $onHeaders($headers, $status);
                        }
                    }
                    if ($content !== '') {
                        $onBody ? $onBody($content) : $out .= $content;
                    }
                } elseif ($type === self::STDERR) {
                    $err .= $content;
                } elseif ($type === self::END_REQUEST) {
                    break;
                }
            }
            return ['status' => $status, 'headers' => $headers, 'body' => $out, 'stderr' => $err];
        } finally {
            fclose($conn);
        }
    }

    /** @param resource $conn */
    private static function write($conn, int $type, int $id, string $content): void
    {
        $len = strlen($content);
        $pad = (8 - ($len % 8)) % 8;
        $rec = pack('CCnnCx', self::VERSION, $type, $id, $len, $pad) . $content . str_repeat("\0", $pad);
        for ($done = 0; $done < strlen($rec);) {
            $n = @fwrite($conn, substr($rec, $done));
            if ($n === false || $n === 0) {
                throw new RuntimeException('The connection to the account\'s PHP broke.');
            }
            $done += $n;
        }
    }

    /** @param resource $conn @return array{0:int,1:string} */
    private static function read($conn): array
    {
        $h = self::readN($conn, 8);
        $r = unpack('Cversion/Ctype/nid/nlen/Cpad', $h);
        $content = $r['len'] > 0 ? self::readN($conn, $r['len']) : '';
        if ($r['pad'] > 0) {
            self::readN($conn, $r['pad']);
        }
        return [$r['type'], $content];
    }

    /** @param resource $conn */
    private static function readN($conn, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($conn, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($conn);
                throw new RuntimeException($meta['timed_out'] ? 'The account\'s PHP took too long to answer.' : 'The account\'s PHP closed the connection.');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private static function nameValue(string $name, string $value): string
    {
        $len = fn(int $l) => $l < 128 ? chr($l) : pack('N', $l | 0x80000000);
        return $len(strlen($name)) . $len(strlen($value)) . $name . $value;
    }
}
