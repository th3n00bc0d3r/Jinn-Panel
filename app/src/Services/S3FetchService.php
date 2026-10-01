<?php
declare(strict_types=1);

/**
 * WHM > cPanel Migration > From backup files > Fetch from S3: copies cPanel
 * backup archives from an S3 bucket (AWS or S3-compatible) into the
 * migration import folder, in the background (s3-fetch.php, a transient
 * systemd unit started by the root worker). The secret key is stored
 * encrypted only while the fetch runs.
 */
final class S3FetchService
{
    /** Archive names the import folder accepts (MigrationService::backupFile). */
    private const NAME_RE = '/^(?:cpmove-|backup-[0-9._-]+_)?[a-z][a-z0-9_]{2,31}\.tar\.gz$/';

    /** @param array<string,mixed> $in endpoint, region, bucket, prefix, access_key, secret_key */
    public static function create(array $me, array $in): int
    {
        if ($me['role'] !== 'admin') {
            throw new RuntimeException('Only an admin can import backup files.');
        }
        $region = trim((string) ($in['region'] ?? '')) ?: 'us-east-1';
        $endpoint = rtrim(trim((string) ($in['endpoint'] ?? '')), '/') ?: "https://s3.$region.amazonaws.com";
        $bucket = trim((string) ($in['bucket'] ?? ''));
        $prefix = ltrim(trim((string) ($in['prefix'] ?? '')), '/');
        $key = trim((string) ($in['access_key'] ?? ''));
        $secret = trim((string) ($in['secret_key'] ?? ''));
        if (!preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#i', $endpoint)) {
            throw new InvalidArgumentException('Endpoint must look like https://s3.eu-west-1.amazonaws.com (no path).');
        }
        if (!preg_match('/^[a-z0-9-]{1,32}$/', $region)) {
            throw new InvalidArgumentException('Region must look like eu-west-1 (or "auto" for Cloudflare R2).');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket)) {
            throw new InvalidArgumentException('Enter a valid bucket name.');
        }
        if (strlen($prefix) > 1024 || preg_match('/[\x00-\x1f]/', $prefix)) {
            throw new InvalidArgumentException('Invalid prefix.');
        }
        if ($key === '' || $secret === '' || strlen($key) > 255 || strlen($secret) > 255) {
            throw new InvalidArgumentException('Enter the access key and secret key.');
        }
        // Fail now rather than in the background on wrong credentials or bucket.
        $found = array_filter((new S3Client($endpoint, $region, $bucket, $key, $secret))->listObjects($prefix), fn($o) => self::wanted($o['key']));
        if (!$found) {
            throw new InvalidArgumentException("No cPanel backups (cpmove-<user>.tar.gz, backup-<date>_<user>.tar.gz) under s3://$bucket/$prefix.");
        }
        $pdo = Database::app();
        $pdo->prepare('INSERT INTO s3_fetches (created_by, endpoint, region, bucket, prefix, access_key, secret_enc, progress) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$me['id'], $endpoint, $region, $bucket, $prefix, $key, Crypto::encrypt($secret), count($found) . ' archive(s) to fetch']);
        $id = (int) $pdo->lastInsertId();
        SystemWorkerService::enqueue("s3-fetch-$id", ['type' => 's3_fetch', 'fetch_id' => $id]);
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 10): array
    {
        return Database::app()->query('SELECT id, endpoint, bucket, prefix, status, progress, log, created_at, finished_at FROM s3_fetches ORDER BY id DESC LIMIT ' . max(1, $limit))->fetchAll();
    }

    /** The background part (s3-fetch.php). */
    public static function run(int $id): int
    {
        $pdo = Database::app();
        $s = $pdo->prepare("SELECT * FROM s3_fetches WHERE id = ? AND status IN ('queued', 'running')");
        $s->execute([$id]);
        $f = $s->fetch();
        if (!$f || empty($f['secret_enc'])) {
            return 0;
        }
        $log = [];
        $set = function (array $fields) use ($pdo, $id, &$log): void {
            $fields['log'] = implode("\n", array_slice($log, -200));
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
            $pdo->prepare("UPDATE s3_fetches SET $sets WHERE id = ?")->execute([...array_values($fields), $id]);
        };
        $set(['status' => 'running', 'progress' => 'Listing the bucket']);
        $status = 'done';
        try {
            $client = new S3Client((string) $f['endpoint'], (string) $f['region'], (string) $f['bucket'], (string) $f['access_key'], Crypto::decrypt((string) $f['secret_enc']));
            $dir = MigrationService::importDir();
            $objects = array_values(array_filter($client->listObjects((string) $f['prefix']), fn($o) => self::wanted($o['key'])));
            foreach ($objects as $i => $o) {
                $name = basename($o['key']);
                $dest = "$dir/$name";
                $label = ($i + 1) . '/' . count($objects) . " $name";
                if (is_file($dest) && filesize($dest) === $o['size']) {
                    $log[] = "$name: already here (same size), skipped";
                    continue;
                }
                $free = (int) @disk_free_space($dir);
                if ($free > 0 && $free < $o['size'] + 1073741824) {
                    throw new RuntimeException("Not enough disk space for $name (" . round($o['size'] / 1073741824, 1) . ' GB, ' . round($free / 1073741824, 1) . ' GB free).');
                }
                $last = 0;
                $client->download($o['key'], $dest, function (int $done, int $total) use (&$last, $set, $label): void {
                    if (time() - $last >= 5) {
                        $last = time();
                        $set(['progress' => "$label: " . round($done / 1048576) . ' / ' . round(max($total, 1) / 1048576) . ' MB']);
                    }
                });
                @chmod($dest, 0640);
                $log[] = "$name: fetched (" . round($o['size'] / 1048576, 1) . ' MB)';
                $set(['progress' => "$label: done"]);
            }
            $progress = count($objects) . ' archive(s) in ' . $dir . ' - choose the accounts below.';
        } catch (Throwable $e) {
            $status = 'failed';
            $log[] = 'FAILED: ' . $e->getMessage();
            $progress = 'Failed: ' . $e->getMessage();
        }
        $set(['status' => $status, 'progress' => mb_substr($progress, 0, 250), 'secret_enc' => null, 'finished_at' => date('Y-m-d H:i:s')]);
        return $status === 'done' ? 0 : 1;
    }

    private static function wanted(string $key): bool
    {
        return preg_match(self::NAME_RE, basename($key)) === 1;
    }
}
