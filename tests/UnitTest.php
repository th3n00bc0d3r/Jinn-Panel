<?php
declare(strict_types=1);

/**
 * Unit tests for the parts of JinnPanel that don't need a server: run with
 *
 *     php tests/UnitTest.php [name-filter]
 *
 * No database, no services, no root - just PHP (with zip and phar). Each
 * test is a function named test_*; exits non-zero on any failure. The
 * integration test for the .htaccess translator (which needs the
 * frankenphp binary) is tests/HtaccessTranslatorTest.php.
 */

const APP = __DIR__ . '/../app';

// The few Config constants the classes under test read.
final class Config
{
    public const SERVER_HOSTNAME = 'server.example.test';
    public const SESSION_NAME = 'hostpanel_sid';
    public const VHOSTS_CADDY_DIR = '/nonexistent';
    public const VHOSTS_DOCROOT_BASE = '/var/www';
}

spl_autoload_register(function (string $class): void {
    foreach (['src', 'src/Services', 'src/Support', 'src/Controllers'] as $dir) {
        $f = APP . "/$dir/$class.php";
        if (is_file($f)) {
            require $f;
            return;
        }
    }
});

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static string $test = '';

    public static function ok(bool $cond, string $what, string $detail = ''): void
    {
        if ($cond) {
            self::$pass++;
            return;
        }
        self::$fail++;
        echo '  FAIL [' . self::$test . "] $what" . ($detail !== '' ? "\n       $detail" : '') . "\n";
    }

    public static function same(mixed $want, mixed $got, string $what): void
    {
        self::ok($want === $got, $what, 'want ' . var_export($want, true) . ', got ' . var_export($got, true));
    }

    public static function throws(callable $fn, string $what): void
    {
        try {
            $fn();
            self::ok(false, $what, 'no exception');
        } catch (Throwable) {
            self::ok(true, $what);
        }
    }
}

function scratch(): string
{
    $d = sys_get_temp_dir() . '/jp-unit-' . bin2hex(random_bytes(4));
    mkdir($d, 0700, true);
    register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($d)));
    return $d;
}

// ---------------------------------------------------------------------------

function test_totp_rfc6238(): void
{
    // RFC 6238 appendix B, SHA-1 secret "12345678901234567890": 6-digit codes.
    $key = '12345678901234567890';
    foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $t => $want) {
        T::same($want, Totp::code($key, intdiv($t, 30)), "code at T=$t");
    }
    $secret = Totp::base32($key);
    T::same($key, Totp::unbase32($secret), 'base32 round trip');
    T::same(1, Totp::verify($secret, '287082', null, 59), 'verify returns the time step');
    T::same(null, Totp::verify($secret, '287082', 1, 59), 'a used step is refused (no replay)');
    T::same(null, Totp::verify($secret, '000000', null, 59), 'wrong code');
    T::same(1, Totp::verify($secret, '287 082', null, 89), 'one step of drift, spaces allowed');
    T::same(32, strlen(Totp::newSecret()), '160-bit secrets');
    T::ok(str_starts_with(Totp::uri($secret, 'me@x', 'JinnPanel'), 'otpauth://totp/JinnPanel%3Ame%40x?secret='), 'otpauth URI');
}

function test_usernames(): void
{
    T::ok(Usernames::problem('Bob') !== null, 'uppercase refused');
    T::ok(Usernames::problem('ab') !== null, 'too short');
    T::ok(Usernames::problem('a_b') !== null, 'underscore refused for hosting accounts');
    foreach (['root', 'hostpanel', 'hostpanelx', 'mysql', 'frankenphp', 'admin', 'pma2'] as $n) {
        T::ok(Usernames::isReserved($n), "$n reserved");
    }
    T::same(null, Usernames::problem('shopkeeper'), 'a normal name');
    T::same(null, Usernames::loginProblem('bob_whm'), 'reseller logins may have underscores');
    T::same('jp_shopkeeper', Usernames::linuxUser('shopkeeper'), 'Linux user name');
}

function test_passwords(): void
{
    T::ok(Passwords::problem('short') !== null, 'too short');
    T::ok(Passwords::problem('password123') !== null, 'common password');
    T::ok(Passwords::problem('aaaaaaaaaaaa') !== null, 'too few distinct characters');
    T::ok(Passwords::problem('xbobsmith-2026!', 'bobsmith') !== null, 'contains the username');
    T::same(null, Passwords::problem('correct horse battery'), 'a fine password');
}

function test_vhost_php_server_rewrite(): void
{
    $sock = 'unix//run/jinnpanel-php/default/u.sock';
    T::same("php_fastcgi $sock\nfile_server", VhostService::fpmRules('php_server', $sock), 'bare php_server');
    $in = "handle @x {\n\trewrite * /a.php\n\tphp_server {\n\t\ttry_files {path} {path}/index.php\n\t}\n}";
    $out = VhostService::fpmRules($in, $sock);
    T::ok(!str_contains($out, 'php_server'), 'no php_server left', $out);
    T::ok(str_contains($out, "\tphp_fastcgi $sock {\n\t\ttry_files {path} {path}/index.php\n\t}\n\tfile_server"), 'subdirectives kept, file_server after', $out);
    T::same(substr_count($in, '{'), substr_count($out, '{'), 'braces balanced');
    T::same("php_fastcgi @m $sock\nfile_server @m", VhostService::fpmRules('php_server @m', $sock), 'matcher kept on both');
    T::same('respond "php_server" 200', VhostService::fpmRules('respond "php_server" 200', $sock), 'only directive position');
}

function test_vhost_static_server(): void
{
    $sock = 'unix//run/p.sock';
    $static = ['sock' => 'unix//run/jinnpanel-static/u/static.sock', 'ttl' => 300];
    $out = VhostService::fpmRules("php_server", $sock, $static);
    T::ok(str_contains($out, "php_fastcgi $sock\nreverse_proxy unix//run/jinnpanel-static/u/static.sock {"), 'php_server -> pool + static server', $out);
    T::ok(str_contains($out, 'header_up X-JP-Root {http.vars.root}') && str_contains($out, 'header_up X-JP-TTL "300"'), 'root and cache lifetime passed', $out);
    T::ok(str_contains($out, "handle_response @jp_missing {\n\t\terror 404\n\t}"), '404 from the static server becomes a Caddy error', $out);
    T::ok(!str_contains(VhostService::fpmRules("file_server", $sock, $static), 'file_server'), 'file_server replaced');
    $err = VhostService::fpmRules("handle_errors 404 {\n\trewrite * /404.html\n\tfile_server\n}", $sock, $static);
    T::ok(str_contains($err, 'copy_response 404') && !str_contains($err, 'error 404'), 'error pages keep their status', $err);
    $multi = VhostService::fpmRules("handle_errors 403 404 {\n\trewrite * /e.html\n\tfile_server\n}\nheader X-A b", $sock, $static);
    T::ok(str_contains($multi, 'handle_errors 403 {') && str_contains($multi, 'handle_errors 404 {') && str_contains($multi, 'copy_response 403') && str_contains($multi, 'copy_response 404'), 'multi-code error blocks split', $multi);
    T::ok(str_ends_with($multi, 'header X-A b'), 'lines after the block kept', $multi);
    T::same(substr_count($multi, '{'), substr_count($multi, '}'), 'braces balanced');
}

function test_cron_schedules(): void
{
    T::same('*/15 * * * *', CronService::normaliseSchedule(' */15  * * * * '), 'normalised');
    T::same('0 0 * * *', CronService::normaliseSchedule('@daily'), 'macro');
    T::same(null, CronService::normaliseSchedule('61 * * * *'), 'minute out of range');
    T::same(null, CronService::normaliseSchedule('* * * *'), 'four fields');
    $ts = mktime(3, 30, 0, 10, 5, 2026); // Monday 2026-10-05 03:30
    T::ok(CronService::due('30 3 * * *', $ts), 'daily at 03:30');
    T::ok(CronService::due('*/10 * * * 1', $ts), 'every 10 min on Mondays');
    T::ok(!CronService::due('30 3 * * 0', $ts), 'not on Sundays');
    T::ok(CronService::due('30 3 1 * mon', $ts), 'day-of-month OR day-of-week when both restricted');
}

function test_mysqldump_rewrite(): void
{
    T::same(null, ProvisioningService::rewriteDumpLine("SET @@GLOBAL.GTID_PURGED='x';\n"), 'GTID dropped');
    $view = (string) ProvisioningService::rewriteDumpLine("CREATE ALGORITHM=UNDEFINED DEFINER=`u`@`localhost` VIEW v AS SELECT 1;\n");
    T::ok(!str_contains($view, 'DEFINER') && str_contains($view, 'VIEW v AS SELECT 1'), 'DEFINER stripped', $view);
    T::ok(str_contains((string) ProvisioningService::rewriteDumpLine("  `c` varchar(5) COLLATE utf8mb4_0900_ai_ci,\n"), 'utf8mb4_unicode_ci'), 'MySQL 8 collation mapped');
    T::same("INSERT INTO t VALUES ('DEFINER=x');\n", ProvisioningService::rewriteDumpLine("INSERT INTO t VALUES ('DEFINER=x');\n"), 'data untouched');
}

function test_file_manager_archives(): void
{
    $root = scratch() . '/example.test';
    mkdir("$root/public", 0755, true);
    $fm = new FileManagerService($root);
    $pub = $fm->dir('public');

    $zip = new ZipArchive();
    $zip->open("$pub/ok.zip", ZipArchive::CREATE);
    $zip->addFromString('a/b.txt', 'hello');
    $zip->close();
    T::same(1, $fm->extract("$pub/ok.zip", $pub), 'zip extracted');
    T::same('hello', @file_get_contents("$pub/a/b.txt"), 'zip content');

    $zip = new ZipArchive();
    $zip->open("$pub/evil.zip", ZipArchive::CREATE);
    $zip->addFromString('../../escape.txt', 'x');
    $zip->close();
    T::throws(fn() => $fm->extract("$pub/evil.zip", $pub), 'zip with ../ refused');
    T::ok(!file_exists(dirname($root) . '/escape.txt'), 'nothing written outside');

    $tmp = scratch();
    mkdir("$tmp/t/d", 0755, true);
    file_put_contents("$tmp/t/d/f.txt", 'tar!');
    exec('tar czf ' . escapeshellarg("$pub/ok.tgz") . ' -C ' . escapeshellarg("$tmp/t") . ' d');
    T::same(1, $fm->extract("$pub/ok.tgz", $pub), 'tar.gz extracted (PharData)');
    T::same('tar!', @file_get_contents("$pub/d/f.txt"), 'tar content');

    symlink('/etc', "$tmp/t/link");
    exec('tar czf ' . escapeshellarg("$pub/link.tgz") . ' -C ' . escapeshellarg("$tmp/t") . ' link');
    T::throws(fn() => $fm->extract("$pub/link.tgz", $pub), 'tar with a link refused');

    T::same($root, $fm->dir('../../..'), 'paths outside fall back to the root');
    T::throws(fn() => $fm->entry($pub, '..'), '".." is not a name');
    $zipPath = $fm->compress($pub, ["$pub/a", "$pub/d"], 'bundle');
    T::ok(is_file($zipPath) && str_ends_with($zipPath, 'bundle.zip'), 'compress');
    $fm->chmod("$pub/a", '600', '700', true);
    T::same('700', sprintf('%o', fileperms("$pub/a") & 0777), 'chmod dir');
    T::same('600', sprintf('%o', fileperms("$pub/a/b.txt") & 0777), 'chmod file');
    $fm->delete("$pub/a");
    T::ok(!file_exists("$pub/a"), 'delete folder');
}

function test_fastcgi_records(): void
{
    // Round trip through a socket pair with a minimal responder on the other end.
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $dir = scratch();
    $sock = "$dir/fcgi.sock";
    $server = stream_socket_server("unix://$sock");
    $pid = pcntl_fork();
    if ($pid === 0) {
        $c = stream_socket_accept($server, 5);
        $params = '';
        $stdin = '';
        while (true) {
            $h = fread($c, 8);
            $r = unpack('Cv/Ct/nid/nlen/Cpad', $h);
            $body = $r['len'] ? stream_get_contents($c, $r['len']) : '';
            $r['pad'] && fread($c, $r['pad']);
            if ($r['t'] === 4) {
                $params .= $body;
            } elseif ($r['t'] === 5) {
                if ($body === '') {
                    break;
                }
                $stdin .= $body;
            }
        }
        $out = "Status: 201 Created\r\nX-Test: yes\r\n\r\n" . strlen($stdin) . ':' . (str_contains($params, 'JINNPANEL_OP') ? 'op' : '-');
        fwrite($c, pack('CCnnCx', 1, 6, 1, strlen($out), 0) . $out);
        fwrite($c, pack('CCnnCx', 1, 3, 1, 8, 0) . pack('NCx3', 0, 0));
        exit(0);
    }
    $body = str_repeat('z', 200000);
    $r = FastCgi::request($sock, ['JINNPANEL_OP' => 'list', 'LONG' => str_repeat('v', 300)], $body);
    pcntl_waitpid($pid, $st);
    T::same(201, $r['status'], 'status header');
    T::same('yes', $r['headers']['x-test'] ?? null, 'headers parsed');
    T::same('200000:op', $r['body'], 'large body and long params arrive');
    unset($pair);
}

function test_htaccess_rules_validate_php_server(): void
{
    $errors = HtaccessTranslator::validate("try_files {path} /index.php\nphp_server", '/var/www/example.test/public', 'route');
    T::same([], $errors, 'simple route rules are valid');
    T::ok(HtaccessTranslator::validate("reverse_proxy 127.0.0.1:2019\nphp_server", '/var/www/example.test/public', 'route') !== [], 'reverse_proxy refused');
    T::ok(HtaccessTranslator::validate("import /etc/passwd\nphp_server", '/var/www/example.test/public', 'route') !== [], 'import refused');
}

function test_s3_signature_shape(): void
{
    $a = S3Client::authorization('PUT', 'bucket.s3.amazonaws.com', '/k', '', '20261001T000000Z', 'eu-central-1', 'AKID', 'SECRET');
    T::ok((bool) preg_match('#^AWS4-HMAC-SHA256 Credential=AKID/20261001/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', $a), 'SigV4 header', $a);
    T::same($a, S3Client::authorization('PUT', 'bucket.s3.amazonaws.com', '/k', '', '20261001T000000Z', 'eu-central-1', 'AKID', 'SECRET'), 'deterministic');
}

// ---------------------------------------------------------------------------

$filter = $argv[1] ?? '';
foreach (get_defined_functions()['user'] as $fn) {
    if (!str_starts_with($fn, 'test_') || ($filter !== '' && !str_contains($fn, $filter))) {
        continue;
    }
    T::$test = substr($fn, 5);
    try {
        $fn();
    } catch (Throwable $e) {
        T::ok(false, 'threw ' . get_class($e), $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
echo T::$fail === 0 ? 'OK' : 'FAILED', ': ', T::$pass, ' passed, ', T::$fail, " failed\n";
exit(T::$fail === 0 ? 0 : 1);
