<?php
declare(strict_types=1);

/**
 * Removes a hosting account and everything it owns on the live services
 * (vhosts, DNS zones, databases + MySQL users, mailboxes, SFTP users),
 * best-effort per resource so one failure doesn't block the rest, then the
 * DB rows (which cascade). Shared by WHM > Accounts > Delete and by the
 * migration runner's rollback of a partially-restored account.
 */
final class AccountCleanupService
{
    /**
     * @param string[] $removeDirs extra directories to delete from disk
     *   (only paths directly under VHOSTS_DOCROOT_BASE are honoured) - used
     *   by migration rollback for docroots it created itself. Normal account
     *   deletion leaves site files in place, as before.
     */
    public static function purge(int $userId, array $removeDirs = []): void
    {
        $pdo = Database::app();

        $domains = $pdo->prepare('SELECT * FROM domains WHERE user_id = ?');
        $domains->execute([$userId]);
        foreach ($domains->fetchAll() as $d) {
            try { VhostService::remove($d['domain_name'], $d['php_version']); } catch (Throwable $e) { error_log($e->getMessage()); }
            try { DnsService::removeZone($d['domain_name']); } catch (Throwable $e) { error_log($e->getMessage()); }
        }

        $droppedUsers = [];
        $dbs = $pdo->prepare('SELECT * FROM db_instances WHERE user_id = ?');
        $dbs->execute([$userId]);
        foreach ($dbs->fetchAll() as $row) {
            try { ProvisioningService::dropDatabase($row['db_name']); } catch (Throwable $e) { error_log($e->getMessage()); }
            if (!isset($droppedUsers[$row['db_user']])) {
                try { ProvisioningService::dropDbUser($row['db_user']); } catch (Throwable $e) { error_log($e->getMessage()); }
                $droppedUsers[$row['db_user']] = true;
            }
        }

        // Extra MySQL users (e.g. several users per database brought over by a migration).
        try {
            $extra = $pdo->prepare('SELECT db_user FROM db_user_accounts WHERE user_id = ?');
            $extra->execute([$userId]);
            foreach ($extra->fetchAll() as $row) {
                if (!isset($droppedUsers[$row['db_user']])) {
                    try { ProvisioningService::dropDbUser($row['db_user']); } catch (Throwable $e) { error_log($e->getMessage()); }
                    $droppedUsers[$row['db_user']] = true;
                }
            }
        } catch (PDOException $e) {
            error_log('db_user_accounts missing - run migrations/003_cpanel_migration.sql: ' . $e->getMessage());
        }

        $emails = $pdo->prepare('SELECT * FROM email_accounts WHERE user_id = ?');
        $emails->execute([$userId]);
        foreach ($emails->fetchAll() as $row) {
            if ($row['mail_account_id']) {
                try { MailService::deleteMailbox($row['mail_account_id']); } catch (Throwable $e) { error_log($e->getMessage()); }
            }
        }

        $ftps = $pdo->prepare('SELECT * FROM ftp_accounts WHERE user_id = ?');
        $ftps->execute([$userId]);
        foreach ($ftps->fetchAll() as $row) {
            try { SftpService::deleteUser($row['username']); } catch (Throwable $e) { error_log($e->getMessage()); }
        }

        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

        foreach ($removeDirs as $dir) {
            self::removeSiteDir($dir);
        }
    }

    /** rm -rf for a site directory, refusing anything that isn't exactly /var/www/<domain>. */
    public static function removeSiteDir(string $dir): void
    {
        $base = rtrim(Config::VHOSTS_DOCROOT_BASE, '/');
        $real = realpath($dir);
        if ($real === false || dirname($real) !== $base || !preg_match(CpanelBackupReader::DOMAIN_RE, basename($real)) || basename($real) === 'hostpanel') {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($real);
    }
}
