<?php
declare(strict_types=1);

/**
 * Backups (WHM > Backups, cPanel > Backups).
 *
 * An account backup is a folder under DIR/<username>/<timestamp>/ with
 *   files.tar.gz        its site folders (/var/www/<domain>, owners kept)
 *   db-<name>.sql.gz    one mysqldump per database
 *   mail.tar.gz         one mbox per mailbox folder (+ flags/dates), read
 *                       over IMAP with the panel's master login
 *   meta.json           what is in it
 * readable by the panel (root:frankenphp, 0640) so customers can download
 * their own. The server backup (DIR/_server/, root only) holds the panel
 * database and the configuration of every service.
 *
 * Backups and restores run as root in their own systemd units, started by
 * hostpanel-worker (they can take a while); the panel only queues them.
 * Daily at the configured hour, every account plus the server are backed
 * up; the newest `keep` of each are kept. Optionally each backup is also
 * uploaded to S3-compatible storage (off-site copy).
 */
final class BackupService
{
    public const DIR = '/var/backups/jinnpanel';
    private const ROOT_PW = '/root/.jinnpanel/mariadb_root_pw';

    /** @return array{enabled:bool, hour:int, keep:int, s3:?array} */
    public static function settings(): array
    {
        $get = function (string $key, string $default): string {
            $s = Database::app()->prepare('SELECT setting_value FROM panel_settings WHERE setting_key = ?');
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return is_string($v) ? $v : $default;
        };
        $s3 = json_decode($get('backup_s3', ''), true);
        return [
            'enabled' => $get('backup_enabled', '1') === '1',
            'hour' => max(0, min(23, (int) $get('backup_hour', '3'))),
            'keep' => max(1, min(30, (int) $get('backup_keep', '2'))),
            's3' => is_array($s3) && !empty($s3['bucket']) ? $s3 : null,
        ];
    }

    /** @param array<string,mixed> $in enabled, hour, keep, s3_* */
    public static function saveSettings(array $in): void
    {
        $set = Database::app()->prepare('REPLACE INTO panel_settings (setting_key, setting_value) VALUES (?, ?)');
        $set->execute(['backup_enabled', !empty($in['enabled']) ? '1' : '0']);
        $set->execute(['backup_hour', (string) max(0, min(23, (int) ($in['hour'] ?? 3)))]);
        $set->execute(['backup_keep', (string) max(1, min(30, (int) ($in['keep'] ?? 2)))]);
        $bucket = trim((string) ($in['s3_bucket'] ?? ''));
        if ($bucket === '') {
            $set->execute(['backup_s3', '']);
            return;
        }
        $old = self::settings()['s3'];
        $endpoint = trim((string) ($in['s3_endpoint'] ?? ''));
        if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?/?$#i', $endpoint)) {
            throw new InvalidArgumentException('The S3 endpoint must be an https:// URL, e.g. https://s3.eu-central-1.amazonaws.com');
        }
        $secret = (string) ($in['s3_secret'] ?? '');
        $set->execute(['backup_s3', json_encode([
            'endpoint' => rtrim($endpoint, '/'),
            'region' => trim((string) ($in['s3_region'] ?? '')) ?: 'us-east-1',
            'bucket' => $bucket,
            'prefix' => trim(trim((string) ($in['s3_prefix'] ?? '')), '/'),
            'access_key' => trim((string) ($in['s3_access_key'] ?? '')),
            'secret_enc' => $secret !== '' ? Crypto::encrypt($secret) : ($old['secret_enc'] ?? ''),
        ])]);
    }

    /** Queues a backup of an account (or, $userId null, of the server); returns its id. */
    public static function queue(?int $userId): int
    {
        $pdo = Database::app();
        $username = '';
        if ($userId !== null) {
            $s = $pdo->prepare("SELECT username FROM users WHERE id = ? AND role = 'user'");
            $s->execute([$userId]);
            $username = (string) $s->fetchColumn();
            if ($username === '') {
                throw new InvalidArgumentException('No such hosting account.');
            }
        }
        $busy = $pdo->prepare("SELECT COUNT(*) FROM backups WHERE " . ($userId === null ? "kind = 'server'" : 'user_id = ?') . " AND status IN ('queued','running','restoring')");
        $busy->execute($userId === null ? [] : [$userId]);
        if ((int) $busy->fetchColumn() > 0) {
            throw new RuntimeException('A backup or restore of this ' . ($userId === null ? 'server' : 'account') . ' is already in progress.');
        }
        $pdo->prepare('INSERT INTO backups (kind, user_id, username, status) VALUES (?, ?, ?, \'queued\')')
            ->execute([$userId === null ? 'server' : 'account', $userId, $username]);
        $id = (int) $pdo->lastInsertId();
        SystemWorkerService::enqueue("backup-$id", ['type' => 'backup_run', 'backup_id' => $id]);
        return $id;
    }

    /** @param list<string> $parts files, databases, mail */
    public static function queueRestore(int $backupId, array $parts): void
    {
        $parts = array_values(array_intersect($parts, ['files', 'databases', 'mail']));
        if (!$parts) {
            throw new InvalidArgumentException('Choose what to restore.');
        }
        $b = self::find($backupId);
        if (!$b || $b['status'] !== 'done' || $b['kind'] !== 'account' || $b['user_id'] === null) {
            throw new RuntimeException('That backup can\'t be restored (not finished, or its account is gone).');
        }
        Database::app()->prepare("UPDATE backups SET status = 'restoring' WHERE id = ?")->execute([$backupId]);
        SystemWorkerService::enqueue("restore-$backupId", ['type' => 'backup_restore', 'backup_id' => $backupId, 'parts' => $parts]);
    }

    public static function find(int $id): ?array
    {
        $s = Database::app()->prepare('SELECT * FROM backups WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        $s = Database::app()->prepare('SELECT * FROM backups WHERE user_id = ? ORDER BY id DESC LIMIT 50');
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 100): array
    {
        return Database::app()->query('SELECT * FROM backups ORDER BY id DESC LIMIT ' . max(1, $limit))->fetchAll();
    }

    /** Downloadable files of a finished account backup: name => absolute path. @return array<string,string> */
    public static function files(array $backup): array
    {
        $out = [];
        $dir = (string) $backup['path'];
        if ($backup['status'] !== 'done' || $backup['kind'] !== 'account' || !str_starts_with($dir, self::DIR . '/') || !is_dir($dir)) {
            return $out;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if (preg_match('/^(files\.tar\.gz|mail\.tar\.gz|db-[A-Za-z0-9_]+\.sql\.gz)$/', $f) && is_file("$dir/$f")) {
                $out[$f] = "$dir/$f";
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Root side (hostpanel-worker: `backup <id>`, `restore <id> <parts>`, `backup-all`)
    // ------------------------------------------------------------------

    public static function execute(int $id, callable $log): void
    {
        $b = self::find($id);
        if (!$b) {
            throw new RuntimeException("No backup #$id");
        }
        $pdo = Database::app();
        $pdo->prepare("UPDATE backups SET status = 'running', log = NULL WHERE id = ?")->execute([$id]);
        $lines = [];
        $note = function (string $m) use (&$lines, $log, $pdo, $id): void {
            $lines[] = '[' . date('H:i:s') . "] $m";
            $log($m);
            $pdo->prepare('UPDATE backups SET log = ? WHERE id = ?')->execute([implode("\n", array_slice($lines, -200)), $id]);
        };
        try {
            $parts = $b['kind'] === 'server' ? self::backupServer($id, $note) : self::backupAccount($b, $note);
            $size = 0;
            foreach (glob($parts['dir'] . '/*') ?: [] as $f) {
                $size += (int) filesize($f);
            }
            $pdo->prepare("UPDATE backups SET status = 'done', path = ?, size_bytes = ?, parts = ?, finished_at = NOW() WHERE id = ?")
                ->execute([$parts['dir'], $size, json_encode($parts['meta']), $id]);
            $note('done: ' . self::bytes($size));
            self::offsite($id, $parts['dir'], $note);
            self::prune($b['kind'] === 'server' ? null : (int) $b['user_id'], $note);
        } catch (Throwable $e) {
            $note('FAILED: ' . $e->getMessage());
            $pdo->prepare("UPDATE backups SET status = 'failed', finished_at = NOW() WHERE id = ?")->execute([$id]);
            throw $e;
        }
    }

    /** @return array{dir:string, meta:array} */
    private static function backupAccount(array $b, callable $note): array
    {
        $pdo = Database::app();
        $userId = (int) $b['user_id'];
        $username = (string) $b['username'];
        $u = Quota::measured($userId);
        $need = $u['used'] + 1073741824;
        $free = (int) @disk_free_space(dirname(self::DIR));
        if ($free > 0 && $free < $need) {
            throw new RuntimeException('Not enough free disk space for this backup (' . self::bytes($free) . ' free, about ' . self::bytes($need) . ' needed).');
        }
        $dir = self::DIR . '/' . $username . '/' . date('Ymd-His');
        self::mkdirs($dir, 0750, 'frankenphp');

        $d = $pdo->prepare('SELECT domain_name FROM domains WHERE user_id = ? ORDER BY domain_name');
        $d->execute([$userId]);
        $domains = array_values(array_filter($d->fetchAll(PDO::FETCH_COLUMN), fn($n) => is_dir(VhostService::siteDir((string) $n))));
        $meta = ['version' => 1, 'username' => $username, 'created' => date('c'), 'domains' => $domains, 'databases' => [], 'mailboxes' => []];

        if ($domains) {
            $note('site files: ' . implode(', ', $domains));
            $code = self::sh('tar --numeric-owner --acls -C ' . escapeshellarg(Config::VHOSTS_DOCROOT_BASE) . ' -czf ' . escapeshellarg("$dir/files.tar.gz") . ' '
                . implode(' ', array_map('escapeshellarg', $domains)), $out);
            if ($code > 1) { // 1 = some file changed while it was read: still a usable archive
                throw new RuntimeException('Archiving the site files failed: ' . mb_substr($out, -300));
            }
        }

        $dbs = $pdo->prepare('SELECT db_name FROM db_instances WHERE user_id = ? ORDER BY db_name');
        $dbs->execute([$userId]);
        foreach ($dbs->fetchAll(PDO::FETCH_COLUMN) as $db) {
            if (!ProvisioningService::isValidIdentifier((string) $db)) {
                continue;
            }
            $note("database $db");
            self::withRootCnf(function (string $cnf) use ($db, $dir): void {
                $code = self::sh('set -o pipefail; mysqldump --defaults-extra-file=' . escapeshellarg($cnf) . ' --single-transaction --quick --routines --triggers --events --hex-blob '
                    . escapeshellarg((string) $db) . ' | gzip -c > ' . escapeshellarg("$dir/db-$db.sql.gz"), $out);
                if ($code !== 0) {
                    throw new RuntimeException("Dumping $db failed: " . mb_substr($out, -300));
                }
            });
            $meta['databases'][] = (string) $db;
        }

        $m = $pdo->prepare("SELECT CONCAT(e.local_part, '@', d.domain_name) FROM email_accounts e JOIN domains d ON d.id = e.domain_id WHERE e.user_id = ? AND e.mail_account_id IS NOT NULL ORDER BY 1");
        $m->execute([$userId]);
        $addresses = $m->fetchAll(PDO::FETCH_COLUMN);
        if ($addresses) {
            $tmp = "$dir/.mail";
            self::mkdirs($tmp, 0700, 'root');
            foreach ($addresses as $address) {
                $note("mailbox $address");
                $meta['mailboxes'][$address] = self::dumpMailbox((string) $address, "$tmp/" . self::safeName((string) $address));
            }
            $code = self::sh('tar -C ' . escapeshellarg($tmp) . ' -czf ' . escapeshellarg("$dir/mail.tar.gz") . ' .', $out);
            self::sh('rm -rf ' . escapeshellarg($tmp), $o2);
            if ($code !== 0) {
                throw new RuntimeException('Archiving the mail failed: ' . mb_substr($out, -300));
            }
        }

        file_put_contents("$dir/meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        foreach (glob("$dir/*") ?: [] as $f) {
            chown($f, 'root');
            chgrp($f, 'frankenphp');
            chmod($f, 0640);
        }
        return ['dir' => $dir, 'meta' => $meta];
    }

    /**
     * One mbox per folder (plus <folder>.json: each message's date and
     * flags, in order). @return array<string,string> folder => file stem
     */
    private static function dumpMailbox(string $address, string $dir): array
    {
        self::mkdirs($dir, 0700, 'root');
        $imap = new ImapClient($address . '%' . Config::MAIL_ADMIN_USER, Config::MAIL_ADMIN_PASS);
        $folders = [];
        foreach ($imap->folders() as $i => $folder) {
            $stem = sprintf('%03d', $i);
            $fh = fopen("$dir/$stem.mbox", 'wb');
            $info = [];
            $imap->each($folder, function (string $raw, string $date, array $flags) use ($fh, &$info): void {
                $raw = str_replace("\r\n", "\n", $raw);
                fwrite($fh, 'From MAILER-DAEMON ' . date('D M j H:i:s Y', strtotime($date) ?: time()) . "\n"
                    . preg_replace('/^(>*From )/m', '>$1', $raw) . (str_ends_with($raw, "\n") ? '' : "\n") . "\n");
                $info[] = [$date, $flags];
            });
            fclose($fh);
            file_put_contents("$dir/$stem.json", json_encode(['folder' => $folder, 'messages' => $info]));
            $folders[$folder] = $stem;
        }
        return $folders;
    }

    /** @return array{dir:string, meta:array} */
    private static function backupServer(int $id, callable $note): array
    {
        $dir = self::DIR . '/_server/' . date('Ymd-His');
        self::mkdirs($dir, 0700, 'root');
        $note('panel database');
        self::withRootCnf(function (string $cnf) use ($dir): void {
            $code = self::sh('set -o pipefail; mysqldump --defaults-extra-file=' . escapeshellarg($cnf) . ' --single-transaction --quick --routines --triggers --events '
                . escapeshellarg(Config::DB_NAME) . ' | gzip -c > ' . escapeshellarg("$dir/panel.sql.gz"), $out);
            if ($code !== 0) {
                throw new RuntimeException('Dumping the panel database failed: ' . mb_substr($out, -300));
            }
        });
        $paths = ['/etc/frankenphp', '/etc/jinnpanel', '/var/lib/frankenphp/site-ini', '/var/lib/frankenphp/site-rules', '/var/lib/frankenphp/sites-enabled',
            '/etc/stalwart', '/etc/sftpgo', '/var/lib/sftpgo', '/var/lib/knot', '/etc/knot', '/root/.jinnpanel', '/etc/fail2ban/jail.d', '/etc/valkey',
            '/etc/my.cnf.d', '/etc/php-zts', '/etc/systemd/system', '/opt/jinnpanel-webmail/current/.env'];
        $paths = array_values(array_filter($paths, 'file_exists'));
        $note('server configuration');
        $code = self::sh('tar --acls -czf ' . escapeshellarg("$dir/config.tar.gz") . ' ' . implode(' ', array_map('escapeshellarg', $paths)), $out);
        if ($code > 1) {
            throw new RuntimeException('Archiving the configuration failed: ' . mb_substr($out, -300));
        }
        foreach (glob("$dir/*") ?: [] as $f) {
            chmod($f, 0600);
        }
        return ['dir' => $dir, 'meta' => ['version' => 1, 'created' => date('c'), 'paths' => $paths]];
    }

    /**
     * Restores the chosen parts into the account as it is now: site files
     * of domains it still has (written over, nothing deleted), databases it
     * still owns (replaced with the dump), mail of mailboxes it still has
     * (messages already there are skipped). @return list<string> domains whose files were restored
     */
    public static function restore(int $id, array $parts, callable $log): array
    {
        $b = self::find($id);
        if (!$b || $b['kind'] !== 'account' || $b['user_id'] === null) {
            throw new RuntimeException("Backup #$id can't be restored");
        }
        $pdo = Database::app();
        $userId = (int) $b['user_id'];
        $dir = (string) $b['path'];
        $meta = json_decode((string) @file_get_contents("$dir/meta.json"), true);
        if (!is_array($meta) || ($meta['username'] ?? '') !== $b['username']) {
            throw new RuntimeException('The backup\'s meta.json is missing or doesn\'t match.');
        }
        $lines = explode("\n", (string) $b['log']);
        $note = function (string $m) use (&$lines, $log, $pdo, $id): void {
            $lines[] = '[' . date('H:i:s') . "] restore: $m";
            $log($m);
            $pdo->prepare('UPDATE backups SET log = ? WHERE id = ?')->execute([implode("\n", array_slice($lines, -200)), $id]);
        };
        $restoredSites = [];
        try {
            if (in_array('files', $parts, true) && is_file("$dir/files.tar.gz")) {
                $d = $pdo->prepare('SELECT domain_name FROM domains WHERE user_id = ?');
                $d->execute([$userId]);
                $mine = $d->fetchAll(PDO::FETCH_COLUMN);
                $sites = array_values(array_intersect((array) $meta['domains'], $mine));
                if ($sites) {
                    $note('site files: ' . implode(', ', $sites));
                    // Root only reads the archive; tar runs as the account and
                    // writes with its rights - the account controls these
                    // folders, so root must not follow paths inside them.
                    $code = self::sh('set -o pipefail; cat ' . escapeshellarg("$dir/files.tar.gz") . ' | runuser -u ' . escapeshellarg(Usernames::linuxUser((string) $b['username']))
                        . ' -- tar -xzf - -C ' . escapeshellarg(Config::VHOSTS_DOCROOT_BASE) . ' --no-same-owner --no-overwrite-dir '
                        . implode(' ', array_map('escapeshellarg', $sites)), $out);
                    if ($code !== 0) {
                        throw new RuntimeException('Extracting the site files failed: ' . mb_substr($out, -300));
                    }
                    $restoredSites = $sites;
                }
            }
            if (in_array('databases', $parts, true)) {
                $owned = $pdo->prepare('SELECT db_name FROM db_instances WHERE user_id = ?');
                $owned->execute([$userId]);
                $mine = $owned->fetchAll(PDO::FETCH_COLUMN);
                foreach ((array) $meta['databases'] as $db) {
                    if (!in_array($db, $mine, true) || !is_file("$dir/db-$db.sql.gz")) {
                        $note("database $db: skipped (not in the account any more)");
                        continue;
                    }
                    $note("database $db");
                    self::withRootCnf(function (string $cnf) use ($db, $dir): void {
                        $code = self::sh('set -o pipefail; gunzip -c ' . escapeshellarg("$dir/db-$db.sql.gz") . ' | mysql --defaults-extra-file=' . escapeshellarg($cnf) . ' ' . escapeshellarg((string) $db), $out);
                        if ($code !== 0) {
                            throw new RuntimeException("Restoring $db failed: " . mb_substr($out, -300));
                        }
                    });
                }
            }
            if (in_array('mail', $parts, true) && is_file("$dir/mail.tar.gz") && !empty($meta['mailboxes'])) {
                $tmp = trim((string) shell_exec('mktemp -d /root/.jinnpanel-restore-XXXXXXXX'));
                if ($tmp === '' || !is_dir($tmp) || self::sh('tar -C ' . escapeshellarg($tmp) . ' -xzf ' . escapeshellarg("$dir/mail.tar.gz"), $out) !== 0) {
                    throw new RuntimeException('Unpacking the mail failed: ' . mb_substr((string) $out, -300));
                }
                $m = $pdo->prepare("SELECT CONCAT(e.local_part, '@', d.domain_name) FROM email_accounts e JOIN domains d ON d.id = e.domain_id WHERE e.user_id = ?");
                $m->execute([$userId]);
                $mine = $m->fetchAll(PDO::FETCH_COLUMN);
                try {
                    foreach ((array) $meta['mailboxes'] as $address => $folders) {
                        if (!in_array($address, $mine, true)) {
                            $note("mailbox $address: skipped (not in the account any more)");
                            continue;
                        }
                        $n = self::restoreMailbox((string) $address, "$tmp/" . self::safeName((string) $address), (array) $folders);
                        $note("mailbox $address: $n message(s) put back");
                    }
                } finally {
                    self::sh('rm -rf ' . escapeshellarg($tmp), $o2);
                }
            }
            $note('done');
        } finally {
            $pdo->prepare("UPDATE backups SET status = 'done' WHERE id = ?")->execute([$id]);
        }
        return $restoredSites;
    }

    private static function restoreMailbox(string $address, string $dir, array $folders): int
    {
        $imap = new ImapClient($address . '%' . Config::MAIL_ADMIN_USER, Config::MAIL_ADMIN_PASS);
        $n = 0;
        foreach ($folders as $folder => $stem) {
            if (!preg_match('/^\d{3}$/', (string) $stem) || !is_file("$dir/$stem.mbox")) {
                continue;
            }
            $info = json_decode((string) @file_get_contents("$dir/$stem.json"), true)['messages'] ?? [];
            $imap->ensureFolder((string) $folder);
            $have = array_flip($imap->messageIds((string) $folder));
            $i = 0;
            self::eachMboxMessage("$dir/$stem.mbox", function (string $raw) use ($imap, $folder, &$have, &$i, &$n, $info): void {
                [$date, $flags] = $info[$i++] ?? ['', []];
                if (preg_match('/^Message-ID:\s*(\S+)/im', substr($raw, 0, 65536), $mid) && isset($have[$mid[1]])) {
                    return;
                }
                $imap->append((string) $folder, str_replace("\n", "\r\n", $raw), (string) $date, (array) $flags);
                $n++;
            });
        }
        return $n;
    }

    /** Calls $fn(raw message) for each message of an mbox written by dumpMailbox(). */
    private static function eachMboxMessage(string $file, callable $fn): void
    {
        $fh = fopen($file, 'rb');
        $msg = null;
        while (($line = fgets($fh)) !== false) {
            if (str_starts_with($line, 'From MAILER-DAEMON ')) {
                if ($msg !== null) {
                    $fn(substr($msg, 0, -1)); // the separating blank line
                }
                $msg = '';
                continue;
            }
            if ($msg !== null) {
                $msg .= preg_replace('/^>(>*From )/', '$1', $line);
            }
        }
        if ($msg !== null && $msg !== '') {
            $fn(substr($msg, 0, -1));
        }
        fclose($fh);
    }

    /** Hourly (hostpanel-worker): at the configured hour, back up everything once a day. */
    public static function dueNow(): bool
    {
        $s = self::settings();
        if (!$s['enabled'] || (int) date('G') !== $s['hour']) {
            return false;
        }
        $last = Database::app()->query("SELECT MAX(created_at) FROM backups WHERE kind = 'server'")->fetchColumn();
        return !$last || strtotime((string) $last) < time() - 20 * 3600;
    }

    /** The daily run (`backup-all`): every hosting account, then the server. */
    public static function runAll(callable $log): void
    {
        $ids = Database::app()->query("SELECT id FROM users WHERE role = 'user' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $userId) {
            try {
                $id = self::queueDirect((int) $userId);
                self::execute($id, $log);
            } catch (Throwable $e) {
                $log("account #$userId: " . $e->getMessage());
            }
        }
        try {
            self::execute(self::queueDirect(null), $log);
        } catch (Throwable $e) {
            $log('server: ' . $e->getMessage());
        }
    }

    /** A backups row for the daily run (no worker job - it runs right here). */
    private static function queueDirect(?int $userId): int
    {
        $pdo = Database::app();
        $username = '';
        if ($userId !== null) {
            $s = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $s->execute([$userId]);
            $username = (string) $s->fetchColumn();
        }
        $pdo->prepare("INSERT INTO backups (kind, user_id, username, status) VALUES (?, ?, ?, 'queued')")
            ->execute([$userId === null ? 'server' : 'account', $userId, $username]);
        return (int) $pdo->lastInsertId();
    }

    /** Keeps the newest `keep` finished backups of the account (or the server); older ones go, locally and off-site. */
    private static function prune(?int $userId, callable $note): void
    {
        $keep = self::settings()['keep'];
        $pdo = Database::app();
        $s = $pdo->prepare('SELECT * FROM backups WHERE ' . ($userId === null ? "kind = 'server'" : 'user_id = ?') . " AND status IN ('done','failed') ORDER BY id DESC");
        $s->execute($userId === null ? [] : [$userId]);
        $done = 0;
        foreach ($s->fetchAll() as $b) {
            if ($b['status'] === 'done' && ++$done <= $keep) {
                continue;
            }
            if ($b['status'] === 'failed' && strtotime((string) $b['created_at']) > time() - 7 * 86400) {
                continue; // recent failures stay visible for a week
            }
            $dir = (string) $b['path'];
            if ($dir !== '' && str_starts_with($dir, self::DIR . '/') && is_dir($dir)) {
                self::sh('rm -rf ' . escapeshellarg($dir), $out);
            }
            if ($b['remote'] === 'uploaded' && ($c = self::s3()) !== null) {
                [$client, $prefix] = $c;
                foreach ($client->listObjects(self::remotePrefix($prefix, $b)) as $o) {
                    try {
                        $client->delete($o['key']);
                    } catch (Throwable) {
                    }
                }
            }
            $pdo->prepare('DELETE FROM backups WHERE id = ?')->execute([$b['id']]);
            $note('removed old backup #' . $b['id']);
        }
    }

    private static function offsite(int $id, string $dir, callable $note): void
    {
        $c = self::s3();
        if ($c === null) {
            return;
        }
        [$client, $prefix] = $c;
        $b = self::find($id);
        try {
            foreach (glob("$dir/*") ?: [] as $f) {
                $client->upload(self::remotePrefix($prefix, $b) . basename($f), $f);
            }
            Database::app()->prepare("UPDATE backups SET remote = 'uploaded' WHERE id = ?")->execute([$id]);
            $note('off-site copy uploaded (' . self::remotePrefix($prefix, $b) . ')');
        } catch (Throwable $e) {
            Database::app()->prepare("UPDATE backups SET remote = 'failed' WHERE id = ?")->execute([$id]);
            $note('off-site upload failed: ' . $e->getMessage());
        }
    }

    private static function remotePrefix(string $prefix, array $b): string
    {
        $who = $b['kind'] === 'server' ? '_server' : (string) $b['username'];
        return ($prefix !== '' ? "$prefix/" : '') . $who . '/' . basename((string) $b['path']) . '/';
    }

    /** @return array{0:S3Client,1:string}|null */
    private static function s3(): ?array
    {
        $s3 = self::settings()['s3'];
        if ($s3 === null || empty($s3['secret_enc'])) {
            return null;
        }
        return [new S3Client($s3['endpoint'], $s3['region'], $s3['bucket'], $s3['access_key'], Crypto::decrypt($s3['secret_enc'])), (string) $s3['prefix']];
    }

    /** Runs $fn with a temporary MySQL option file for root (root's password from install.sh). */
    private static function withRootCnf(callable $fn): void
    {
        $pw = trim((string) @file_get_contents(self::ROOT_PW));
        if ($pw === '') {
            throw new RuntimeException('The MariaDB root password file is missing (' . self::ROOT_PW . ').');
        }
        $cnf = tempnam('/root', '.jp-my-');
        chmod($cnf, 0600);
        file_put_contents($cnf, "[client]\nuser=root\npassword=\"" . addcslashes($pw, "\"\\") . "\"\n");
        try {
            $fn($cnf);
        } finally {
            @unlink($cnf);
        }
    }

    private static function sh(string $cmd, ?string &$out): int
    {
        exec('/bin/bash -c ' . escapeshellarg($cmd) . ' 2>&1', $lines, $code);
        $out = implode("\n", $lines);
        return $code;
    }

    /**
     * Creates $dir (and DIR above it) root-owned: account backups are
     * group frankenphp so the panel can offer downloads, the server's
     * (_server/, holding every service's secrets) are root's only.
     */
    private static function mkdirs(string $dir, int $mode, string $group): void
    {
        foreach ([self::DIR, dirname($dir), $dir] as $p) {
            if (!is_dir($p)) {
                mkdir($p, 0700, true);
            }
            $private = $group === 'root' && $p !== self::DIR;
            chown($p, 'root');
            chgrp($p, $private ? 'root' : 'frankenphp');
            chmod($p, $p === $dir ? $mode : ($private ? 0700 : 0750));
        }
    }

    private static function safeName(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9@._-]/', '_', $s);
    }

    private static function bytes(int $b): string
    {
        return function_exists('fmt_bytes') ? fmt_bytes($b) : round($b / 1048576, 1) . ' MB';
    }
}
