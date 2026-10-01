<?php
declare(strict_types=1);

/**
 * Suspending a hosting account switches off everything it runs, not just
 * its panel login: its sites answer 503 (and its PHP-FPM pools stop), its
 * mailboxes can't log in (mail is still received and kept), its SFTP logins
 * and MySQL users are locked, its object cache login is disabled, and its
 * cron jobs don't run. Unsuspending turns each back on. Best-effort per
 * part; what failed is returned so WHM can say so.
 */
final class SuspensionService
{
    /** @return list<string> problems (empty = all done) */
    public static function apply(int $userId, bool $suspend): array
    {
        $pdo = Database::app();
        $problems = [];
        $u = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $u->execute([$userId]);
        $user = $u->fetch();
        if (!$user) {
            return ['account not found'];
        }
        $try = function (string $what, callable $fn) use (&$problems): void {
            try {
                $fn();
            } catch (Throwable $e) {
                $problems[] = "$what: " . $e->getMessage();
                error_log("suspension ($what): " . $e->getMessage());
            }
        };

        Auth::invalidateSessions($userId);

        // Sites: re-rendered as 503 pages (or back), then pools via the worker.
        $d = $pdo->prepare('SELECT * FROM domains WHERE user_id = ?');
        $d->execute([$userId]);
        $domains = $d->fetchAll();
        foreach ($domains as $row) {
            $try("site {$row['domain_name']}", fn() => VhostService::create((string) $row['domain_name'], (string) $row['php_version'], (string) $row['ssl_mode'], false, false));
        }
        if ($domains) {
            VhostService::reload();
        }
        AccountRuntime::sync($userId);

        $m = $pdo->prepare("SELECT CONCAT(e.local_part, '@', d.domain_name) AS address, e.mail_account_id FROM email_accounts e JOIN domains d ON d.id = e.domain_id WHERE e.user_id = ? AND e.mail_account_id IS NOT NULL");
        $m->execute([$userId]);
        foreach ($m->fetchAll() as $row) {
            $try("mailbox {$row['address']}", fn() => MailService::setLoginAllowed((string) $row['mail_account_id'], !$suspend));
        }

        $f = $pdo->prepare('SELECT username FROM ftp_accounts WHERE user_id = ?');
        $f->execute([$userId]);
        foreach ($f->fetchAll(PDO::FETCH_COLUMN) as $login) {
            $try("SFTP $login", fn() => SftpService::setEnabled((string) $login, !$suspend));
        }

        $try('MySQL users', fn() => self::lockMysql($userId, $suspend));

        if (!empty($user['cache_secret_enc'])) {
            $try('object cache', function () use ($user, $suspend): void {
                $v = Valkey::admin();
                $v->cmd('ACL', 'SETUSER', CacheService::valkeyUser((string) $user['username']), $suspend ? 'off' : 'on');
                $v->cmd('ACL', 'SAVE');
            });
        }
        return $problems;
    }

    /** ACCOUNT LOCK / UNLOCK on every MySQL login (any host) of the account's MySQL users. */
    private static function lockMysql(int $userId, bool $lock): void
    {
        $pdo = Database::app();
        $s = $pdo->prepare('SELECT db_user FROM db_user_accounts WHERE user_id = ? UNION SELECT db_user FROM db_instances WHERE user_id = ? AND db_user IS NOT NULL');
        $s->execute([$userId, $userId]);
        $names = array_values(array_filter($s->fetchAll(PDO::FETCH_COLUMN), fn($n) => ProvisioningService::isValidIdentifier((string) $n)));
        if (!$names) {
            return;
        }
        $prov = Database::provisioning();
        $in = implode(',', array_fill(0, count($names), '?'));
        $hosts = $prov->prepare("SELECT User, Host FROM mysql.user WHERE User IN ($in)");
        $hosts->execute($names);
        foreach ($hosts->fetchAll(PDO::FETCH_NUM) as [$name, $host]) {
            $prov->exec("ALTER USER '$name'@" . $prov->quote((string) $host) . ($lock ? ' ACCOUNT LOCK' : ' ACCOUNT UNLOCK'));
            if ($lock) {
                // Connections already open keep working until they're closed.
                $ids = $prov->prepare('SELECT ID FROM information_schema.PROCESSLIST WHERE USER = ?');
                $ids->execute([$name]);
                foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    try {
                        $prov->exec('KILL CONNECTION ' . (int) $id);
                    } catch (Throwable) {
                    }
                }
            }
        }
    }
}
