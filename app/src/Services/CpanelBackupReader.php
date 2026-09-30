<?php
declare(strict_types=1);

/**
 * Reads an extracted cPanel full backup (the pkgacct / "cpmove" layout that
 * Backup::fullbackup_to_* produces):
 *
 *   backup-MM.DD.YYYY_HH-MM-SS_<user>/
 *     cp/<user>               account settings (PLAN=, CONTACTEMAIL=, DNS=...)
 *     shadow                  the account's login password hash
 *     userdata/main           domain list (main/addon/sub/parked), YAML
 *     userdata/<domain>       per-domain vhost data incl. documentroot, YAML
 *     mysql/<db>.sql          one mysqldump per database
 *     mysql.sql               MySQL users (password hashes) + GRANTs
 *     homedir/                the whole home directory (or homedir.tar)
 *       etc/<domain>/passwd   mailboxes of that domain
 *       etc/<domain>/shadow   mailbox password hashes
 *       mail/<domain>/<box>/  Maildir++ mail store
 *
 * EVERYTHING in here came from the source server and is treated as
 * untrusted: every name that becomes a path, a database identifier, or a
 * mailbox is validated against a strict allow-list first, and every path is
 * resolved and confirmed to still be inside the backup before use.
 */
final class CpanelBackupReader
{
    public const DOMAIN_RE = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/';
    public const LOCALPART_RE = '/^[a-z0-9][a-z0-9._-]{0,63}$/';
    public const USERNAME_RE = '/^[a-z][a-z0-9_]{2,31}$/';

    private string $root;
    private ?array $cp = null;

    public function __construct(string $extractDir, private string $username)
    {
        if (!preg_match(self::USERNAME_RE, $username)) {
            throw new RuntimeException("cPanel username \"$username\" can't be used as a JinnPanel username (3-32 chars: a-z, 0-9, _; starting with a letter).");
        }
        $this->root = self::locateRoot($extractDir, $username);
    }

    /** The archive has exactly one top-level directory; find it. */
    private static function locateRoot(string $extractDir, string $username): string
    {
        $candidates = [];
        foreach (scandir($extractDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $path = "$extractDir/$entry";
            if (is_dir($path) && !is_link($path) && (is_dir("$path/cp") || is_dir("$path/homedir") || is_file("$path/homedir.tar"))) {
                $candidates[] = $path;
            }
        }
        if (count($candidates) !== 1) {
            throw new RuntimeException('The archive does not look like a cPanel full backup (expected one top-level backup directory, found ' . count($candidates) . ').');
        }
        $root = realpath($candidates[0]);
        if (is_file("$root/cp/$username") === false && !str_ends_with(basename($root), "_$username") && basename($root) !== "cpmove-$username") {
            throw new RuntimeException("The received backup does not belong to account \"$username\".");
        }
        return $root;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function username(): string
    {
        return $this->username;
    }

    /** Resolves a relative path inside the backup; null if missing or escaping it. */
    public function path(string $relative): ?string
    {
        if (str_contains($relative, "\0")) {
            return null;
        }
        $real = realpath($this->root . '/' . ltrim($relative, '/'));
        if ($real === false || ($real !== $this->root && !str_starts_with($real, $this->root . '/'))) {
            return null;
        }
        return $real;
    }

    /** Extracted home directory, unpacking homedir.tar first if that's how it was shipped. */
    public function homedir(): string
    {
        $dir = $this->root . '/homedir';
        if (!is_dir($dir) && is_file($this->root . '/homedir.tar')) {
            mkdir($dir, 02770);
            self::run(['tar', '-xf', $this->root . '/homedir.tar', '-C', $dir, '--no-same-owner', '--no-same-permissions']);
            unlink($this->root . '/homedir.tar');
        }
        if (!is_dir($dir) || is_link($dir)) {
            throw new RuntimeException('The backup contains no home directory.');
        }
        return realpath($dir);
    }

    /** @param string[] $cmd */
    public static function run(array $cmd, ?string &$output = null): int
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not run ' . $cmd[0]);
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return proc_close($proc);
    }

    // ------------------------------------------------------------------
    // Account
    // ------------------------------------------------------------------

    /** cp/<user> as KEY => value. */
    public function cpFile(): array
    {
        if ($this->cp !== null) {
            return $this->cp;
        }
        $this->cp = [];
        $file = $this->path("cp/{$this->username}");
        if ($file && is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) {
                    $this->cp[$m[1]] = trim($m[2]);
                }
            }
        }
        return $this->cp;
    }

    public function contactEmail(): ?string
    {
        $email = $this->cpFile()['CONTACTEMAIL'] ?? '';
        $email = trim(explode(',', $email)[0]);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null;
    }

    public function plan(): ?string
    {
        $plan = $this->cpFile()['PLAN'] ?? '';
        return $plan !== '' ? $plan : null;
    }

    /** The account's own login password hash (crypt format), if present and usable. */
    public function accountPasswordHash(): ?string
    {
        $file = $this->path('shadow');
        if (!$file || !is_file($file)) {
            return null;
        }
        $line = trim((string) file_get_contents($file, false, null, 0, 4096));
        if (str_contains($line, ':')) {
            $line = explode(':', $line)[1] ?? '';
        }
        return self::isCryptHash($line) ? $line : null;
    }

    /** SHA-512/SHA-256/MD5-crypt, bcrypt, yescrypt - all verifiable by PHP's password_verify()/crypt(). */
    public static function isCryptHash(string $hash): bool
    {
        return (bool) preg_match('#^\$(1|5|6|2[abxy]|y)\$[./A-Za-z0-9$=,]{8,}$#', $hash);
    }

    // ------------------------------------------------------------------
    // Domains
    // ------------------------------------------------------------------

    /**
     * @return array<int, array{name:string, type:string, docroot_rel:?string}>
     *   type: main | addon | sub | parked. docroot_rel is relative to homedir.
     */
    public function domains(): array
    {
        $main = $this->yaml('userdata/main');
        $mainDomain = strtolower((string) ($main['main_domain'] ?? ($this->cpFile()['DNS'] ?? '')));
        if (!preg_match(self::DOMAIN_RE, $mainDomain)) {
            throw new RuntimeException('Could not determine the account\'s main domain from the backup.');
        }

        $addons = [];
        foreach ((array) ($main['addon_domains'] ?? []) as $addon => $backingSub) {
            $addons[strtolower((string) $addon)] = strtolower((string) $backingSub);
        }

        $out = [['name' => $mainDomain, 'type' => 'main', 'docroot_rel' => $this->docrootRel($mainDomain) ?? 'public_html']];
        foreach ($addons as $addon => $sub) {
            $out[] = ['name' => $addon, 'type' => 'addon', 'docroot_rel' => $this->docrootRel($sub) ?? $this->docrootRel($addon)];
        }
        foreach ((array) ($main['sub_domains'] ?? []) as $sub) {
            $sub = strtolower((string) $sub);
            if (in_array($sub, $addons, true)) {
                continue; // an addon's internal backing subdomain, not a real site of its own
            }
            $out[] = ['name' => $sub, 'type' => 'sub', 'docroot_rel' => $this->docrootRel($sub)];
        }
        foreach ((array) ($main['parked_domains'] ?? []) as $parked) {
            $out[] = ['name' => strtolower((string) $parked), 'type' => 'parked', 'docroot_rel' => null];
        }

        $seen = [];
        return array_values(array_filter($out, function ($d) use (&$seen) {
            if (!preg_match(self::DOMAIN_RE, $d['name']) || isset($seen[$d['name']])) {
                return false;
            }
            return $seen[$d['name']] = true;
        }));
    }

    private function docrootRel(string $vhost): ?string
    {
        if (!preg_match(self::DOMAIN_RE, $vhost)) {
            return null;
        }
        $ud = $this->yaml("userdata/$vhost");
        $docroot = (string) ($ud['documentroot'] ?? '');
        $home = rtrim((string) ($ud['homedir'] ?? ''), '/');
        if ($docroot === '') {
            return null;
        }
        if ($home !== '' && str_starts_with($docroot, "$home/")) {
            $rel = substr($docroot, strlen($home) + 1);
        } elseif (preg_match('#^/home\d*/' . preg_quote($this->username, '#') . '/(.+)$#', $docroot, $m)) {
            $rel = $m[1];
        } else {
            return null;
        }
        $rel = trim($rel, '/');
        foreach (explode('/', $rel) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }
        return $rel;
    }

    /**
     * Minimal YAML reader - exactly enough for cPanel's userdata files:
     * top-level `key: value`, nested maps (`  k: v`) and lists (`  - v`).
     */
    public function yaml(string $relative): array
    {
        $file = $this->path($relative);
        if (!$file || !is_file($file)) {
            return [];
        }
        return self::parseSimpleYaml((string) file_get_contents($file));
    }

    public static function parseSimpleYaml(string $text): array
    {
        $out = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (trim($line) === '' || trim($line) === '---' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (preg_match('/^([^\s:][^:]*):\s*(.*)$/', $line, $m)) {
                $key = trim($m[1], " '\"");
                $val = trim($m[2]);
                if ($val === '' || $val === '{}' || $val === '[]') {
                    $out[$key] = [];
                    $current = $key;
                } else {
                    $out[$key] = self::yamlScalar($val);
                    $current = null;
                }
            } elseif ($current !== null && preg_match('/^\s+-\s+(.*)$/', $line, $m)) {
                $out[$current][] = self::yamlScalar(trim($m[1]));
            } elseif ($current !== null && preg_match('/^\s+([^:]+):\s*(.*)$/', $line, $m)) {
                $out[$current][trim($m[1], " '\"")] = self::yamlScalar(trim($m[2]));
            }
        }
        return $out;
    }

    private static function yamlScalar(string $v): string
    {
        if (strlen($v) >= 2 && ($v[0] === "'" || $v[0] === '"') && $v[-1] === $v[0]) {
            return substr($v, 1, -1);
        }
        return $v;
    }

    // ------------------------------------------------------------------
    // Databases
    // ------------------------------------------------------------------

    /** Database dump files that belong to this account: [dbName => absolute .sql path]. */
    public function databases(): array
    {
        $dir = $this->path('mysql');
        if (!$dir || !is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (!str_ends_with($f, '.sql')) {
                continue;
            }
            $db = substr($f, 0, -4);
            if ($this->ownsDbIdentifier($db) && is_file("$dir/$f") && !is_link("$dir/$f")) {
                $out[$db] = "$dir/$f";
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Only identifiers namespaced to this account (`user` or `user_*`) are
     * ever accepted - this is what stops a crafted backup from touching
     * `hostpanel`, `mysql`, another tenant's database, or a panel DB user.
     */
    public function ownsDbIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,63}$/', $name)
            && ($name === $this->username || str_starts_with($name, $this->username . '_'));
    }

    /**
     * MySQL users + grants from mysql.sql.
     *
     * @return array<string, array{hash:?string, plugin:string, grants:array<int,array{pattern:string,privs:string}>}>
     */
    public function dbUsers(): array
    {
        $file = $this->path('mysql.sql');
        if (!$file || !is_file($file)) {
            return [];
        }
        return self::parseMysqlGrants((string) file_get_contents($file), fn(string $u) => $this->ownsDbIdentifier($u) && !str_starts_with($u, 'cpses_'));
    }

    public static function parseMysqlGrants(string $sql, callable $accept): array
    {
        $users = [];
        $hostRank = ['localhost' => 3, '127.0.0.1' => 2, '%' => 1];
        foreach (preg_split('/;\s*[\r\n]+/', $sql) as $stmt) {
            $stmt = trim($stmt);
            if (!preg_match("/\\bTO\\s+'((?:[^'\\\\]|\\\\.)+)'@'([^']*)'/i", $stmt, $um)
                && !preg_match("/^CREATE\\s+USER\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?'((?:[^'\\\\]|\\\\.)+)'@'([^']*)'/i", $stmt, $um)) {
                continue;
            }
            $user = stripslashes($um[1]);
            $host = $um[2];
            if (!$accept($user)) {
                continue;
            }
            $rank = $hostRank[$host] ?? 0;
            $users[$user] ??= ['hash' => null, 'plugin' => 'mysql_native_password', 'grants' => [], '_rank' => -1];
            $u = &$users[$user];

            // Password: prefer the localhost entry's hash.
            if ($rank >= $u['_rank']) {
                if (preg_match("/IDENTIFIED\\s+BY\\s+PASSWORD\\s+'(\\*[0-9A-Fa-f]{40})'/i", $stmt, $m)) {
                    [$u['hash'], $u['plugin'], $u['_rank']] = [strtoupper($m[1]), 'mysql_native_password', $rank];
                } elseif (preg_match("/IDENTIFIED\\s+(?:VIA|WITH)\\s+'?([a-z0-9_]+)'?(?:\\s+(?:AS|USING)\\s+(?:'([^']*)'|0x[0-9A-Fa-f]+))?/i", $stmt, $m)) {
                    // MySQL 8 dumps caching_sha2 hashes as unquoted 0x... literals.
                    $m[2] ??= '';
                    $isNative = strtolower($m[1]) === 'mysql_native_password' && preg_match('/^\*[0-9A-Fa-f]{40}$/', $m[2]);
                    [$u['hash'], $u['plugin'], $u['_rank']] = [$isNative ? strtoupper($m[2]) : null, strtolower($m[1]), $rank];
                }
            }

            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+`((?:[^`]|``)+)`\.\*\s+TO/is', $stmt, $gm)) {
                $u['grants'][] = ['pattern' => str_replace('``', '`', $gm[2]), 'privs' => strtoupper(trim($gm[1]))];
            }
            unset($u);
        }
        foreach ($users as &$u) {
            unset($u['_rank']);
            $u['grants'] = array_values(array_unique($u['grants'], SORT_REGULAR));
        }
        unset($u);
        return $users;
    }

    /** Does a GRANT database pattern (`user\_%`, `user\_wp`) cover $db? */
    public static function grantPatternMatches(string $pattern, string $db): bool
    {
        $regex = '';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $regex .= preg_quote($pattern[++$i], '/');
            } elseif ($c === '%') {
                $regex .= '.*';
            } elseif ($c === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($c, '/');
            }
        }
        return (bool) preg_match('/^' . $regex . '$/', $db);
    }

    // ------------------------------------------------------------------
    // Email
    // ------------------------------------------------------------------

    /** Mailboxes of $domain: [localPart => password hash or null]. */
    public function mailAccounts(string $domain): array
    {
        if (!preg_match(self::DOMAIN_RE, $domain)) {
            return [];
        }
        $home = $this->homedir();
        $passwd = self::inside($home, "etc/$domain/passwd");
        if ($passwd === null || !is_file($passwd)) {
            return [];
        }
        $hashes = [];
        $shadow = self::inside($home, "etc/$domain/shadow");
        if ($shadow !== null && is_file($shadow)) {
            foreach (file($shadow, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $parts = explode(':', $line);
                if (count($parts) >= 2) {
                    $hashes[strtolower($parts[0])] = $parts[1];
                }
            }
        }
        $out = [];
        foreach (file($passwd, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $local = strtolower(explode(':', $line)[0] ?? '');
            if (preg_match(self::LOCALPART_RE, $local)) {
                $hash = $hashes[$local] ?? '';
                $out[$local] = self::isCryptHash($hash) ? $hash : null;
            }
        }
        ksort($out);
        return $out;
    }

    /** Maildir of local@domain, or null if it has no stored mail. */
    public function maildir(string $domain, string $local): ?string
    {
        if (!preg_match(self::DOMAIN_RE, $domain) || !preg_match(self::LOCALPART_RE, $local)) {
            return null;
        }
        $dir = self::inside($this->homedir(), "mail/$domain/$local");
        return ($dir !== null && is_dir($dir)) ? $dir : null;
    }

    /** realpath($base/$rel) if it exists and is still inside $base. */
    public static function inside(string $base, string $rel): ?string
    {
        $real = realpath($base . '/' . $rel);
        if ($real === false || !str_starts_with($real, rtrim($base, '/') . '/')) {
            return null;
        }
        return $real;
    }
}
