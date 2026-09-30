<?php
declare(strict_types=1);

/**
 * Creates/drops real per-customer MySQL databases and users via the
 * elevated `hostpanel_prov` connection. Identifiers are validated with a
 * strict allow-list regex since PDO cannot parameterize identifiers.
 */
final class ProvisioningService
{
    /**
     * hostpanel_prov can only re-grant privileges it itself holds - "ALL
     * PRIVILEGES" includes things like CREATE ROUTINE/TRIGGER/VIEW that it
     * doesn't have, which makes MariaDB reject the whole statement. Grant
     * the same working set hostpanel_prov was itself given.
     */
    public const GRANT_SET = 'SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, LOCK TABLES';

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

        // No FLUSH PRIVILEGES needed (or wanted - it needs the RELOAD
        // privilege, which hostpanel_prov deliberately doesn't have):
        // CREATE USER/GRANT/DROP USER take effect immediately.
        self::grantDatabase($dbName, $dbUser);
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

    // ------------------------------------------------------------------
    // Used by the cPanel migration (restores existing databases/users
    // as-is rather than creating fresh ones).
    // ------------------------------------------------------------------

    public static function databaseExists(string $dbName): bool
    {
        $stmt = Database::provisioning()->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$dbName]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function userExists(string $dbUser): bool
    {
        $stmt = Database::provisioning()->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ?');
        $stmt->execute([$dbUser]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Unlike createDatabase(), fails if it already exists - a restore must never merge into someone else's data. */
    public static function createEmptyDatabase(string $dbName): void
    {
        if (!self::isValidIdentifier($dbName)) {
            throw new InvalidArgumentException("Invalid database name \"$dbName\".");
        }
        Database::provisioning()->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    public static function createUser(string $dbUser, string $password): void
    {
        if (!self::isValidIdentifier($dbUser)) {
            throw new InvalidArgumentException("Invalid database user \"$dbUser\".");
        }
        $pdo = Database::provisioning();
        $pdo->exec("CREATE USER '$dbUser'@'localhost' IDENTIFIED BY " . $pdo->quote($password));
    }

    /**
     * Recreates a user with its existing mysql_native_password hash, so the
     * application's config (wp-config.php etc.) keeps working unchanged.
     */
    public static function createUserWithNativeHash(string $dbUser, string $hash): void
    {
        if (!self::isValidIdentifier($dbUser)) {
            throw new InvalidArgumentException("Invalid database user \"$dbUser\".");
        }
        if (!preg_match('/^\*[0-9A-F]{40}$/', $hash)) {
            throw new InvalidArgumentException('Not a mysql_native_password hash.');
        }
        Database::provisioning()->exec("CREATE USER '$dbUser'@'localhost' IDENTIFIED BY PASSWORD '$hash'");
    }

    public static function grantDatabase(string $dbName, string $dbUser): void
    {
        if (!self::isValidIdentifier($dbName) || !self::isValidIdentifier($dbUser)) {
            throw new InvalidArgumentException('Invalid database or username.');
        }
        Database::provisioning()->exec('GRANT ' . self::GRANT_SET . " ON `$dbName`.* TO '$dbUser'@'localhost'");
    }

    /**
     * Streams a mysqldump file into $dbName through the mysql client,
     * rewriting the few things a dump from another server commonly carries
     * that this (deliberately least-privilege) connection can't execute:
     * DEFINER clauses, GTID/binlog SET statements (need SUPER), and MySQL
     * 8's utf8mb4_0900_* collations (unknown to MariaDB).
     *
     * Runs with --force so one statement this connection can't execute
     * (e.g. CREATE VIEW/TRIGGER, which hostpanel_prov deliberately can't do)
     * doesn't abandon every table after it. Returns mysql's error output
     * ('' = clean import); throws only if the import couldn't run at all.
     */
    public static function importDump(string $dbName, string $dumpFile, string $workDir): string
    {
        if (!self::isValidIdentifier($dbName)) {
            throw new InvalidArgumentException("Invalid database name \"$dbName\".");
        }
        $cnf = $workDir . '/.my-' . bin2hex(random_bytes(4)) . '.cnf';
        file_put_contents($cnf, "[client]\nuser=" . Config::PROV_DB_USER . "\npassword=\"" . addcslashes(Config::PROV_DB_PASS, "\"\\") . "\"\nhost=" . Config::PROV_DB_HOST . "\nprotocol=TCP\n");
        chmod($cnf, 0600);

        $in = fopen($dumpFile, 'rb');
        if ($in === false) {
            @unlink($cnf);
            throw new RuntimeException("Cannot read $dumpFile");
        }
        $errFile = $workDir . '/.mysql-err-' . bin2hex(random_bytes(4));
        $proc = proc_open(
            ['mysql', '--defaults-extra-file=' . $cnf, '--default-character-set=utf8mb4', '--max-allowed-packet=1G', '--force', $dbName],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $errFile, 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            fclose($in);
            @unlink($cnf);
            throw new RuntimeException('Could not start the mysql client.');
        }

        try {
            while (($line = fgets($in)) !== false) {
                $line = self::rewriteDumpLine($line);
                if ($line === null) {
                    continue;
                }
                if (@fwrite($pipes[0], $line) === false) {
                    break; // mysql exited on an error - its message is in $errFile
                }
            }
        } finally {
            fclose($in);
            @fclose($pipes[0]);
            $code = proc_close($proc);
            @unlink($cnf);
        }
        $raw = (string) @file_get_contents($errFile);
        @unlink($errFile);
        // With --force the MariaDB client keeps going AND exits 0 even when
        // statements failed, so the exit code can't be trusted: the ERROR
        // lines it prints (between "-----" statement echoes) are the signal.
        preg_match_all('/^ERROR \d+ .*$/m', $raw, $m);
        $err = implode("\n", array_slice($m[0], 0, 50));
        if (preg_match('/ERROR (1044|1045|2002|2003|2005|2006|2013)\b/', $err) || ($code !== 0 && $err === '')) {
            throw new RuntimeException($err !== '' ? self::firstLine($err) : (trim($raw) !== '' ? substr(trim($raw), 0, 300) : "mysql exited with code $code"));
        }
        return $err;
    }

    private static function firstLine(string $s): string
    {
        return strtok($s, "\n") ?: $s;
    }

    /** @return string|null the line to feed to mysql, or null to drop it */
    public static function rewriteDumpLine(string $line): ?string
    {
        // Data lines are left completely untouched.
        if (str_starts_with($line, 'INSERT ') || str_starts_with($line, 'REPLACE ') || str_starts_with($line, '(')) {
            return $line;
        }
        if (preg_match('/^\s*(\/\*![0-9]+\s*)?SET\s+@@(GLOBAL\.GTID_PURGED|SESSION\.SQL_LOG_BIN|GLOBAL\.GTID_MODE)/i', $line)) {
            return null;
        }
        if (stripos($line, 'DEFINER') !== false) {
            $line = preg_replace('/\/\*![0-9]+\s+DEFINER\s*=\s*\S+\s*\*\/\s*/i', '', $line);
            $line = preg_replace('/\s+DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@]+)@(`[^`]*`|\'[^\']*\'|[^\s*]+)/i', '', $line);
        }
        if (stripos($line, '_0900_') !== false) {
            $line = preg_replace('/\butf8mb4_0900_[a-z_]+\b/i', 'utf8mb4_unicode_ci', $line);
        }
        return $line;
    }
}
