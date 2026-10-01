<?php
declare(strict_types=1);

/**
 * Executes one migration, account by account. Runs only from
 * app/worker/migration-runner.php, launched by hostpanel-worker.php as a
 * transient systemd unit (`systemd-run --uid=frankenphp --gid=webusers`):
 * outside FrankenPHP's process tree (no request timeouts, no fork quirks),
 * and deliberately NOT as root, since everything it unpacks came from
 * another server. It needs no more than the web app itself already has.
 *
 * Per account:
 *  1. Ask the source cPanel for a full backup (cPanel's own pkgacct format)
 *       push: Backup::fullbackup_to_scp_with_password into a temporary,
 *             single-use SFTPGo user on this server (port 2022)
 *       pull: Backup::fullbackup_to_homedir, then download it over a cPanel
 *             session (create_user_session / login)
 *       file: no source server - use an archive already on this server in
 *             MigrationService::importDir() (e.g. from cPanel's scheduled
 *             backups, copied from offsite storage); it is left in place
 *  2. Verify (gzip -t) and extract as an unprivileged user
 *  3. Restore: account (same username + password hash) -> domains + vhosts
 *     + DNS -> site files -> databases (same names, users, password hashes)
 *     -> mailboxes (same password hashes) -> stored mail (Maildir -> JMAP)
 *  4. Report per resource; anything half-created by a failed attempt is
 *     rolled back before a retry.
 */
final class MigrationRunner
{
    private PDO $pdo;
    private array $m;
    private array $opt;
    private CpanelApiClient $api;
    private array $item = [];
    private array $report = [];
    private string $work = '';
    private int $lastBeat = 0;
    private ?array $creatorCache = null;
    /** Set while restoring only the mail of a finished item: its status must not change. */
    private bool $mailOnly = false;
    /** The restoring account's main domain (parked domains become its aliases). */
    private ?string $mainDomain = null;
    /** @var array<string,array> address => report entry from before a mail restore */
    private array $previousEmail = [];

    public function __construct(private int $migrationId)
    {
    }

    public function run(): int
    {
        $this->pdo = Database::app();
        $m = $this->loadMigration();
        if (!$m) {
            fwrite(STDERR, "Migration {$this->migrationId} not found\n");
            return 1;
        }
        if (!in_array($m['status'], MigrationService::ACTIVE, true)) {
            $this->log("Nothing to do - migration is {$m['status']}.");
            return 0;
        }
        $this->m = $m;
        $this->opt = MigrationService::options($m);
        $this->updateMigration(['status' => 'running', 'started_at' => $m['started_at'] ?: date('Y-m-d H:i:s')]);
        $this->log("=== Runner started (pid " . getmypid() . ") - {$m['source_type']} {$m['source_user']}@{$m['source_host']}:{$m['source_port']}, {$m['transfer_mode']} mode");

        // Anything a previous (crashed/killed) runner left mid-flight.
        $this->pdo->prepare("UPDATE migration_items SET status = 'failed', step = 'Interrupted', error = 'The migration runner stopped unexpectedly while working on this account. Use Retry.'
                             WHERE migration_id = ? AND status IN ('backing_up','transferring','restoring')")->execute([$this->migrationId]);

        try {
            $this->preflight();
            if ($m['transfer_mode'] !== 'file') {
                if (empty($m['secret_enc'])) {
                    throw new RuntimeException('Source credentials were discarded - start a new migration.');
                }
                $this->api = CpanelApiClient::fromMigration($m, Crypto::decrypt($m['secret_enc']));
            }

            $stmt = $this->pdo->prepare("SELECT * FROM migration_items WHERE migration_id = ? AND selected = 1 AND status = 'pending' ORDER BY is_reseller DESC, id");
            $stmt->execute([$this->migrationId]);
            foreach ($stmt->fetchAll() as $item) {
                if ($this->cancelRequested()) {
                    break;
                }
                $this->processItem($item);
            }

            $stmt = $this->pdo->prepare('SELECT * FROM migration_items WHERE migration_id = ? AND selected = 1 AND mail_restore = 1 ORDER BY id');
            $stmt->execute([$this->migrationId]);
            foreach ($stmt->fetchAll() as $item) {
                if ($this->cancelRequested()) {
                    break;
                }
                $this->restoreMailItem($item);
            }
        } catch (Throwable $e) {
            $this->log('FATAL: ' . $e->getMessage());
            $this->pdo->prepare("UPDATE migration_items SET status = 'failed', step = 'Failed', error = ? WHERE migration_id = ? AND status = 'pending' AND selected = 1")
                ->execute([$e->getMessage(), $this->migrationId]);
        }

        $this->finish();
        return 0;
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    private function preflight(): void
    {
        foreach (['curl', 'openssl', 'pdo_mysql', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new RuntimeException("PHP CLI extension \"$ext\" is missing on this server.");
            }
        }
        foreach (['tar', 'gzip', 'rsync', 'mysql'] as $bin) {
            if (trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null')) === '') {
                throw new RuntimeException("Required command \"$bin\" is not installed (dnf -y install $bin).");
            }
        }
        $dir = MigrationService::workDir();
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException("Work directory $dir is missing or not writable by " . get_current_user() . ' (re-run install.sh, or: mkdir -p ' . $dir . ' && chown frankenphp:webusers ' . $dir . ').');
        }
    }

    private function finish(): void
    {
        $counts = [];
        foreach ($this->pdo->query('SELECT status, COUNT(*) c FROM migration_items WHERE migration_id = ' . (int) $this->migrationId . ' AND selected = 1 GROUP BY status')->fetchAll() as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }
        $cancelled = $this->cancelRequested();
        if ($cancelled) {
            $this->pdo->prepare("UPDATE migration_items SET status = 'cancelled', step = 'Cancelled' WHERE migration_id = ? AND status = 'pending'")->execute([$this->migrationId]);
        }
        $this->pdo->prepare("UPDATE migration_items SET mail_restore = 0, step = ? WHERE migration_id = ? AND mail_restore = 1")
            ->execute([$cancelled ? 'Mail restore cancelled' : 'Mail restore did not run', $this->migrationId]);
        $failed = ($counts['failed'] ?? 0);
        $ok = ($counts['completed'] ?? 0) + ($counts['completed_with_errors'] ?? 0);
        $status = match (true) {
            $cancelled => 'cancelled',
            $failed > 0 && $ok === 0 => 'failed',
            $failed > 0 || ($counts['completed_with_errors'] ?? 0) > 0 => 'completed_with_errors',
            default => 'completed',
        };
        $fields = ['status' => $status, 'finished_at' => date('Y-m-d H:i:s')];
        if ($failed === 0 && !$cancelled) {
            $fields['secret_enc'] = null; // nothing left to retry - don't keep the source credentials around
        }
        $this->updateMigration($fields);
        @rmdir(MigrationService::workDir() . '/' . $this->migrationId);
        $this->log("=== Runner finished: $status");
    }

    private function processItem(array $item): void
    {
        $this->item = $item;
        $user = (string) $item['source_username'];
        $previous = json_decode((string) $item['report'], true) ?: [];
        $this->log("--- $user: starting");

        if (!empty($previous['created'])) {
            $this->log("$user: rolling back the previous attempt's partial restore");
            $this->rollback($previous['created']);
        }
        $this->report = [
            'source' => $previous['source'] ?? [],
            'created' => ['user_id' => null, 'reseller_id' => null, 'dirs' => []],
            'account' => [], 'domains' => [], 'databases' => [], 'db_users' => [], 'email' => [],
            'warnings' => [], 'info' => [],
        ];
        $this->setItem([
            'status' => 'backing_up', 'step' => 'Requesting a full backup from the source server', 'progress' => 2,
            'started_at' => date('Y-m-d H:i:s'), 'finished_at' => null, 'error' => null, 'target_user_id' => null,
        ]);

        $this->work = MigrationService::workDir() . '/' . $this->migrationId . '/' . (int) $item['id'];
        self::rrmdir($this->work);
        @mkdir($this->work . '/incoming', 02770, true);
        @mkdir($this->work . '/extract', 02770, true);

        $sftpUser = null;
        try {
            $archive = match ($this->m['transfer_mode']) {
                'push' => $this->pushBackup($user, $sftpUser),
                'file' => $this->localBackup($user),
                default => $this->pullBackup($user),
            };
            if ($sftpUser) {
                $this->deleteSftpUser($sftpUser);
                $sftpUser = null;
            }

            $this->setItem(['status' => 'restoring', 'step' => 'Extracting the backup', 'progress' => 45]);
            $this->extract($archive, $this->work . '/extract');
            if ($this->m['transfer_mode'] !== 'file') {
                @unlink($archive); // our own download; a file-mode archive belongs to the operator
            }

            $reader = new CpanelBackupReader($this->work . '/extract', $user);
            $this->restore($reader);

            $problems = $this->report['warnings'] || $this->hasFailures();
            $this->setItem([
                'status' => $problems ? 'completed_with_errors' : 'completed',
                'step' => $problems ? 'Done - review the notes below' : 'Done', 'progress' => 100,
                'finished_at' => date('Y-m-d H:i:s'), 'report' => $this->reportJson(),
            ]);
            $this->log("--- $user: " . ($problems ? 'done with notes' : 'done'));
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $this->log("--- $user: FAILED - $msg");
            if (!empty($this->report['created']['user_id']) || !empty($this->report['created']['reseller_id']) || !empty($this->report['created']['dirs'])) {
                $this->log("$user: rolling back partial restore");
                $this->rollback($this->report['created']);
                $this->report['created'] = ['user_id' => null, 'reseller_id' => null, 'dirs' => []];
                $msg .= ' (Partially restored data was rolled back.)';
            }
            $this->report['error'] = $msg;
            $this->setItem([
                'status' => $msg === 'Cancelled.' ? 'cancelled' : 'failed', 'step' => $msg === 'Cancelled.' ? 'Cancelled' : 'Failed',
                'error' => $msg, 'finished_at' => date('Y-m-d H:i:s'), 'report' => $this->reportJson(),
            ]);
        } finally {
            if ($sftpUser) {
                $this->deleteSftpUser($sftpUser);
            }
            self::rrmdir($this->work);
        }
    }

    /**
     * "Restore mail" for an item that already finished: fetches the backup
     * again, extracts only the mail parts of the home directory, and creates
     * the mailboxes (with their stored mail) that aren't on this server yet.
     * The account, its sites and databases are never touched or rolled
     * back, and the item keeps its completed status even if this fails.
     */
    private function restoreMailItem(array $item): void
    {
        $this->item = $item;
        $this->mailOnly = true;
        $user = (string) $item['source_username'];
        $previous = json_decode((string) $item['report'], true) ?: [];
        $this->report = array_merge(['email' => [], 'warnings' => [], 'info' => []], $previous);
        $oldEmail = (array) $this->report['email'];
        $this->previousEmail = array_column($oldEmail, null, 'address');
        $this->report['email'] = [];
        $this->log("--- $user: restoring mail");
        $this->setItem(['step' => 'Fetching the backup', 'progress' => 5, 'error' => null]);

        $this->work = MigrationService::workDir() . '/' . $this->migrationId . '/' . (int) $item['id'];
        self::rrmdir($this->work);
        @mkdir($this->work . '/incoming', 02770, true);
        @mkdir($this->work . '/extract', 02770, true);

        $sftpUser = null;
        $error = null;
        try {
            $owner = $this->pdo->prepare('SELECT username FROM users WHERE id = ?');
            $owner->execute([(int) $item['target_user_id']]);
            if ($owner->fetchColumn() !== $user) {
                throw new RuntimeException("The JinnPanel account \"$user\" this item restored no longer exists.");
            }
            $archive = match ($this->m['transfer_mode']) {
                'push' => $this->pushBackup($user, $sftpUser),
                'file' => $this->localBackup($user),
                default => $this->pullBackup($user),
            };
            if ($sftpUser) {
                $this->deleteSftpUser($sftpUser);
                $sftpUser = null;
            }
            $this->setItem(['step' => 'Extracting mail from the backup', 'progress' => 45]);
            $this->extract($archive, $this->work . '/extract', ['*/cp/*', '*/shadow', '*/va/*', '*/userdata/*', '*/proftpdpasswd', '*/cron/*', '*/homedir/etc/*', '*/homedir/mail/*', '*/homedir/.autorespond/*', '*/homedir.tar']);
            if ($this->m['transfer_mode'] !== 'file') {
                @unlink($archive);
            }

            $domains = $this->pdo->prepare('SELECT domain_name FROM domains WHERE user_id = ? ORDER BY id');
            $domains->execute([(int) $item['target_user_id']]);
            $opt = $this->opt;
            $this->opt['email_data'] = true; // the point of a mail restore, whatever the original run chose
            $reader = new CpanelBackupReader($this->work . '/extract', $user);
            $here = $domains->fetchAll(PDO::FETCH_COLUMN);
            try {
                $this->restoreEmail((int) $item['target_user_id'], $reader, $here);
                // Extras that older migrations didn't bring over (idempotent).
                $this->setItem(['step' => 'FTP accounts and cron jobs', 'progress' => 95]);
                $this->mainDomain = $reader->mainDomain();
                $this->restoreFtpAccounts((int) $item['target_user_id'], $reader, $reader->domains(), $here);
                $this->restoreCron((int) $item['target_user_id'], $reader, $reader->domains(), $here);
            } finally {
                $this->opt = $opt;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $this->log("--- $user: mail restore FAILED - $error");
        } finally {
            if ($sftpUser) {
                $this->deleteSftpUser($sftpUser);
            }
            self::rrmdir($this->work);
            $this->mailOnly = false;
        }

        // Mailboxes this run skipped (already on the server) keep their old entry.
        $done = array_column($this->report['email'], 'address');
        $kept = array_filter($oldEmail, fn($e) => !in_array($e['address'] ?? '', $done, true));
        $created = count(array_filter($this->report['email'], fn($e) => ($e['status'] ?? '') !== 'failed'));
        $this->report['email'] = array_values(array_merge($kept, $this->report['email']));
        $this->report['info'][] = date('Y-m-d H:i') . ': mail restored again from the backup - '
            . ($error === null ? "$created mailbox(es) restored." : "stopped: $error");

        $this->report['info'] = array_values(array_unique($this->report['info']));
        $problems = $this->report['warnings'] || $this->hasFailures();
        $this->pdo->prepare('UPDATE migration_items SET mail_restore = 0, status = ?, step = ?, progress = 100, error = ?, report = ? WHERE id = ?')->execute([
            $problems || $error !== null ? 'completed_with_errors' : 'completed',
            $error === null ? "Mail restored: $created mailbox(es)" : ($error === 'Cancelled.' ? 'Mail restore cancelled' : 'Mail restore failed'),
            $error === 'Cancelled.' ? null : $error,
            $this->reportJson(),
            (int) $item['id'],
        ]);
        $this->log("--- $user: mail restore " . ($error === null ? "done ($created mailbox(es) restored)" : 'stopped'));
    }

    private function hasFailures(): bool
    {
        foreach (['domains', 'databases', 'db_users', 'email', 'forwarders', 'ftp'] as $k) {
            foreach ($this->report[$k] ?? [] as $r) {
                if (in_array($r['status'] ?? '', ['failed', 'partial'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function rollback(array $created): void
    {
        try {
            if (!empty($created['user_id'])) {
                AccountCleanupService::purge((int) $created['user_id'], (array) ($created['dirs'] ?? []));
            } else {
                foreach ((array) ($created['dirs'] ?? []) as $d) {
                    AccountCleanupService::removeSiteDir($d);
                }
            }
            if (!empty($created['reseller_id'])) {
                $this->pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'reseller'")->execute([(int) $created['reseller_id']]);
            }
        } catch (Throwable $e) {
            $this->log('rollback warning: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Step 1: get the backup onto this server
    // ------------------------------------------------------------------

    private function pushBackup(string $user, ?string &$sftpUser): string
    {
        $incoming = $this->work . '/incoming';
        $sftpUser = 'jpmig' . $this->migrationId . 'x' . (int) $this->item['id'];
        $pass = bin2hex(random_bytes(16));
        $this->deleteSftpUser($sftpUser); // leftover from a crashed attempt
        SftpService::createUser($sftpUser, $pass, $incoming, 0);

        $host = trim((string) $this->opt['public_host']) ?: Config::SERVER_IP;
        $port = (int) $this->opt['public_port'] ?: 2022;
        $this->log("$user: asking the source to build a full backup and push it to $host:$port over SCP");
        $this->api->uapi($user, 'Backup', 'fullbackup_to_scp_with_password', [
            'host' => $host, 'port' => $port, 'username' => $sftpUser, 'password' => $pass,
            'directory' => '/', 'homedir' => 'include',
        ], 180);

        $this->setItem(['status' => 'transferring', 'step' => 'Source is building the backup - waiting for it to arrive', 'progress' => 8]);
        $deadline = time() + (int) $this->opt['timeout_hours'] * 3600;
        $firstByteDeadline = time() + (int) $this->opt['first_byte_minutes'] * 60;
        $lastSize = -1;
        $stable = 0;
        while (true) {
            $this->checkpoint();
            clearstatcache();
            $files = array_merge(glob("$incoming/*.tar.gz") ?: [], glob("$incoming/*.tgz") ?: []);
            if ($files) {
                usort($files, fn($a, $b) => filesize($b) <=> filesize($a));
                $file = $files[0];
                $size = (int) filesize($file);
                $this->setItem(['step' => 'Receiving backup from source: ' . self::mb($size), 'progress' => min(38, 10 + (int) log(max(1, $size / 1048576), 2) * 2)]);
                $stable = ($size === $lastSize && $size > 0) ? $stable + 1 : 0;
                $lastSize = $size;
                if ($stable >= 3) {
                    $this->setItem(['step' => 'Verifying the received archive (' . self::mb($size) . ')']);
                    if (self::gzipOk($file)) {
                        $this->log("$user: received " . basename($file) . ' (' . self::mb($size) . ')');
                        return $file;
                    }
                    $stable = 0; // still being written
                }
            } elseif (time() > $firstByteDeadline) {
                throw new RuntimeException("The source never delivered the backup to $host:$port within {$this->opt['first_byte_minutes']} minutes. Its firewall probably blocks outgoing connections to that port, or that address isn't this server's public IP. Retry with Pull transfer mode (needs a WHM login, or the cPanel password).");
            }
            if (time() > $deadline) {
                throw new RuntimeException("Timed out after {$this->opt['timeout_hours']} hours waiting for the backup.");
            }
            sleep(10);
        }
    }

    private function pullBackup(string $user): string
    {
        $home = $this->remoteHome($user);
        $before = array_column($this->remoteBackups($user, $home), 'name');

        $this->log("$user: asking the source to build a full backup in $home");
        $this->api->uapi($user, 'Backup', 'fullbackup_to_homedir', ['homedir' => 'include'], 180);
        $this->setItem(['status' => 'backing_up', 'step' => 'Source is building the backup', 'progress' => 6]);

        $deadline = time() + (int) $this->opt['timeout_hours'] * 3600;
        $lastSize = -1;
        $stable = 0;
        $target = null;
        while ($target === null) {
            sleep(15);
            $this->checkpoint();
            if (time() > $deadline) {
                throw new RuntimeException("Timed out after {$this->opt['timeout_hours']} hours waiting for the source to finish the backup.");
            }
            $new = array_values(array_filter($this->remoteBackups($user, $home), fn($f) => !in_array($f['name'], $before, true)));
            if (!$new) {
                continue;
            }
            usort($new, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
            $size = $new[0]['size'];
            $this->setItem(['step' => 'Source is building the backup: ' . self::mb($size) . ' so far', 'progress' => min(18, 6 + (int) log(max(1, $size / 1048576), 2))]);
            $stable = ($size === $lastSize && $size > 0) ? $stable + 1 : 0;
            $lastSize = $size;
            if ($stable >= 3) {
                $target = $new[0];
            }
        }

        $local = $this->work . '/incoming/' . $target['name'];
        for ($attempt = 1; ; $attempt++) {
            $session = $this->api->openCpanelSession($user, $this->work);
            $this->setItem(['status' => 'transferring', 'step' => 'Downloading backup (' . self::mb($target['size']) . ')', 'progress' => 20]);
            try {
                $this->api->download($session, $target['path'], $local, function (int $done, int $total) use ($target): bool {
                    $total = $total ?: $target['size'];
                    $this->setItem(['step' => 'Downloading backup: ' . self::mb($done) . ' of ' . self::mb($total), 'progress' => 20 + ($total > 0 ? (int) (18 * $done / $total) : 0)]);
                    return !$this->cancelRequested(); // false aborts the transfer
                });
            } finally {
                @unlink($session['jar']);
            }
            $this->checkpoint();
            $this->setItem(['step' => 'Verifying the downloaded archive', 'progress' => 39]);
            if (self::gzipOk($local)) {
                break;
            }
            if ($attempt >= 3) {
                throw new RuntimeException('The downloaded backup is not a valid gzip archive (3 attempts).');
            }
            $this->log("$user: archive incomplete, retrying download in 60s");
            sleep(60);
        }
        $this->log("$user: downloaded {$target['name']} (" . self::mb((int) filesize($local)) . ')');
        $this->report['info'][] = "The backup file {$target['path']} was left in the source account's home directory - delete it there once you've checked the migration.";
        return $local;
    }

    private function localBackup(string $user): string
    {
        $this->setItem(['status' => 'transferring', 'step' => 'Verifying the backup file', 'progress' => 20]);
        $file = MigrationService::backupFile($user);
        if ($file === null) {
            throw new RuntimeException("No backup file for \"$user\" in " . MigrationService::importDir() . '.');
        }
        if (!is_readable($file) || !self::gzipOk($file)) {
            throw new RuntimeException(basename($file) . ' is not readable or not a valid gzip archive.');
        }
        $this->log("$user: using " . basename($file) . ' (' . self::mb((int) filesize($file)) . ')');
        $this->report['info'][] = 'Restored from ' . basename($file) . ' in ' . MigrationService::importDir() . ' - delete it there once you\'ve checked the migration.';
        return $file;
    }

    private function remoteHome(string $user): string
    {
        try {
            $data = $this->api->uapi($user, 'Variables', 'get_user_information', ['name' => 'home']);
            $home = (string) ($data['home'] ?? '');
        } catch (Throwable $e) {
            $home = '';
        }
        return preg_match('#^/[A-Za-z0-9._/-]+$#', $home) ? rtrim($home, '/') : '/home/' . $user;
    }

    /** @return array<int, array{name:string, path:string, size:int, mtime:int}> */
    private function remoteBackups(string $user, string $home): array
    {
        $data = $this->api->uapi($user, 'Fileman', 'list_files', ['dir' => $home, 'types' => 'file', 'show_hidden' => 0]);
        $list = is_array($data['files'] ?? null) ? $data['files'] : (is_array($data) && array_is_list($data) ? $data : []);
        $out = [];
        foreach ($list as $f) {
            $name = (string) ($f['file'] ?? basename((string) ($f['fullpath'] ?? '')));
            if (preg_match('/^backup-[0-9._-]+_' . preg_quote($user, '/') . '\.tar\.gz$/', $name)) {
                $out[] = ['name' => $name, 'path' => (string) ($f['fullpath'] ?? "$home/$name"), 'size' => (int) ($f['size'] ?? 0), 'mtime' => (int) ($f['mtime'] ?? 0)];
            }
        }
        return $out;
    }

    private function deleteSftpUser(string $name): void
    {
        try {
            SftpService::deleteUser($name);
        } catch (Throwable $e) {
            $this->log("warning: could not remove temporary SFTP user $name: " . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Step 2: extract
    // ------------------------------------------------------------------

    /** @param string[] $members only these (wildcard) members, e.g. just the mail; all if empty */
    private function extract(string $archive, string $dest, array $members = []): void
    {
        $size = (int) filesize($archive);
        $free = (int) @disk_free_space($dest);
        if ($free > 0 && $free < $size * 3) {
            throw new RuntimeException('Not enough free disk space to extract and restore this account (have ' . self::mb($free) . ', need roughly ' . self::mb($size * 3) . ').');
        }
        // GNU tar already refuses absolute and ".." member names; running as
        // an unprivileged user means it also can't set owners or devices.
        $cmd = ['tar', '-xzf', $archive, '-C', $dest, '--no-same-owner', '--no-same-permissions', '--delay-directory-restore'];
        if ($members) {
            array_push($cmd, '--wildcards', ...$members);
        }
        $code = CpanelBackupReader::run($cmd, $out);
        // With member patterns, tar exits 2 when one of them matched nothing
        // (e.g. no homedir.tar) - fine as long as something was extracted.
        if ($members && $code === 2 && preg_match('/Not found in archive/', (string) $out) && count(scandir($dest) ?: []) > 2) {
            $code = 0;
        }
        if ($code > 1) {
            throw new RuntimeException('Extracting the backup failed: ' . substr(trim((string) $out), 0, 400));
        }
    }

    private static function gzipOk(string $file): bool
    {
        return CpanelBackupReader::run(['gzip', '-t', $file]) === 0;
    }

    // ------------------------------------------------------------------
    // Step 3: restore
    // ------------------------------------------------------------------

    private function restore(CpanelBackupReader $r): void
    {
        $user = $r->username();
        $this->setItem(['step' => 'Creating the hosting account', 'progress' => 50]);

        $chk = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $chk->execute([$user]);
        if ((int) $chk->fetchColumn() > 0) {
            throw new RuntimeException("A JinnPanel account named \"$user\" already exists. Rename or delete it, then retry.");
        }

        $domains = $r->domains();
        [$packageId, $packageName] = $this->resolvePackage($this->item['source_plan'] ?: $r->plan());

        $generated = null;
        $hash = $r->accountPasswordHash();
        if ($hash === null) {
            $generated = Crypto::randomPassword();
            $hash = password_hash($generated, PASSWORD_BCRYPT);
        }
        $email = $r->contactEmail() ?? ($this->report['source']['email'] ?? null);

        $parentId = $this->resolveParent($user);
        if ((int) $this->item['is_reseller'] === 1 && $this->opt['owner'] === 'recreate' && $this->creator()['role'] === 'admin') {
            $parentId = $this->createResellerLogin($user, $hash, $email);
        }

        $ins = $this->pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role, parent_id, package_id, status) VALUES (?, ?, ?, NULL, 'user', ?, ?, 'active')");
        $ins->execute([$user, $this->uniqueEmail($email, $user), $hash, $parentId, $packageId]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->report['created']['user_id'] = $userId;
        $this->report['account'] = [
            'username' => $user,
            'password' => $generated ? 'generated' : 'preserved',
            'generated_password' => $generated,
            'package' => $packageName,
            'owner' => $parentId ? $this->usernameOf($parentId) : null,
        ];
        $this->setItem(['target_user_id' => $userId, 'report' => $this->reportJson()]);
        $this->log("$user: account created (id $userId, package " . ($packageName ?? 'none') . ')');

        // Domains + site files
        $home = ($this->opt['files'] || $this->opt['email_accounts']) ? $r->homedir() : null;
        $this->mainDomain = $r->mainDomain();
        $migratedDomains = [];
        foreach ($domains as $i => $d) {
            $this->checkpoint();
            $this->setItem(['step' => "Domain {$d['name']}" . ($this->opt['files'] ? ' + files' : ''), 'progress' => 52 + (int) (18 * $i / max(1, count($domains)))]);
            if ($this->restoreDomain($userId, $d, $home)) {
                $migratedDomains[] = $d['name'];
            }
        }
        $this->importDnsRecords($r, $migratedDomains);
        $this->setItem(['report' => $this->reportJson()]);

        if ($this->opt['databases']) {
            $this->restoreDatabases($userId, $r);
            $this->setItem(['report' => $this->reportJson()]);
        }

        if ($this->opt['email_accounts']) {
            $this->restoreEmail($userId, $r, $migratedDomains);
            $this->setItem(['report' => $this->reportJson()]);
        }

        if ($this->opt['files']) {
            $this->setItem(['step' => 'FTP accounts and cron jobs', 'progress' => 95]);
            $this->restoreFtpAccounts($userId, $r, $domains, $migratedDomains);
            $this->restoreCron($userId, $r, $domains, $migratedDomains);
        }

        $this->setItem(['step' => 'Final checks', 'progress' => 97]);
        $this->postChecks($userId, $r, $migratedDomains, $packageId);
        $this->relinkChildren($user);
    }

    private function restoreDomain(int $userId, array $d, ?string $home): bool
    {
        $name = $d['name'];
        if ($d['type'] === 'parked') {
            // A parked domain shows the main site: an alias of the main domain.
            $main = $this->pdo->prepare('SELECT * FROM domains WHERE user_id = ? AND domain_name = ?');
            $main->execute([$userId, (string) $this->mainDomain]);
            $mainRow = $main->fetch();
            try {
                if (!$mainRow) {
                    throw new RuntimeException('the main domain was not restored');
                }
                DomainAliasService::add($mainRow, $name);
                $this->report['domains'][] = ['name' => $name, 'type' => 'parked', 'status' => 'ok', 'note' => "Alias of {$mainRow['domain_name']} (serves the same site)."];
            } catch (Throwable $e) {
                $this->report['domains'][] = ['name' => $name, 'type' => 'parked', 'status' => 'failed', 'note' => 'Not added as an alias: ' . $e->getMessage()];
            }
            return false;
        }
        $chk = $this->pdo->prepare('SELECT COUNT(*) FROM domains WHERE domain_name = ?');
        $chk->execute([$name]);
        if ((int) $chk->fetchColumn() > 0) {
            $this->report['domains'][] = ['name' => $name, 'type' => $d['type'], 'status' => 'failed', 'note' => 'Already hosted on this server by another account.'];
            return false;
        }

        $siteDir = rtrim(Config::VHOSTS_DOCROOT_BASE, '/') . '/' . $name;
        $existed = is_dir($siteDir);
        $ssl = SslService::resolveMode((string) $this->opt['ssl_mode'], $name);
        try {
            $docroot = VhostService::create($name, 'default', $ssl);
        } catch (Throwable $e) {
            $this->report['domains'][] = ['name' => $name, 'type' => $d['type'], 'status' => 'failed', 'note' => 'Vhost: ' . $e->getMessage()];
            return false;
        }
        if (!$existed) {
            $this->report['created']['dirs'][] = $siteDir;
        }
        $dnsOk = true;
        try {
            DnsService::createZone($name);
        } catch (Throwable $e) {
            $dnsOk = false;
        }
        $this->pdo->prepare("INSERT INTO domains (user_id, domain_name, docroot, dns_provisioned, php_version, php_port, ssl_mode) VALUES (?, ?, ?, ?, 'default', NULL, ?)")
            ->execute([$userId, $name, $docroot, $dnsOk ? 1 : 0, $ssl]);

        $entry = ['name' => $name, 'type' => $d['type'], 'status' => 'ok', 'note' => $dnsOk ? '' : 'DNS zone provisioning failed (site works; re-provision from cPanel > DNS).'];
        if ($existed) {
            $entry['note'] = trim($entry['note'] . ' Its directory already existed on disk; migrated files were merged into it.');
        }

        if ($this->opt['files']) {
            $src = ($home && $d['docroot_rel']) ? CpanelBackupReader::inside($home, $d['docroot_rel']) : null;
            if ($src === null || !is_dir($src)) {
                $entry['status'] = 'partial';
                $entry['note'] = trim($entry['note'] . ' Its document root wasn\'t found in the backup, so no files were copied.');
            } else {
                $placeholder = "$docroot/index.php";
                if (is_file($placeholder) && str_contains((string) file_get_contents($placeholder, false, null, 0, 512), 'This domain is live. Replace this file')) {
                    unlink($placeholder); // don't let our placeholder shadow the site's own index.html
                }
                // --safe-links drops symlinks pointing outside the tree
                // (e.g. at /etc). -rlpt, not -a: no owners/groups/devices.
                $code = CpanelBackupReader::run(['rsync', '-rlpt', '--safe-links', '--chmod=Dug+rwx,Fug+rw', "$src/", "$docroot/"], $out);
                if ($code !== 0) {
                    $entry['status'] = in_array($code, [23, 24], true) ? 'partial' : 'failed';
                    $entry['note'] = trim($entry['note'] . ' Copying files: ' . substr(trim((string) $out), -300));
                }
                $entry['source_docroot'] = '~/' . $d['docroot_rel'];
                // MultiPHP INI Editor settings (FrankenPHP ignores .user.ini).
                try {
                    $row = $this->pdo->prepare('SELECT * FROM domains WHERE domain_name = ?');
                    $row->execute([$name]);
                    foreach (PhpSettingsService::importCpanelIni($row->fetch(), $docroot) as $n) {
                        $this->report['info'][] = "$name: $n.";
                    }
                } catch (Throwable $e) {
                    $this->report['warnings'][] = "$name: PHP settings from cPanel not imported: " . $e->getMessage();
                }
            }
        }
        $this->report['domains'][] = $entry;
        $this->log("{$this->item['source_username']}: domain $name {$entry['status']}");
        return true;
    }

    /**
     * cPanel's extra FTP accounts become SFTP accounts (same password - the
     * crypt hash is kept). Their home maps from /home/<user>/... onto the
     * new layout; a folder that wasn't part of a site is created empty.
     */
    private function restoreFtpAccounts(int $userId, CpanelBackupReader $r, array $domains, array $migrated): void
    {
        $accounts = $r->ftpAccounts();
        if (!$accounts) {
            return;
        }
        $this->report['ftp'] = [];
        $user = $r->username();
        $roots = [];
        foreach ($domains as $d) {
            if (in_array($d['name'], $migrated, true) && $d['docroot_rel']) {
                $roots[rtrim((string) $d['docroot_rel'], '/')] = $d['name'];
            }
        }
        uksort($roots, fn($a, $b) => strlen($b) <=> strlen($a));
        $mainDir = $this->mainDomain && in_array($this->mainDomain, $migrated, true) ? VhostService::siteDir($this->mainDomain) : null;
        $ins = $this->pdo->prepare('INSERT INTO ftp_accounts (user_id, domain_id, username, home_dir) VALUES (?, ?, ?, ?)');
        $dom = $this->pdo->prepare('SELECT id FROM domains WHERE domain_name = ?');
        foreach ($accounts as $a) {
            $entry = ['name' => $a['name'], 'status' => 'ok', 'note' => ''];
            try {
                if ($a['locked'] || $a['hash'] === null) {
                    throw new RuntimeException($a['locked'] ? 'disabled on cPanel - not recreated' : 'no usable password hash - create it again in cPanel > FTP');
                }
                [$home, $domainName, $created] = [null, null, false];
                foreach ($roots as $rel => $domainName) {
                    if ($a['home_rel'] === $rel || str_starts_with($a['home_rel'], "$rel/")) {
                        $home = rtrim(VhostService::effectiveDocroot($domainName) . substr($a['home_rel'], strlen($rel)), '/');
                        break;
                    }
                    $domainName = null;
                }
                if ($home === null) {
                    if ($mainDir === null) {
                        throw new RuntimeException('its folder has no place on this server (main domain not migrated)');
                    }
                    $domainName = $this->mainDomain;
                    $home = rtrim($mainDir . '/' . $a['home_rel'], '/');
                    if (str_contains($a['home_rel'], '..') || !preg_match('#^[A-Za-z0-9._/ -]*$#', $a['home_rel'])) {
                        throw new RuntimeException("unusual home folder ~/{$a['home_rel']}");
                    }
                }
                if (!is_dir($home)) {
                    @mkdir($home, 02775, true);
                    $created = true;
                }
                $label = preg_replace('/[^a-z0-9_]/', '_', strtolower(strstr($a['name'] . '@', '@', true)));
                $label = preg_match('/^[a-z]/', $label) ? $label : 'ftp_' . $label;
                $name = substr($user . '_' . $label, 0, 31);
                $exists = $this->pdo->prepare('SELECT user_id FROM ftp_accounts WHERE username = ?');
                $exists->execute([$name]);
                $owner = $exists->fetchColumn();
                if ($owner !== false && (int) $owner === $userId) {
                    $entry['status'] = 'ok';
                    $entry['note'] = "SFTP login $name is already here";
                    $this->report['ftp'][] = $entry;
                    continue; // a mail/extras restore running again
                }
                if ($owner !== false) {
                    $name = substr($user . '_' . $label, 0, 26) . '_' . substr(md5($a['name']), 0, 4);
                }
                SftpService::createUser($name, (string) $a['hash'], $home, 0);
                $dom->execute([$domainName]);
                $ins->execute([$userId, $dom->fetchColumn() ?: null, $name, $home]);
                $entry['note'] = "SFTP login $name (same password), folder $home" . ($created && !in_array($a['home_rel'], array_keys($roots), true) && $home !== $mainDir ? ' - created empty: it wasn\'t part of the sites\' files' : '');
            } catch (Throwable $e) {
                $entry['status'] = str_contains($e->getMessage(), 'not recreated') ? 'skipped' : 'failed';
                $entry['note'] = $e->getMessage();
            }
            $this->report['ftp'][] = $entry;
        }
    }

    /** Cron jobs that run a PHP script of the account's sites, or fetch a URL. */
    private function restoreCron(int $userId, CpanelBackupReader $r, array $domains, array $migrated): void
    {
        $tab = $r->crontab();
        if (trim($tab) === '') {
            return;
        }
        $map = [];
        foreach ($domains as $d) {
            if (in_array($d['name'], $migrated, true) && $d['docroot_rel']) {
                $map[rtrim((string) $d['docroot_rel'], '/')] = $d['name'];
            }
        }
        uksort($map, fn($a, $b) => strlen($b) <=> strlen($a));
        $res = CronService::fromCpanel($tab, $r->username(), $map);
        $dom = $this->pdo->prepare('SELECT id FROM domains WHERE domain_name = ? AND user_id = ?');
        foreach ($res['jobs'] as $j) {
            try {
                $in = ['schedule' => $j['schedule'], 'kind' => $j['kind'], 'url' => $j['url'] ?? '', 'args' => $j['args'] ?? ''];
                if ($j['kind'] === 'php') {
                    $dom->execute([$j['domain'], $userId]);
                    $in['domain_id'] = (int) $dom->fetchColumn();
                    $abs = VhostService::effectiveDocroot($j['domain']) . '/' . $j['docroot_path'];
                    $in['target'] = substr($abs, strlen(VhostService::siteDir($j['domain'])) + 1);
                }
                $same = $this->pdo->prepare('SELECT COUNT(*) FROM cron_jobs WHERE user_id = ? AND schedule = ? AND kind = ? AND (target = ? OR target = ?) AND args = ?');
                $same->execute([$userId, $j['schedule'], $j['kind'], $j['url'] ?? '', isset($j['domain']) ? VhostService::effectiveDocroot($j['domain']) . '/' . $j['docroot_path'] : '', $in['args']]);
                if ((int) $same->fetchColumn() > 0) {
                    continue;
                }
                CronService::create(['id' => $userId], $in);
                $this->report['info'][] = 'Cron job recreated: ' . $j['schedule'] . ' ' . ($j['kind'] === 'php' ? 'php ' . ($in['target'] ?? '') : $j['url']) . '.';
            } catch (Throwable $e) {
                $this->report['warnings'][] = 'Cron job not recreated (' . $e->getMessage() . '): ' . $j['schedule'];
            }
        }
        foreach ($res['skipped'] as $line) {
            $this->report['warnings'][] = "Cron job not recreated: $line";
        }
    }

    /** Custom DNS records from the backup's zone files (CpanelZoneImporter decides what's kept). */
    private function importDnsRecords(CpanelBackupReader $r, array $domains): void
    {
        $all = $this->pdo->query('SELECT domain_name FROM domains')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($domains as $domain) {
            $file = $r->path("dnszones/$domain.db");
            $zone = DnsService::findZoneByName($domain);
            if ($file === null || !is_file($file) || $zone === null) {
                continue;
            }
            try {
                $rep = CpanelZoneImporter::import((int) $zone['id'], (string) file_get_contents($file), $all);
                DnsService::publish((int) $zone['id']);
                foreach ($this->report['domains'] as &$entry) {
                    if ($entry['name'] === $domain) {
                        $entry['dns_records'] = count($rep['added']) . ' imported' . ($rep['external_mail'] ? ', external mail kept' : '');
                    }
                }
                unset($entry);
                foreach ($rep['failed'] as $f) {
                    $this->report['warnings'][] = "DNS record not imported for $domain: $f";
                }
            } catch (Throwable $e) {
                $this->report['warnings'][] = "DNS records for $domain weren't imported: " . $e->getMessage();
            }
        }
    }

    private function restoreDatabases(int $userId, CpanelBackupReader $r): void
    {
        $owner = $r->username();
        $dumps = $r->databases();
        $migrated = [];
        $n = 0;
        foreach ($dumps as $db => $file) {
            $this->checkpoint();
            $this->setItem(['step' => "Database $db (" . self::mb((int) filesize($file)) . ')', 'progress' => 70 + (int) (8 * $n++ / max(1, count($dumps)))]);
            $inPanel = $this->pdo->prepare('SELECT COUNT(*) FROM db_instances WHERE db_name = ?');
            $inPanel->execute([$db]);
            if ((int) $inPanel->fetchColumn() > 0 || ProvisioningService::databaseExists($db)) {
                $this->report['databases'][] = ['name' => $db, 'status' => 'failed', 'note' => 'A database with this name already exists on this server.'];
                continue;
            }
            try {
                ProvisioningService::createEmptyDatabase($db);
            } catch (Throwable $e) {
                $this->report['databases'][] = ['name' => $db, 'status' => 'failed', 'note' => $e->getMessage()];
                continue;
            }
            try {
                $errors = ProvisioningService::importDump($db, $file, $this->work);
                $entry = ['name' => $db, 'status' => $errors === '' ? 'ok' : 'partial', 'size' => self::mb((int) filesize($file))];
                if ($errors !== '') {
                    $entry['note'] = 'Imported with errors: ' . self::firstLines($errors, 4)
                        . (preg_match('/command denied|CREATE VIEW|TRIGGER|ROUTINE/i', $errors) ? ' (Views, triggers and stored routines need privileges JinnPanel doesn\'t grant customer databases.)' : '');
                }
                $this->report['databases'][] = $entry;
                $migrated[] = $db;
            } catch (Throwable $e) {
                ProvisioningService::dropDatabase($db);
                $this->report['databases'][] = ['name' => $db, 'status' => 'failed', 'note' => $e->getMessage()];
            }
        }
        if (!$migrated) {
            return;
        }

        // MySQL users: same names, same password hashes, access to the same databases.
        $dbOwnerUser = [];
        $extraRow = $this->pdo->prepare('INSERT INTO db_user_accounts (user_id, db_user) VALUES (?, ?)');
        foreach ($r->dbUsers() as $dbUser => $info) {
            $dbsForUser = array_values(array_filter($migrated, function ($db) use ($info) {
                foreach ($info['grants'] as $g) {
                    if (CpanelBackupReader::grantPatternMatches($g['pattern'], $db)) {
                        return true;
                    }
                }
                return false;
            }));
            if (!$dbsForUser) {
                continue;
            }
            if (ProvisioningService::userExists($dbUser)) {
                $this->report['db_users'][] = ['name' => $dbUser, 'status' => 'failed', 'note' => 'A MySQL user with this name already exists on this server.'];
                continue;
            }
            $generated = null;
            try {
                if ($info['hash'] !== null) {
                    ProvisioningService::createUserWithNativeHash($dbUser, $info['hash']);
                } else {
                    $generated = Crypto::randomPassword();
                    ProvisioningService::createUser($dbUser, $generated);
                }
                foreach ($dbsForUser as $db) {
                    ProvisioningService::grantDatabase($db, $dbUser);
                    // Shown as the database's user in cPanel > MySQL Databases:
                    // prefer a dedicated user over the account-wide one.
                    if (!isset($dbOwnerUser[$db]) || ($dbOwnerUser[$db] === $owner && $dbUser !== $owner)) {
                        $dbOwnerUser[$db] = $dbUser;
                    }
                }
                $extraRow->execute([$userId, $dbUser]);
                $this->report['db_users'][] = [
                    'name' => $dbUser, 'status' => 'ok', 'databases' => $dbsForUser,
                    'password' => $generated ? 'generated' : 'preserved', 'generated_password' => $generated,
                    'note' => $generated ? "Its original password hash ({$info['plugin']}) can't be used by MariaDB - update the app's config with the new password." : '',
                ];
            } catch (Throwable $e) {
                $this->report['db_users'][] = ['name' => $dbUser, 'status' => 'failed', 'note' => $e->getMessage()];
            }
        }

        $row = $this->pdo->prepare('INSERT INTO db_instances (user_id, db_name, db_user) VALUES (?, ?, ?)');
        foreach ($migrated as $db) {
            $dbUser = $dbOwnerUser[$db] ?? null;
            if ($dbUser === null) {
                // No user in the backup had access - give it one so it's usable.
                $dbUser = substr($db, 0, 32);
                if (ProvisioningService::userExists($dbUser)) {
                    $this->report['warnings'][] = "Database $db has no MySQL user with access to it; create one in cPanel > MySQL Databases.";
                    $dbUser = $owner;
                } else {
                    $pw = Crypto::randomPassword();
                    ProvisioningService::createUser($dbUser, $pw);
                    ProvisioningService::grantDatabase($db, $dbUser);
                    $extraRow->execute([$userId, $dbUser]);
                    $this->report['db_users'][] = ['name' => $dbUser, 'status' => 'ok', 'databases' => [$db], 'password' => 'generated', 'generated_password' => $pw, 'note' => 'Created because no user in the backup had access to this database.'];
                }
            }
            $row->execute([$userId, $db, $dbUser]);
        }
        $this->log("$owner: " . count($migrated) . ' database(s) restored');
    }

    private function restoreEmail(int $userId, CpanelBackupReader $r, array $domains): void
    {
        $withData = (bool) $this->opt['email_data'];
        $preserve = $this->opt['mail_passwords'] !== 'generate';
        $insert = $this->pdo->prepare('INSERT INTO email_accounts (user_id, domain_id, local_part, mail_account_id) VALUES (?, ?, ?, ?)');
        $exists = $this->pdo->prepare('SELECT mail_account_id FROM email_accounts WHERE domain_id = ? AND local_part = ?');

        // The cPanel account's own default mailbox becomes <user>@<main
        // domain>, logging in with the cPanel account password like cPanel
        // webmail's default account did.
        $user = $r->username();
        $main = $r->mainDomain();
        $defaultMaildir = ($withData && $main !== null && in_array($main, $domains, true)) ? $r->defaultMaildir() : null;
        $catchAll = $r->catchAllDomains();
        if ($catchAll) {
            $this->report['info'][] = 'On cPanel, mail to unknown addresses at ' . implode(', ', $catchAll)
                . " went to the account's default mailbox (catch-all)."
                . (($this->opt['catch_all'] ?? 'reject') === 'keep' ? '' : ' Not kept: unknown addresses are rejected (cPanel > Email > Default address to change it).');
        }

        foreach ($domains as $domain) {
            $accounts = $r->mailAccounts($domain);
            if ($defaultMaildir !== null && $domain === $main) {
                if (isset($accounts[$user])) {
                    $this->report['warnings'][] = "The account's default mailbox (system mail) wasn't migrated: $user@$main is already a regular mailbox.";
                    $defaultMaildir = null;
                } else {
                    $accounts[$user] = $r->accountPasswordHash();
                }
            }
            $isDefault = fn(string $local) => $defaultMaildir !== null && $domain === $main && $local === $user;
            if (!$accounts) {
                continue;
            }
            $dRow = $this->pdo->prepare('SELECT * FROM domains WHERE domain_name = ? AND user_id = ?');
            $dRow->execute([$domain, $userId]);
            $domainRow = $dRow->fetch();
            try {
                $mailDomainId = MailService::ensureDomain($domain);
                $this->pdo->prepare('UPDATE domains SET mail_domain_id = ? WHERE id = ?')->execute([$mailDomainId, $domainRow['id']]);
                MailDnsService::syncAfterMailDomain((int) $domainRow['id']);
            } catch (Throwable $e) {
                foreach (array_keys($accounts) as $local) {
                    $this->report['email'][] = ['address' => "$local@$domain", 'status' => 'failed', 'note' => 'Mail domain: ' . $e->getMessage()];
                }
                continue;
            }

            foreach ($accounts as $local => $hash) {
                $this->checkpoint();
                $address = "$local@$domain";
                $this->setItem(['step' => "Mailbox $address", 'progress' => 80]);
                $maildir = $withData ? ($isDefault($local) ? $defaultMaildir : $r->maildir($domain, $local)) : null;
                $exists->execute([$domainRow['id'], $local]);
                $existingId = $exists->fetchColumn();
                if ($existingId !== false) {
                    // Already here (a mail restore after an earlier run): only
                    // add the stored messages it doesn't have yet.
                    $old = $this->previousEmail[$address] ?? ['address' => $address, 'status' => 'ok', 'password' => 'unchanged', 'note' => ''];
                    if ($maildir === null) {
                        continue;
                    }
                    $entry = array_merge($old, ['status' => 'ok', 'note' => '']);
                    $this->importMail($entry, (string) $existingId, $address, $maildir, true);
                    $entry['messages'] = (int) ($old['messages'] ?? 0) + (int) ($entry['messages'] ?? 0);
                    $this->report['email'][] = $entry;
                    $this->log("{$r->username()}: mailbox $address already here, {$entry['status']} ({$entry['messages']} messages)");
                    continue;
                }

                $generated = null;
                $real = ($preserve && $hash !== null) ? $hash : ($generated = Crypto::randomPassword(16));
                $entry = ['address' => $address, 'status' => 'ok', 'password' => $generated ? 'generated' : 'preserved', 'generated_password' => $generated,
                    'note' => $isDefault($local) ? "The cPanel account's default mailbox (system mail, catch-all); same password as the cPanel account." : ''];
                try {
                    $accountId = MailService::createMailbox($mailDomainId, $local, $real);
                } catch (Throwable $e) {
                    if ($generated !== null) {
                        $this->report['email'][] = ['address' => $address, 'status' => 'failed', 'note' => $e->getMessage()];
                        continue;
                    }
                    // Mail server didn't accept the imported hash - fall back to a new password.
                    $generated = Crypto::randomPassword(16);
                    $entry = array_merge($entry, ['password' => 'generated', 'generated_password' => $generated, 'note' => 'The original password hash was not accepted by the mail server; a new password was set.']);
                    try {
                        $accountId = MailService::createMailbox($mailDomainId, $local, $generated);
                    } catch (Throwable $e2) {
                        $this->report['email'][] = ['address' => $address, 'status' => 'failed', 'note' => $e2->getMessage()];
                        continue;
                    }
                }
                $insert->execute([$userId, $domainRow['id'], $local, $accountId]);

                if ($maildir) {
                    $this->importMail($entry, $accountId, $address, $maildir, false);
                } elseif ($withData) {
                    $entry['messages'] = 0;
                }
                $this->report['email'][] = $entry;
                $this->log("{$r->username()}: mailbox $address {$entry['status']}" . (isset($entry['messages']) ? " ({$entry['messages']} messages)" : ''));
            }
        }
        $this->restoreMailRules($userId, $r, $domains);
    }

    /**
     * cPanel forwarders and autoresponders, and - when the migration keeps
     * them - the domains' default addresses. Idempotent (a mail restore runs
     * it again): forwarders merge their destinations, autoresponders are
     * overwritten.
     */
    private function restoreMailRules(int $userId, CpanelBackupReader $r, array $domains): void
    {
        $this->report['forwarders'] ??= [];
        $dRow = $this->pdo->prepare('SELECT * FROM domains WHERE domain_name = ? AND user_id = ?');
        $box = $this->pdo->prepare('SELECT e.id FROM email_accounts e JOIN domains d ON d.id = e.domain_id WHERE d.user_id = ? AND d.domain_name = ? AND e.local_part = ?');
        $keepDefault = ($this->opt['catch_all'] ?? 'reject') === 'keep';
        $main = $r->mainDomain();

        foreach ($domains as $domain) {
            $dRow->execute([$domain, $userId]);
            $row = $dRow->fetch();
            if (!$row) {
                continue;
            }
            $fw = $r->forwarders($domain);
            foreach ($fw['forwarders'] as $local => $dests) {
                $address = "$local@$domain";
                $this->report['forwarders'] = array_values(array_filter($this->report['forwarders'], fn($e) => ($e['address'] ?? '') !== $address));
                try {
                    MailRulesService::addForwarder($userId, $row, $local, implode(',', $dests));
                    $this->report['forwarders'][] = ['address' => $address, 'status' => 'ok', 'note' => 'to ' . implode(', ', $dests)];
                } catch (Throwable $e) {
                    $this->report['forwarders'][] = ['address' => $address, 'status' => 'failed', 'note' => $e->getMessage()];
                }
                $dRow->execute([$domain, $userId]);
                $row = $dRow->fetch(); // mail_domain_id may have been set
            }
            foreach ($fw['skipped'] as $s) {
                $this->report['warnings'][] = "Forwarder not recreated (pipes to programs and :fail:/:blackhole: rules aren't supported): $s";
            }

            if ($keepDefault && ($target = $r->defaultAddress($domain)) !== null && $target[0] !== ':') {
                $address = str_contains($target, '@') ? strtolower($target) : ($main !== null ? strtolower("$target@$main") : null);
                try {
                    if ($address === null || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException("unsupported target \"$target\"");
                    }
                    $mailDomainId = $row['mail_domain_id'] ?: MailService::ensureDomain($domain);
                    $this->pdo->prepare('UPDATE domains SET mail_domain_id = ? WHERE id = ?')->execute([$mailDomainId, $row['id']]);
                    MailService::setCatchAll((string) $mailDomainId, $address);
                    $this->pdo->prepare('UPDATE domains SET catch_all = ? WHERE id = ?')->execute([$address, $row['id']]);
                    $this->report['info'][] = "Default address of $domain kept: mail to unknown addresses goes to $address.";
                } catch (Throwable $e) {
                    $this->report['warnings'][] = "Default address of $domain not kept: " . $e->getMessage();
                }
            }
        }

        foreach ($r->autoresponders() as $address => $ar) {
            [$local, $domain] = explode('@', $address, 2);
            $box->execute([$userId, $domain, $local]);
            $id = $box->fetchColumn();
            if ($id === false) {
                $this->report['warnings'][] = "Autoresponder for $address not recreated: it isn't a mailbox here (JinnPanel autoresponders belong to a mailbox).";
                continue;
            }
            try {
                MailRulesService::saveAutoresponder($userId, (int) $id, $ar);
                $this->report['info'][] = "Autoresponder of $address recreated (\"{$ar['subject']}\").";
            } catch (Throwable $e) {
                $this->report['warnings'][] = "Autoresponder for $address not recreated: " . $e->getMessage();
            }
        }
    }

    /**
     * Imports a Maildir into $address (logged in as the mail admin, so the
     * mailbox's password stays as it is) and records the outcome in $entry.
     */
    private function importMail(array &$entry, string $mailAccountId, string $address, string $maildir, bool $skipExisting): void
    {
        try {
            $stats = MailService::withImportLimitsLifted($mailAccountId, fn() => MailImportService::asAdmin($address)->importMaildir(
                $maildir,
                function (array $s) use ($address) {
                    $this->setItem(['step' => "Mailbox $address: {$s['imported']} messages imported"]);
                },
                fn() => $this->cancelRequested(),
                $skipExisting,
            ));
            $entry['messages'] = $stats['imported'];
            $entry['folders'] = $stats['folders'];
            if ($stats['failed'] > 0) {
                $entry['status'] = 'partial';
                $entry['note'] = trim($entry['note'] . " {$stats['failed']} message(s) failed to import: " . implode('; ', array_slice($stats['errors'], 0, 3)));
            }
            if ($stats['skipped'] > 0) {
                $entry['note'] = trim($entry['note'] . " {$stats['skipped']} deleted/oversized message(s) skipped.");
            }
            if ($stats['existing'] > 0) {
                $entry['note'] = trim($entry['note'] . " {$stats['existing']} message(s) were already in the mailbox.");
            }
        } catch (Throwable $e) {
            if ($e->getMessage() === 'Cancelled.') {
                throw $e;
            }
            $entry['status'] = 'partial';
            $entry['note'] = trim($entry['note'] . ' Stored mail was not imported: ' . $e->getMessage());
        }
    }

    private function postChecks(int $userId, CpanelBackupReader $r, array $domains, ?int $packageId): void
    {
        $user = $r->username();
        $htaccess = [];
        $hardcoded = [];
        // (.user.ini/php.ini aren't listed: their settings are imported and
        // their error_log path replaced - see PhpSettingsService.)
        $candidates = ['wp-config.php', 'configuration.php', '.env', 'config.php', 'app/etc/env.php', 'sites/default/settings.php'];
        foreach ($domains as $domain) {
            $docroot = VhostService::docroot($domain);
            if (is_file("$docroot/.htaccess")) {
                $htaccess[] = $domain;
            }
            foreach ($candidates as $c) {
                $f = "$docroot/$c";
                if (is_file($f) && !is_link($f) && filesize($f) < 1048576 && preg_match('#/home\d*/' . preg_quote($user, '#') . '/#', (string) file_get_contents($f))) {
                    $hardcoded[] = "$domain/$c";
                }
            }
        }
        // .htaccess -> Caddy routes (cPanel > Domains > domain > Routes).
        $review = [];
        foreach ($htaccess as $domain) {
            try {
                $row = $this->pdo->prepare('SELECT * FROM domains WHERE domain_name = ? AND user_id = ?');
                $row->execute([$domain, $userId]);
                $d = $row->fetch();
                $g = RoutesService::overview($d)['generated'];
                $errors = RoutesService::queueSave($d, (string) $g['route'], (string) $g['site'], (bool) $g['needs_review']);
                if ($errors) {
                    $this->report['warnings'][] = "$domain: the rules translated from .htaccess didn't pass the checks (" . implode(' ', array_slice($errors, 0, 2)) . ') - set them in cPanel > Domains > Routes.';
                } elseif ($g['needs_review']) {
                    $review[] = $domain;
                }
            } catch (Throwable $e) {
                $this->report['warnings'][] = "$domain: .htaccess not translated - " . $e->getMessage();
            }
        }
        if ($htaccess) {
            $this->report['info'][] = '.htaccess rules translated for ' . implode(', ', $htaccess) . ' (this server doesn\'t read .htaccess).';
        }
        if ($review) {
            $this->report['warnings'][] = 'Routes need review for ' . implode(', ', $review) . ': some .htaccess rules couldn\'t be translated exactly - see cPanel > Domains > (domain) > Routes.';
        }
        if ($hardcoded) {
            $this->report['warnings'][] = 'These files contain the old /home/' . $user . '/ path and may need updating: ' . implode(', ', $hardcoded) . '.';
        }

        if ($packageId) {
            $pkg = $this->pdo->prepare('SELECT * FROM packages WHERE id = ?');
            $pkg->execute([$packageId]);
            $p = $pkg->fetch();
            $usage = Quota::usage($userId);
            $over = [];
            foreach (['domains' => 'max_domains', 'databases' => 'max_databases', 'email_accounts' => 'max_email_accounts'] as $k => $col) {
                if ($p && $usage[$k] > (int) $p[$col]) {
                    $over[] = str_replace('_', ' ', $k) . " {$usage[$k]}/{$p[$col]}";
                }
            }
            if ($over) {
                $this->report['warnings'][] = "The account exceeds its \"{$p['name']}\" package (" . implode(', ', $over) . '). Everything was migrated, but the user can\'t add more until you assign a bigger package.';
            }
        }
        $this->report['info'][] = 'Not migrated: SSL certificates (new ones are issued automatically once DNS points here).';
        $this->report['info'][] = 'The sites go live once DNS for each domain points at this server (' . Config::SERVER_IP . ').';
    }

    // ------------------------------------------------------------------
    // Ownership / packages
    // ------------------------------------------------------------------

    private function creator(): array
    {
        if ($this->creatorCache === null) {
            $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$this->m['created_by']]);
            $this->creatorCache = $stmt->fetch() ?: ['id' => 0, 'role' => 'admin'];
        }
        return $this->creatorCache;
    }

    /** @return array{0:?int,1:?string} */
    private function resolvePackage(?string $sourcePlan): array
    {
        $creator = $this->creator();
        $scope = $creator['role'] === 'admin' ? '' : ' AND (owner_id IS NULL OR owner_id = ' . (int) $creator['id'] . ')';
        if ($this->opt['match_packages'] && $sourcePlan) {
            // cPanel prefixes reseller plans as "reseller_Plan".
            foreach (array_unique([$sourcePlan, preg_replace('/^[a-z0-9]+_/', '', $sourcePlan)]) as $name) {
                $stmt = $this->pdo->prepare("SELECT id, name FROM packages WHERE LOWER(name) = LOWER(?)$scope LIMIT 1");
                $stmt->execute([$name]);
                if ($p = $stmt->fetch()) {
                    return [(int) $p['id'], $p['name']];
                }
            }
        }
        if (!empty($this->opt['package_id'])) {
            $stmt = $this->pdo->prepare("SELECT id, name FROM packages WHERE id = ?$scope");
            $stmt->execute([(int) $this->opt['package_id']]);
            if ($p = $stmt->fetch()) {
                return [(int) $p['id'], $p['name']];
            }
        }
        return [null, null];
    }

    private function resolveParent(string $user): ?int
    {
        $creator = $this->creator();
        if ($creator['role'] === 'reseller') {
            return (int) $creator['id'];
        }
        $owner = (string) $this->opt['owner'];
        if (str_starts_with($owner, 'reseller:')) {
            return (int) substr($owner, 9) ?: null;
        }
        if ($owner === 'recreate' && !empty($this->item['source_owner']) && $this->item['source_owner'] !== $user) {
            return $this->resellerLoginFor((string) $this->item['source_owner']);
        }
        return null;
    }

    /** JinnPanel reseller id created in this migration for source reseller $sourceReseller, if any. */
    private function resellerLoginFor(string $sourceReseller): ?int
    {
        $stmt = $this->pdo->prepare('SELECT report FROM migration_items WHERE migration_id = ? AND source_username = ? AND is_reseller = 1');
        $stmt->execute([$this->migrationId, $sourceReseller]);
        $rep = json_decode((string) $stmt->fetchColumn(), true);
        return !empty($rep['created']['reseller_id']) ? (int) $rep['created']['reseller_id'] : null;
    }

    /**
     * A cPanel reseller is two things at once - a WHM login and a hosting
     * account with its own sites. JinnPanel keeps those separate: the
     * hosting account keeps the original username (its databases are
     * prefixed with it), the WHM login becomes "<name>_whm".
     */
    private function createResellerLogin(string $user, string $hash, ?string $email): int
    {
        $login = substr($user, 0, 27) . '_whm';
        $chk = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        for ($i = 2; ; $i++) {
            $chk->execute([$login]);
            if ((int) $chk->fetchColumn() === 0) {
                break;
            }
            $login = substr($user, 0, 25) . '_whm' . $i;
        }
        $this->pdo->prepare("INSERT INTO users (username, email, password_hash, role, parent_id, package_id, status) VALUES (?, ?, ?, 'reseller', NULL, NULL, 'active')")
            ->execute([$login, $this->uniqueEmail($email, $login), $hash]);
        $id = (int) $this->pdo->lastInsertId();
        $this->report['created']['reseller_id'] = $id;
        $this->report['account']['reseller_login'] = $login;
        $this->setItem(['report' => $this->reportJson()]);
        $this->log("$user: reseller WHM login \"$login\" created");
        return $id;
    }

    /** After a reseller is (re)created on retry, re-attach its already-migrated customers. */
    private function relinkChildren(string $user): void
    {
        $rid = $this->report['created']['reseller_id'] ?? null;
        if (!$rid) {
            return;
        }
        $this->pdo->prepare("UPDATE users u JOIN migration_items i ON i.target_user_id = u.id
                             SET u.parent_id = ?
                             WHERE i.migration_id = ? AND i.source_owner = ? AND i.source_username <> ? AND u.role = 'user'")
            ->execute([$rid, $this->migrationId, $user, $user]);
    }

    private function uniqueEmail(?string $email, string $user): string
    {
        $chk = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $candidates = array_filter([$email, "$user@" . Config::SERVER_HOSTNAME]);
        foreach ($candidates as $c) {
            $chk->execute([$c]);
            if ((int) $chk->fetchColumn() === 0) {
                return $c;
            }
        }
        for ($i = 2; ; $i++) {
            $c = "$user+$i@" . Config::SERVER_HOSTNAME;
            $chk->execute([$c]);
            if ((int) $chk->fetchColumn() === 0) {
                return $c;
            }
        }
    }

    private function usernameOf(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ?: null;
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    private function loadMigration(): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM migrations WHERE id = ?');
        $stmt->execute([$this->migrationId]);
        return $stmt->fetch() ?: null;
    }

    private function cancelRequested(): bool
    {
        $stmt = $this->pdo->prepare('SELECT cancel_requested FROM migrations WHERE id = ?');
        $stmt->execute([$this->migrationId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /** Heartbeat + cancellation point, called from every wait loop. */
    private function checkpoint(): void
    {
        if (time() - $this->lastBeat >= 10) {
            $this->lastBeat = time();
            $this->updateMigration(['heartbeat_at' => date('Y-m-d H:i:s')]);
            if ($this->cancelRequested()) {
                throw new RuntimeException('Cancelled.');
            }
        }
    }

    private function updateMigration(array $fields): void
    {
        $fields['heartbeat_at'] ??= date('Y-m-d H:i:s');
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
        $this->pdo->prepare("UPDATE migrations SET $sets WHERE id = ?")->execute([...array_values($fields), $this->migrationId]);
    }

    private function setItem(array $fields): void
    {
        $allowed = ['status', 'step', 'progress', 'report', 'error', 'started_at', 'finished_at', 'target_user_id'];
        $fields = array_intersect_key($fields, array_flip($allowed));
        if ($this->mailOnly) {
            unset($fields['status'], $fields['started_at'], $fields['finished_at']);
            if (isset($fields['step'])) {
                $fields['step'] = 'Restoring mail: ' . $fields['step'];
            }
        }
        if (isset($fields['step'])) {
            $fields['step'] = mb_substr((string) $fields['step'], 0, 250);
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
        $this->pdo->prepare("UPDATE migration_items SET $sets WHERE id = ?")->execute([...array_values($fields), $this->item['id']]);
        $this->checkpointQuiet();
    }

    private function checkpointQuiet(): void
    {
        if (time() - $this->lastBeat >= 10) {
            $this->lastBeat = time();
            $this->updateMigration(['heartbeat_at' => date('Y-m-d H:i:s')]);
        }
    }

    private function reportJson(): string
    {
        return json_encode($this->report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function log(string $msg): void
    {
        @file_put_contents(MigrationService::logFile($this->migrationId), '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
        fwrite(STDOUT, "$msg\n"); // -> journal of the transient unit
    }

    private static function mb(int $bytes): string
    {
        return $bytes >= 1073741824 ? round($bytes / 1073741824, 2) . ' GB' : round($bytes / 1048576, 1) . ' MB';
    }

    private static function firstLines(string $s, int $n): string
    {
        return implode(' | ', array_slice(array_filter(array_map('trim', explode("\n", $s))), 0, $n));
    }

    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        // Extracted trees can contain read-only directories; make sure we
        // can descend and delete.
        CpanelBackupReader::run(['chmod', '-R', 'u+rwX', $dir]);
        CpanelBackupReader::run(['rm', '-rf', '--one-file-system', $dir]);
    }
}
