<?php
declare(strict_types=1);

/**
 * Talks to SFTPGo's REST API to provision virtual SFTP accounts - no local
 * Linux users needed, per the "virtual users via REST API" design.
 */
final class SftpService
{
    private static ?string $token = null;

    private static function token(): string
    {
        if (self::$token !== null) {
            return self::$token;
        }
        $res = Http::json('GET', Config::SFTP_API_BASE . '/api/v2/token', null, [], Config::SFTP_ADMIN_USER . ':' . Config::SFTP_ADMIN_PASS);
        if ($res['status'] !== 200 || empty($res['body']['access_token'])) {
            throw new RuntimeException('Could not authenticate to SFTPGo API.');
        }
        return self::$token = $res['body']['access_token'];
    }

    private static function authHeader(): array
    {
        return ['Authorization' => 'Bearer ' . self::token()];
    }

    public static function createUser(string $username, string $password, string $homeDir, int $quotaMb): void
    {
        if (!is_dir($homeDir)) {
            mkdir($homeDir, 02775, true);
        }

        $res = Http::json('POST', Config::SFTP_API_BASE . '/api/v2/users', [
            'username' => $username,
            'password' => $password,
            'status' => 1,
            'home_dir' => $homeDir,
            'quota_size' => $quotaMb > 0 ? $quotaMb * 1024 * 1024 : 0,
            'permissions' => ['/' => ['*']],
            'filesystem' => ['provider' => 0],
        ], self::authHeader());

        if ($res['status'] >= 300) {
            throw new RuntimeException('SFTPGo user creation failed: ' . $res['raw']);
        }
    }

    public static function deleteUser(string $username): void
    {
        $res = Http::json('DELETE', Config::SFTP_API_BASE . '/api/v2/users/' . rawurlencode($username), null, self::authHeader());
        if ($res['status'] >= 300 && $res['status'] !== 404) {
            throw new RuntimeException('SFTPGo user deletion failed: ' . $res['raw']);
        }
    }
}
