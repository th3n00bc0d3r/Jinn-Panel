<?php
declare(strict_types=1);

/**
 * App-side half of the cPanel migration feature: records migrations and
 * their per-account items, and hands execution to the background runner
 * (app/worker/migration-runner.php) through the same config-queue the rest
 * of the panel uses - hostpanel-worker.php turns a `migration_start` job
 * into a detached systemd-run unit running as frankenphp:webusers.
 *
 * The web request never downloads, extracts, or restores anything itself:
 * a full account can take hours, and none of that belongs inside a
 * FrankenPHP request.
 */
final class MigrationService
{
    public const FINISHED = ['completed', 'completed_with_errors', 'failed', 'cancelled'];
    public const ACTIVE = ['queued', 'running'];

    public static function workDir(): string
    {
        return defined('Config::MIGRATION_DIR') ? (string) constant('Config::MIGRATION_DIR') : '/var/lib/jinnpanel/migrations';
    }

    /**
     * Where `file` mode looks for backups already on this server (e.g. copied
     * from offsite storage): <user>.tar.gz, cpmove-<user>.tar.gz or
     * backup-<date>_<user>.tar.gz, readable by frankenphp.
     */
    public static function importDir(): string
    {
        return self::workDir() . '/import';
    }

    /** Backup archive for $user in importDir(), or null. Newest wins if there are several. */
    public static function backupFile(string $user): ?string
    {
        if (!preg_match(CpanelBackupReader::USERNAME_RE, $user)) {
            return null;
        }
        $dir = self::importDir();
        $found = [];
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === "$user.tar.gz" || $f === "cpmove-$user.tar.gz" || preg_match('/^backup-[0-9._-]+_' . preg_quote($user, '/') . '\.tar\.gz$/', $f)) {
                $path = "$dir/$f";
                if (is_file($path) && !is_link($path)) {
                    $found[$path] = (int) filemtime($path);
                }
            }
        }
        arsort($found);
        return array_key_first($found);
    }

    /** Accounts that have a backup in importDir(): username => archive path. */
    public static function availableBackupFiles(): array
    {
        $out = [];
        foreach (scandir(self::importDir()) ?: [] as $f) {
            if (preg_match('/^(?:cpmove-|backup-[0-9._-]+_)?([a-z][a-z0-9_]{2,31})\.tar\.gz$/', $f, $m) && !isset($out[$m[1]])) {
                $file = self::backupFile($m[1]);
                if ($file !== null) {
                    $out[$m[1]] = $file;
                }
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Queues a `file` mode migration of $usernames from importDir(). No
     * source server and no credentials: the runner restores straight from
     * the archives. Admin only (same reach as a WHM root source).
     *
     * @param string[] $usernames
     */
    public static function startFromFiles(array $me, array $usernames, array $options = []): int
    {
        if ($me['role'] !== 'admin') {
            throw new RuntimeException('Only an admin can import backup files.');
        }
        $usernames = array_values(array_unique($usernames));
        if (!$usernames) {
            throw new InvalidArgumentException('No accounts given.');
        }
        foreach ($usernames as $u) {
            if (self::backupFile($u) === null) {
                throw new InvalidArgumentException("No backup for \"$u\" in " . self::importDir() . '.');
            }
        }
        $pdo = Database::app();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO migrations (created_by, source_type, source_host, source_port, source_user, auth_type, secret_enc, verify_tls, transfer_mode, options, status)
                 VALUES (?, 'root', 'backup files', 0, 'import', 'password', NULL, 1, 'file', ?, 'draft')"
            )->execute([$me['id'], json_encode(self::defaultOptions())]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO migration_items (migration_id, source_username, selected, report) VALUES (?, ?, 0, ?)');
            foreach ($usernames as $u) {
                $ins->execute([$id, $u, json_encode(['source' => ['file' => basename((string) self::backupFile($u))]])]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::start($id, $options, $usernames);
        return $id;
    }

    /** Whether failed items can be retried: file imports need no stored credentials. */
    public static function canRetry(array $m): bool
    {
        return $m['transfer_mode'] === 'file' || !empty($m['secret_enc']);
    }

    public static function logFile(int $id): string
    {
        return __DIR__ . "/../../storage/logs/migration-{$id}.log";
    }

    public static function defaultOptions(): array
    {
        return [
            'files' => true,
            'databases' => true,
            'email_accounts' => true,
            'email_data' => true,
            'package_id' => null,
            'match_packages' => true,
            'owner' => 'none',
            'mail_passwords' => 'preserve',
            'catch_all' => 'reject',
            'ssl_mode' => 'auto',
            'public_host' => Config::SERVER_IP,
            'public_port' => defined('Config::SFTP_PUBLIC_PORT') ? (int) constant('Config::SFTP_PUBLIC_PORT') : 2022,
            'timeout_hours' => 12,
            'first_byte_minutes' => 120,
        ];
    }

    public static function options(array $m): array
    {
        $o = json_decode((string) ($m['options'] ?? ''), true);
        return array_merge(self::defaultOptions(), is_array($o) ? $o : []);
    }

    /** Admins see every migration; resellers only their own. */
    public static function find(int $id, array $me): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM migrations WHERE id = ?');
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        if (!$m || ($me['role'] !== 'admin' && (int) $m['created_by'] !== (int) $me['id'])) {
            return null;
        }
        return $m;
    }

    public static function listFor(array $me): array
    {
        $sql = 'SELECT m.*, u.username AS creator,
                       (SELECT COUNT(*) FROM migration_items i WHERE i.migration_id = m.id AND i.selected = 1) AS item_count,
                       (SELECT COUNT(*) FROM migration_items i WHERE i.migration_id = m.id AND i.status IN (\'completed\',\'completed_with_errors\')) AS done_count
                FROM migrations m JOIN users u ON u.id = m.created_by';
        if ($me['role'] === 'admin') {
            return Database::app()->query("$sql ORDER BY m.id DESC")->fetchAll();
        }
        $stmt = Database::app()->prepare("$sql WHERE m.created_by = ? ORDER BY m.id DESC");
        $stmt->execute([$me['id']]);
        return $stmt->fetchAll();
    }

    public static function items(int $migrationId, bool $selectedOnly = false): array
    {
        $stmt = Database::app()->prepare('SELECT * FROM migration_items WHERE migration_id = ?' . ($selectedOnly ? ' AND selected = 1' : '') . ' ORDER BY is_reseller DESC, source_username');
        $stmt->execute([$migrationId]);
        return $stmt->fetchAll();
    }

    /**
     * Stores a connected-but-not-started migration with the accounts the
     * source offered, so the next screen can show them for selection.
     *
     * @param array{type:string,host:string,port:int,user:string,auth_type:string,verify_tls:bool,transfer_mode:string} $src
     */
    public static function createDraft(array $me, array $src, string $secret, array $accounts): int
    {
        $pdo = Database::app();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO migrations (created_by, source_type, source_host, source_port, source_user, auth_type, secret_enc, verify_tls, transfer_mode, options, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\')'
            );
            $stmt->execute([
                $me['id'], $src['type'], $src['host'], $src['port'], $src['user'], $src['auth_type'],
                Crypto::encrypt($secret), $src['verify_tls'] ? 1 : 0, $src['transfer_mode'],
                json_encode(self::defaultOptions()),
            ]);
            $id = (int) $pdo->lastInsertId();

            $ins = $pdo->prepare(
                'INSERT INTO migration_items (migration_id, source_username, source_domain, source_owner, source_plan, is_reseller, selected, report)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?)'
            );
            foreach ($accounts as $a) {
                $ins->execute([
                    $id, $a['user'], $a['domain'] ?: null, $a['owner'] ?: null, $a['plan'] ?: null, $a['is_reseller'] ? 1 : 0,
                    json_encode(['source' => ['email' => $a['email'] ?? null, 'disk_used_mb' => $a['disk_used_mb'] ?? null, 'suspended' => $a['suspended'] ?? false]]),
                ]);
            }
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Username / domain collisions with what's already on this server, per
     * item - shown on the selection screen so problems surface before the
     * (long) backup step rather than after it.
     *
     * @return array<int,string[]> itemId => problems
     */
    public static function conflicts(array $items): array
    {
        $pdo = Database::app();
        $userChk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $domChk = $pdo->prepare('SELECT COUNT(*) FROM domains WHERE domain_name = ?');
        $out = [];
        foreach ($items as $it) {
            $p = [];
            if (!preg_match(CpanelBackupReader::USERNAME_RE, $it['source_username'])) {
                $p[] = 'username not valid in JinnPanel';
            } else {
                $userChk->execute([$it['source_username']]);
                if ((int) $userChk->fetchColumn() > 0) {
                    $p[] = 'username already exists here';
                }
            }
            if ($it['source_domain']) {
                $domChk->execute([$it['source_domain']]);
                if ((int) $domChk->fetchColumn() > 0) {
                    $p[] = 'main domain already hosted here';
                }
            }
            if ($p) {
                $out[(int) $it['id']] = $p;
            }
        }
        return $out;
    }

    /** @param string[] $usernames */
    public static function start(int $id, array $options, array $usernames): void
    {
        $pdo = Database::app();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE migration_items SET selected = 0 WHERE migration_id = ?')->execute([$id]);
            $sel = $pdo->prepare("UPDATE migration_items SET selected = 1, status = 'pending' WHERE migration_id = ? AND source_username = ?");
            foreach ($usernames as $u) {
                $sel->execute([$id, $u]);
            }
            $pdo->prepare('DELETE FROM migration_items WHERE migration_id = ? AND selected = 0')->execute([$id]);
            $pdo->prepare("UPDATE migrations SET options = ?, status = 'queued', cancel_requested = 0 WHERE id = ?")
                ->execute([json_encode(array_merge(self::defaultOptions(), $options)), $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::enqueueRunner($id);
    }

    public static function enqueueRunner(int $id): void
    {
        SystemWorkerService::enqueue("migration-{$id}", ['type' => 'migration_start', 'migration_id' => $id]);
    }

    public static function isStalled(array $m): bool
    {
        if ($m['status'] !== 'running') {
            return false;
        }
        $beat = strtotime((string) ($m['heartbeat_at'] ?? $m['started_at'] ?? ''));
        return !$beat || time() - $beat > 600;
    }

    public static function requestCancel(array $m): void
    {
        $pdo = Database::app();
        if ($m['status'] === 'queued' || $m['status'] === 'draft' || self::isStalled($m)) {
            // Nothing is (still) running to notice a flag - settle it directly.
            $pdo->prepare("UPDATE migration_items SET status = 'cancelled', step = 'Cancelled' WHERE migration_id = ? AND status NOT IN ('completed','completed_with_errors','failed')")
                ->execute([$m['id']]);
            $pdo->prepare("UPDATE migration_items SET mail_restore = 0, step = 'Mail restore cancelled' WHERE migration_id = ? AND mail_restore = 1")->execute([$m['id']]);
            $pdo->prepare("UPDATE migrations SET status = 'cancelled', cancel_requested = 1, finished_at = NOW() WHERE id = ?")->execute([$m['id']]);
            return;
        }
        $pdo->prepare('UPDATE migrations SET cancel_requested = 1 WHERE id = ?')->execute([$m['id']]);
    }

    /** Re-queues failed/cancelled items. The runner rolls back whatever a previous attempt half-created first. */
    public static function retry(array $m): int
    {
        if (!self::canRetry($m)) {
            throw new RuntimeException('The source credentials for this migration were discarded. Start a new migration instead.');
        }
        $pdo = Database::app();
        $stmt = $pdo->prepare("UPDATE migration_items SET status = 'pending', step = 'Waiting to retry', progress = 0, error = NULL
                               WHERE migration_id = ? AND status IN ('failed','cancelled')");
        $stmt->execute([$m['id']]);
        $count = $stmt->rowCount();
        if ($count > 0) {
            $pdo->prepare("UPDATE migrations SET status = 'queued', cancel_requested = 0, finished_at = NULL WHERE id = ?")->execute([$m['id']]);
            self::enqueueRunner((int) $m['id']);
        }
        return $count;
    }

    /**
     * Queues "restore mail" for finished items (all of them, or just
     * $itemId): the runner fetches each account's backup again and creates
     * the mailboxes - with their stored mail - that aren't on this server
     * yet. Nothing else about the account is touched.
     */
    public static function restoreMail(array $m, ?int $itemId = null): int
    {
        if (!in_array($m['status'], self::FINISHED, true)) {
            throw new RuntimeException('Wait for the migration to finish (or cancel it) first.');
        }
        if (!self::canRetry($m)) {
            throw new RuntimeException('The source credentials for this migration were discarded, so its backups can\'t be fetched again. Start a new migration instead.');
        }
        $pdo = Database::app();
        $sql = "UPDATE migration_items SET mail_restore = 1, step = 'Waiting to restore mail', progress = 0
                WHERE migration_id = ? AND selected = 1 AND status IN ('completed','completed_with_errors') AND target_user_id IS NOT NULL";
        $args = [$m['id']];
        if ($itemId !== null) {
            $sql .= ' AND id = ?';
            $args[] = $itemId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $count = $stmt->rowCount();
        if ($count > 0) {
            $pdo->prepare("UPDATE migrations SET status = 'queued', cancel_requested = 0, finished_at = NULL WHERE id = ?")->execute([$m['id']]);
            self::enqueueRunner((int) $m['id']);
        }
        return $count;
    }

    public static function discardSecret(int $id): void
    {
        Database::app()->prepare('UPDATE migrations SET secret_enc = NULL WHERE id = ?')->execute([$id]);
    }

    /** Source credentials never outlive a finished migration by more than a week. */
    public static function purgeStaleSecrets(): void
    {
        Database::app()->exec("UPDATE migrations SET secret_enc = NULL
                               WHERE secret_enc IS NOT NULL
                                 AND ((status IN ('completed','completed_with_errors','failed','cancelled') AND finished_at < NOW() - INTERVAL 7 DAY)
                                      OR (status = 'draft' AND created_at < NOW() - INTERVAL 1 DAY))");
    }

    public static function delete(array $m): void
    {
        if (in_array($m['status'], self::ACTIVE, true) && !self::isStalled($m)) {
            throw new RuntimeException('Cancel the migration before deleting it.');
        }
        Database::app()->prepare('DELETE FROM migrations WHERE id = ?')->execute([$m['id']]);
        @unlink(self::logFile((int) $m['id']));
    }

    public static function tailLog(int $id, int $lines = 80): string
    {
        $file = self::logFile($id);
        if (!is_file($file)) {
            return '';
        }
        $fh = fopen($file, 'rb');
        $size = filesize($file);
        $chunk = min($size, 64 * 1024);
        fseek($fh, -$chunk, SEEK_END);
        $data = (string) fread($fh, $chunk);
        fclose($fh);
        $all = explode("\n", rtrim($data));
        return implode("\n", array_slice($all, -$lines));
    }
}
