<?php
/** @var array<string,mixed> $me */
/** @var array<string,mixed>|null $traffic StatsService::traffic() */
/** @var array<string,mixed>|null $server StatsService::server() (admins) */
/** @var array<string,array>|null $uptime StatsService::uptime() (admins) */
/** @var list<array<string,mixed>> $limits StatsService::nearingLimits() */
/** @var array<string,mixed>|null $stats ServerStatus::stats() (admins) */
/** @var list<array<string,mixed>> $alerts AlertService::collect() (admins) */
/** @var list<array<string,mixed>> $recent */
$admin = ($me['role'] ?? '') === 'admin';
$levelIcon = ['danger' => ['x-circle', 'text-danger'], 'warning' => ['alert', 'text-warning'], 'info' => ['info', 'text-info']];
$down = $stats ? array_keys(array_filter($stats['services'], fn($s) => $s !== 'active')) : [];
$what = ['mariadb' => 'Databases', 'frankenphp' => 'Web server and panel', 'stalwart' => 'Mail', 'sftpgo' => 'SFTP', 'knot' => 'DNS'];

$counts = Ui::plural((int) $userCount, 'hosting account') . ' and ' . Ui::plural((int) $domainCount, 'domain')
    . ((int) $suspendedCount ? ', ' . (int) $suspendedCount . ' suspended' : '');
if ($admin) {
    $desc = ($down ? 'Not answering: ' . implode(', ', array_map(fn($s) => $what[$s] ?? $s, $down)) . '. ' : 'All services are answering. ')
        . $counts . ($resellerCount ? ' (' . Ui::plural((int) $resellerCount, 'reseller') . ')' : '') . ' on ' . Config::SERVER_HOSTNAME . '.';
} else {
    $desc = 'Your ' . $counts . '.';
}
View::slot('desc', e($desc));
View::slot('actions', '<a href="/whm/accounts/create" class="btn btn-primary">' . icon('user-plus') . 'Create account</a>');

// The server's figures, as a row under the traffic chart (admins).
$trafficFoot = '';
if ($admin && $stats) {
    $diskPct = $stats['disk_total'] > 0 ? $stats['disk_used'] / $stats['disk_total'] * 100 : 0;
    $cell = fn(string $label, string $figure, string $of, string $chart) => '<div class="flex items-end justify-between gap-4 px-5 py-4"><div class="min-w-0"><p class="text-[13px] text-muted">' . e($label) . '</p>'
        . '<p class="mt-1.5 flex items-baseline gap-1.5"><span class="figure-sm whitespace-nowrap">' . e($figure) . '</span><span class="truncate text-[12.5px] text-subtle">' . e($of) . '</span></p></div>' . $chart . '</div>';
    $spark = fn(array $v, string $label, array $o = []) => count($v) > 1 ? '<div class="w-24 shrink-0">' . Chart::sparkline($v, $label, $o) . '</div>' : '';
    $trafficFoot = '<div class="panel-row grid-cols-1 border-t border-line sm:grid-cols-2 xl:grid-cols-4">'
        . $cell('CPU load', number_format((float) $stats['load'][0], 2), 'on ' . Ui::plural((int) $stats['cpus'], 'core'), $spark($server['cpu'] ?? [], 'CPU use over the last 12 hours'))
        . $cell('Memory', fmt_bytes((int) $stats['mem_used']), 'of ' . fmt_bytes((int) $stats['mem_total']), $spark($server['mem'] ?? [], 'Memory in use over the last 12 hours'))
        . $cell('Disk', fmt_bytes((int) $stats['disk_used']), 'of ' . fmt_bytes((int) $stats['disk_total']), Chart::ring($diskPct, 'Disk used', ['size' => 48, 'tone' => Ui::usageTone($diskPct) === 'danger' ? 'danger' : (Ui::usageTone($diskPct) === 'warning' ? 'warning' : 'accent')]))
        . $cell('Sent this month', fmt_bytes((int) ($server['bwMonth'] ?? 0)), 'by all sites', $spark($server['bwDays'] ?? [], 'Data sent by all sites per day this month', ['area' => true]))
        . '</div>';
}
$trafficTitle = $admin ? 'Requests to all sites' : 'Requests to your accounts\' sites';
$trafficLive = true; // these panels refresh every 30 seconds (charts.js)
?>
<div class="stack">
    <?php include __DIR__ . '/../partials/traffic_panel.php'; ?>

    <?php if (!$admin && !empty($alloc)): ?>
    <?php $allocTitle = 'Your allocation'; include __DIR__ . '/../partials/reseller_allocation.php'; ?>
    <?php endif; ?>

    <div class="grid grid-cols-1 gap-6 <?= $admin ? 'lg:grid-cols-3' : 'lg:grid-cols-2' ?>">
        <section class="panel" aria-labelledby="busiest-title">
            <?= Ui::cardHeader('Busiest sites', 'Requests in the last ' . StatsService::RANGES[$range], '<a href="/whm/analytics?range=' . e($range) . '" class="btn btn-ghost btn-sm">Analytics</a>', id: 'busiest-title') ?>
            <div class="card-body">
                <?= Chart::ranked(array_map(fn($r) => [(string) $r['domain_name'], (float) $r['n'], Chart::format((float) $r['n'], 'count')], $traffic['busiest'] ?? []), 'Busiest sites by requests') ?>
            </div>
        </section>

        <?php if ($admin): ?>
        <section class="panel" aria-labelledby="services-title" data-live="services">
            <?= Ui::cardHeader('Services', $uptime && array_sum(array_column($uptime, 'checks')) > 0 ? 'Checked every minute; each block is 30 minutes of the last 24 hours.' : 'Checked by connecting to each one just now.', id: 'services-title') ?>
            <ul class="card-body space-y-4">
                <?php foreach (($stats['services'] ?? []) as $svc => $status): $u = $uptime[$svc] ?? null; $now = $status === 'active'; ?>
                <li>
                    <div class="mb-1.5 flex items-baseline justify-between gap-3 text-[13px]">
                        <span class="min-w-0 truncate text-fg"><?= e($what[$svc] ?? $svc) ?> <span class="ml-1 font-mono text-[11.5px] text-subtle"><?= e($svc) ?></span></span>
                        <span class="flex shrink-0 items-center gap-1.5 tabular-nums <?= $now ? 'text-muted' : 'text-danger-text' ?>">
                            <?= $now ? icon('check-circle', 'h-3.5 w-3.5 text-success') : icon('x-circle', 'h-3.5 w-3.5') ?>
                            <?= !$now ? 'Not answering' : ($u && $u['pct'] !== null ? e(StatsService::pct($u['pct'])) . ' up' : 'Answering') ?>
                        </span>
                    </div>
                    <?php if ($u && $u['checks'] > 0): ?>
                    <?= Chart::uptime($u['cells'], ($what[$svc] ?? $svc) . ': ' . StatsService::pct($u['pct']) . ' up in the last 24 hours' . ($u['down'] !== '' ? ', ' . $u['down'] : '')) ?>
                    <?php if ($u['down'] !== ''): ?><p class="mt-1 text-[12px] text-danger-text"><?= e(ucfirst($u['down'])) ?></p><?php endif; ?>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <section class="panel" aria-labelledby="limits-title" data-live="limits">
            <?= Ui::cardHeader('Nearing their limits', 'At the current rate', '<a href="/whm/accounts?sort=disk" class="btn btn-ghost btn-sm">Accounts</a>', id: 'limits-title') ?>
            <?php if (!$limits): ?>
            <p class="card-body flex items-center gap-2 text-[13px] text-muted"><?= icon('check-circle', 'h-4 w-4 shrink-0 text-success') ?>No account is close to a limit.</p>
            <?php else: ?>
            <ul class="card-body space-y-5">
                <?php foreach ($limits as $l): ?>
                <li>
                    <div class="mb-1.5 flex items-baseline justify-between gap-3 text-[13px]">
                        <a href="/whm/accounts/<?= (int) $l['id'] ?>" class="truncate font-medium text-fg hover:text-accent-text"><?= e($l['username']) ?></a>
                        <span class="shrink-0 text-muted"><?= e($l['what']) ?> <span class="tabular-nums"><?= round($l['pct']) ?>%</span></span>
                    </div>
                    <?= Ui::progress(min(100, $l['pct']), $l['username'] . ': ' . $l['what'], $l['tone']) ?>
                    <p class="mt-1 text-[12px] <?= $l['tone'] === 'danger' ? 'text-danger-text' : 'text-subtle' ?>"><?= e($l['note']) ?></p>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="grid grid-cols-1 gap-6 <?= $admin ? 'lg:grid-cols-3' : '' ?>">
        <?php if ($admin): ?>
        <section class="panel lg:col-span-2" aria-labelledby="attention-title" data-live="attention">
            <?= Ui::cardHeader('Needs attention', '', '<a href="/whm/alerts" class="btn btn-ghost btn-sm">All alerts</a>', id: 'attention-title') ?>
            <?php if (!$alerts): ?>
            <div class="flex items-center gap-3 px-5 py-4 text-[13.5px]">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-success-soft text-success-text"><?= icon('check', 'h-4 w-4') ?></span>
                <span><span class="font-medium text-fg">All clear.</span> <span class="text-muted">Services answer, backups ran, no account is over its limits.</span></span>
            </div>
            <?php else: ?>
            <ul class="divide-y divide-line">
                <?php foreach (array_slice($alerts, 0, 5) as $a): [$ic, $tone] = $levelIcon[$a['level']] ?? $levelIcon['info']; ?>
                <li class="flex items-start gap-3 px-5 py-3.5">
                    <?= icon($ic, "mt-0.5 h-[18px] w-[18px] shrink-0 $tone") ?>
                    <div class="min-w-0 flex-1">
                        <p class="text-[13.5px] font-medium text-fg"><?= e($a['title']) ?></p>
                        <?php if ($a['detail'] !== ''): ?><p class="mt-0.5 text-[13px] text-muted"><?= e($a['detail']) ?></p><?php endif; ?>
                    </div>
                    <?php if (!empty($a['href'])): ?><a href="<?= e($a['href']) ?>" class="btn btn-secondary btn-sm shrink-0">Open</a><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($alerts) > 5): ?>
            <div class="card-footer justify-start text-[13px] text-muted"><?= count($alerts) - 5 ?> more on the <a href="/whm/alerts" class="link">Alerts</a> page.</div>
            <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="panel" aria-labelledby="recent-title" data-live="recent">
            <?= Ui::cardHeader('Recent activity', '', '<a href="/whm/activity" class="btn btn-ghost btn-sm">Log</a>', id: 'recent-title') ?>
            <?php if (!$recent): ?>
            <?= Ui::empty('Nothing yet', 'Sign-ins and every change made in the panel show up here.', 'activity') ?>
            <?php else: ?>
            <ol class="space-y-3.5 px-5 py-4">
                <?php foreach ($recent as $r): ?>
                <li class="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-2 text-[13px]">
                    <span class="text-subtle" title="<?= e((string) $r['created_at']) ?>"><?= e(fmt_ago((string) $r['created_at'])) ?></span>
                    <span class="min-w-0">
                        <span class="font-medium text-fg"><?= e($r['actor_name'] ?? 'system') ?></span>
                        <span class="text-muted"><?= e((string) $r['detail'] !== '' ? (string) $r['detail'] : str_replace(['.', '_'], ' ', (string) $r['action'])) ?></span>
                    </span>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($logServices): ?>
    <section class="panel" aria-labelledby="logs-title">
        <div class="card-header">
            <div>
                <h2 id="logs-title" class="card-title">Service logs</h2>
                <p class="card-desc">The last lines of each service's journal, refreshed by the background worker every 5 seconds.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <form method="get" action="/whm">
                    <label for="log-pick" class="sr-only">Service</label>
                    <select id="log-pick" name="log" class="select select-sm w-56" data-autosubmit>
                        <?php foreach ($logServices as $svc): ?>
                        <option value="<?= e($svc) ?>" <?= $selectedLog === $svc ? 'selected' : '' ?>><?= e($svc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <form method="post" action="/whm/logs/pull">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="service" value="<?= e($selectedLog) ?>">
                    <button class="btn btn-secondary btn-sm"><?= icon('download') ?>Last 1000 lines</button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <pre class="log" tabindex="0" aria-label="Log of <?= e($selectedLog) ?>"><?= e($logContent) ?></pre>
        </div>
    </section>
    <?php endif; ?>
</div>
