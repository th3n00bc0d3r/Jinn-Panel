<?php
declare(strict_types=1);

/**
 * Drops job files for hostpanel-worker.php (run every 5s by
 * hostpanel-worker.timer, as root, via systemd - deliberately NOT as a
 * child of FrankenPHP). See that script for why: FrankenPHP is sandboxed
 * (can't write to /etc) and has a verified quirk forking children that
 * connect() to Unix sockets.
 *
 * Each job is signed with a key derived from APP_KEY, and the worker
 * refuses unsigned, foreign-owned or linked job files. What the worker
 * writes back (job logs, log snapshots) lives in OUT_DIR, which only root
 * can write.
 */
final class SystemWorkerService
{
    private const QUEUE_DIR = __DIR__ . '/../../storage/config-queue';
    public const OUT_DIR = '/var/lib/jinnpanel/worker';

    /** @param array<string,mixed> $job */
    public static function enqueue(string $label, array $job): string
    {
        if (!is_dir(self::QUEUE_DIR)) {
            mkdir(self::QUEUE_DIR, 0770, true);
        }
        $safeLabel = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $label);
        $id = $safeLabel . '-' . bin2hex(random_bytes(4));
        $payload = json_encode($job, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = json_encode(['payload' => $payload, 'sig' => hash_hmac('sha256', $payload, self::key())], JSON_UNESCAPED_SLASHES);
        // Written under a temporary name, so the worker never reads half a job.
        $tmp = self::QUEUE_DIR . "/.{$id}.tmp";
        file_put_contents($tmp, $body);
        chmod($tmp, 0600);
        rename($tmp, self::QUEUE_DIR . "/{$id}.json");
        return $id;
    }

    private static function key(): string
    {
        return hash_hkdf('sha256', (string) Config::APP_KEY, 32, 'jinnpanel-worker-jobs-v1');
    }

    /** Whether a job with this label prefix is still waiting for the worker. */
    public static function pending(string $label): bool
    {
        $safeLabel = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $label);
        return (bool) glob(self::QUEUE_DIR . "/{$safeLabel}*.json");
    }

    /** Best-effort read of the worker's last log line for a job label (for UI feedback). */
    public static function lastLog(string $label): ?string
    {
        $safeLabel = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $label);
        $matches = glob(self::OUT_DIR . "/worker-{$safeLabel}*.log") ?: [];
        if (!$matches) {
            return null;
        }
        usort($matches, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $lines = file($matches[0]);
        return $lines ? trim(end($lines)) : null;
    }

    /** A file the worker wrote for the panel (e.g. live-mariadb.log), or null. */
    public static function output(string $name): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,200}$/', $name)) {
            return null;
        }
        $c = @file_get_contents(self::OUT_DIR . '/' . $name);
        return $c === false ? null : $c;
    }
}
