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

    public static function withinLimit(int $userId, string $resource): bool
    {
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
