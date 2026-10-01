<?php
declare(strict_types=1);

/**
 * cPanel > MySQL Databases, the way cPanel does it: databases and users are
 * separate, a user is added to any of the account's databases with chosen
 * privileges, and "Remote MySQL" hosts give every user of the account the
 * same login from those addresses.
 *
 * The account's users are db_user_accounts; grants are always read from
 * MariaDB itself (mysql.db), never mirrored. Remote logins are copies of
 * user@localhost (same password hash, same grants) for each remote host,
 * re-synced after every change (syncRemote). The firewall opens 3306 only
 * to the hosts listed (root worker, mysql_firewall job).
 */
final class MysqlService
{
    /** What "All privileges" means for a customer (no GRANT/SUPER/FILE etc.). */
    public const PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'REFERENCES', 'LOCK TABLES',
        'CREATE TEMPORARY TABLES', 'CREATE VIEW', 'SHOW VIEW', 'CREATE ROUTINE', 'ALTER ROUTINE', 'EXECUTE', 'EVENT', 'TRIGGER'];
    /** mysql.db column => privilege name. */
    private const DB_COLUMNS = ['Select_priv' => 'SELECT', 'Insert_priv' => 'INSERT', 'Update_priv' => 'UPDATE', 'Delete_priv' => 'DELETE',
        'Create_priv' => 'CREATE', 'Drop_priv' => 'DROP', 'Alter_priv' => 'ALTER', 'Index_priv' => 'INDEX', 'References_priv' => 'REFERENCES',
        'Lock_tables_priv' => 'LOCK TABLES', 'Create_tmp_table_priv' => 'CREATE TEMPORARY TABLES', 'Create_view_priv' => 'CREATE VIEW',
        'Show_view_priv' => 'SHOW VIEW', 'Create_routine_priv' => 'CREATE ROUTINE', 'Alter_routine_priv' => 'ALTER ROUTINE',
        'Execute_priv' => 'EXECUTE', 'Event_priv' => 'EVENT', 'Trigger_priv' => 'TRIGGER'];
    private const NAME_RE = '/^[a-z][a-z0-9_]{0,30}$/';

    public static function users(int $userId): array
    {
        $s = Database::app()->prepare('SELECT db_user FROM db_user_accounts WHERE user_id = ? ORDER BY db_user');
        $s->execute([$userId]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function databases(int $userId): array
    {
        $s = Database::app()->prepare('SELECT * FROM db_instances WHERE user_id = ? ORDER BY db_name');
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    /** @return array<string, array<string, list<string>>> db_user => db_name => privileges (localhost grants) */
    public static function grants(int $userId): array
    {
        $users = self::users($userId);
        $dbs = array_column(self::databases($userId), 'db_name');
        if (!$users || !$dbs) {
            return [];
        }
        $in = fn(array $a) => implode(',', array_fill(0, count($a), '?'));
        $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys(self::DB_COLUMNS)));
        // Db is stored as granted: plain (GRANT ON `a_b`.*) or LIKE-escaped (`a\_b`).
        $forms = array_values(array_unique(array_merge($dbs, array_map([self::class, 'likeEscape'], $dbs))));
        $s = Database::provisioning()->prepare("SELECT User, Db, $cols FROM mysql.db WHERE Host = 'localhost' AND User IN ({$in($users)}) AND Db IN ({$in($forms)})");
        $s->execute([...$users, ...$forms]);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            $privs = [];
            foreach (self::DB_COLUMNS as $col => $p) {
                if (($r[$col] ?? 'N') === 'Y') {
                    $privs[] = $p;
                }
            }
            if ($privs) {
                $out[$r['User']][self::unlikeEscape((string) $r['Db'])] = $privs;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Databases
    // ------------------------------------------------------------------

    public static function createDatabase(array $me, string $label): string
    {
        $name = self::name($me, $label, 63);
        if (!Quota::withinLimit((int) $me['id'], 'databases')) {
            throw new InvalidArgumentException('You have reached your package\'s database limit.');
        }
        if (ProvisioningService::databaseExists($name)) {
            throw new InvalidArgumentException("A database called $name already exists.");
        }
        ProvisioningService::createEmptyDatabase($name);
        Database::app()->prepare("INSERT INTO db_instances (user_id, db_name, db_user) VALUES (?, ?, '')")->execute([$me['id'], $name]);
        return $name;
    }

    /** Drops the database and every grant on it (MariaDB keeps grants of dropped databases). */
    public static function dropDatabase(array $me, int $dbId): string
    {
        $s = Database::app()->prepare('SELECT * FROM db_instances WHERE id = ? AND user_id = ?');
        $s->execute([$dbId, $me['id']]);
        $db = $s->fetch() ?: throw new InvalidArgumentException('Database not found.');
        $pdo = Database::provisioning();
        $g = $pdo->prepare('SELECT User, Host, Db FROM mysql.db WHERE Db IN (?, ?)');
        $g->execute([(string) $db['db_name'], self::likeEscape((string) $db['db_name'])]);
        foreach ($g->fetchAll() as $r) {
            if (self::validUser((string) $r['User']) && self::validHost((string) $r['Host'])) {
                // As stored (plain or \_-escaped), or MariaDB finds no such grant.
                self::revoke((string) $r['User'], (string) $r['Host'], (string) $r['Db']);
            }
        }
        ProvisioningService::dropDatabase((string) $db['db_name']);
        Database::app()->prepare('DELETE FROM db_instances WHERE id = ?')->execute([$db['id']]);
        return (string) $db['db_name'];
    }

    // ------------------------------------------------------------------
    // Users
    // ------------------------------------------------------------------

    public static function createUser(array $me, string $label, string $password): string
    {
        $name = self::name($me, $label, 32);
        self::checkPassword($password);
        if (ProvisioningService::userExists($name)) {
            throw new InvalidArgumentException("A MySQL user called $name already exists.");
        }
        ProvisioningService::createUser($name, $password);
        Database::app()->prepare('INSERT INTO db_user_accounts (user_id, db_user) VALUES (?, ?)')->execute([$me['id'], $name]);
        self::syncRemote((int) $me['id']);
        return $name;
    }

    public static function setPassword(array $me, string $dbUser, string $password): void
    {
        self::ownedUser($me, $dbUser);
        self::checkPassword($password);
        $pdo = Database::provisioning();
        foreach (self::hostsOf($dbUser) as $host) {
            $pdo->exec("ALTER USER '$dbUser'@'$host' IDENTIFIED BY " . $pdo->quote($password));
        }
    }

    public static function deleteUser(array $me, string $dbUser): void
    {
        self::ownedUser($me, $dbUser);
        $pdo = Database::provisioning();
        foreach (self::hostsOf($dbUser) as $host) {
            $pdo->exec("DROP USER IF EXISTS '$dbUser'@'$host'");
        }
        Database::app()->prepare('DELETE FROM db_user_accounts WHERE user_id = ? AND db_user = ?')->execute([$me['id'], $dbUser]);
        Database::app()->prepare("UPDATE db_instances SET db_user = '' WHERE user_id = ? AND db_user = ?")->execute([$me['id'], $dbUser]);
    }

    /** @param list<string> $privileges subset of PRIVILEGES (empty = remove the user from the database) */
    public static function setGrant(array $me, string $dbUser, int $dbId, array $privileges): void
    {
        self::ownedUser($me, $dbUser);
        $s = Database::app()->prepare('SELECT db_name FROM db_instances WHERE id = ? AND user_id = ?');
        $s->execute([$dbId, $me['id']]);
        $db = (string) ($s->fetchColumn() ?: throw new InvalidArgumentException('Database not found.'));
        $privileges = array_values(array_intersect(self::PRIVILEGES, array_map('strtoupper', $privileges)));
        $pdo = Database::provisioning();
        $has = $pdo->prepare("SELECT Db FROM mysql.db WHERE User = ? AND Host = 'localhost' AND Db IN (?, ?)");
        $has->execute([$dbUser, $db, self::likeEscape($db)]);
        foreach ($has->fetchAll(PDO::FETCH_COLUMN) as $stored) {
            self::revoke($dbUser, 'localhost', (string) $stored);
        }
        if ($privileges) {
            $pdo->exec('GRANT ' . implode(', ', $privileges) . " ON `$db`.* TO '$dbUser'@'localhost'");
        }
        self::syncRemote((int) $me['id']);
    }

    // ------------------------------------------------------------------
    // Remote MySQL
    // ------------------------------------------------------------------

    public static function remoteHosts(int $userId): array
    {
        $s = Database::app()->prepare('SELECT host FROM mysql_remote_hosts WHERE user_id = ? ORDER BY host');
        $s->execute([$userId]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Accepts an IPv4/IPv6 address, an IPv4 CIDR (/8 /16 /24 /32 or any prefix) or % (anywhere). */
    public static function addRemoteHost(array $me, string $raw): string
    {
        $host = self::mysqlHost(trim($raw)) ?? throw new InvalidArgumentException('Enter an IP address (e.g. 203.0.113.7), an IPv4 range (e.g. 203.0.113.0/24) or % for anywhere.');
        Database::app()->prepare('INSERT IGNORE INTO mysql_remote_hosts (user_id, host) VALUES (?, ?)')->execute([$me['id'], $host]);
        self::syncRemote((int) $me['id']);
        self::syncFirewall();
        return $host;
    }

    public static function removeRemoteHost(array $me, string $host): void
    {
        Database::app()->prepare('DELETE FROM mysql_remote_hosts WHERE user_id = ? AND host = ?')->execute([$me['id'], $host]);
        self::syncRemote((int) $me['id']);
        self::syncFirewall();
    }

    /** Makes user@host logins for every (user, remote host) of the account match user@localhost. */
    public static function syncRemote(int $userId): void
    {
        $pdo = Database::provisioning();
        $hosts = self::remoteHosts($userId);
        $grants = self::grants($userId);
        foreach (self::users($userId) as $u) {
            if (!self::validUser($u)) {
                continue;
            }
            $existing = array_values(array_diff(self::hostsOf($u), ['localhost']));
            foreach (array_diff($existing, $hosts) as $gone) {
                $pdo->exec("DROP USER IF EXISTS '$u'@'$gone'");
            }
            if (!$hosts) {
                continue;
            }
            $hash = self::passwordHash($u);
            if ($hash === null) {
                continue;
            }
            foreach ($hosts as $h) {
                if (!self::validHost($h)) {
                    continue;
                }
                if (!in_array($h, $existing, true)) {
                    $pdo->exec("CREATE USER '$u'@'$h' IDENTIFIED BY PASSWORD '$hash'");
                } else {
                    $pdo->exec("ALTER USER '$u'@'$h' IDENTIFIED BY PASSWORD '$hash'");
                }
                // Same database grants as user@localhost.
                $g = $pdo->prepare('SELECT Db FROM mysql.db WHERE User = ? AND Host = ?');
                $g->execute([$u, $h]);
                foreach ($g->fetchAll(PDO::FETCH_COLUMN) as $db) {
                    self::revoke($u, $h, (string) $db);
                }
                foreach ($grants[$u] ?? [] as $db => $privs) {
                    $pdo->exec('GRANT ' . implode(', ', $privs) . " ON `$db`.* TO '$u'@'$h'");
                }
            }
        }
    }

    /** Queues the firewall rules for port 3306: open only to the remote hosts of all accounts. */
    public static function syncFirewall(): void
    {
        $all = Database::app()->query('SELECT DISTINCT host FROM mysql_remote_hosts')->fetchAll(PDO::FETCH_COLUMN);
        $sources = array_values(array_unique(array_filter(array_map([self::class, 'firewallSource'], $all))));
        sort($sources);
        SystemWorkerService::enqueue('mysql-firewall', ['type' => 'mysql_firewall', 'sources' => $sources]);
    }

    /** MySQL host spec for user input, or null. */
    public static function mysqlHost(string $in): ?string
    {
        if ($in === '%') {
            return '%';
        }
        if (filter_var($in, FILTER_VALIDATE_IP)) {
            return strtolower($in);
        }
        if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $in, $m) && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (int) $m[2] >= 8 && (int) $m[2] <= 32) {
            $mask = (int) $m[2] === 0 ? 0 : (~0 << (32 - (int) $m[2])) & 0xFFFFFFFF;
            $net = ip2long($m[1]) & $mask;
            return long2ip($net) . '/' . long2ip($mask); // MariaDB wants base/netmask
        }
        return null;
    }

    /** firewalld source for a MySQL host spec ('' = anywhere). */
    public static function firewallSource(string $host): ?string
    {
        if ($host === '%') {
            return '0.0.0.0/0';
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        if (preg_match('#^([\d.]+)/([\d.]+)$#', $host, $m) && filter_var($m[1], FILTER_VALIDATE_IP) && filter_var($m[2], FILTER_VALIDATE_IP)) {
            $bits = substr_count(decbin(ip2long($m[2])), '1');
            return "{$m[1]}/$bits";
        }
        return null;
    }

    // ------------------------------------------------------------------
    // phpMyAdmin sign-on
    // ------------------------------------------------------------------

    public const PMA_TOKENS = '/var/lib/jinnpanel-pma/tokens';

    /**
     * A temporary MySQL login for phpMyAdmin with the account's databases
     * (2 hours; cron-run.php drops expired ones), handed over through a
     * single-use token file the phpMyAdmin instance reads. Returns the URL.
     */
    public static function phpMyAdminUrl(array $me): string
    {
        $dbs = array_column(self::databases((int) $me['id']), 'db_name');
        if (!$dbs) {
            throw new InvalidArgumentException('Create a database first.');
        }
        $user = 'jpma_' . bin2hex(random_bytes(6));
        $pass = bin2hex(random_bytes(20));
        $pdo = Database::provisioning();
        $pdo->exec("CREATE USER '$user'@'localhost' IDENTIFIED BY " . $pdo->quote($pass) . ' WITH MAX_USER_CONNECTIONS 10');
        foreach ($dbs as $db) {
            if (ProvisioningService::isValidIdentifier($db)) {
                $pdo->exec('GRANT ' . implode(', ', self::PRIVILEGES) . " ON `$db`.* TO '$user'@'localhost'");
            }
        }
        Database::app()->prepare('INSERT INTO pma_logins (mysql_user, user_id, expires_at) VALUES (?, ?, NOW() + INTERVAL 2 HOUR)')->execute([$user, $me['id']]);
        $token = bin2hex(random_bytes(32));
        $file = self::PMA_TOKENS . '/' . hash('sha256', $token) . '.json';
        if (@file_put_contents($file, json_encode(['user' => $user, 'password' => $pass, 'expires' => time() + 60])) === false) {
            throw new RuntimeException('phpMyAdmin isn\'t installed on this server (re-run install.sh).');
        }
        @chmod($file, 0660);
        return '/phpmyadmin/jp-signon.php?token=' . $token;
    }

    /** Drops expired phpMyAdmin logins (cron-run.php, every minute). */
    public static function dropExpiredPmaLogins(): int
    {
        $rows = Database::app()->query('SELECT mysql_user FROM pma_logins WHERE expires_at < NOW()')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $u) {
            if (preg_match('/^jpma_[0-9a-f]{12}$/', (string) $u)) {
                Database::provisioning()->exec("DROP USER IF EXISTS '$u'@'localhost'");
            }
            Database::app()->prepare('DELETE FROM pma_logins WHERE mysql_user = ?')->execute([$u]);
        }
        foreach (glob(self::PMA_TOKENS . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - 300) {
                @unlink($f);
            }
        }
        return count($rows);
    }

    /** All the account's MySQL logins, for an account purge. */
    public static function dropAccount(int $userId): void
    {
        $pdo = Database::provisioning();
        foreach (self::users($userId) as $u) {
            foreach (self::validUser($u) ? self::hostsOf($u) : [] as $h) {
                $pdo->exec("DROP USER IF EXISTS '$u'@'$h'");
            }
        }
        $s = Database::app()->prepare('SELECT mysql_user FROM pma_logins WHERE user_id = ?');
        $s->execute([$userId]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $u) {
            if (preg_match('/^jpma_[0-9a-f]{12}$/', (string) $u)) {
                $pdo->exec("DROP USER IF EXISTS '$u'@'localhost'");
            }
        }
    }

    // ------------------------------------------------------------------

    private static function name(array $me, string $label, int $max): string
    {
        $label = strtolower(trim($label));
        if (!preg_match(self::NAME_RE, $label)) {
            throw new InvalidArgumentException('Names are letters, numbers and _, starting with a letter.');
        }
        $name = $me['username'] . '_' . $label;
        if (strlen($name) > $max) {
            throw new InvalidArgumentException("$name is too long (MySQL allows $max characters).");
        }
        return $name;
    }

    private static function checkPassword(string $p): void
    {
        if (strlen($p) < 10 || !preg_match('/[a-z]/i', $p) || !preg_match('/\d/', $p)) {
            throw new InvalidArgumentException('Use at least 10 characters with letters and numbers.');
        }
    }

    private static function ownedUser(array $me, string $dbUser): void
    {
        if (!self::validUser($dbUser) || !in_array($dbUser, self::users((int) $me['id']), true)) {
            throw new InvalidArgumentException('MySQL user not found.');
        }
    }

    /** @return list<string> hosts this MySQL user exists for */
    private static function hostsOf(string $dbUser): array
    {
        $s = Database::provisioning()->prepare('SELECT Host FROM mysql.user WHERE User = ?');
        $s->execute([$dbUser]);
        return array_values(array_filter($s->fetchAll(PDO::FETCH_COLUMN), [self::class, 'validHost']));
    }

    private static function passwordHash(string $dbUser): ?string
    {
        $row = Database::provisioning()->query("SHOW CREATE USER '$dbUser'@'localhost'")->fetchColumn();
        return preg_match("/IDENTIFIED BY PASSWORD '(\\*[0-9A-F]{40})'/", (string) $row, $m) ? $m[1] : null;
    }

    private static function validUser(string $u): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/', $u);
    }

    private static function validHost(string $h): bool
    {
        return $h === 'localhost' || $h === '%' || (bool) filter_var($h, FILTER_VALIDATE_IP) || (bool) preg_match('#^[\d.]+/[\d.]+$#', $h);
    }

    /**
     * Revokes exactly what user@host holds on $storedDb (as mysql.db names
     * it). Not "REVOKE ALL": that includes privileges the panel's own
     * account doesn't hold (e.g. DELETE HISTORY), which MariaDB refuses.
     */
    private static function revoke(string $user, string $host, string $storedDb): void
    {
        if (!self::validUser($user) || !self::validHost($host)) {
            return;
        }
        $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys(self::DB_COLUMNS)));
        $s = Database::provisioning()->prepare("SELECT $cols FROM mysql.db WHERE User = ? AND Host = ? AND Db = ?");
        $s->execute([$user, $host, $storedDb]);
        $row = $s->fetch();
        if (!$row) {
            return;
        }
        $privs = [];
        foreach (self::DB_COLUMNS as $col => $p) {
            if (($row[$col] ?? 'N') === 'Y') {
                $privs[] = $p;
            }
        }
        if ($privs) {
            Database::provisioning()->exec('REVOKE ' . implode(', ', $privs) . ' ON `' . str_replace('`', '', $storedDb) . "`.* FROM '$user'@'$host'");
        }
    }

    /** mysql.db stores database names with _ and % escaped as LIKE patterns. */
    private static function likeEscape(string $db): string
    {
        return str_replace(['_', '%'], ['\\_', '\\%'], $db);
    }

    private static function unlikeEscape(string $db): string
    {
        return str_replace(['\\_', '\\%'], ['_', '%'], $db);
    }
}
