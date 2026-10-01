<?php
declare(strict_types=1);

/**
 * Cron jobs (cPanel > Cron Jobs). Two kinds only - a PHP script inside one
 * of the account's sites (run with php-zts and the site's PHP settings, cwd
 * = the script's folder) or a URL to fetch - because a free-form command
 * would be a shell as the web user every site runs as.
 *
 * cron-run.php (systemd timer, every minute) starts the jobs due that
 * minute, each through cron-exec.php in the background, with a per-job lock
 * so a slow run is never started twice, and a time limit.
 */
final class CronService
{
    public const MAX_JOBS = 25;
    public const TIMEOUT = 900;
    private const FIELDS = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
    private const NAMES = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
    private const MACROS = ['@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *', '@weekly' => '0 0 * * 0', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *'];

    /** Normalised 5-field schedule, or null if it isn't one. */
    public static function normaliseSchedule(string $expr): ?string
    {
        $expr = strtolower(trim(preg_replace('/\s+/', ' ', $expr)));
        $expr = self::MACROS[$expr] ?? $expr;
        $parts = explode(' ', $expr);
        if (count($parts) !== 5) {
            return null;
        }
        foreach ($parts as $i => $p) {
            if (self::expand($p, ...self::FIELDS[$i]) === null) {
                return null;
            }
        }
        return implode(' ', $parts);
    }

    /** Whether $schedule fires in the minute of $ts (server local time). */
    public static function due(string $schedule, int $ts): bool
    {
        $parts = explode(' ', $schedule);
        if (count($parts) !== 5) {
            return false;
        }
        [$min, $hour, $dom, $mon, $dow] = array_map(fn($i) => self::expand($parts[$i], ...self::FIELDS[$i]) ?? [], range(0, 4));
        $dowNow = (int) date('w', $ts);
        $dowMatch = in_array($dowNow, $dow, true) || ($dowNow === 0 && in_array(7, $dow, true));
        $domMatch = in_array((int) date('j', $ts), $dom, true);
        // Classic cron: when both day fields are restricted, either may match.
        $day = ($parts[2] !== '*' && $parts[4] !== '*') ? ($domMatch || $dowMatch) : ($domMatch && $dowMatch);
        return in_array((int) date('i', $ts), $min, true) && in_array((int) date('G', $ts), $hour, true)
            && in_array((int) date('n', $ts), $mon, true) && $day;
    }

    /** @return list<int>|null */
    private static function expand(string $field, int $lo, int $hi): ?array
    {
        $out = [];
        foreach (explode(',', $field) as $part) {
            if (!preg_match('#^(\*|([a-z0-9]+)(?:-([a-z0-9]+))?)(?:/(\d{1,2}))?$#', $part, $m)) {
                return null;
            }
            $step = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 1;
            if ($step < 1) {
                return null;
            }
            if ($m[1] === '*') {
                [$a, $b] = [$lo, $hi];
            } else {
                $a = self::value($m[2]);
                $b = isset($m[3]) && $m[3] !== '' ? self::value($m[3]) : ($step > 1 ? $hi : $a);
            }
            if ($a === null || $b === null || $a < $lo || $b > $hi || $a > $b) {
                return null;
            }
            for ($v = $a; $v <= $b; $v += $step) {
                $out[$v] = $v;
            }
        }
        return array_values($out);
    }

    private static function value(string $v): ?int
    {
        return ctype_digit($v) ? (int) $v : (self::NAMES[$v] ?? null);
    }

    /**
     * @param array<string,mixed> $in schedule, kind, domain_id, target (php: path inside the site folder), url, args
     */
    public static function create(array $me, array $in): void
    {
        $pdo = Database::app();
        $n = $pdo->prepare('SELECT COUNT(*) FROM cron_jobs WHERE user_id = ?');
        $n->execute([$me['id']]);
        if ((int) $n->fetchColumn() >= self::MAX_JOBS) {
            throw new InvalidArgumentException('At most ' . self::MAX_JOBS . ' cron jobs per account.');
        }
        [$schedule, $kind, $domainId, $target, $args] = self::validate($me, $in);
        $pdo->prepare('INSERT INTO cron_jobs (user_id, domain_id, schedule, kind, target, args) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$me['id'], $domainId, $schedule, $kind, $target, $args]);
    }

    /** @return array{0:string,1:string,2:?int,3:string,4:string} */
    public static function validate(array $me, array $in): array
    {
        $schedule = self::normaliseSchedule((string) ($in['schedule'] ?? ''))
            ?? throw new InvalidArgumentException('The schedule must be 5 cron fields, e.g. "*/15 * * * *" (or @hourly, @daily).');
        $kind = ($in['kind'] ?? 'php') === 'url' ? 'url' : 'php';
        if ($kind === 'url') {
            $url = trim((string) ($in['url'] ?? ''));
            if (!preg_match('#^https?://[^\s"\'<>]{1,1000}$#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('Enter a full URL, e.g. https://example.com/cron.php');
            }
            return [$schedule, 'url', null, $url, ''];
        }
        $s = Database::app()->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
        $s->execute([(int) ($in['domain_id'] ?? 0), $me['id']]);
        $domain = $s->fetch() ?: throw new InvalidArgumentException('Choose one of your domains.');
        $path = self::scriptPath((string) $domain['domain_name'], (string) ($in['target'] ?? ''));
        $args = trim((string) ($in['args'] ?? ''));
        if (strlen($args) > 255 || preg_match('/[\x00-\x1f`$;&|<>\\\\]/', $args)) {
            throw new InvalidArgumentException('Arguments can\'t contain shell characters ($ ; & | < > ` \\).');
        }
        return [$schedule, 'php', (int) $domain['id'], $path, $args];
    }

    /** Absolute path of a PHP script inside /var/www/<domain>/ (relative input), or an exception. */
    public static function scriptPath(string $domain, string $rel): string
    {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        $base = realpath(VhostService::siteDir($domain));
        $real = $base !== false ? realpath($base . '/' . $rel) : false;
        if ($rel === '' || str_contains($rel, '..') || $real === false || !is_file($real) || !str_ends_with($real, '.php') || !str_starts_with($real, $base . '/')) {
            throw new InvalidArgumentException("Enter a .php file inside /var/www/$domain/ (relative, e.g. public/cron.php) that exists.");
        }
        return $real;
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        $s = Database::app()->prepare('SELECT c.*, d.domain_name FROM cron_jobs c LEFT JOIN domains d ON d.id = c.domain_id WHERE c.user_id = ? ORDER BY c.id');
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    public static function lockFile(int $id): string
    {
        return sys_get_temp_dir() . "/jinnpanel-cron-$id.lock";
    }

    /** Runs one job now and records the result (cron-exec.php). */
    public static function execute(int $id): void
    {
        $pdo = Database::app();
        $s = $pdo->prepare('SELECT c.*, d.domain_name, u.status AS user_status FROM cron_jobs c JOIN users u ON u.id = c.user_id LEFT JOIN domains d ON d.id = c.domain_id WHERE c.id = ?');
        $s->execute([$id]);
        $job = $s->fetch();
        if (!$job || $job['user_status'] !== 'active') {
            return;
        }
        $lock = fopen(self::lockFile($id), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return; // the previous run is still going
        }
        $pdo->prepare('UPDATE cron_jobs SET last_run_at = NOW() WHERE id = ?')->execute([$id]);
        if ($job['kind'] === 'url') {
            $ch = curl_init((string) $job['target']);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'JinnPanel-Cron/1', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
            $body = curl_exec($ch);
            $status = $body === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $output = $body === false ? curl_error($ch) : (string) $body;
        } else {
            // Re-check: the script must still be inside that domain's folder.
            $script = self::scriptPath((string) $job['domain_name'], substr((string) $job['target'], strlen(VhostService::siteDir((string) $job['domain_name'])) + 1));
            $cmd = array_merge(['timeout', (string) self::TIMEOUT, PHP_BINARY, '-f', $script, '--'], $job['args'] !== '' ? preg_split('/\s+/', (string) $job['args']) : []);
            $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => VhostService::siteDir((string) $job['domain_name']),
                'JINNPANEL_DOCROOT' => VhostService::effectiveDocroot((string) $job['domain_name'])]; // site PHP settings (see install.sh's dispatcher)
            $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, dirname($script), $env);
            $output = '';
            if (is_resource($proc)) {
                while (!feof($pipes[1])) {
                    $output .= (string) fread($pipes[1], 65536);
                    if (strlen($output) > 1048576) {
                        $output = substr($output, -65536);
                    }
                }
                fclose($pipes[1]);
                $status = proc_close($proc);
                if ($status === 124) {
                    $status = -1;
                    $output .= "\n[stopped after " . self::TIMEOUT . " seconds]";
                }
            } else {
                $status = 127;
                $output = 'Could not start PHP.';
            }
        }
        $pdo->prepare('UPDATE cron_jobs SET last_status = ?, last_output = ? WHERE id = ?')
            ->execute([$status, mb_substr(mb_scrub($output), -4000), $id]);
        flock($lock, LOCK_UN);
    }

    /**
     * cPanel cron lines -> jobs. Recognised: "php [-q] /home/<user>/x.php [args]"
     * (any php binary) and "wget/curl <url>"; anything else is reported.
     *
     * @param array<string,string> $docrootMap cPanel docroot (relative to home, e.g. public_html) => domain
     * @return array{jobs: list<array>, skipped: list<string>}
     */
    public static function fromCpanel(string $crontab, string $user, array $docrootMap): array
    {
        $jobs = [];
        $skipped = [];
        foreach (preg_split('/\r?\n/', $crontab) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || preg_match('/^[A-Z_]+=/', $line)) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 6);
            if (str_starts_with($parts[0], '@')) {
                $schedule = self::normaliseSchedule($parts[0]);
                $cmd = trim(substr($line, strlen($parts[0])));
            } else {
                $schedule = count($parts) === 6 ? self::normaliseSchedule(implode(' ', array_slice($parts, 0, 5))) : null;
                $cmd = $parts[5] ?? '';
            }
            $cmd = trim(preg_replace('#\s*(>>?|2>&1|2>)\s*\S*\s*(2>&1)?\s*$#', '', $cmd)); // drop output redirection
            if ($schedule === null) {
                $skipped[] = "$line (schedule not understood)";
                continue;
            }
            if (preg_match('#^(?:/\S*/)?php(?:-cli)?(?:\d[\d.]*)?(?:\s+-[qf])*\s+(/home\d*/' . preg_quote($user, '#') . '/(\S+\.php))((?:\s+[^\s;&|<>`$]+)*)$#', $cmd, $m)) {
                $rel = $m[2];
                foreach ($docrootMap as $cpRoot => $domain) {
                    if (str_starts_with($rel, rtrim($cpRoot, '/') . '/')) {
                        $jobs[] = ['schedule' => $schedule, 'kind' => 'php', 'domain' => $domain,
                            'docroot_path' => substr($rel, strlen(rtrim($cpRoot, '/')) + 1), 'args' => trim($m[3])];
                        continue 2;
                    }
                }
                $skipped[] = "$line (the script is outside the sites' folders)";
            } elseif (preg_match('#^(?:wget|curl)\b.*?\b(https?://[^\s"\'<>]+)#', $cmd, $m)) {
                $jobs[] = ['schedule' => $schedule, 'kind' => 'url', 'url' => $m[1]];
            } else {
                $skipped[] = "$line (only PHP scripts and URL fetches are supported)";
            }
        }
        return ['jobs' => $jobs, 'skipped' => $skipped];
    }
}
