<?php
declare(strict_types=1);

/**
 * Collects the statistics behind the dashboards and the Stats tab. Runs as
 * root in hostpanel-worker (periodicTasks):
 *
 *  - sample()  every minute: load, CPU, memory, disk and network of the
 *              server (stat_server) and whether each service answers
 *              (stat_service_checks);
 *  - ingest()  every 5 minutes: the new part of the web access log, added
 *              up per domain and hour (stat_domain_hourly), top pages and
 *              referrers per day (stat_domain_top), served bytes and
 *              requests per account and day (stat_account_daily). The
 *              bytes also go to UsageService for monthly bandwidth;
 *  - recordDisk() with the hourly usage run: each account's disk use today;
 *  - prune()   nightly: drops old rows, keeps the 50 biggest pages and
 *              referrers of past days, forgets yesterday's visitors and salt.
 *
 * Visitors are counted without storing addresses (see migration 026).
 */
final class StatsCollector
{
    /** Root-only state: where the access log was read up to, CPU/network counters, the day's salt. */
    public static string $stateDir = '/var/lib/jinnpanel/worker';
    public static string $saltFile = '/var/lib/jinnpanel/stats-salt';
    public static string $accessLog = UsageService::ACCESS_LOG;

    public const KEEP_SERVER_DAYS = 32;
    public const KEEP_DOMAIN_DAYS = 95;
    public const KEEP_ACCOUNT_DAYS = 400;
    public const KEEP_TOP = 50;

    // -----------------------------------------------------------------------
    // Every minute

    public static function sample(callable $log): void
    {
        $pdo = Database::app();
        $minute = date('Y-m-d H:i:00');
        $prev = json_decode((string) @file_get_contents(self::state('.stat-counters')), true) ?: [];
        $now = ['at' => time()];

        // CPU busy share since the last sample, from /proc/stat's first line.
        $cpu = null;
        $line = (string) (@file('/proc/stat')[0] ?? '');
        if (str_starts_with($line, 'cpu ')) {
            $f = array_map('intval', preg_split('/\s+/', trim(substr($line, 4))));
            $idle = ($f[3] ?? 0) + ($f[4] ?? 0);
            $total = array_sum(array_slice($f, 0, 8));
            $now['cpu'] = [$total - $idle, $total];
            if (isset($prev['cpu']) && $total > $prev['cpu'][1]) {
                $cpu = round(max(0, min(100, ($now['cpu'][0] - $prev['cpu'][0]) / ($total - $prev['cpu'][1]) * 100)), 2);
            }
        }
        // Network bytes since the last sample, all interfaces but loopback.
        [$rx, $tx] = [null, null];
        $dev = @file('/proc/net/dev');
        if ($dev) {
            $r = $t = 0;
            foreach (array_slice($dev, 2) as $l) {
                [$name, $rest] = array_map('trim', explode(':', $l, 2)) + [1 => ''];
                if ($name === 'lo' || $rest === '') {
                    continue;
                }
                $v = preg_split('/\s+/', $rest);
                $r += (int) ($v[0] ?? 0);
                $t += (int) ($v[8] ?? 0);
            }
            $now['net'] = [$r, $t];
            if (isset($prev['net']) && $r >= $prev['net'][0] && $t >= $prev['net'][1] && time() - (int) ($prev['at'] ?? 0) < 600) {
                [$rx, $tx] = [$r - $prev['net'][0], $t - $prev['net'][1]];
            }
        }
        @file_put_contents(self::state('.stat-counters'), json_encode($now));

        $s = ServerStatus::machine();
        $pdo->prepare('INSERT IGNORE INTO stat_server (at, load1, cpu_pct, mem_used, mem_total, disk_used, disk_total, net_rx, net_tx) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$minute, round((float) $s['load'][0], 2), $cpu, $s['mem_used'], $s['mem_total'], $s['disk_used'], $s['disk_total'], $rx, $tx]);

        $ins = $pdo->prepare('INSERT IGNORE INTO stat_service_checks (service, at, up, ms) VALUES (?, ?, ?, ?)');
        foreach (ServerStatus::SERVICES as $svc => $port) {
            $t0 = microtime(true);
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            if ($conn) {
                fclose($conn);
            }
            $ins->execute([$svc, $minute, $conn ? 1 : 0, $conn ? min(65535, $ms) : null]);
        }
    }

    // -----------------------------------------------------------------------
    // Every 5 minutes

    /**
     * Reads the access log from where the last run stopped (following a
     * rotation to the rolled file first), for at most $budget seconds.
     *
     * @return array{lines:int, counted:int, seconds:float, more:bool}
     */
    public static function ingest(callable $log, float $budget = 30.0): array
    {
        $t0 = microtime(true);
        $result = ['lines' => 0, 'counted' => 0, 'seconds' => 0.0, 'more' => false];
        if (!is_file(self::$accessLog)) {
            return $result;
        }
        // One reader at a time (the worker, and "usage" run by hand or at boot).
        $lock = @fopen(self::state('.access-log.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $result;
        }
        [$hosts, $owner, $names] = self::hosts();
        $agg = new AccessLogStats($hosts, self::visitorHasher());
        $state = json_decode((string) @file_get_contents(self::state('.access-log-offset')), true) ?: [];
        $ino = (int) fileinode(self::$accessLog);
        $offset = (int) ($state['offset'] ?? 0);

        if ((int) ($state['ino'] ?? 0) !== $ino) {
            // Rotated since the last run: finish the rolled file, then start the new one at 0.
            if ($offset > 0 && ($rolled = self::rolledFile((int) ($state['ino'] ?? 0), (int) ($state['at'] ?? 0))) !== null) {
                self::readFrom($rolled, $offset, $agg, $t0 + $budget);
            }
            $offset = 0;
        }
        if ($offset > (int) filesize(self::$accessLog)) {
            $offset = 0; // truncated
        }
        [$offset, $more] = self::readFrom(self::$accessLog, $offset, $agg, $t0 + $budget);

        self::write($agg, $owner);
        file_put_contents(self::state('.access-log-offset'), json_encode(['ino' => $ino, 'offset' => $offset, 'at' => time()]));
        self::addBandwidth($agg->bytes(), $names);

        $result = ['lines' => $agg->lines, 'counted' => $agg->counted, 'seconds' => round(microtime(true) - $t0, 3), 'more' => $more];
        if ($agg->lines > 0) {
            $log("access log: {$agg->lines} line(s), {$agg->counted} counted, in {$result['seconds']} s" . ($more ? ' (more to read)' : ''));
        }
        return $result;
    }

    /**
     * Bytes served per domain name since the last call (UsageService adds
     * them to each account's month), then forgets them.
     *
     * @return array<string,int>
     */
    public static function takeBandwidth(): array
    {
        $fh = @fopen(self::state('.bandwidth-pending'), 'c+');
        if (!$fh) {
            return [];
        }
        flock($fh, LOCK_EX);
        $data = json_decode((string) stream_get_contents($fh), true) ?: [];
        ftruncate($fh, 0);
        flock($fh, LOCK_UN);
        fclose($fh);
        return array_map('intval', is_array($data) ? $data : []);
    }

    // -----------------------------------------------------------------------
    // Hourly, with the usage run

    public static function recordDisk(int $userId, int $files, int $db, int $mail): void
    {
        Database::app()->prepare('INSERT INTO stat_account_daily (user_id, day, disk_bytes, files_bytes, db_bytes, mail_bytes) VALUES (?, ?, ?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE disk_bytes = VALUES(disk_bytes), files_bytes = VALUES(files_bytes), db_bytes = VALUES(db_bytes), mail_bytes = VALUES(mail_bytes)')
            ->execute([$userId, date('Y-m-d'), $files + $db + $mail, $files, $db, $mail]);
    }

    // -----------------------------------------------------------------------
    // Nightly

    public static function prune(callable $log): void
    {
        $pdo = Database::app();
        // PHP's clock, not NOW(): rows hold PHP's local time (the database's zone may differ).
        $today = date('Y-m-d');
        $days = fn(int $n) => date('Y-m-d', strtotime("-$n day"));
        $n = 0;
        foreach ([
            ['DELETE FROM stat_server WHERE at < ?', $days(self::KEEP_SERVER_DAYS)],
            ['DELETE FROM stat_service_checks WHERE at < ?', $days(self::KEEP_SERVER_DAYS)],
            ['DELETE FROM stat_domain_hourly WHERE hour < ?', $days(self::KEEP_DOMAIN_DAYS)],
            ['DELETE FROM stat_domain_top WHERE day < ?', $days(self::KEEP_DOMAIN_DAYS)],
            ['DELETE FROM stat_account_daily WHERE day < ?', $days(self::KEEP_ACCOUNT_DAYS)],
            ['DELETE FROM stat_visitors WHERE day < ?', $today],
        ] as [$sql, $before]) {
            $st = $pdo->prepare($sql);
            $st->execute([$before]);
            $n += $st->rowCount();
        }

        // Past days keep their biggest pages and referrers; the rest become "(other)".
        $g = $pdo->prepare('SELECT domain_id, day, kind FROM stat_domain_top WHERE day < ? AND value <> ? GROUP BY domain_id, day, kind HAVING COUNT(*) > ' . self::KEEP_TOP);
        $g->execute([$today, AccessLogStats::OTHER]);
        $groups = $g->fetchAll();
        foreach ($groups as $g) {
            $rows = $pdo->prepare('SELECT value, hits FROM stat_domain_top WHERE domain_id = ? AND day = ? AND kind = ? AND value <> ? ORDER BY hits DESC, value LIMIT 100000 OFFSET ' . self::KEEP_TOP);
            $rows->execute([$g['domain_id'], $g['day'], $g['kind'], AccessLogStats::OTHER]);
            $drop = $rows->fetchAll();
            $pdo->beginTransaction();
            $del = $pdo->prepare('DELETE FROM stat_domain_top WHERE domain_id = ? AND day = ? AND kind = ? AND value = ?');
            foreach ($drop as $d) {
                $del->execute([$g['domain_id'], $g['day'], $g['kind'], $d['value']]);
            }
            $pdo->prepare('INSERT INTO stat_domain_top (domain_id, day, kind, value, hits) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)')
                ->execute([$g['domain_id'], $g['day'], $g['kind'], AccessLogStats::OTHER, array_sum(array_column($drop, 'hits'))]);
            $pdo->commit();
            $n += count($drop);
        }

        // Yesterday's salt goes: its hashes can no longer be matched to anyone.
        $salt = json_decode((string) @file_get_contents(self::$saltFile), true) ?: [];
        $kept = array_filter($salt, fn($k) => $k >= $today, ARRAY_FILTER_USE_KEY);
        if (count($kept) !== count($salt)) {
            self::writeSalt($kept);
        }
        $log("statistics: $n old row(s) removed");
    }

    // -----------------------------------------------------------------------

    /**
     * Every host name a site answers to (with and without www., aliases).
     *
     * @return array{0:array<string,int>, 1:array<int,int>, 2:array<int,string>} host => domain id, domain id => account id, domain id => name
     */
    public static function hosts(): array
    {
        $hosts = $owner = $names = [];
        foreach (Database::app()->query('SELECT id, user_id, domain_name FROM domains')->fetchAll() as $d) {
            $name = strtolower((string) $d['domain_name']);
            $id = (int) $d['id'];
            $hosts[$name] = $hosts['www.' . $name] = $id;
            $owner[$id] = (int) $d['user_id'];
            $names[$id] = $name;
        }
        foreach (Database::app()->query('SELECT domain_id, alias_name FROM domain_aliases')->fetchAll() as $a) {
            $alias = strtolower((string) $a['alias_name']);
            if (isset($owner[(int) $a['domain_id']])) {
                $hosts[$alias] = $hosts['www.' . $alias] = (int) $a['domain_id'];
            }
        }
        return [$hosts, $owner, $names];
    }

    /** fn(ip, agent, day) => 8-byte hash with that day's random salt (made on first use). */
    public static function visitorHasher(): callable
    {
        $salts = json_decode((string) @file_get_contents(self::$saltFile), true) ?: [];
        return function (string $ip, string $agent, string $day) use (&$salts): string {
            if (!isset($salts[$day])) {
                $salts[$day] = bin2hex(random_bytes(16));
                self::writeSalt($salts);
            }
            return substr(hash('sha256', $salts[$day] . "\n" . $ip . "\n" . $agent, true), 0, 8);
        };
    }

    /** @return array{0:int,1:bool} where reading stopped, and whether there is more */
    private static function readFrom(string $file, int $offset, AccessLogStats $agg, float $deadline): array
    {
        $gz = str_ends_with($file, '.gz');
        $fh = @fopen(($gz ? 'compress.zlib://' : '') . $file, 'rb');
        if (!$fh) {
            return [$offset, false];
        }
        if ($gz) {
            // A compressed rolled file can't seek: skip what was read already.
            $skip = $offset;
            while ($skip > 0 && !feof($fh)) {
                $chunk = fread($fh, min(1048576, $skip));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $skip -= strlen($chunk);
            }
        } else {
            fseek($fh, $offset);
        }
        $more = false;
        $n = 0;
        while (($line = fgets($fh)) !== false) {
            if (!str_ends_with($line, "\n")) {
                break; // still being written: next run
            }
            $offset += strlen($line);
            $agg->add($line);
            if (++$n % 2000 === 0 && microtime(true) > $deadline) {
                $more = !feof($fh);
                break;
            }
        }
        fclose($fh);
        return [$offset, $more];
    }

    /** After a rotation: the rolled file - still uncompressed with the old inode, or the newest .gz since the last run. */
    private static function rolledFile(int $ino, int $since): ?string
    {
        $dir = dirname(self::$accessLog);
        $base = pathinfo(self::$accessLog, PATHINFO_FILENAME);
        foreach (glob("$dir/$base-*.log") ?: [] as $f) {
            if ((int) @fileinode($f) === $ino) {
                return $f;
            }
        }
        $gz = array_filter(glob("$dir/$base-*.log.gz") ?: [], fn($f) => (int) @filemtime($f) >= $since);
        usort($gz, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $gz[0] ?? null;
    }

    /** @param array<int,int> $owner domain id => account id */
    private static function write(AccessLogStats $agg, array $owner): void
    {
        $hourly = $agg->hourly();
        if ($hourly === []) {
            return;
        }
        $pdo = Database::app();
        $pdo->beginTransaction();
        try {
            // Visitors: only hashes not seen today count, in the hour they first came.
            foreach ($agg->visitors() as $id => $days) {
                foreach ($days as $day => $hashes) {
                    foreach (array_chunk($hashes, 500, true) as $chunk) {
                        $in = implode(',', array_fill(0, count($chunk), '?'));
                        $q = $pdo->prepare("SELECT hash FROM stat_visitors WHERE domain_id = ? AND day = ? AND hash IN ($in)");
                        $q->execute(array_merge([$id, $day], array_map('strval', array_keys($chunk))));
                        $seen = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
                        $new = array_diff_key($chunk, $seen);
                        if ($new === []) {
                            continue;
                        }
                        $pdo->prepare('INSERT IGNORE INTO stat_visitors (domain_id, day, hash) VALUES ' . implode(',', array_fill(0, count($new), '(?, ?, ?)')))
                            ->execute(array_merge(...array_map(fn($h) => [$id, $day, (string) $h], array_keys($new))));
                        foreach ($new as $hour) {
                            $hourly[$id][$hour]['visitors'] = ($hourly[$id][$hour]['visitors'] ?? 0) + 1;
                        }
                    }
                }
            }

            $cols = ['requests', 'bytes', 'visitors', 'pages', 's2xx', 's3xx', 's4xx', 's5xx', 'time_ms', 'slow'];
            $rows = [];
            $daily = [];
            foreach ($hourly as $id => $hours) {
                foreach ($hours as $hour => $c) {
                    $row = [$id, date('Y-m-d H:00:00', $hour)];
                    foreach ($cols as $col) {
                        $row[] = (int) ($c[$col] ?? 0);
                    }
                    $rows[] = $row;
                    if (isset($owner[$id])) {
                        $k = $owner[$id] . '|' . date('Y-m-d', $hour);
                        $daily[$k] ??= [0, 0];
                        $daily[$k][0] += (int) ($c['bytes'] ?? 0);
                        $daily[$k][1] += (int) ($c['requests'] ?? 0);
                    }
                }
            }
            $update = implode(', ', array_map(fn($c) => "$c = $c + VALUES($c)", $cols));
            foreach (array_chunk($rows, 300) as $chunk) {
                $pdo->prepare('INSERT INTO stat_domain_hourly (domain_id, hour, ' . implode(', ', $cols) . ') VALUES '
                    . implode(',', array_fill(0, count($chunk), '(' . implode(',', array_fill(0, count($cols) + 2, '?')) . ')'))
                    . " ON DUPLICATE KEY UPDATE $update")
                    ->execute(array_merge(...$chunk));
            }

            $top = [];
            foreach ($agg->top() as $id => $days) {
                foreach ($days as $day => $kinds) {
                    foreach ($kinds as $kind => $values) {
                        foreach ($values as $value => $hits) {
                            $top[] = [$id, $day, $kind, (string) $value, $hits];
                        }
                    }
                }
            }
            foreach (array_chunk($top, 300) as $chunk) {
                $pdo->prepare('INSERT INTO stat_domain_top (domain_id, day, kind, value, hits) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)'))
                    . ' ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)')
                    ->execute(array_merge(...$chunk));
            }

            $acc = $pdo->prepare('INSERT INTO stat_account_daily (user_id, day, bw_bytes, requests) VALUES (?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE bw_bytes = bw_bytes + VALUES(bw_bytes), requests = requests + VALUES(requests)');
            foreach ($daily as $k => [$bytes, $requests]) {
                [$user, $day] = explode('|', $k);
                $acc->execute([(int) $user, $day, $bytes, $requests]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Puts bytes taken with takeBandwidth() but not counted back. @param array<string,int> $byName */
    public static function returnBandwidth(array $byName): void
    {
        $names = array_keys($byName);
        self::addBandwidth(array_values($byName), $names);
    }

    /** @param array<int,int> $bytes key => bytes; @param array<int,string> $names the same key => domain name */
    private static function addBandwidth(array $bytes, array $names): void
    {
        if ($bytes === []) {
            return;
        }
        $fh = @fopen(self::state('.bandwidth-pending'), 'c+');
        if (!$fh) {
            return;
        }
        flock($fh, LOCK_EX);
        $data = json_decode((string) stream_get_contents($fh), true) ?: [];
        foreach ($bytes as $id => $b) {
            if (isset($names[$id])) {
                $data[$names[$id]] = (int) ($data[$names[$id]] ?? 0) + $b;
            }
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($data));
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    private static function writeSalt(array $salts): void
    {
        $tmp = self::$saltFile . '.tmp';
        $old = umask(0077);
        file_put_contents($tmp, json_encode($salts));
        umask($old);
        @chmod($tmp, 0600);
        rename($tmp, self::$saltFile);
    }

    private static function state(string $name): string
    {
        return self::$stateDir . '/' . $name;
    }
}
