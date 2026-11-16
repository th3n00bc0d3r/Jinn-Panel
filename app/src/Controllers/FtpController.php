<?php
declare(strict_types=1);

final class FtpController
{
    public static function index(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $pdo = Database::app();

        $stmt = $pdo->prepare('SELECT * FROM ftp_accounts WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$me['id']]);

        $domainsStmt = $pdo->prepare('SELECT * FROM domains WHERE user_id = ? ORDER BY domain_name');
        $domainsStmt->execute([$me['id']]);

        View::render('cpanel/ftp', [
            'title' => 'FTP / SFTP Accounts',
            'accounts' => $stmt->fetchAll(),
            'domains' => $domainsStmt->fetchAll(),
            'usage' => Quota::usage($me['id']),
            'pkg' => Quota::package($me['id']),
        ], 'cpanel');
    }

    public static function store(): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();

        if (!Quota::withinLimit($me['id'], 'ftp_accounts')) {
            Flash::error('You have reached your package\'s FTP account limit.');
            header('Location: /cpanel/ftp');
            exit;
        }

        $label = strtolower(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $domainId = (int) ($_POST['domain_id'] ?? 0) ?: null;

        if (!preg_match('/^[a-z][a-z0-9_]{2,30}$/', $label)) {
            Flash::error('FTP username must be 3-31 characters: letters, numbers, underscore.');
            header('Location: /cpanel/ftp');
            exit;
        }
        if (($problem = Passwords::problem($password, $label)) !== null) {
            Flash::error('FTP: ' . $problem);
            header('Location: /cpanel/ftp');
            exit;
        }

        $pdo = Database::app();
        // No domain: an account-wide login, which sees every site as /<domain>.
        $homeDir = SftpService::ACCOUNT_ROOT . '/' . $me['username'];
        if ($domainId) {
            $dStmt = $pdo->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
            $dStmt->execute([$domainId, $me['id']]);
            $domain = $dStmt->fetch();
            if (!$domain) {
                Flash::error('Select one of your domains, or leave it blank for your account root.');
                header('Location: /cpanel/ftp');
                exit;
            }
            $homeDir = dirname($domain['docroot']); // the domain folder, one level above /public
        }

        $ftpUsername = substr($me['username'] . '_' . $label, 0, 31);

        try {
            SftpService::createUser($ftpUsername, $password, $homeDir, Quota::package($me['id'])['disk_quota_mb'] ?? 0, (string) $me['username']);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error('Could not create the SFTP account: ' . $e->getMessage());
            header('Location: /cpanel/ftp');
            exit;
        }

        $stmt = $pdo->prepare('INSERT INTO ftp_accounts (user_id, domain_id, username, home_dir) VALUES (?, ?, ?, ?)');
        $stmt->execute([$me['id'], $domainId, $ftpUsername, $homeDir]);
        if (!$domainId) {
            try {
                SftpService::syncAccountFolders((int) $me['id']);
            } catch (Throwable $e) {
                error_log('sftp folders: ' . $e->getMessage());
            }
        }

        Flash::ok("SFTP account \"$ftpUsername\" created (port 2022).");
        header('Location: /cpanel/ftp');
        exit;
    }

    /**
     * SSH keys for an SFTP login (key sign-in instead of, or as well as, the
     * password). One OpenSSH public key per line; an empty box removes them.
     */
    public static function keys(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $stmt = Database::app()->prepare('SELECT * FROM ftp_accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) $params['id'], $me['id']]);
        $row = $stmt->fetch();
        if (!$row) {
            Flash::error('SFTP account not found.');
            header('Location: /cpanel/ftp');
            exit;
        }
        $keys = [];
        foreach (preg_split('/\R/', (string) ($_POST['public_keys'] ?? '')) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!self::validKey($line)) {
                Flash::error('Line ' . ($i + 1) . ' is not an OpenSSH public key (it should start with ssh-ed25519, ssh-rsa or ecdsa-sha2-…). Paste the contents of the .pub file, not the private key.');
                header('Location: /cpanel/ftp');
                exit;
            }
            $keys[] = $line;
        }
        if ($keys && !Features::has('ssh_keys')) {
            Flash::error('Your package doesn\'t include SSH keys for SFTP. Ask your hosting provider if you need them.');
            header('Location: /cpanel/ftp');
            exit;
        }
        if (count($keys) > 10) {
            Flash::error('At most 10 keys per login.');
            header('Location: /cpanel/ftp');
            exit;
        }
        try {
            SftpService::updateUser((string) $row['username'], ['public_keys' => $keys]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error('The SFTP server didn\'t accept the keys - try again in a moment.');
            header('Location: /cpanel/ftp');
            exit;
        }
        Database::app()->prepare('UPDATE ftp_accounts SET public_keys = ? WHERE id = ?')->execute([$keys ? implode("\n", $keys) : null, $row['id']]);
        Flash::ok($keys ? count($keys) . " SSH key(s) saved for {$row['username']}." : "SSH keys removed from {$row['username']}.");
        header('Location: /cpanel/ftp');
        exit;
    }

    /** An OpenSSH public key line whose base64 blob names the same key type. */
    public static function validKey(string $line): bool
    {
        if (!preg_match('#^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com) ([A-Za-z0-9+/]{40,}={0,3})(?: [^\r\n]{0,200})?$#', $line, $m)) {
            return false;
        }
        $blob = base64_decode($m[2], true);
        if ($blob === false || strlen($blob) < 4) {
            return false;
        }
        $len = unpack('N', substr($blob, 0, 4))[1];
        return substr($blob, 4, $len) === $m[1];
    }

    public static function destroy(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $id = (int) $params['id'];

        $stmt = Database::app()->prepare('SELECT * FROM ftp_accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $me['id']]);
        $row = $stmt->fetch();
        if (!$row) {
            Flash::error('FTP account not found.');
            header('Location: /cpanel/ftp');
            exit;
        }

        try { SftpService::deleteUser($row['username']); } catch (Throwable $e) { error_log($e->getMessage()); }

        $del = Database::app()->prepare('DELETE FROM ftp_accounts WHERE id = ?');
        $del->execute([$id]);

        Flash::ok('FTP account deleted.');
        header('Location: /cpanel/ftp');
        exit;
    }
}
