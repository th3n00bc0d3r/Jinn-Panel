<?php
declare(strict_types=1);

/**
 * Warns hosting accounts by email when they reach 90% of their disk or
 * monthly bandwidth, and when they go over (WHM > Notifications: "quota").
 * Runs after the hourly usage measurement (root worker). Each level is
 * mailed once; dropping below and climbing again warns again, and the
 * bandwidth levels start over every month.
 */
final class UsageNoticeService
{
    public static function run(callable $log): void
    {
        $pdo = Database::app();
        $month = date('Y-m');
        $on = Settings::get('notify_quota', '1') === '1';
        $rows = $pdo->query(
            "SELECT u.id, u.username, u.email, u.role, u.status, p.disk_quota_mb, p.bandwidth_mb, a.*
             FROM account_usage a JOIN users u ON u.id = a.user_id JOIN packages p ON p.id = u.package_id
             WHERE u.role = 'user'"
        )->fetchAll();
        foreach ($rows as $r) {
            $diskLimit = Quota::bytes($r['disk_quota_mb']);
            $bwLimit = Quota::bytes($r['bandwidth_mb']);
            $diskUsed = (int) $r['files_bytes'] + (int) $r['db_bytes'] + (int) $r['mail_bytes'];
            $bwUsed = $r['bw_month'] === $month ? (int) $r['bw_bytes'] : 0;
            $disk = self::level($diskUsed, $diskLimit, (bool) $r['over_disk']);
            $bw = self::level($bwUsed, $bwLimit, (bool) $r['over_bandwidth']);
            $wasDisk = (int) $r['disk_notice'];
            $wasBw = $r['bw_notice_month'] === $month ? (int) $r['bw_notice'] : 0;

            foreach ([['disk', $disk, $wasDisk, $diskUsed, $diskLimit], ['bandwidth', $bw, $wasBw, $bwUsed, $bwLimit]] as [$what, $now, $was, $used, $limit]) {
                if ($now > $was && $on && $r['status'] === 'active') {
                    $sent = NotificationService::quota($r, $what, $now, $used, $limit);
                    $log("{$r['username']}: $what at $now% - " . ($sent ? 'emailed' : 'email failed'));
                }
            }
            if ($disk !== $wasDisk || $bw !== $wasBw || $r['bw_notice_month'] !== $month) {
                $pdo->prepare('UPDATE account_usage SET disk_notice = ?, bw_notice = ?, bw_notice_month = ? WHERE user_id = ?')
                    ->execute([$disk, $bw, $month, $r['id']]);
            }
        }
    }

    /** 0, 90 or 100 for a usage against a limit (0 = unlimited). */
    public static function level(int $used, int $limit, bool $over = false): int
    {
        if ($limit <= 0) {
            return 0;
        }
        if ($over || $used > $limit) {
            return 100;
        }
        return $used >= $limit * 0.9 ? 90 : 0;
    }
}
