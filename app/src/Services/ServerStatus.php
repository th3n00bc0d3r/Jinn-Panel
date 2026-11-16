<?php
declare(strict_types=1);

/** Live numbers about the server for WHM (dashboard, alerts). Admin only. */
final class ServerStatus
{
    /**
     * Checked by whether each service's port accepts connections, not via
     * `systemctl is-active`: FrankenPHP's SELinux domain (httpd_t) may not
     * query systemd over D-Bus. "Can it serve a request" is the more useful
     * signal anyway, and a plain TCP connect needs no privileges.
     */
    public const SERVICES = [
        'mariadb' => 3306,
        'frankenphp' => 443,
        'stalwart' => 8080,
        'sftpgo' => 8090,
        'knot' => 53,
    ];

    /** @return array<string,string> service => 'active' | 'inactive' */
    public static function services(): array
    {
        $out = [];
        foreach (self::SERVICES as $svc => $port) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
            if ($conn) {
                fclose($conn);
            }
            $out[$svc] = $conn ? 'active' : 'inactive';
        }
        return $out;
    }

    /**
     * @return array{disk_total:int, disk_used:int, mem_total:int, mem_used:int, load:array<int,float>, cpus:int, uptime:int, services:array<string,string>}
     */
    public static function stats(): array
    {
        return self::machine() + ['services' => self::services()];
    }

    /**
     * Disk, memory, load, CPUs and uptime (no service checks).
     *
     * @return array{disk_total:int, disk_used:int, mem_total:int, mem_used:int, load:array<int,float>, cpus:int, uptime:int}
     */
    public static function machine(): array
    {
        $diskTotal = (int) (@disk_total_space('/') ?: 0);
        $diskFree = (int) (@disk_free_space('/') ?: 0);

        $memTotal = 0;
        $memAvailable = 0;
        foreach (@file('/proc/meminfo') ?: [] as $line) {
            if (str_starts_with($line, 'MemTotal:')) {
                $memTotal = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT) * 1024;
            } elseif (str_starts_with($line, 'MemAvailable:')) {
                $memAvailable = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT) * 1024;
            }
        }
        $cpus = 0;
        foreach (@file('/proc/cpuinfo') ?: [] as $line) {
            if (str_starts_with($line, 'processor')) {
                $cpus++;
            }
        }
        $uptime = (int) (float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0];

        return [
            'disk_total' => $diskTotal,
            'disk_used' => $diskTotal - $diskFree,
            'mem_total' => $memTotal,
            'mem_used' => $memTotal - $memAvailable,
            'load' => function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0],
            'cpus' => max(1, $cpus),
            'uptime' => $uptime,
        ];
    }
}
