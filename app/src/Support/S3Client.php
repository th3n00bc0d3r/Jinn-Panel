<?php
declare(strict_types=1);

/**
 * Minimal S3 client (AWS Signature V4): list a prefix, download an object
 * to a file. Works with AWS (virtual-hosted URLs) and S3-compatible stores
 * - MinIO, Wasabi, Cloudflare R2, Backblaze B2 - (path-style URLs).
 */
final class S3Client
{
    public function __construct(
        private string $endpoint,   // https://s3.eu-west-1.amazonaws.com, https://<account>.r2.cloudflarestorage.com, ...
        private string $region,
        private string $bucket,
        private string $accessKey,
        private string $secretKey,
    ) {
        $this->endpoint = rtrim($endpoint, '/');
    }

    /** @return list<array{key:string,size:int}> every object under $prefix */
    public function listObjects(string $prefix): array
    {
        $out = [];
        $token = null;
        do {
            $q = ['list-type' => '2', 'prefix' => $prefix, 'max-keys' => '1000'];
            if ($token !== null) {
                $q['continuation-token'] = $token;
            }
            $res = $this->request('GET', '', $q);
            if ($res['status'] !== 200) {
                throw new RuntimeException('Listing the bucket failed: ' . self::error($res));
            }
            $xml = @simplexml_load_string($res['body']);
            if ($xml === false) {
                throw new RuntimeException('The storage returned an unreadable bucket listing.');
            }
            foreach ($xml->Contents ?? [] as $c) {
                $out[] = ['key' => (string) $c->Key, 'size' => (int) $c->Size];
            }
            $token = ((string) ($xml->IsTruncated ?? 'false')) === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');
        return $out;
    }

    /** Streams an object to $dest (written as $dest.part, renamed when complete). */
    public function download(string $key, string $dest, ?callable $onProgress = null): void
    {
        $part = $dest . '.part';
        $fh = fopen($part, 'wb');
        if ($fh === false) {
            throw new RuntimeException("Can't write $part.");
        }
        try {
            $res = $this->request('GET', $key, [], $fh, $onProgress);
        } finally {
            fclose($fh);
        }
        if ($res['status'] !== 200) {
            $err = (string) @file_get_contents($part, false, null, 0, 2000);
            @unlink($part);
            throw new RuntimeException("Downloading $key failed: " . self::error(['status' => $res['status'], 'body' => $err]));
        }
        rename($part, $dest);
    }

    /**
     * @param array<string,string> $query
     * @param resource|null $sink write the body here instead of returning it
     * @return array{status:int, body:string}
     */
    private function request(string $method, string $key, array $query = [], $sink = null, ?callable $onProgress = null): array
    {
        $parts = parse_url($this->endpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $virtual = str_ends_with($host, 'amazonaws.com');
        $path = '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        if ($virtual) {
            $host = $this->bucket . '.' . $host;
        } else {
            $path = '/' . rawurlencode($this->bucket) . ($key === '' ? '' : $path);
        }
        if ($key === '' && $virtual) {
            $path = '/';
        }

        ksort($query);
        $qs = implode('&', array_map(fn($k, $v) => rawurlencode((string) $k) . '=' . rawurlencode((string) $v), array_keys($query), $query));
        $now = gmdate('Ymd\THis\Z');
        $auth = self::authorization($method, $host . $port, $path, $qs, $now, $this->region, $this->accessKey, $this->secretKey);

        $ch = curl_init("$scheme://$host$port$path" . ($qs !== '' ? "?$qs" : ''));
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'x-amz-content-sha256: UNSIGNED-PAYLOAD', 'x-amz-date: ' . $now],
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FAILONERROR => false,
        ];
        if ($sink !== null) {
            $opts[CURLOPT_FILE] = $sink;
            $opts[CURLOPT_LOW_SPEED_LIMIT] = 1024;
            $opts[CURLOPT_LOW_SPEED_TIME] = 120;
            if ($onProgress !== null) {
                $opts[CURLOPT_NOPROGRESS] = false;
                $opts[CURLOPT_XFERINFOFUNCTION] = function ($ch, $total, $done) use ($onProgress) {
                    $onProgress((int) $done, (int) $total);
                    return 0;
                };
            }
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
            $opts[CURLOPT_TIMEOUT] = 120;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException('Storage request failed: ' . curl_error($ch));
        }
        return ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($body) ? $body : ''];
    }

    /** The SigV4 Authorization header for an UNSIGNED-PAYLOAD request (public for the tests). */
    public static function authorization(string $method, string $host, string $path, string $qs, string $now, string $region, string $accessKey, string $secretKey): string
    {
        $day = substr($now, 0, 8);
        $headers = ['host' => $host, 'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD', 'x-amz-date' => $now];
        ksort($headers);
        $canonical = implode("\n", [
            $method, $path, $qs,
            implode('', array_map(fn($k, $v) => "$k:$v\n", array_keys($headers), $headers)),
            implode(';', array_keys($headers)),
            'UNSIGNED-PAYLOAD',
        ]);
        $scope = "$day/$region/s3/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonical);
        $k = hash_hmac('sha256', $day, 'AWS4' . $secretKey, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', 's3', $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        return "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, SignedHeaders=" . implode(';', array_keys($headers))
            . ', Signature=' . hash_hmac('sha256', $toSign, $k);
    }

    private static function error(array $res): string
    {
        if (preg_match('#<Code>([^<]+)</Code>(?:.*?<Message>([^<]*)</Message>)?#s', (string) $res['body'], $m)) {
            return "HTTP {$res['status']} {$m[1]}" . (!empty($m[2]) ? " - {$m[2]}" : '');
        }
        return "HTTP {$res['status']}";
    }
}
