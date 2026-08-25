<?php
declare(strict_types=1);

/**
 * Creates/drops real per-customer MySQL databases and users via the
 * elevated `hostpanel_prov` connection. Identifiers are validated with a
 * strict allow-list regex since PDO cannot parameterize identifiers.
 */
final class ProvisioningService
{
    public static function isValidIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,62}$/', $name);
    }

    public static function createDatabase(string $dbName, string $dbUser, string $dbPass): void
    {
        if (!self::isValidIdentifier($dbName) || !self::isValidIdentifier($dbUser)) {
            throw new InvalidArgumentException('Invalid database or username.');
        }
        $pdo = Database::provisioning();
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // MariaDB's CREATE USER grammar does not accept a bound parameter in
        // the account-name position (`?@'localhost'` is a syntax error), so
        // $dbUser - already validated against a strict identifier allow-list
        // above - is interpolated directly; only the password is escaped via
        // PDO::quote().
        $quotedPass = $pdo->quote($dbPass);
        $pdo->exec("CREATE USER IF NOT EXISTS '$dbUser'@'localhost' IDENTIFIED BY $quotedPass");

        // hostpanel_prov can only re-grant privileges it itself holds - "ALL
        // PRIVILEGES" includes things like CREATE ROUTINE/TRIGGER/VIEW that
        // it doesn't have, which makes MariaDB reject the whole statement.
        // Grant the same working set hostpanel_prov was itself given.
        // No FLUSH PRIVILEGES needed (or wanted - it needs the RELOAD
        // privilege, which hostpanel_prov deliberately doesn't have):
        // CREATE USER/GRANT/DROP USER take effect immediately.
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, LOCK TABLES
                     ON `$dbName`.* TO '$dbUser'@'localhost'");
    }

    public static function dropDatabase(string $dbName): void
    {
        if (!self::isValidIdentifier($dbName)) {
            return;
        }
        Database::provisioning()->exec("DROP DATABASE IF EXISTS `$dbName`");
    }

    public static function dropDbUser(string $dbUser): void
    {
        if (!self::isValidIdentifier($dbUser)) {
            return;
        }
        Database::provisioning()->exec("DROP USER IF EXISTS '$dbUser'@'localhost'");
    }
}
