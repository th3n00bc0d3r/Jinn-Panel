<?php
declare(strict_types=1);

/**
 * Minimal cURL JSON client used by the Mail and SFTP service integrations.
 */
final class Http
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:mixed, raw:string}
     */
    public static function json(string $method, string $url, ?array $payload = null, array $headers = [], ?string $basicAuth = null): array
    {
        $ch = curl_init($url);
        $defaultHeaders = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $k => $v) {
            $defaultHeaders[] = "$k: $v";
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $defaultHeaders,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_ENCODING => '', // accept gzip/deflate and auto-decode
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
        if ($basicAuth !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            throw new RuntimeException("HTTP request failed: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $body = json_decode($raw, true);
        return ['status' => $status, 'body' => $body, 'raw' => $raw];
    }
}
