<?php
declare(strict_types=1);

/**
 * Drops job files for hostpanel-worker.php (run every 5s by
 * hostpanel-worker.timer, as root, via systemd - deliberately NOT as a
 * child of FrankenPHP). See that script for why: FrankenPHP is sandboxed
 * (can't write to /etc) and has a verified quirk forking children that
 * connect() to Unix sockets.
 */
final class SystemWorkerService
{
    private const QUEUE_DIR = __DIR__ . '/../../storage/config-queue';
    private const LOG_DIR = __DIR__ . '/../../storage/logs';

    /** @param array<string,mixed> $job */
    public static function enqueue(string $label, array $job): string
    {
        if (!is_dir(self::QUEUE_DIR)) {
            mkdir(self::QUEUE_DIR, 02775, true);
        }
        $safeLabel = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $label);
        $id = $safeLabel . '-' . bin2hex(random_bytes(4));
        file_put_contents(self::QUEUE_DIR . "/{$id}.json", json_encode($job, JSON_PRETTY_PRINT));
        return $id;
    }

    /** Best-effort read of the worker's last log line for a job label (for UI feedback). */
    public static function lastLog(string $label): ?string
    {
        $safeLabel = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $label);
        $matches = glob(self::LOG_DIR . "/worker-{$safeLabel}*.log") ?: [];
        if (!$matches) {
            return null;
        }
        rsort($matches);
        $lines = file($matches[0]);
        return $lines ? trim(end($lines)) : null;
    }
}
