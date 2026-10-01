<?php
declare(strict_types=1);

/**
 * Just enough IMAP4rev1 for backups: log in to the local mail server as a
 * mailbox (master login "<address>%<admin>"), list folders, stream every
 * message out, and append messages back. Talks to 127.0.0.1:993; the
 * certificate is for the server's hostname, so peer-name checks are off
 * for this loopback connection only.
 */
final class ImapClient
{
    /** @var resource */
    private $conn;
    private int $tag = 0;

    public function __construct(string $user, string $password, string $host = '127.0.0.1', int $port = 993)
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $conn = @stream_socket_client("tls://$host:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if ($conn === false) {
            throw new RuntimeException("IMAP connection failed: $errstr");
        }
        stream_set_timeout($conn, 120);
        $this->conn = $conn;
        $this->line(); // greeting
        $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password));
    }

    public function __destruct()
    {
        if (is_resource($this->conn)) {
            @fwrite($this->conn, 'Z LOGOUT' . "\r\n");
            @fclose($this->conn);
        }
    }

    /** @return list<string> folder names (raw IMAP names) */
    public function folders(): array
    {
        $out = [];
        foreach ($this->command('LIST "" "*"') as $line) {
            if (preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) (.+)$/i', $line, $m) && stripos($m[1], '\\Noselect') === false) {
                $out[] = self::unquote(trim($m[2]));
            }
        }
        return $out;
    }

    /**
     * Calls $onMessage(string $raw, string $internalDate, list<string> $flags)
     * for every message in $folder. Returns the count.
     */
    public function each(string $folder, callable $onMessage): int
    {
        $exists = 0;
        foreach ($this->command('EXAMINE ' . self::quote($folder)) as $line) {
            if (preg_match('/^\* (\d+) EXISTS/i', $line, $m)) {
                $exists = (int) $m[1];
            }
        }
        if ($exists === 0) {
            return 0;
        }
        $n = 0;
        // In batches: one FETCH of a big mailbox would be a single huge reply.
        for ($from = 1; $from <= $exists; $from += 200) {
            $to = min($exists, $from + 199);
            $tag = $this->send("FETCH $from:$to (FLAGS INTERNALDATE BODY.PEEK[])");
            while (true) {
                $line = $this->line();
                if (str_starts_with($line, "$tag ")) {
                    if (!preg_match("/^$tag OK/i", $line)) {
                        throw new RuntimeException("IMAP FETCH failed: $line");
                    }
                    break;
                }
                if (!preg_match('/^\* \d+ FETCH \((.*)\{(\d+)\}$/i', $line, $m)) {
                    continue;
                }
                $raw = $this->read((int) $m[2]);
                $tail = $this->line(); // the rest of the FETCH item list, if any
                $meta = $m[1] . ' ' . $tail;
                preg_match('/FLAGS \(([^)]*)\)/i', $meta, $f);
                preg_match('/INTERNALDATE "([^"]+)"/i', $meta, $d);
                $flags = array_values(array_filter(explode(' ', $f[1] ?? ''), fn($x) => $x !== '' && strcasecmp($x, '\\Recent') !== 0));
                $onMessage($raw, $d[1] ?? '', $flags);
                $n++;
            }
        }
        return $n;
    }

    public function ensureFolder(string $folder): void
    {
        try {
            $this->command('CREATE ' . self::quote($folder));
        } catch (RuntimeException) {
            // already exists
        }
    }

    /** @return list<string> Message-IDs already in $folder (for skipping duplicates on restore) */
    public function messageIds(string $folder): array
    {
        $ids = [];
        $this->command('EXAMINE ' . self::quote($folder));
        $tag = $this->send('FETCH 1:* (BODY.PEEK[HEADER.FIELDS (MESSAGE-ID)])');
        while (true) {
            $line = $this->line();
            if (str_starts_with($line, "$tag ")) {
                break; // NO when the folder is empty
            }
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $hdr = $this->read((int) $m[1]);
                if (preg_match('/^Message-ID:\s*(\S+)/im', $hdr, $id)) {
                    $ids[] = $id[1];
                }
            }
        }
        return $ids;
    }

    /** @param list<string> $flags */
    public function append(string $folder, string $raw, string $internalDate = '', array $flags = []): void
    {
        $flagList = '(' . implode(' ', array_filter($flags, fn($f) => preg_match('/^\\\\?[A-Za-z$]+$/', $f))) . ')';
        $date = $internalDate !== '' ? ' "' . $internalDate . '"' : '';
        $tag = $this->send('APPEND ' . self::quote($folder) . " $flagList$date {" . strlen($raw) . '}');
        $line = $this->line();
        if (!str_starts_with($line, '+')) {
            throw new RuntimeException("IMAP APPEND refused: $line");
        }
        $this->write($raw . "\r\n");
        while (true) {
            $line = $this->line();
            if (str_starts_with($line, "$tag ")) {
                if (!preg_match("/^$tag OK/i", $line)) {
                    throw new RuntimeException("IMAP APPEND failed: $line");
                }
                return;
            }
        }
    }

    /** @return list<string> untagged lines */
    private function command(string $cmd): array
    {
        $tag = $this->send($cmd);
        $lines = [];
        while (true) {
            $line = $this->line();
            if (str_starts_with($line, "$tag ")) {
                if (!preg_match("/^$tag OK/i", $line)) {
                    throw new RuntimeException('IMAP ' . strtok($cmd, ' ') . ' failed: ' . substr($line, strlen($tag) + 1));
                }
                return $lines;
            }
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $line .= $this->read((int) $m[1]) . $this->line();
            }
            $lines[] = $line;
        }
    }

    private function send(string $cmd): string
    {
        $tag = 'A' . (++$this->tag);
        $this->write("$tag $cmd\r\n");
        return $tag;
    }

    private function write(string $data): void
    {
        for ($done = 0; $done < strlen($data);) {
            $n = @fwrite($this->conn, substr($data, $done));
            if ($n === false || $n === 0) {
                throw new RuntimeException('IMAP connection lost.');
            }
            $done += $n;
        }
    }

    private function line(): string
    {
        $line = fgets($this->conn);
        if ($line === false) {
            throw new RuntimeException('IMAP connection lost.');
        }
        return rtrim($line, "\r\n");
    }

    private function read(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->conn, min(65536, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('IMAP connection lost.');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private static function quote(string $s): string
    {
        return '"' . addcslashes($s, "\"\\") . '"';
    }

    private static function unquote(string $s): string
    {
        return (strlen($s) >= 2 && $s[0] === '"') ? stripcslashes(substr($s, 1, -1)) : $s;
    }
}
