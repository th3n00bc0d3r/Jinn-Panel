<?php
declare(strict_types=1);

/**
 * Talks to a remote cPanel & WHM server for the migration feature.
 *
 * Three source types share one code path:
 *  - cpanel   : a single cPanel account, UAPI directly on :2083
 *               (https://host:2083/execute/Module/function)
 *  - reseller : a WHM reseller, WHM API 1 on :2087, scoped by cPanel itself
 *               to accounts that reseller owns
 *  - root     : WHM as root, every account on the server
 *
 * For the two WHM types, per-account UAPI calls (Backup, Fileman, ...) go
 * through WHM API 1's `uapi_cpanel` proxy, which runs the UAPI function as
 * that cPanel user - cPanel's documented, recommended way for resellers and
 * root to call UAPI on an account without knowing its password.
 *
 * Auth: API tokens (`Authorization: whm user:TOKEN` / `cpanel user:TOKEN`)
 * are preferred - they bypass 2FA and can be revoked right after the move.
 * Plain passwords use HTTP Basic auth.
 */
final class CpanelApiClient
{
    public const TYPES = ['cpanel', 'reseller', 'root'];

    public function __construct(
        private string $type,
        private string $host,
        private int $port,
        private string $user,
        private string $authType,
        private string $secret,
        private bool $verifyTls = true,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown source type.');
        }
    }

    public static function fromMigration(array $m, string $secret): self
    {
        return new self(
            (string) $m['source_type'],
            (string) $m['source_host'],
            (int) $m['source_port'],
            (string) $m['source_user'],
            (string) $m['auth_type'],
            $secret,
            (bool) $m['verify_tls'],
        );
    }

    public static function defaultPort(string $type): int
    {
        return $type === 'cpanel' ? 2083 : 2087;
    }

    public function isWhm(): bool
    {
        return $this->type !== 'cpanel';
    }

    /** Port cPanel (not WHM) itself listens on - where sessions and downloads live. */
    private function cpanelPort(): int
    {
        return $this->isWhm() ? 2083 : $this->port;
    }

    // ------------------------------------------------------------------
    // Account discovery
    // ------------------------------------------------------------------

    /**
     * Verifies the credentials and returns the accounts this login can
     * migrate, normalized to: user, domain, owner, plan, email, disk_used_mb,
     * suspended, is_reseller.
     *
     * @return array<int, array<string,mixed>>
     */
    public function discoverAccounts(): array
    {
        if (!$this->isWhm()) {
            $d = $this->uapi($this->user, 'DomainInfo', 'list_domains');
            return [[
                'user' => $this->user,
                'domain' => (string) ($d['main_domain'] ?? ''),
                'owner' => null,
                'plan' => null,
                'email' => null,
                'disk_used_mb' => null,
                'suspended' => false,
                'is_reseller' => false,
            ]];
        }

        $resellers = [];
        if ($this->type === 'root') {
            try {
                $resellers = $this->listResellers();
            } catch (Throwable $e) {
                throw new RuntimeException('These credentials work, but not with root privileges (listresellers was refused). Use "Reseller" as the source type instead. (' . $e->getMessage() . ')');
            }
        }

        $data = $this->whm('listaccts', ['want' => 'user,domain,owner,plan,email,diskused,suspended']);
        $out = [];
        foreach ((array) ($data['acct'] ?? []) as $a) {
            $user = (string) ($a['user'] ?? '');
            if ($user === '' || $user === 'root') {
                continue;
            }
            $out[] = [
                'user' => $user,
                'domain' => (string) ($a['domain'] ?? ''),
                'owner' => (string) ($a['owner'] ?? ''),
                'plan' => (string) ($a['plan'] ?? ''),
                'email' => (string) ($a['email'] ?? ''),
                'disk_used_mb' => self::parseMb((string) ($a['diskused'] ?? '')),
                'suspended' => !empty($a['suspended']),
                'is_reseller' => in_array($user, $resellers, true),
            ];
        }
        usort($out, fn($a, $b) => [$b['is_reseller'], $a['user']] <=> [$a['is_reseller'], $b['user']]);
        return $out;
    }

    /** @return string[] */
    public function listResellers(): array
    {
        $data = $this->whm('listresellers');
        return array_values(array_map('strval', (array) ($data['reseller'] ?? [])));
    }

    private static function parseMb(string $v): ?int
    {
        if (!preg_match('/^([\d.]+)\s*([KMGT]?)/i', trim($v), $m)) {
            return null;
        }
        $mult = ['' => 1, 'K' => 1 / 1024, 'M' => 1, 'G' => 1024, 'T' => 1048576][strtoupper($m[2])];
        return (int) round((float) $m[1] * $mult);
    }

    // ------------------------------------------------------------------
    // API primitives
    // ------------------------------------------------------------------

    /** WHM API 1 call. Returns the `data` member; throws on metadata.result = 0. */
    public function whm(string $function, array $params = [], int $timeout = 60): array
    {
        if (!$this->isWhm()) {
            throw new LogicException('WHM API is not available for a single cPanel account.');
        }
        $res = $this->request("/json-api/$function", ['api.version' => 1] + $params, $timeout);
        if ((int) ($res['metadata']['result'] ?? 0) !== 1) {
            throw new RuntimeException("WHM $function failed: " . ($res['metadata']['reason'] ?? 'unknown reason'));
        }
        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    /**
     * Runs a UAPI function as $cpUser - directly for a cPanel login, or via
     * WHM API 1 `uapi_cpanel` for reseller/root. Returns UAPI's `data`.
     */
    public function uapi(string $cpUser, string $module, string $function, array $params = [], int $timeout = 60): mixed
    {
        if ($this->isWhm()) {
            $res = $this->request('/json-api/uapi_cpanel', [
                'api.version' => 1,
                'cpanel.user' => $cpUser,
                'cpanel.module' => $module,
                'cpanel.function' => $function,
            ] + $params, $timeout);
            if ((int) ($res['metadata']['result'] ?? 0) !== 1) {
                throw new RuntimeException("uapi_cpanel $module::$function for $cpUser failed: " . ($res['metadata']['reason'] ?? 'unknown reason'));
            }
            $uapi = $res['data']['uapi'] ?? null;
        } else {
            if ($cpUser !== $this->user) {
                throw new LogicException('A cPanel login can only act on its own account.');
            }
            $uapi = $this->request("/execute/$module/$function", $params, $timeout);
        }

        if (!is_array($uapi) || (int) ($uapi['status'] ?? 0) !== 1) {
            $errors = is_array($uapi['errors'] ?? null) ? implode('; ', $uapi['errors']) : 'no response';
            throw new RuntimeException("UAPI $module::$function failed: $errors");
        }
        return $uapi['data'] ?? null;
    }

    private function authHeader(): string
    {
        if ($this->authType === 'token') {
            return 'Authorization: ' . ($this->isWhm() ? 'whm' : 'cpanel') . ' ' . $this->user . ':' . $this->secret;
        }
        return 'Authorization: Basic ' . base64_encode($this->user . ':' . $this->secret);
    }

    private function baseCurl(string $url, int $timeout): CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'JinnPanel-Migration/1.0',
        ]);
        return $ch;
    }

    private function request(string $path, array $query, int $timeout): array
    {
        $url = 'https://' . $this->host . ':' . $this->port . $path . '?' . http_build_query($query);
        $ch = $this->baseCurl($url, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [$this->authHeader(), 'Accept: application/json']);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            throw new RuntimeException(self::explainCurlError($errno, $err));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($status === 401 || $status === 403) {
            throw new RuntimeException("The source server rejected the credentials (HTTP $status). Check the username and API token/password" . ($this->isWhm() ? ', and that the token has the privileges this action needs.' : '.'));
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            $snippet = trim(substr(strip_tags((string) $raw), 0, 160));
            throw new RuntimeException("Unexpected non-JSON response from the source server (HTTP $status): $snippet");
        }
        return $json;
    }

    private static function explainCurlError(int $errno, string $err): string
    {
        return match ($errno) {
            CURLE_SSL_CACERT, CURLE_PEER_FAILED_VERIFICATION => "TLS certificate verification failed ($err). If the source uses a self-signed certificate, untick \"Verify TLS certificate\".",
            CURLE_COULDNT_RESOLVE_HOST => "Could not resolve the source hostname ($err).",
            CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT => "Could not connect to the source server ($err). Check the host, port, and that this server's IP isn't blocked by the source firewall.",
            default => "Request to source server failed: $err",
        };
    }

    // ------------------------------------------------------------------
    // Sessions + downloads (pull mode)
    // ------------------------------------------------------------------

    /**
     * Opens a cPanel web session as $cpUser so a file can be downloaded
     * from the account's home directory (API tokens alone can't download).
     *  - WHM (reseller/root): WHM API 1 create_user_session (Single Sign On)
     *  - cPanel + password:   /login/?login_only=1
     *
     * @return array{base:string, jar:string}
     */
    public function openCpanelSession(string $cpUser, string $workDir): array
    {
        $jar = $workDir . '/.cookies-' . bin2hex(random_bytes(4));
        touch($jar);
        chmod($jar, 0600);

        if ($this->isWhm()) {
            $data = $this->whm('create_user_session', [
                'user' => $cpUser,
                'service' => 'cpaneld',
                'preferred_domain' => $this->host,
            ]);
            $url = (string) ($data['url'] ?? '');
            $token = (string) ($data['cp_security_token'] ?? '');
            if ($url === '' || !preg_match('#^/cpsess\d+$#', $token)) {
                throw new RuntimeException('create_user_session did not return a usable session.');
            }
            $ch = $this->baseCurl($url, 60);
            curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5]);
            if (curl_exec($ch) === false) {
                throw new RuntimeException('Could not open the cPanel session: ' . curl_error($ch));
            }
            unset($ch); // flush the cookie jar to disk
            return ['base' => 'https://' . $this->host . ':' . $this->cpanelPort() . $token, 'jar' => $jar];
        }

        if ($this->authType !== 'password') {
            throw new RuntimeException('Pull mode needs the account password for a single cPanel account (cPanel API tokens cannot open a file-download session). Use push mode, or connect with the password.');
        }
        $ch = $this->baseCurl('https://' . $this->host . ':' . $this->port . '/login/?login_only=1', 60);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['user' => $this->user, 'pass' => $this->secret]),
            CURLOPT_COOKIEJAR => $jar,
            CURLOPT_COOKIEFILE => $jar,
        ]);
        $raw = curl_exec($ch);
        unset($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        $token = (string) ($json['security_token'] ?? '');
        if ((int) ($json['status'] ?? 0) !== 1 || !preg_match('#^/cpsess\d+$#', $token)) {
            throw new RuntimeException('cPanel login failed' . (isset($json['message']) ? ': ' . $json['message'] : '.') . ' (If the account uses two-factor authentication, connect with an API token and push mode instead.)');
        }
        return ['base' => 'https://' . $this->host . ':' . $this->port . $token, 'jar' => $jar];
    }

    /**
     * Streams a file from the account's home directory to $localFile.
     * $onProgress(int $bytesDone, int $bytesTotal) is called every few
     * seconds; returning false from it aborts the transfer.
     */
    public function download(array $session, string $remotePath, string $localFile, ?callable $onProgress = null): void
    {
        $fh = fopen($localFile, 'wb');
        if ($fh === false) {
            throw new RuntimeException("Cannot write $localFile");
        }
        $url = $session['base'] . '/download?skipencode=1&file=' . rawurlencode($remotePath);
        $ch = $this->baseCurl($url, 0);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE => $fh,
            CURLOPT_COOKIEFILE => $session['jar'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 0,
            // Abort only if the transfer genuinely stalls (<1 KB/s for 5 min).
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 300,
            CURLOPT_NOPROGRESS => $onProgress === null,
        ]);
        if ($onProgress !== null) {
            $last = 0;
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, function ($c, $dlTotal, $dlNow) use ($onProgress, &$last) {
                if (time() - $last >= 5) {
                    $last = time();
                    return $onProgress((int) $dlNow, (int) $dlTotal) === false ? 1 : 0;
                }
                return 0;
            });
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        unset($ch);
        fclose($fh);

        if ($ok === false) {
            throw new RuntimeException("Download failed: $err");
        }
        if ($status !== 200 || str_contains($type, 'text/html')) {
            throw new RuntimeException("Download of $remotePath was refused by the source (HTTP $status, $type).");
        }
    }
}
