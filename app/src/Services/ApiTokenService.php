<?php
declare(strict_types=1);

/**
 * API tokens for the provisioning API (/json-api/*, ApiController).
 *
 * A token belongs to an administrator or reseller and acts with exactly that
 * account's rights. Tokens look like WHM's (32 uppercase letters and digits)
 * so WHM API clients take them unchanged. Only their SHA-256 is stored: the
 * token is shown once, when it's created. A token can be limited to a list of
 * client addresses.
 */
final class ApiTokenService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    public const MAX_PER_USER = 20;

    /** @return list<array<string,mixed>> the tokens $me may see (an admin sees all, with owner_username) */
    public static function list(array $me): array
    {
        $sql = 'SELECT t.id, t.user_id, t.name, t.token_hint, t.allowed_ips, t.last_used_at, t.last_used_ip, t.created_at, u.username AS owner_username
                FROM api_tokens t JOIN users u ON u.id = t.user_id';
        if ($me['role'] === 'admin') {
            return Database::app()->query("$sql ORDER BY t.created_at DESC")->fetchAll();
        }
        $s = Database::app()->prepare("$sql WHERE t.user_id = ? ORDER BY t.created_at DESC");
        $s->execute([$me['id']]);
        return $s->fetchAll();
    }

    /**
     * Creates a token for $me. Returns the token itself (shown once) or the problems.
     *
     * @return array{token: ?string, errors: list<string>}
     */
    public static function create(array $me, string $name, string $allowedIps): array
    {
        $name = trim($name);
        $errors = [];
        if ($name === '' || mb_strlen($name) > 64) {
            $errors[] = 'Give the token a name of up to 64 characters, e.g. the site or tool that uses it.';
        }
        $ips = self::parseIps($allowedIps, $errors);
        $n = Database::app()->prepare('SELECT COUNT(*) FROM api_tokens WHERE user_id = ?');
        $n->execute([$me['id']]);
        if ((int) $n->fetchColumn() >= self::MAX_PER_USER) {
            $errors[] = 'You already have ' . self::MAX_PER_USER . ' tokens - delete one you no longer use first.';
        }
        if ($errors) {
            return ['token' => null, 'errors' => $errors];
        }
        $token = '';
        for ($i = 0; $i < 32; $i++) {
            $token .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        Database::app()->prepare('INSERT INTO api_tokens (user_id, name, token_hash, token_hint, allowed_ips) VALUES (?, ?, ?, ?, ?)')
            ->execute([$me['id'], $name, hash('sha256', $token), substr($token, 0, 6), $ips ? implode(',', $ips) : null]);
        return ['token' => $token, 'errors' => []];
    }

    /** Deletes a token $me may manage; false if there's no such token. */
    public static function delete(array $me, int $id): bool
    {
        if ($me['role'] === 'admin') {
            $s = Database::app()->prepare('DELETE FROM api_tokens WHERE id = ?');
            $s->execute([$id]);
        } else {
            $s = Database::app()->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ?');
            $s->execute([$id, $me['id']]);
        }
        return $s->rowCount() > 0;
    }

    /**
     * The account a request's token acts as, or null. Accepts WHM's
     * `Authorization: whm <username>:<token>` (the username must be the
     * token's owner) and `Authorization: Bearer <token>`. The owner must be an
     * active admin or reseller, and the client address must be allowed.
     *
     * @return array{user: ?array, error: string}
     */
    public static function authenticate(string $header, string $ip): array
    {
        $header = trim($header);
        $claimed = null;
        if (preg_match('/^whm\s+([^:\s]+):([A-Za-z0-9]+)$/i', $header, $m)) {
            [$claimed, $token] = [strtolower($m[1]), $m[2]];
        } elseif (preg_match('/^Bearer\s+([A-Za-z0-9]+)$/i', $header, $m)) {
            $token = $m[1];
        } else {
            return ['user' => null, 'error' => 'Send the token as "Authorization: whm <username>:<token>" or "Authorization: Bearer <token>".'];
        }
        $s = Database::app()->prepare(
            'SELECT t.id AS token_id, t.allowed_ips, u.* FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? LIMIT 1'
        );
        $s->execute([hash('sha256', strtoupper($token))]);
        $row = $s->fetch();
        if (!$row || ($claimed !== null && $claimed !== strtolower((string) $row['username']))) {
            return ['user' => null, 'error' => 'Invalid API token.'];
        }
        if ($row['status'] !== 'active' || !in_array($row['role'], ['admin', 'reseller'], true)) {
            return ['user' => null, 'error' => 'The account this token belongs to is not an active administrator or reseller.'];
        }
        if ($row['allowed_ips'] !== null && $row['allowed_ips'] !== '' && !in_array($ip, explode(',', (string) $row['allowed_ips']), true)) {
            return ['user' => null, 'error' => "This token may not be used from $ip."];
        }
        Database::app()->prepare('UPDATE api_tokens SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?')
            ->execute([substr($ip, 0, 45), $row['token_id']]);
        unset($row['token_id'], $row['allowed_ips']);
        return ['user' => $row, 'error' => ''];
    }

    /** @param list<string> $errors @return list<string> */
    private static function parseIps(string $raw, array &$errors): array
    {
        $ips = [];
        foreach (preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $errors[] = "\"$ip\" is not an IP address.";
                continue;
            }
            $ips[] = inet_ntop(inet_pton($ip)); // canonical form, as REMOTE_ADDR has it
        }
        if (strlen(implode(',', $ips)) > 500) {
            $errors[] = 'That is too many addresses for one token.';
        }
        return array_values(array_unique($ips));
    }
}
