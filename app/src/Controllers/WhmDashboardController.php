<?php
declare(strict_types=1);

final class WhmDashboardController
{
    public static function index(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $user = Auth::user();
        $pdo = Database::app();

        if ($user['role'] === 'admin') {
            $resellerCount = (int) $pdo->query("SELECT COUNT(*) c FROM users WHERE role='reseller'")->fetch()['c'];
            $userCount = (int) $pdo->query("SELECT COUNT(*) c FROM users WHERE role='user'")->fetch()['c'];
            $domainCount = (int) $pdo->query('SELECT COUNT(*) c FROM domains')->fetch()['c'];
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE role='user' AND parent_id = ?");
            $stmt->execute([$user['id']]);
            $resellerCount = 0;
            $userCount = (int) $stmt->fetch()['c'];

            $stmt = $pdo->prepare('SELECT COUNT(*) c FROM domains d JOIN users u ON u.id = d.user_id WHERE u.parent_id = ?');
            $stmt->execute([$user['id']]);
            $domainCount = (int) $stmt->fetch()['c'];
        }

        View::render('whm/dashboard', [
            'title' => 'Dashboard',
            'resellerCount' => $resellerCount,
            'userCount' => $userCount,
            'domainCount' => $domainCount,
            'stats' => $user['role'] === 'admin' ? self::serverStats() : null,
            'logServices' => $user['role'] === 'admin' ? self::logServices() : [],
            'selectedLog' => $_GET['log'] ?? 'frankenphp',
            'logContent' => $user['role'] === 'admin' ? self::readLog((string) ($_GET['log'] ?? 'frankenphp')) : null,
        ], 'whm');
    }

    public static function pullLog(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $service = (string) ($_POST['service'] ?? 'frankenphp');
        if (!in_array($service, self::logServices(), true)) {
            $service = 'frankenphp';
        }
        SystemWorkerService::enqueue("pull-{$service}", ['type' => 'pull_log', 'service' => $service, 'lines' => 1000]);
        Flash::ok("Pulling the latest 1000 lines for $service - ready in a few seconds.");
        header('Location: /whm?log=' . urlencode($service));
        exit;
    }

    /** Services with a live log snapshot on disk - written every 5s by hostpanel-worker.php. */
    private static function logServices(): array
    {
        $fixed = ['mariadb', 'frankenphp', 'stalwart', 'sftpgo', 'knot'];
        $alt = [];
        foreach (glob(__DIR__ . '/../../storage/logs/live-frankenphp-php*.log') ?: [] as $f) {
            $alt[] = basename($f, '.log') !== '' ? str_replace('live-', '', basename($f, '.log')) : null;
        }
        return array_values(array_filter(array_merge($fixed, $alt)));
    }

    private static function readLog(string $service): string
    {
        $file = __DIR__ . "/../../storage/logs/live-{$service}.log";
        if (!is_file($file)) {
            return "No log data yet for \"$service\" - the background worker refreshes this every 5 seconds; it may not have run yet.";
        }
        return (string) file_get_contents($file) ?: '(empty)';
    }

    private static function serverStats(): array
    {
        $diskTotal = @disk_total_space('/') ?: 0;
        $diskFree = @disk_free_space('/') ?: 0;
        $diskUsed = $diskTotal - $diskFree;

        $memTotal = 0;
        $memAvailable = 0;
        $meminfo = @file('/proc/meminfo');
        if ($meminfo) {
            foreach ($meminfo as $line) {
                if (str_starts_with($line, 'MemTotal:')) {
                    $memTotal = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT) * 1024;
                }
                if (str_starts_with($line, 'MemAvailable:')) {
                    $memAvailable = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT) * 1024;
                }
            }
        }

        // Checked by whether each service's port actually accepts connections,
        // not via `systemctl is-active`: FrankenPHP's SELinux domain (httpd_t)
        // is deliberately not allowed to query systemd over D-Bus ("Access
        // denied" from systemd itself, not a misconfiguration to work around).
        // A port check is arguably the more meaningful signal anyway - it's
        // "can this thing actually serve a request", not just "does its unit
        // exist" - and needs no special privileges since it's a plain TCP
        // connect the app already has via httpd_can_network_connect.
        $services = [
            'mariadb' => 3306,
            'frankenphp' => 443,
            'stalwart' => 8080,
            'sftpgo' => 8090,
            'knot' => 53,
        ];
        $serviceStatus = [];
        foreach ($services as $svc => $port) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
            if ($conn) {
                fclose($conn);
                $serviceStatus[$svc] = 'active';
            } else {
                $serviceStatus[$svc] = 'inactive';
            }
        }

        return [
            'disk_total' => $diskTotal,
            'disk_used' => $diskUsed,
            'mem_total' => $memTotal,
            'mem_used' => $memTotal - $memAvailable,
            'services' => $serviceStatus,
        ];
    }
}
