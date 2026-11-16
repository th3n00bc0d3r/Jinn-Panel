<?php
declare(strict_types=1);

/**
 * How much of its own package a reseller has handed out: the packages of
 * its hosting accounts added up (disk, bandwidth, domains) and the number
 * of accounts, against the reseller's own package. A reseller can't go
 * over it (creating an account, moving one to a bigger package, raising
 * one of its own packages); an administrator can, and the reseller's page
 * then shows it as over.
 */
final class ResellerService
{
    /** metric => [package column, label] */
    public const METRICS = [
        'disk' => ['disk_quota_mb', 'Disk'],
        'bandwidth' => ['bandwidth_mb', 'Bandwidth'],
        'domains' => ['max_domains', 'Domains'],
    ];

    /**
     * What is handed out and what is allowed. Each metric: used (-1 when an
     * account's package is unlimited) and cap (-1 = unlimited). With $what,
     * the totals as they would be after a change:
     *  - 'add' => package row       a new account on that package
     *  - 'swap' => [userId => row]  an account moved to another package
     *  - 'pkg' => [pkgId => row]    a package's limits changed
     *
     * @param array<string,mixed> $what
     * @return array{package:?array, accounts:array{used:int,cap:int}, disk:array{used:int,cap:int}, bandwidth:array{used:int,cap:int}, domains:array{used:int,cap:int}}
     */
    public static function allocation(int $resellerId, array $what = []): array
    {
        $pdo = Database::app();
        $s = $pdo->prepare('SELECT p.* FROM users u LEFT JOIN packages p ON p.id = u.package_id WHERE u.id = ?');
        $s->execute([$resellerId]);
        $own = $s->fetch() ?: null;
        $own = $own && $own['id'] !== null ? $own : null;

        $s = $pdo->prepare("SELECT u.id AS uid, p.* FROM users u LEFT JOIN packages p ON p.id = u.package_id WHERE u.parent_id = ? AND u.role = 'user'");
        $s->execute([$resellerId]);
        $rows = [];
        foreach ($s->fetchAll() as $r) {
            $pkg = $r;
            if (isset($what['swap'][(int) $r['uid']])) {
                $pkg = $what['swap'][(int) $r['uid']];
            } elseif ($r['id'] !== null && isset($what['pkg'][(int) $r['id']])) {
                $pkg = $what['pkg'][(int) $r['id']];
            }
            $rows[] = $pkg;
        }
        if (!empty($what['add'])) {
            $rows[] = $what['add'];
        }

        $out = ['package' => $own, 'accounts' => ['used' => count($rows), 'cap' => $own ? (int) $own['max_accounts'] : 0]];
        foreach (self::METRICS as $m => [$col]) {
            $used = 0;
            foreach ($rows as $pkg) {
                if ($pkg === null || $pkg[$col] === null) {
                    continue;
                }
                if (Quota::unlimited($pkg[$col])) {
                    $used = Quota::UNLIMITED;
                    break;
                }
                $used += (int) $pkg[$col];
            }
            $out[$m] = ['used' => $used, 'cap' => $own ? (int) $own[$col] : 0];
        }
        return $out;
    }

    /**
     * What would go over the reseller's package after $what (see
     * allocation()). A change that doesn't add to a metric is fine even when
     * the reseller is already over it (an administrator put it there).
     *
     * @return list<string>
     */
    public static function problems(int $resellerId, array $what): array
    {
        $before = self::allocation($resellerId);
        $after = self::allocation($resellerId, $what);
        if ($after['package'] === null) {
            return ['You have no package of your own, so you can\'t hand out resources - ask the server administrator.'];
        }
        $rank = fn(int $v): int => $v < 0 ? PHP_INT_MAX : $v;
        $out = [];
        foreach (self::METRICS as $m => [$col, $label]) {
            ['used' => $used, 'cap' => $cap] = $after[$m];
            if (Quota::unlimited($cap) || $rank($used) <= $cap || $rank($used) <= $rank($before[$m]['used'])) {
                continue;
            }
            $out[] = Quota::unlimited($used)
                ? "$label: an unlimited package doesn't fit in your own limit (" . self::fmt($m, $cap) . ').'
                : "$label: your accounts' packages would add up to " . self::fmt($m, $used) . ', more than your ' . self::fmt($m, $cap) . '.';
        }
        return $out;
    }

    public static function fmt(string $metric, int $v): string
    {
        return $metric === 'domains' ? Quota::fmtCount($v) : Quota::fmtMb($v);
    }
}
