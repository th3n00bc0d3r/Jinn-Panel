<?php
declare(strict_types=1);

/**
 * Measures what each hosting account uses, hourly (run as root by
 * hostpanel-worker): disk = its site folders + its databases + its
 * mailboxes, and bandwidth = bytes its sites served this month, from the
 * web server's access log. Results go to account_usage; Quota reads them.
 *
 * Over the disk quota, the panel refuses anything that adds data (uploads,
 * extracting, new databases/mailboxes/domains). With XFS user quotas on
 * the filesystem (install.sh JINNPANEL_XFS_QUOTA=1, then a reboot), the
 * account's Linux user also gets a hard limit, so its own PHP and SFTP stop
 * writing too. Over the monthly bandwidth, its sites answer 509 until the
 * month ends or the package grows.
 */
final class UsageService
{
    public const ACCESS_LOG = '/var/log/jinnpanel-web/access.log'; // written by Caddy (frankenphp)
    private const STATE = '/var/lib/jinnpanel/worker/.access-log-offset';

    public static function refresh(callable $log): void
    {
        $pdo = Database::app();
        $month = date('Y-m');
        $bandwidth = self::bandwidthSinceLastRun($log);
        $xfs = self::xfsQuotaOn();

        foreach ($pdo->query("SELECT u.id, u.username, p.disk_quota_mb, p.bandwidth_mb FROM users u LEFT JOIN packages p ON p.id = u.package_id WHERE u.role = 'user'")->fetchAll() as $u) {
            $id = (int) $u['id'];
            $d = $pdo->prepare('SELECT domain_name FROM domains WHERE user_id = ?');
            $d->execute([$id]);
            $domains = $d->fetchAll(PDO::FETCH_COLUMN);

            $files = 0;
            foreach ($domains as $name) {
                $files += self::du(VhostService::siteDir((string) $name));
            }
            $files += self::du(AccountRuntime::HOME . '/' . $u['username']);
            $db = self::databaseBytes($id);
            $mail = self::mailBytes($id);

            $cur = $pdo->prepare('SELECT * FROM account_usage WHERE user_id = ?');
            $cur->execute([$id]);
            $row = $cur->fetch() ?: ['bw_month' => '', 'bw_bytes' => 0, 'over_bandwidth' => 0];
            $bw = ($row['bw_month'] === $month ? (int) $row['bw_bytes'] : 0);
            foreach ($domains as $name) {
                $bw += $bandwidth[strtolower((string) $name)] ?? 0;
            }

            $diskLimit = (int) ($u['disk_quota_mb'] ?? 0) * 1048576;
            $bwLimit = (int) ($u['bandwidth_mb'] ?? 0) * 1048576;
            $overDisk = $diskLimit > 0 && ($files + $db + $mail) > $diskLimit;
            $overBw = $bwLimit > 0 && $bw > $bwLimit;

            $pdo->prepare('INSERT INTO account_usage (user_id, files_bytes, db_bytes, mail_bytes, bw_month, bw_bytes, over_disk, over_bandwidth, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                           ON DUPLICATE KEY UPDATE files_bytes = VALUES(files_bytes), db_bytes = VALUES(db_bytes), mail_bytes = VALUES(mail_bytes),
                             bw_month = VALUES(bw_month), bw_bytes = VALUES(bw_bytes), over_disk = VALUES(over_disk), over_bandwidth = VALUES(over_bandwidth), updated_at = NOW()')
                ->execute([$id, $files, $db, $mail, $month, $bw, $overDisk ? 1 : 0, $overBw ? 1 : 0]);

            if ((int) $row['over_bandwidth'] !== ($overBw ? 1 : 0)) {
                // Its sites switch to (or back from) the "bandwidth exceeded" page.
                $all = $pdo->prepare('SELECT * FROM domains WHERE user_id = ?');
                $all->execute([$id]);
                foreach ($all->fetchAll() as $dom) {
                    try {
                        VhostService::create((string) $dom['domain_name'], (string) $dom['php_version'], (string) $dom['ssl_mode'], false, false);
                    } catch (Throwable $e) {
                        $log("{$dom['domain_name']}: " . $e->getMessage());
                    }
                }
                $reload = true;
                $log("{$u['username']}: " . ($overBw ? 'over its monthly bandwidth - sites paused' : 'back within its bandwidth'));
            }
            if ($xfs) {
                // Files only (databases and mail live elsewhere), with the package quota as the hard limit.
                exec('xfs_quota -x -c ' . escapeshellarg('limit -u bhard=' . max(0, (int) ($u['disk_quota_mb'] ?? 0)) . 'm ' . Usernames::linuxUser((string) $u['username'])) . ' / 2>&1');
            }
        }
        if (!empty($reload)) {
            exec('frankenphp reload --config ' . escapeshellarg(Config::FRANKENPHP_CONFIG) . ' --address ' . escapeshellarg(VhostService::ADMIN_SOCKET) . ' --force 2>&1');
        }
    }

    /** Bytes on disk under $dir (du), 0 if it's missing. */
    public static function du(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        exec('du -s -B1 -x ' . escapeshellarg($dir) . ' 2>/dev/null', $out);
        return (int) strtok((string) ($out[0] ?? '0'), "\t");
    }

    private static function databaseBytes(int $userId): int
    {
        $s = Database::app()->prepare('SELECT db_name FROM db_instances WHERE user_id = ?');
        $s->execute([$userId]);
        $names = array_values(array_filter($s->fetchAll(PDO::FETCH_COLUMN), fn($n) => ProvisioningService::isValidIdentifier((string) $n)));
        if (!$names) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($names), '?'));
        $q = Database::provisioning()->prepare("SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ($in)");
        $q->execute($names);
        return (int) $q->fetchColumn();
    }

    private static function mailBytes(int $userId): int
    {
        $s = Database::app()->prepare('SELECT mail_account_id FROM email_accounts WHERE user_id = ? AND mail_account_id IS NOT NULL');
        $s->execute([$userId]);
        $ids = $s->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) {
            return 0;
        }
        try {
            return MailService::usedBytes(array_map('strval', $ids));
        } catch (Throwable $e) {
            error_log('mail usage: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Response bytes per host since the last run, from the JSON access log
     * every site writes (VhostService). Remembers where it stopped; starts
     * over when the log was rotated.
     *
     * @return array<string,int> domain (www. and aliases folded in) => bytes
     */
    private static function bandwidthSinceLastRun(callable $log): array
    {
        if (!is_file(self::ACCESS_LOG)) {
            return [];
        }
        $state = json_decode((string) @file_get_contents(self::STATE), true) ?: [];
        $ino = (int) fileinode(self::ACCESS_LOG);
        $offset = ((int) ($state['ino'] ?? 0) === $ino) ? (int) ($state['offset'] ?? 0) : 0;
        if ($offset > (int) filesize(self::ACCESS_LOG)) {
            $offset = 0;
        }
        $aliases = [];
        foreach (Database::app()->query('SELECT a.alias_name, d.domain_name FROM domain_aliases a JOIN domains d ON d.id = a.domain_id')->fetchAll() as $a) {
            $aliases[strtolower((string) $a['alias_name'])] = strtolower((string) $a['domain_name']);
        }
        $fh = fopen(self::ACCESS_LOG, 'rb');
        fseek($fh, $offset);
        $bytes = [];
        $lines = 0;
        while (($line = fgets($fh)) !== false) {
            if (!str_ends_with($line, "\n")) {
                break; // being written - next run
            }
            $offset += strlen($line);
            $lines++;
            $e = json_decode($line, true);
            if (!is_array($e)) {
                continue;
            }
            $host = strtolower(preg_replace('/:\d+$/', '', (string) ($e['request']['host'] ?? '')));
            $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
            $host = $aliases[$host] ?? $host;
            $bytes[$host] = ($bytes[$host] ?? 0) + (int) ($e['size'] ?? 0);
        }
        fclose($fh);
        file_put_contents(self::STATE, json_encode(['ino' => $ino, 'offset' => $offset]));
        $log("access log: $lines request(s) counted");
        return $bytes;
    }

    /** Whether / has XFS user quotas switched on (rootflags=uquota). */
    private static function xfsQuotaOn(): bool
    {
        exec('xfs_quota -x -c "state -u" / 2>/dev/null', $out);
        $text = implode("\n", $out);
        return str_contains($text, 'Accounting: ON') && str_contains($text, 'Enforcement: ON');
    }
}
