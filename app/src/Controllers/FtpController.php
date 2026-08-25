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
        if (strlen($password) < 8) {
            Flash::error('FTP password must be at least 8 characters.');
            header('Location: /cpanel/ftp');
            exit;
        }

        $pdo = Database::app();
        $homeDir = Config::VHOSTS_DOCROOT_BASE . '/' . $me['username'];
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
            SftpService::createUser($ftpUsername, $password, $homeDir, Quota::package($me['id'])['disk_quota_mb'] ?? 0);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error('Could not create the SFTP account: ' . $e->getMessage());
            header('Location: /cpanel/ftp');
            exit;
        }

        $stmt = $pdo->prepare('INSERT INTO ftp_accounts (user_id, domain_id, username, home_dir) VALUES (?, ?, ?, ?)');
        $stmt->execute([$me['id'], $domainId, $ftpUsername, $homeDir]);

        Flash::ok("SFTP account \"$ftpUsername\" created (port 2022).");
        header('Location: /cpanel/ftp');
        exit;
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
