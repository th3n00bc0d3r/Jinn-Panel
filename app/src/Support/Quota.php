<?php
declare(strict_types=1);

final class Quota
{
    public static function package(int $userId): ?array
    {
        $stmt = Database::app()->prepare(
            'SELECT p.* FROM packages p JOIN users u ON u.package_id = p.id WHERE u.id = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public static function usage(int $userId): array
    {
        $pdo = Database::app();
        $count = function (string $table) use ($pdo, $userId): int {
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM $table WHERE user_id = ?");
            $stmt->execute([$userId]);
            return (int) $stmt->fetch()['c'];
        };
        return [
            'domains' => $count('domains'),
            'databases' => $count('db_instances'),
            'email_accounts' => $count('email_accounts'),
            'ftp_accounts' => $count('ftp_accounts'),
        ];
    }

    /**
     * Measured disk/bandwidth (UsageService, hourly) against the package.
     *
     * @return array{files:int, databases:int, mail:int, used:int, limit:int, over:bool, bw_used:int, bw_limit:int, bw_over:bool, updated:?string}
     */
    public static function measured(int $userId): array
    {
        $s = Database::app()->prepare('SELECT * FROM account_usage WHERE user_id = ?');
        $s->execute([$userId]);
        $u = $s->fetch() ?: [];
        $pkg = self::package($userId);
        $files = (int) ($u['files_bytes'] ?? 0);
        $db = (int) ($u['db_bytes'] ?? 0);
        $mail = (int) ($u['mail_bytes'] ?? 0);
        return [
            'files' => $files, 'databases' => $db, 'mail' => $mail, 'used' => $files + $db + $mail,
            'limit' => (int) ($pkg['disk_quota_mb'] ?? 0) * 1048576, 'over' => (bool) ($u['over_disk'] ?? false),
            'bw_used' => ($u['bw_month'] ?? '') === date('Y-m') ? (int) ($u['bw_bytes'] ?? 0) : 0,
            'bw_limit' => (int) ($pkg['bandwidth_mb'] ?? 0) * 1048576, 'bw_over' => (bool) ($u['over_bandwidth'] ?? false),
            'updated' => $u['updated_at'] ?? null,
        ];
    }

    /** Over the disk quota: nothing that adds data is allowed (uploads, new databases/mailboxes/domains...). */
    public static function diskFull(int $userId): bool
    {
        $s = Database::app()->prepare('SELECT over_disk FROM account_usage WHERE user_id = ?');
        $s->execute([$userId]);
        return (bool) $s->fetchColumn();
    }

    /** Throws the message the customer sees when the disk quota is used up. */
    public static function requireDiskSpace(int $userId): void
    {
        if (self::diskFull($userId)) {
            throw new RuntimeException('Your account is over its disk space quota - delete files, databases or mail (or ask for a bigger package) first.');
        }
    }

    public static function withinLimit(int $userId, string $resource): bool
    {
        if (in_array($resource, ['domains', 'databases', 'email_accounts'], true) && self::diskFull($userId)) {
            return false;
        }
        $pkg = self::package($userId);
        if (!$pkg) {
            return false; // no package assigned = no provisioning allowed
        }
        $limits = [
            'domains' => (int) $pkg['max_domains'],
            'databases' => (int) $pkg['max_databases'],
            'email_accounts' => (int) $pkg['max_email_accounts'],
            'ftp_accounts' => (int) $pkg['max_ftp_accounts'],
        ];
        $usage = self::usage($userId);
        return ($usage[$resource] ?? 0) < ($limits[$resource] ?? 0);
    }
}
