<?php
declare(strict_types=1);

/**
 * Imports a cPanel (Dovecot) Maildir++ mail store into a Stalwart mailbox
 * using plain, standard JMAP (RFC 8620/8621): each message file is uploaded
 * as a blob, then attached to the right mailbox with Email/import, keeping
 * its flags (seen/answered/flagged/draft) and original received date.
 *
 * It logs in to the mailbox with Stalwart's master-user login
 * ("<address>%<admin>" + the admin password, see asAdmin()), so the
 * mailbox keeps its real (imported) password throughout and the panel never
 * needs to know it.
 *
 * With $skipExisting, messages the mailbox already holds (same Message-ID,
 * or same received time and size when there is none) are skipped, so an
 * import that stopped half-way can be run again without duplicates.
 *
 * Maildir++ layout: the mailbox root's cur/ + new/ are INBOX; every
 * ".Name" or ".Parent.Child" subdirectory is a folder (Dovecot's "."
 * hierarchy separator, names in IMAP modified UTF-7).
 */
final class MailImportService
{
    private const BATCH = 25;
    private const RETRIES = 8;
    private const ROLE_NAMES = [
        'sent' => ['sent', 'sent items', 'sent messages', 'sent mail'],
        'drafts' => ['drafts', 'draft'],
        'trash' => ['trash', 'deleted items', 'deleted messages', 'bin'],
        'junk' => ['junk', 'spam', 'junk e-mail', 'junk email', 'bulk mail'],
        'archive' => ['archive', 'archives'],
    ];

    private string $accountId = '';
    private int $maxUpload = 50000000;
    /** @var array<string,string> "parentId\0name" => mailboxId */
    private array $byPath = [];
    /** @var array<string,string> role => mailboxId */
    private array $byRole = [];

    /** @var array<string,true> "mailboxId dedupKey" of messages already in the mailbox */
    private array $existing = [];

    public function __construct(private string $login, private string $password)
    {
    }

    /** Logs in to $address as the Stalwart admin (master user), not with the mailbox's own password. */
    public static function asAdmin(string $address): self
    {
        return new self($address . '%' . Config::MAIL_ADMIN_USER, Config::MAIL_ADMIN_PASS);
    }

    /**
     * @return array{imported:int, failed:int, skipped:int, folders:int, errors:array<int,string>}
     */
    public function importMaildir(string $maildir, ?callable $onProgress = null, ?callable $shouldStop = null, bool $skipExisting = false): array
    {
        $this->openSession();
        $this->loadMailboxes();
        if ($skipExisting) {
            $this->loadExisting();
        }

        $stats = ['imported' => 0, 'failed' => 0, 'skipped' => 0, 'existing' => 0, 'folders' => 0, 'errors' => []];
        foreach ($this->folders($maildir) as $folderPath => $dir) {
            $mailboxId = $this->mailboxFor($folderPath);
            $stats['folders']++;
            $batch = [];
            foreach (['cur', 'new'] as $sub) {
                foreach (is_dir("$dir/$sub") ? (scandir("$dir/$sub") ?: []) : [] as $f) {
                    if ($f[0] === '.' || !is_file("$dir/$sub/$f") || is_link("$dir/$sub/$f")) {
                        continue;
                    }
                    if ($shouldStop && $shouldStop()) {
                        throw new RuntimeException('Cancelled.');
                    }
                    $file = "$dir/$sub/$f";
                    [$keywords, $trashed] = self::flagsFromFilename($f, $sub === 'new');
                    if ($trashed) {
                        $stats['skipped']++; // Dovecot "T" flag: deleted, just not expunged yet
                        continue;
                    }
                    try {
                        $message = self::readMessage($file);
                        $size = strlen($message);
                        if ($size === 0 || $size > $this->maxUpload) {
                            $stats['skipped']++;
                            $stats['errors'][] = basename($file) . ": skipped (size $size bytes)";
                            continue;
                        }
                        if ($this->existing && isset($this->existing[$mailboxId . ' ' . self::dedupKey($message, self::receivedAt($f, $file))])) {
                            $stats['existing']++;
                            continue;
                        }
                        $blobId = $this->upload($message);
                    } catch (Throwable $e) {
                        $stats['failed']++;
                        self::note($stats, basename($file) . ': ' . $e->getMessage());
                        continue;
                    }
                    $batch[] = [
                        'blobId' => $blobId,
                        'mailboxIds' => (object) [$mailboxId => true], // object even if the id looks numeric
                        'keywords' => $keywords ?: new stdClass(),
                        'receivedAt' => gmdate('Y-m-d\TH:i:s\Z', self::receivedAt($f, $file)),
                    ];
                    if (count($batch) >= self::BATCH) {
                        $this->flush($batch, $stats);
                        if ($onProgress) {
                            $onProgress($stats);
                        }
                    }
                }
            }
            $this->flush($batch, $stats);
            if ($onProgress) {
                $onProgress($stats);
            }
        }
        return $stats;
    }

    /**
     * A message file's contents, decompressed: cPanel's Dovecot zlib plugin
     * stores messages gzip-compressed (and can use bzip2/xz/zstd), while the
     * plain ones are left as they are.
     */
    public static function readMessage(string $file): string
    {
        $raw = (string) file_get_contents($file);
        $out = match (true) {
            str_starts_with($raw, "\x1f\x8b") => @gzdecode($raw),
            str_starts_with($raw, 'BZh') && function_exists('bzdecompress') => @bzdecompress($raw),
            str_starts_with($raw, "\xfd7zXZ\x00") => self::decompressWith('xz', $file),
            str_starts_with($raw, "\x28\xb5\x2f\xfd") => self::decompressWith('zstd', $file),
            default => $raw,
        };
        if (!is_string($out)) {
            throw new RuntimeException('compressed message could not be decompressed');
        }
        return $out;
    }

    private static function decompressWith(string $tool, string $file): string|false
    {
        if (trim((string) shell_exec('command -v ' . escapeshellarg($tool) . ' 2>/dev/null')) === '') {
            throw new RuntimeException("message is $tool-compressed and $tool is not installed (dnf -y install $tool)");
        }
        $out = '';
        return CpanelBackupReader::run([$tool, '-dc', $file], $out) === 0 ? $out : false;
    }

    private static function note(array &$stats, string $msg): void
    {
        if (count($stats['errors']) < 20) {
            $stats['errors'][] = $msg;
        }
    }

    /** @return array<string,string> folder path ('' = INBOX, 'Work/2024' = nested) => directory */
    public function folders(string $maildir): array
    {
        $out = [];
        if (is_dir("$maildir/cur") || is_dir("$maildir/new")) {
            $out[''] = $maildir;
        }
        foreach (scandir($maildir) ?: [] as $entry) {
            if (strlen($entry) < 2 || $entry[0] !== '.' || $entry === '..') {
                continue;
            }
            $dir = "$maildir/$entry";
            if (!is_dir($dir) || is_link($dir) || (!is_dir("$dir/cur") && !is_dir("$dir/new"))) {
                continue;
            }
            $name = substr($entry, 1);
            if (stripos($name, 'INBOX.') === 0) {
                $name = substr($name, 6);
            }
            $parts = array_filter(array_map([self::class, 'decodeFolderName'], explode('.', $name)), fn($p) => $p !== '');
            if ($parts) {
                $out[implode('/', $parts)] = $dir;
            }
        }
        return $out;
    }

    public static function decodeFolderName(string $name): string
    {
        if (str_contains($name, '&') && function_exists('mb_convert_encoding')) {
            $decoded = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
            if (is_string($decoded) && $decoded !== '') {
                $name = $decoded;
            }
        }
        return trim(str_replace('/', '-', $name));
    }

    /** @return array{0: array<string,bool>, 1: bool} keywords, trashed */
    public static function flagsFromFilename(string $filename, bool $isNew): array
    {
        $keywords = [];
        $trashed = false;
        if (!$isNew && preg_match('/[:!]2,([A-Za-z]*)$/', $filename, $m)) {
            $map = ['S' => '$seen', 'R' => '$answered', 'F' => '$flagged', 'D' => '$draft'];
            foreach (str_split($m[1]) as $flag) {
                if (isset($map[$flag])) {
                    $keywords[$map[$flag]] = true;
                }
                if ($flag === 'T') {
                    $trashed = true;
                }
            }
        }
        return [$keywords, $trashed];
    }

    public static function receivedAt(string $filename, string $path): int
    {
        if (preg_match('/^(\d{9,10})\./', $filename, $m) && (int) $m[1] > 315532800 && (int) $m[1] <= time() + 86400) {
            return (int) $m[1];
        }
        return (int) (@filemtime($path) ?: time());
    }

    /** Message-ID when the message has one, else received time + size (what Stalwart reports back). */
    public static function dedupKey(string $message, int $receivedAt): string
    {
        $end = strpos($message, "\r\n\r\n");
        $end = $end === false ? strpos($message, "\n\n") : $end;
        $headers = substr($message, 0, $end === false ? 65536 : $end);
        if (preg_match('/^Message-ID:[ \t]*(?:\r?\n[ \t]+)?<([^>\r\n]+)>/mi', $headers, $m)) {
            return 'id:' . trim($m[1]); // Stalwart trims "<id >" too
        }
        return 'at:' . $receivedAt . ':' . strlen($message);
    }

    private function loadExisting(): void
    {
        for ($position = 0; ; $position += count($ids)) {
            $resp = $this->call([
                ['Email/query', ['accountId' => $this->accountId, 'position' => $position, 'limit' => 500], 'q'],
                ['Email/get', ['accountId' => $this->accountId, 'properties' => ['mailboxIds', 'messageId', 'receivedAt', 'size'],
                    '#ids' => ['resultOf' => 'q', 'name' => 'Email/query', 'path' => '/ids']], 'g'],
            ]);
            $ids = (array) ($resp[0][1]['ids'] ?? []);
            foreach ((array) ($resp[1][1]['list'] ?? []) as $e) {
                $key = !empty($e['messageId'][0])
                    ? 'id:' . $e['messageId'][0]
                    : 'at:' . strtotime((string) $e['receivedAt']) . ':' . (int) $e['size'];
                foreach (array_keys((array) ($e['mailboxIds'] ?? [])) as $mb) {
                    $this->existing[$mb . ' ' . $key] = true;
                }
            }
            if (!$ids) {
                return;
            }
        }
    }

    // ------------------------------------------------------------------
    // JMAP plumbing
    // ------------------------------------------------------------------

    private function openSession(): void
    {
        $res = $this->http('GET', Config::MAIL_API_BASE . '/jmap/session', null, 'application/json');
        $body = json_decode($res['raw'], true);
        if ($res['status'] !== 200 || !is_array($body)) {
            throw new RuntimeException('Could not log in to the new mailbox over JMAP (HTTP ' . $res['status'] . ').');
        }
        $this->accountId = (string) ($body['primaryAccounts']['urn:ietf:params:jmap:mail'] ?? '');
        if ($this->accountId === '') {
            throw new RuntimeException('JMAP session has no mail account.');
        }
        $max = (int) ($body['capabilities']['urn:ietf:params:jmap:core']['maxSizeUpload'] ?? 0);
        if ($max > 0) {
            $this->maxUpload = $max;
        }
    }

    private function call(array $methodCalls): array
    {
        $payload = json_encode([
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
            'methodCalls' => $methodCalls,
        ], JSON_UNESCAPED_SLASHES);
        $res = $this->http('POST', Config::MAIL_API_BASE . '/jmap/', $payload, 'application/json');
        $body = json_decode($res['raw'], true);
        if ($res['status'] !== 200 || !is_array($body)) {
            throw new RuntimeException('JMAP call failed (HTTP ' . $res['status'] . '): ' . substr($res['raw'], 0, 200));
        }
        return $body['methodResponses'] ?? [];
    }

    private function upload(string $message): string
    {
        $res = $this->http('POST', Config::MAIL_API_BASE . '/jmap/upload/' . rawurlencode($this->accountId) . '/', $message, 'message/rfc822');
        $body = json_decode($res['raw'], true);
        if ($res['status'] >= 300 || empty($body['blobId'])) {
            throw new RuntimeException('upload failed (HTTP ' . $res['status'] . ')');
        }
        return (string) $body['blobId'];
    }

    private function flush(array &$batch, array &$stats): void
    {
        if (!$batch) {
            return;
        }
        $emails = [];
        foreach ($batch as $i => $e) {
            $emails["m$i"] = $e;
        }
        try {
            $resp = $this->call([['Email/import', ['accountId' => $this->accountId, 'emails' => $emails], '0']]);
            $result = $resp[0][1] ?? [];
            $stats['imported'] += count($result['created'] ?? []);
            foreach ((array) ($result['notCreated'] ?? []) as $err) {
                $stats['failed']++;
                self::note($stats, 'import: ' . ($err['description'] ?? $err['type'] ?? 'rejected'));
            }
            if (($resp[0][0] ?? '') === 'error') {
                $stats['failed'] += count($batch);
                self::note($stats, 'import batch error: ' . json_encode($result));
            }
        } catch (Throwable $e) {
            $stats['failed'] += count($batch);
            self::note($stats, $e->getMessage());
        }
        $batch = [];
    }

    private function loadMailboxes(): void
    {
        $resp = $this->call([['Mailbox/get', ['accountId' => $this->accountId, 'properties' => ['id', 'name', 'parentId', 'role']], '0']]);
        foreach ((array) ($resp[0][1]['list'] ?? []) as $mb) {
            $this->byPath[($mb['parentId'] ?? '') . "\0" . mb_strtolower((string) $mb['name'])] = (string) $mb['id'];
            if (!empty($mb['role'])) {
                $this->byRole[(string) $mb['role']] = (string) $mb['id'];
            }
        }
        if (!isset($this->byRole['inbox'])) {
            throw new RuntimeException('The new mailbox has no Inbox.');
        }
    }

    private function mailboxFor(string $folderPath): string
    {
        if ($folderPath === '') {
            return $this->byRole['inbox'];
        }
        $parts = explode('/', $folderPath);
        if (count($parts) === 1) {
            foreach (self::ROLE_NAMES as $role => $names) {
                if (in_array(mb_strtolower($parts[0]), $names, true) && isset($this->byRole[$role])) {
                    return $this->byRole[$role];
                }
            }
        }
        $parentId = '';
        foreach ($parts as $name) {
            $key = $parentId . "\0" . mb_strtolower($name);
            if (!isset($this->byPath[$key])) {
                $create = ['name' => $name];
                if ($parentId !== '') {
                    $create['parentId'] = $parentId;
                }
                $resp = $this->call([['Mailbox/set', ['accountId' => $this->accountId, 'create' => ['f' => $create]], '0']]);
                $id = $resp[0][1]['created']['f']['id'] ?? null;
                if (!$id) {
                    throw new RuntimeException("Could not create folder \"$folderPath\": " . json_encode($resp[0][1]['notCreated'] ?? $resp));
                }
                $this->byPath[$key] = (string) $id;
            }
            $parentId = $this->byPath[$key];
        }
        return $parentId;
    }

    /** @return array{status:int, raw:string} */
    /**
     * One JMAP HTTP request. Stalwart rate-limits uploads and requests per
     * account (HTTP 429), which a big mailbox hits within seconds - back off
     * (Retry-After when given) and try again rather than losing the message.
     */
    private function http(string $method, string $url, ?string $body, string $contentType): array
    {
        for ($attempt = 1; ; $attempt++) {
            $retryAfter = null;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $this->login . ':' . $this->password,
                CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType, 'Accept: application/json'],
                CURLOPT_TIMEOUT => 120,
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$retryAfter): int {
                    if (preg_match('/^retry-after:\s*(\d+)/i', $line, $m)) {
                        $retryAfter = (int) $m[1];
                    }
                    return strlen($line);
                },
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $raw = curl_exec($ch);
            if ($raw === false) {
                throw new RuntimeException('Mail server request failed: ' . curl_error($ch));
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (!in_array($status, [429, 503], true) || $attempt >= self::RETRIES) {
                return ['status' => $status, 'raw' => (string) $raw];
            }
            sleep(min(60, max(1, $retryAfter ?? 2 ** ($attempt - 1))));
        }
    }
}
