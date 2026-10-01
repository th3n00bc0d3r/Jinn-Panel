<?php
declare(strict_types=1);

/** Minimal RESP client for the local Valkey (the panel shouldn't need the redis extension). */
final class Valkey
{
    /** @var resource */
    private $sock;

    public function __construct(string $user, string $password, string $host = '127.0.0.1', int $port = 6379)
    {
        $s = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 3);
        if ($s === false) {
            throw new RuntimeException("The object cache (Valkey) isn't reachable: $errstr");
        }
        stream_set_timeout($s, 10);
        $this->sock = $s;
        $this->cmd('AUTH', $user, $password);
    }

    public static function admin(): self
    {
        return new self('jinnpanel', defined('Config::VALKEY_ADMIN_PASS') ? (string) constant('Config::VALKEY_ADMIN_PASS') : '');
    }

    /** @return mixed string|int|array|null; throws on an error reply */
    public function cmd(string ...$args): mixed
    {
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $out .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
        }
        fwrite($this->sock, $out);
        return $this->read();
    }

    private function read(): mixed
    {
        $line = fgets($this->sock);
        if ($line === false) {
            throw new RuntimeException('The object cache closed the connection.');
        }
        $type = $line[0];
        $data = substr($line, 1, -2);
        switch ($type) {
            case '+':
                return $data;
            case '-':
                throw new RuntimeException('Valkey: ' . $data);
            case ':':
                return (int) $data;
            case '$':
                $len = (int) $data;
                if ($len < 0) {
                    return null;
                }
                $buf = '';
                while (strlen($buf) < $len + 2) {
                    $chunk = fread($this->sock, $len + 2 - strlen($buf));
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException('Short read from the object cache.');
                    }
                    $buf .= $chunk;
                }
                return substr($buf, 0, $len);
            case '*':
                $n = (int) $data;
                if ($n < 0) {
                    return null;
                }
                $arr = [];
                for ($i = 0; $i < $n; $i++) {
                    $arr[] = $this->read();
                }
                return $arr;
            default:
                throw new RuntimeException('Unexpected reply from the object cache.');
        }
    }

    /** Deletes every key matching $pattern (SCAN + UNLINK); returns how many. */
    public function deleteMatching(string $pattern): int
    {
        $cursor = '0';
        $n = 0;
        do {
            [$cursor, $keys] = $this->cmd('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '1000');
            if ($keys) {
                $n += (int) $this->cmd('UNLINK', ...$keys);
            }
        } while ($cursor !== '0');
        return $n;
    }

    public function countMatching(string $pattern, int $max = 100000): int
    {
        $cursor = '0';
        $n = 0;
        do {
            [$cursor, $keys] = $this->cmd('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '1000');
            $n += count((array) $keys);
        } while ($cursor !== '0' && $n < $max);
        return $n;
    }

    public function __destruct()
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
    }
}
