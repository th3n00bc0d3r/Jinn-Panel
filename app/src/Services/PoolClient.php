<?php
declare(strict_types=1);

/**
 * Runs file work for a domain inside its account's PHP-FPM pool - as the
 * account's own Linux user - through runtime/pool-agent.php (FastCGI over
 * the pool's socket). The panel itself can only read into site folders
 * (static files), so this is how File Manager, Exposed files, Routes and
 * cache clearing touch customer files.
 */
final class PoolClient
{
    public const AGENT = '/usr/local/lib/jinnpanel/pool/pool-agent.php';

    /**
     * @param array<string,mixed> $args
     * @param resource|string|null $body
     * @return array<string,mixed> the agent's JSON reply (ok == true)
     */
    public static function call(string $domain, string $op, array $args = [], $body = null): array
    {
        $res = self::send($domain, $op, $args, $body);
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('The site\'s PHP gave no usable answer' . ($res['stderr'] !== '' ? ': ' . mb_substr(trim($res['stderr']), 0, 200) : '.'));
        }
        if (empty($data['ok'])) {
            throw new RuntimeException((string) ($data['error'] ?? 'The operation failed.'));
        }
        return $data;
    }

    /**
     * Streams the agent's raw reply (downloads): $onHeaders gets its headers
     * before the first chunk goes to $onBody. A JSON reply is an error.
     *
     * @param callable(array<string,string>):void $onHeaders
     * @param callable(string):void $onBody
     */
    public static function stream(string $domain, string $op, array $args, callable $onHeaders, callable $onBody): void
    {
        $isError = false;
        self::send($domain, $op, $args, null, function (string $chunk) use (&$isError, $onBody): void {
            if (!$isError) {
                $onBody($chunk);
            }
        }, function (array $headers) use (&$isError, $onHeaders): void {
            $isError = str_starts_with((string) ($headers['content-type'] ?? ''), 'application/json');
            if (!$isError) {
                $onHeaders($headers);
            }
        });
        if ($isError) {
            throw new RuntimeException('The download failed.');
        }
    }

    /** @return array{status:int, headers:array<string,string>, body:string, stderr:string} */
    private static function send(string $domain, string $op, array $args, $body, ?callable $onBody = null, ?callable $onHeaders = null): array
    {
        $s = Database::app()->prepare('SELECT d.php_version, u.username, u.id AS user_id FROM domains d JOIN users u ON u.id = d.user_id WHERE d.domain_name = ?');
        $s->execute([$domain]);
        $row = $s->fetch();
        if (!$row) {
            throw new RuntimeException('Domain not found.');
        }
        $socket = AccountRuntime::socket((string) $row['username'], (string) $row['php_version']);
        if (!file_exists($socket)) {
            // Not set up yet (a domain just added) - or the account is suspended.
            AccountRuntime::sync((int) $row['user_id']);
            if (!AccountRuntime::waitReady((string) $row['username'], (string) $row['php_version'], 15)) {
                throw new RuntimeException('The site\'s PHP isn\'t running (yet) - try again in a few seconds.');
            }
        }
        // The arguments go in the body (a list of file names can be long);
        // only an upload has a body of its own, and then its few arguments
        // travel as a parameter.
        $argsJson = json_encode($args, JSON_UNESCAPED_SLASHES);
        if ($body === null) {
            $body = $argsJson;
        }
        $length = is_string($body) ? strlen($body) : (is_resource($body) ? (int) (fstat($body)['size'] ?? 0) : 0);
        $params = [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_SOFTWARE' => 'JinnPanel',
            'SCRIPT_FILENAME' => self::AGENT,
            'SCRIPT_NAME' => '/pool-agent.php',
            // PUT: the body is raw upload data, not a form (post_max_size doesn't apply).
            'REQUEST_METHOD' => 'PUT',
            'CONTENT_TYPE' => 'application/octet-stream',
            'CONTENT_LENGTH' => (string) $length,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'JINNPANEL_AGENT' => '1',
            'JINNPANEL_OP' => $op,
            'JINNPANEL_ROOT' => VhostService::siteDir($domain),
            'JINNPANEL_ARGS' => $op === 'upload' ? $argsJson : '',
        ];
        return FastCgi::request($socket, $params, $body, $onBody, 900, $onHeaders);
    }
}
