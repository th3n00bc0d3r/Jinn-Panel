<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $app = null;
    private static ?PDO $prov = null;

    public static function app(): PDO
    {
        if (self::$app === null) {
            self::$app = new PDO(
                'mysql:host=' . Config::DB_HOST . ';dbname=' . Config::DB_NAME . ';charset=utf8mb4',
                Config::DB_USER,
                Config::DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }
        return self::$app;
    }

    /**
     * Elevated connection used ONLY by ProvisioningService to create/drop
     * per-customer databases and MySQL users. Never expose this to
     * user-controlled queries directly.
     */
    public static function provisioning(): PDO
    {
        if (self::$prov === null) {
            self::$prov = new PDO(
                'mysql:host=' . Config::PROV_DB_HOST . ';charset=utf8mb4',
                Config::PROV_DB_USER,
                Config::PROV_DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }
        return self::$prov;
    }
}
