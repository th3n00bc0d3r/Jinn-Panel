<?php
declare(strict_types=1);

/**
 * Imports a cPanel (Dovecot) Maildir++ mail store into a Stalwart mailbox
 * using plain, standard JMAP (RFC 8620/8621): each message file is uploaded
 * as a blob, then attached to the right mailbox with Email/import, keeping
 * its flags (seen/answered/flagged/draft) and original received date.
 *
 * It authenticates AS the mailbox user, with a random one-off password the
 * migration runner gives the account just for the import and replaces with
 * the real (imported) password hash right after - so no admin impersonation
 * is needed, and the user's real password is never known in plaintext.
 *
 * Maildir++ layout: the mailbox root's cur/ + new/ are INBOX; every
 * ".Name" or ".Parent.Child" subdirectory is a folder (Dovecot's "."
 * hierarchy separator, names in IMAP modified UTF-7).
 */
final class MailImportService
{
    private const BATCH = 25;
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

    public function __construct(private string $login, private string $password)
    {
    }

    /**
     * @return array{imported:int, failed:int, skipped:int, folders:int, errors:array<int,string>}
     */
    public function importMaildir(string $maildir, ?callable $onProgress = null, ?callable $shouldStop = null): array
    {
        $this->openSession();
        $this->loadMailboxes();

        $stats = ['imported' => 0, 'failed' => 0, 'skipped' => 0, 'folders' => 0, 'errors' => []];
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
                    $size = (int) filesize($file);
                    if ($size === 0 || $size > $this->maxUpload) {
                        $stats['skipped']++;
                        $stats['errors'][] = basename($file) . ": skipped (size $size bytes)";
                        continue;
                    }
                    try {
                        $blobId = $this->upload((string) file_get_contents($file));
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
    private function http(string $method, string $url, ?string $body, string $contentType): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_ENCODING => '',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('Mail server request failed: ' . curl_error($ch));
        }
        return ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'raw' => (string) $raw];
    }
}
