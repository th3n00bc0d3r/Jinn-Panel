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
    T::same("php_fastcgi $sock {\n\ttry_files {path} {path}/index.php {path}\n}\nfile_server", VhostService::fpmRules('php_server', $sock), 'bare php_server (try_files ends in {path}: Caddy 2.11 first_exist_fallback)');
    $in = "handle @x {\n\trewrite * /a.php\n\tphp_server {\n\t\ttry_files {path} {path}/index.php\n\t}\n}";
    $out = VhostService::fpmRules($in, $sock);
    T::ok(!str_contains($out, 'php_server'), 'no php_server left', $out);
    T::ok(str_contains($out, "\tphp_fastcgi $sock {\n\t\ttry_files {path} {path}/index.php {path}\n\t}\n\tfile_server"), 'subdirectives kept ({path} fallback appended), file_server after', $out);
    T::same(substr_count($out, '{'), substr_count($out, '}'), 'braces balanced');
    T::same("php_fastcgi @m $sock {\n\ttry_files {path} {path}/index.php {path}\n}\nfile_server @m", VhostService::fpmRules('php_server @m', $sock), 'matcher kept on both');
    T::ok(str_contains(VhostService::fpmRules("php_server {\n\ttry_files {path} =404\n}", $sock), "try_files {path} =404\n"), '=status fallback left alone');
    T::same('respond "php_server" 200', VhostService::fpmRules('respond "php_server" 200', $sock), 'only directive position');
}

function test_vhost_static_server(): void
{
    $sock = 'unix//run/p.sock';
    $static = ['sock' => 'unix//run/jinnpanel-static/u/static.sock', 'ttl' => 300];
    $out = VhostService::fpmRules("php_server", $sock, $static);
    // In a route block: inside handle blocks Caddy would sort reverse_proxy before php_fastcgi
    // and the static server would get .php requests (it refuses them, but PHP must run).
    T::ok(str_starts_with($out, "route {\n\tphp_fastcgi $sock {\n\t\ttry_files {path} {path}/index.php {path}\n\t}\n\treverse_proxy unix//run/jinnpanel-static/u/static.sock {"), 'php_server -> route { pool, then static server }', $out);
    T::ok(str_contains($out, 'header_up X-JP-Root {http.vars.root}') && str_contains($out, 'header_up X-JP-TTL "300"'), 'root and cache lifetime passed', $out);
    T::ok(str_contains($out, "handle_response @jp_missing {\n\t\t\terror 404\n\t\t}"), '404 from the static server becomes a Caddy error', $out);
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

function test_routes_sync_plan(): void
{
    $rules = "# Generated\nrewrite /a /b\nphp_server";
    $gen = ['route' => $rules, 'site' => '', 'needs_review' => false];
    $in = ['route' => $rules . "\n", 'site' => null];
    $other = ['route' => "redir /x /y 301\nphp_server\n", 'site' => null];
    $none = ['route' => null, 'site' => null];
    $empty = ['route' => '', 'site' => '', 'needs_review' => false];

    // First look: on only when nothing would change.
    T::same('enable', RoutesService::syncPlan(null, null, 'h1', $in, $gen), 'rules in use are the translation');
    T::same('enable', RoutesService::syncPlan(null, null, 'h1', $none, $empty), 'no .htaccess, no rules');
    T::same('disable', RoutesService::syncPlan(null, null, 'h1', $other, $gen), 'rules edited by hand');
    T::same('disable', RoutesService::syncPlan(null, null, 'h1', $none, $gen), '.htaccess never applied');
    T::same('disable', RoutesService::syncPlan(null, null, 'h1', $other, $empty), 'rules without .htaccess');

    // Sync on.
    T::same('skip', RoutesService::syncPlan(true, 'h1', 'h1', $other, $gen), 'unchanged .htaccess');
    T::same('apply', RoutesService::syncPlan(true, 'h1', 'h2', $other, $gen), 'changed .htaccess');
    T::same('apply', RoutesService::syncPlan(true, null, 'h1', $other, $gen), 'just turned on');
    T::same('same', RoutesService::syncPlan(true, 'h1', 'h2', $in, $gen), 'changed, same translation');
    T::same('review', RoutesService::syncPlan(true, 'h1', 'h2', $other, ['needs_review' => true] + $gen), 'inexact translation never applied');
    T::same('apply', RoutesService::syncPlan(true, 'h1', 'h2', $other, $empty), '.htaccess removed: default routing');

    T::ok(RoutesService::filesHash(['' => 'a', 'x' => 'b'], '/d') === RoutesService::filesHash(['x' => 'b', '' => 'a'], '/d'), 'hash ignores order');
    T::ok(RoutesService::filesHash(['' => 'a'], '/d') !== RoutesService::filesHash(['' => 'a'], '/e'), 'hash includes the docroot');
}

function test_s3_signature_shape(): void
{
    $a = S3Client::authorization('PUT', 'bucket.s3.amazonaws.com', '/k', '', '20261001T000000Z', 'eu-central-1', 'AKID', 'SECRET');
    T::ok((bool) preg_match('#^AWS4-HMAC-SHA256 Credential=AKID/20261001/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', $a), 'SigV4 header', $a);
    T::same($a, S3Client::authorization('PUT', 'bucket.s3.amazonaws.com', '/k', '', '20261001T000000Z', 'eu-central-1', 'AKID', 'SECRET'), 'deterministic');
}

function test_ssh_public_keys(): void
{
    $blob = fn(string $type) => base64_encode(pack('N', strlen($type)) . $type . pack('N', 32) . str_repeat("\x01", 32));
    T::ok(FtpController::validKey('ssh-ed25519 ' . $blob('ssh-ed25519') . ' me@laptop'), 'ed25519 with comment');
    T::ok(FtpController::validKey('ssh-ed25519 ' . $blob('ssh-ed25519')), 'ed25519 without comment');
    T::ok(!FtpController::validKey('ssh-rsa ' . $blob('ssh-ed25519')), 'type in the blob must match');
    T::ok(!FtpController::validKey('ssh-ed25519 AAAA'), 'too short');
    T::ok(!FtpController::validKey('-----BEGIN OPENSSH PRIVATE KEY-----'), 'private key refused');
    T::ok(!FtpController::validKey("ssh-ed25519 " . $blob('ssh-ed25519') . " x\nssh-rsa y"), 'no second line smuggled in');
}

function test_recovery_code_shape(): void
{
    T::ok(RecoveryCodes::looksLikeOne('k7mq-2xpd-9fhw'), 'dashed');
    T::ok(RecoveryCodes::looksLikeOne(' K7MQ 2XPD 9FHW '), 'spaces and case');
    T::ok(!RecoveryCodes::looksLikeOne('123456'), 'a TOTP code is not one');
}

function test_ui_components_escape(): void
{
    require_once APP . '/src/View.php';
    $_SESSION = ['csrf' => str_repeat('a', 64)];
    $html = Ui::field('name', 'Name <b>', ['value' => '"><script>x</script>', 'hint' => 'h&i', 'required' => true]);
    T::ok(!str_contains($html, '<script>') && !str_contains($html, '<b>'), 'label, value escaped', $html);
    T::ok((bool) preg_match('/<label for="(f-\d+)"[^>]*>.*<input[^>]* id="\1"/s', $html), 'label tied to the input', $html);
    T::ok(str_contains($html, 'aria-describedby="') && str_contains($html, 'required'), 'hint described, required');
    T::same(' a="1" b', Ui::attrs(['a' => 1, 'b' => true, 'c' => false, 'd' => null]), 'attrs: true bare, false/null dropped');
    $_SESSION['old'] = ['email' => 'kept@example.com', 'password' => 'secret'];
    T::ok(str_contains(Ui::field('email', 'Email'), 'value="kept@example.com"'), 'old value refilled');
    T::ok(!str_contains(Ui::field('password', 'Password', ['type' => 'password']), 'secret'), 'never a password');
    $form = Ui::deleteForm('/x/1/delete', 'Really "delete"?');
    T::ok(str_contains($form, 'data-confirm="Really &quot;delete&quot;?"') && str_contains($form, 'aria-label="Delete"') && str_contains($form, 'name="csrf_token"'), 'delete form: confirm, name, CSRF', $form);
    T::same('badge badge-danger', preg_replace('/^<span class="([^"]+)".*$/s', '$1', Ui::status('failed')), 'status tone');
}

function test_package_features(): void
{
    $all = Features::forPackage(null);
    T::ok(count($all) === count(Features::LIST) && !in_array(false, $all, true), 'no setting: everything on');
    T::ok(!in_array(false, Features::forPackage(['features' => null]), true), 'NULL column: everything on');
    $pkg = ['features' => Features::fromForm(['features' => ['cron' => '1', 'file_manager' => '1']])];
    $f = Features::forPackage($pkg);
    T::ok($f['cron'] && $f['file_manager'] && !$f['backups'] && !$f['ssh_keys'], 'unchecked boxes are off', json_encode($f));
    T::same(['dns_editor' => false, 'ssh_keys' => true], Features::overrides(['feature_overrides' => Features::overridesFromForm(['override' => ['dns_editor' => '0', 'ssh_keys' => '1', 'cron' => '', 'bogus' => '1']])]), 'overrides: only set, known keys');
    T::same(null, Features::overridesFromForm(['override' => ['cron' => '']]), 'no overrides: NULL');
    T::same(true, Features::forPackage(['features' => '{"cron":false}'])['backups'], 'a key missing from a package is on');
}

function test_unlimited_limits(): void
{
    T::ok(Quota::unlimited(-1) && Quota::unlimited('-1') && !Quota::unlimited(0) && !Quota::unlimited(5), 'unlimited is -1');
    T::same(0, Quota::bytes(-1), 'unlimited MB -> 0 bytes (no limit)');
    T::same(5 * 1048576, Quota::bytes(5), 'MB -> bytes');
    T::same('Unlimited', Quota::fmtMb(-1), 'fmtMb unlimited');
    T::same('5 GB', Quota::fmtMb(5120), 'fmtMb GB');
    T::same('1.5 GB', Quota::fmtMb(1536), 'fmtMb decimals');
    T::same('512 MB', Quota::fmtMb(512), 'fmtMb MB');
    T::same('Unlimited', Quota::fmtCount(-1), 'fmtCount unlimited');
    T::same('0', Quota::fmtCount(0), 'fmtCount none');
}

function test_quota_notice_levels(): void
{
    T::same(0, UsageNoticeService::level(500, 0), 'no limit: never');
    T::same(0, UsageNoticeService::level(89, 100), 'under 90%');
    T::same(90, UsageNoticeService::level(90, 100), 'at 90%');
    T::same(100, UsageNoticeService::level(101, 100), 'over');
    T::same(100, UsageNoticeService::level(50, 100, true), 'flagged over by the usage run');
}

function test_chart_scale_and_formats(): void
{
    require_once APP . '/src/View.php';
    T::same([4.0, [0.0, 1.0, 2.0, 3.0, 4.0]], Chart::scale(0), 'nothing yet: 0..4');
    T::same([20000.0, [0.0, 5000.0, 10000.0, 15000.0, 20000.0]], Chart::scale(17436), 'rounds up to 1/2/2.5/5 steps');
    T::same([0.8, [0.0, 0.2, 0.4, 0.6, 0.8]], Chart::scale(0.62), 'fractions (load)');
    T::same(100.0, Chart::scale(100)[0], 'an exact step stays');
    T::same(2000.0, Chart::scale(1600)[0], '1600 -> 2000 (step 500)');
    T::same('1,284', Chart::compact(1284), 'compact under 10K keeps digits');
    T::same('12.9K', Chart::compact(12900), 'compact K');
    T::same('3.4M', Chart::compact(3400000), 'compact M');
    T::same('20K', Chart::compact(20000), 'no trailing .0');
    T::same('0.25', Chart::compact(0.25), 'small fractions');
    T::same('0 B', Chart::bytes(0), 'bytes zero');
    T::same('1.5 GB', Chart::bytes(1610612736), 'bytes GB');
    T::same('512 KB', Chart::bytes(524288), 'bytes KB');
    T::same('43%', Chart::format(42.6, 'pct'), 'pct');
    T::same('180 ms', Chart::format(180, 'ms'), 'ms');
    T::same('1.2 s', Chart::format(1240, 'ms'), 'ms over a second');
    T::same('0.62', Chart::format(0.62, 'load'), 'load');
    T::same('1,234,567', Chart::format(1234567, 'count'), 'count');
}

function test_chart_markup(): void
{
    require_once APP . '/src/View.php';
    View::reset();
    $html = Chart::columns(
        [['name' => 'a<b>'], ['name' => 'b'], ['name' => 'c'], ['name' => 'd'], ['name' => 'e']],
        [[1, 2, 3, 4, 5], [5, 4, 3, 2, 1]],
        ['Mon', 'Tue'],
        'Requests "per" day'
    );
    T::ok(!str_contains($html, '<b>') && str_contains($html, 'a&lt;b&gt;'), 'series names escaped');
    T::ok(str_contains($html, 'aria-label="Requests &quot;per&quot; day.'), 'label escaped, role img');
    T::ok(str_contains($html, '&quot;name&quot;:&quot;e&quot;,&quot;series&quot;:&quot;so&quot;') && str_contains($html, '<span class="so"'), 'a fifth series folds into Other');
    T::ok(str_contains($html, 'Show as table') && substr_count($html, '<tr>') === 3, 'table view: header + 2 rows');
    T::same(['charts'], View::scripts(), 'charts.js is loaded once');
    Chart::line([['name' => 'x', 'values' => [1, 2]]], ['a', 'b'], 'x');
    T::same(['charts'], View::scripts(), 'and only once');
    T::ok(str_contains(Chart::line([], [], 'none'), 'No data yet'), 'empty data: a note, not a broken chart');
    T::ok(str_contains(Chart::sparkline([3], 'one'), 'No data yet'), 'one point is not a line');
    T::ok(!str_contains(Chart::columns([['name' => 'a']], [[1], [2]], ['x', 'y'], 'one series'), 'Total'), 'one series: no total');
}

function test_access_log_stats(): void
{
    $hashes = [];
    $agg = new AccessLogStats(['acme.test' => 1, 'www.acme.test' => 1, 'acme.example' => 1, 'bakery.test' => 2], function (string $ip, string $ua, string $day) use (&$hashes): string {
        $hashes[] = $ip;
        return substr(hash('sha256', "salt|$ip|$ua", true), 0, 8);
    });
    $line = fn(array $e) => json_encode($e + ['ts' => 1763100000.5, 'status' => 200, 'size' => 1000, 'duration' => 0.25]);
    $req = fn(string $host, string $uri, array $h = [], string $type = 'text/html; charset=utf-8') => [
        'request' => ['host' => $host, 'uri' => $uri, 'method' => 'GET', 'client_ip' => '203.0.113.9', 'headers' => $h + ['User-Agent' => ['Mozilla/5.0 Firefox']]],
        'resp_headers' => $type === '' ? [] : ['Content-Type' => [$type]],
    ];
    T::ok($agg->add($line($req('www.acme.test', '/shop?utm=1', ['Referer' => ['https://www.google.com/search']]))), 'www. counts for the domain');
    T::ok($agg->add($line($req('acme.example:443', '/shop'))), 'an alias with a port');
    T::ok($agg->add($line($req('acme.test', '/app.css', [], ''))), 'a file');
    T::ok($agg->add($line(['status' => 404] + $req('acme.test', '/wp-login.php'))), '404 page');
    T::ok($agg->add($line(['status' => 503, 'duration' => 2.5] + $req('acme.test', '/slow'))), 'slow 5xx');
    T::ok($agg->add($line($req('acme.test', '/', ['User-Agent' => ['Googlebot/2.1'], 'Referer' => ['https://acme.test/about']]))), 'bot from its own site');
    T::ok(!$agg->add($line($req('other.test', '/'))), 'unknown host skipped');
    T::ok(!$agg->add('not json'), 'garbage skipped');
    T::same(8, $agg->lines, 'lines seen');
    T::same(6, $agg->counted, 'lines counted');

    $hour = array_values($agg->hourly()[1])[0];
    T::same(6, $hour['requests'], 'requests');
    T::same(5, $hour['pages'], 'pages: not the .css');
    T::same([4, 0, 1, 1], [$hour['s2xx'], $hour['s3xx'], $hour['s4xx'], $hour['s5xx']], 'status classes');
    T::same(1, $hour['slow'], 'one page over a second');
    T::same(250 * 4 + 2500, $hour['time_ms'], 'page time summed');
    T::same(6000, $agg->bytes()[1], 'bytes for bandwidth');
    $top = $agg->top()[1][date('Y-m-d', 1763100000)];
    T::same(['/shop' => 2, '/' => 1], $top['page'], 'top pages: query dropped, errors left out');
    T::same(['google.com' => 1], $top['referrer'], 'referrer host without www., own site left out');
    $visitors = $agg->visitors()[1][date('Y-m-d', 1763100000)];
    T::same(1, count($visitors), 'the same browser is one visitor; bots are none');
    T::ok(!in_array('203.0.113.9', array_map('strval', array_keys($visitors)), true), 'no address kept');

    T::same('/a b', AccessLogStats::path('/a%20b?x=1#y'), 'path decoded, query dropped');
    T::same(190, mb_strlen(AccessLogStats::path('/' . str_repeat('x', 300))), 'path capped');
    T::same('', AccessLogStats::referrer('android-app://com.x'), 'not a web referrer');
    T::same(250, AccessLogStats::durationMs(0.25), 'seconds');
    T::same(13, AccessLogStats::durationMs('12.5ms'), 'string');
    T::same(1500, AccessLogStats::durationMs(1500000000), 'nanoseconds');

    $many = new AccessLogStats(['a.test' => 1], fn() => '12345678');
    for ($i = 0; $i < 5; $i++) {
        $many->add($line($req('a.test', "/p$i")));
    }
    $t = $many->top(3)[1][date('Y-m-d', 1763100000)]['page'];
    T::same(4, count($t), 'kept 3 + (other)');
    T::same(2, $t[AccessLogStats::OTHER] ?? 0, 'the rest under (other)');
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
