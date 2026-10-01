<?php
declare(strict_types=1);

/**
 * Brings every SFTP login in line with its hosting account: files it
 * writes belong to the account's Linux user (uid/gid), and account-wide
 * logins see each of the account's sites as /<domain>. Run as frankenphp by
 * install.sh after the accounts were synced; idempotent.
 */

require __DIR__ . '/../src/cli_bootstrap.php';

$pdo = Database::app();
$rows = $pdo->query('SELECT f.username, f.user_id, u.username AS owner FROM ftp_accounts f JOIN users u ON u.id = f.user_id ORDER BY f.id')->fetchAll();
$failed = 0;
foreach ($rows as $r) {
    try {
        [$uid, $gid] = SftpService::ids((string) $r['owner']);
        SftpService::updateUser((string) $r['username'], ['uid' => $uid, 'gid' => $gid, 'permissions' => ['/' => SftpService::PERMISSIONS]]);
        echo "ok      {$r['username']} -> " . Usernames::linuxUser((string) $r['owner']) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAILED  {$r['username']}: {$e->getMessage()}\n";
    }
}
foreach (array_unique(array_column($rows, 'user_id')) as $userId) {
    try {
        SftpService::syncAccountFolders((int) $userId);
    } catch (Throwable $e) {
        $failed++;
        echo "FAILED  folders of account #$userId: {$e->getMessage()}\n";
    }
}
exit($failed > 0 ? 1 : 0);
