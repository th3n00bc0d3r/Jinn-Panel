<?php
declare(strict_types=1);

/**
 * Talks to SFTPGo's REST API to provision virtual SFTP accounts. They're
 * virtual (no login of their own on the server), but the files they write
 * belong to the hosting account's Linux user: SFTPGo hands each new file to
 * the uid/gid set on the SFTP user (it holds CAP_CHOWN for that, see
 * install.sh), so the account's PHP can always change what was uploaded.
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

    /** @param string|array{0:int,1:int} $owner the hosting account's username (its Linux user owns the files), or uid/gid */
    public static function createUser(string $username, string $password, string $homeDir, int $quotaMb, string|array $owner): void
    {
        [$uid, $gid] = is_array($owner) ? $owner : self::ids($owner);
        $res = Http::json('POST', Config::SFTP_API_BASE . '/api/v2/users', [
            'username' => $username,
            'password' => $password,
            'status' => 1,
            'home_dir' => $homeDir,
            'uid' => $uid,
            'gid' => $gid,
            // SFTPGo creates a missing home folder itself (as the account).
            'quota_size' => $quotaMb > 0 ? $quotaMb * 1024 * 1024 : 0,
            'permissions' => ['/' => self::PERMISSIONS],
            'filesystem' => ['provider' => 0],
        ], self::authHeader());

        if ($res['status'] >= 300) {
            throw new RuntimeException('SFTPGo user creation failed: ' . $res['raw']);
        }
    }

    /**
     * Everything but symlinks and chown: a link in a site folder could point
     * Caddy (which serves static files as frankenphp) at someone else's files.
     */
    public const PERMISSIONS = ['list', 'download', 'upload', 'overwrite', 'delete', 'rename', 'create_dirs', 'chmod', 'chtimes', 'copy'];

    /** uid/gid of the hosting account's Linux user. @return array{0:int,1:int} */
    public static function ids(string $owner): array
    {
        $pw = posix_getpwnam(Usernames::linuxUser($owner));
        if ($pw === false) {
            throw new RuntimeException('The account isn\'t set up on the server yet - try again in a few seconds.');
        }
        return [(int) $pw['uid'], (int) $pw['gid']];
    }

    /**
     * Changes fields of an existing SFTP user (SFTPGo replaces the whole
     * user on update, so this reads it first).
     *
     * @param array<string,mixed> $changes
     */
    public static function updateUser(string $username, array $changes): void
    {
        $get = Http::json('GET', Config::SFTP_API_BASE . '/api/v2/users/' . rawurlencode($username), null, self::authHeader());
        if ($get['status'] === 404) {
            return;
        }
        // Decoded as objects: SFTPGo's empty {} values must go back as {}, not [].
        $user = json_decode((string) $get['raw']);
        if ($get['status'] >= 300 || !$user instanceof stdClass) {
            throw new RuntimeException('SFTPGo user lookup failed: HTTP ' . $get['status']);
        }
        foreach ($changes as $k => $v) {
            $user->$k = $v;
        }
        unset($user->password); // keep the current one
        $res = Http::json('PUT', Config::SFTP_API_BASE . '/api/v2/users/' . rawurlencode($username), $user, self::authHeader());
        if ($res['status'] >= 300) {
            throw new RuntimeException('SFTPGo user update failed: HTTP ' . $res['status']);
        }
    }

    /** Where account-wide SFTP logins start: an empty folder holding one virtual folder per site. */
    public const ACCOUNT_ROOT = '/var/lib/jinnpanel/sftp';

    /**
     * An account-wide login (no single domain) sees /<domain> for each of
     * the account's sites, as SFTPGo virtual folders. Call after domains
     * are added or removed: every such login of the account is updated.
     */
    public static function syncAccountFolders(int $userId): void
    {
        $pdo = Database::app();
        $u = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $u->execute([$userId]);
        $owner = (string) $u->fetchColumn();
        $logins = $pdo->prepare('SELECT username FROM ftp_accounts WHERE user_id = ? AND home_dir = ?');
        $logins->execute([$userId, self::ACCOUNT_ROOT . '/' . $owner]);
        $names = $logins->fetchAll(PDO::FETCH_COLUMN);
        if (!$names) {
            return;
        }
        $folders = [];
        $d = $pdo->prepare('SELECT domain_name FROM domains WHERE user_id = ? ORDER BY domain_name');
        $d->execute([$userId]);
        foreach ($d->fetchAll(PDO::FETCH_COLUMN) as $domain) {
            $folder = 'site-' . $domain;
            $res = Http::json('GET', Config::SFTP_API_BASE . '/api/v2/folders/' . rawurlencode($folder), null, self::authHeader());
            if ($res['status'] === 404) {
                $res = Http::json('POST', Config::SFTP_API_BASE . '/api/v2/folders', ['name' => $folder, 'mapped_path' => VhostService::siteDir((string) $domain)], self::authHeader());
                if ($res['status'] >= 300) {
                    throw new RuntimeException("SFTPGo folder for $domain: HTTP " . $res['status']);
                }
            }
            $folders[] = ['name' => $folder, 'virtual_path' => '/' . $domain, 'quota_size' => -1, 'quota_files' => -1];
        }
        foreach ($names as $login) {
            self::updateUser((string) $login, ['virtual_folders' => $folders]);
        }
    }

    public static function setEnabled(string $username, bool $enabled): void
    {
        self::updateUser($username, ['status' => $enabled ? 1 : 0]);
    }

    public static function deleteUser(string $username): void
    {
        $res = Http::json('DELETE', Config::SFTP_API_BASE . '/api/v2/users/' . rawurlencode($username), null, self::authHeader());
        if ($res['status'] >= 300 && $res['status'] !== 404) {
            throw new RuntimeException('SFTPGo user deletion failed: ' . $res['raw']);
        }
    }
}
