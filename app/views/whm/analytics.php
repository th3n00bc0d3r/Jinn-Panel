<?php
/** @var array<string,mixed> $me */
/** @var string $range 24h | 7d | 30d */
/** @var string $metric requests | visitors | bytes */
/** @var string $sort */
/** @var array<string,mixed>|null $traffic StatsService::traffic() */
/** @var array<string,mixed>|null $server StatsService::serverRange() (admins) */
/** @var list<array<string,mixed>> $sites StatsService::sites() */
$admin = ($me['role'] ?? '') === 'admin';
$rangeName = StatsService::RANGES[$range];
View::slot('desc', e($admin ? 'Traffic to every site on this server, and how the server kept up.' : 'Traffic to the sites of the accounts you created.'));
View::slot('actions', Chart::ranges(['requests' => 'Requests', 'visitors' => 'Visitors', 'bytes' => 'Data sent'], $metric, 'Measure', 'metric'));
$trafficTitle = ['requests' => 'Requests', 'visitors' => 'Visitors', 'bytes' => 'Data sent'][$metric] . ($admin ? ' to all sites' : ' to your accounts\' sites');
$trafficRegion = false;

// Sortable column headers (links, so they work without JavaScript).
$query = $_GET;
$th = function (string $key, string $label, string $cls = '') use ($sort, $query): string {
    $query['sort'] = $key;
    $on = $sort === $key;
    return '<th scope="col" class="' . $cls . '"' . ($on ? ' aria-sort="' . ($key === 'name' ? 'ascending' : 'descending') . '"' : '') . '>'
        . '<a href="?' . e(http_build_query($query)) . '" class="th-sort">' . e($label) . ($on ? icon('chevron-down', 'h-3.5 w-3.5' . ($key === 'name' ? ' rotate-180' : '')) : '') . '</a></th>';
};
$totalRequests = array_sum(array_column($sites, 'requests'));
?>
<div class="stack" data-chart-region="analytics">
    <?php include __DIR__ . '/../partials/traffic_panel.php'; ?>

    <?php if ($admin && $server !== null): ?>
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section class="panel" aria-labelledby="cpu-title">
            <?= Ui::cardHeader('CPU and memory', 'Average per ' . $server['per'] . ', in percent of the whole server', id: 'cpu-title') ?>
            <div class="card-body">
                <?php if ($server['has']): ?>
                <div class="mb-4"><?= Chart::legend([['name' => 'CPU'], ['name' => 'Memory']], true) ?></div>
                <?php endif; ?>
                <?= Chart::line($server['has'] ? [['name' => 'CPU', 'values' => $server['cpu']], ['name' => 'Memory', 'values' => $server['mem']]] : [], $server['labels'], 'CPU and memory use per ' . $server['per'], ['fmt' => 'pct', 'max' => 100, 'height' => 200]) ?>
            </div>
        </section>
        <section class="panel" aria-labelledby="net-title">
            <?= Ui::cardHeader('Data the server sent', 'All network traffic out, per ' . $server['per'] . ' (sites, mail, backups to S3...)', id: 'net-title') ?>
            <div class="card-body">
                <?= Chart::columns($server['has'] ? [['name' => 'Sent']] : [], array_map(fn($v) => [$v], $server['tx']), $server['labels'], 'Data sent by the server per ' . $server['per'], ['fmt' => 'bytes', 'height' => 200]) ?>
            </div>
        </section>
    </div>
    <?php endif; ?>

    <section class="panel" aria-labelledby="sites-title">
        <?= Ui::cardHeader(Ui::plural(count($sites), 'site') . ' with traffic', 'Last ' . $rangeName . '. Server errors are answers with a 5xx status; page time is how long pages took to answer, on average.', id: 'sites-title') ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= $th('name', 'Site') ?>
                        <?= $th('requests', 'Requests', 'text-right') ?>
                        <?= $th('visitors', 'Visitors', 'hidden text-right sm:table-cell') ?>
                        <?= $th('bytes', 'Data sent', 'hidden text-right md:table-cell') ?>
                        <?= $th('errors', 'Server errors', 'hidden text-right lg:table-cell') ?>
                        <?= $th('time', 'Page time', 'hidden text-right lg:table-cell') ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sites as $s): $errPct = $s['requests'] > 0 ? $s['errors'] / $s['requests'] * 100 : 0; ?>
                    <tr>
                        <td class="max-w-[20rem]">
                            <span class="block truncate font-mono text-[13px] text-fg"><?= e($s['domain_name']) ?></span>
                            <a href="/whm/accounts/<?= (int) $s['user_id'] ?>" class="text-[12.5px] text-muted hover:text-fg"><?= e($s['username']) ?></a>
                        </td>
                        <td class="text-right">
                            <span class="text-fg"><?= e(Chart::format($s['requests'], 'count')) ?></span>
                            <span class="block text-[12px] text-subtle"><?= $totalRequests > 0 ? e(Chart::format($s['requests'] / $totalRequests * 100, 'pct')) . ' of all' : '' ?></span>
                        </td>
                        <td class="hidden text-right sm:table-cell"><?= e(Chart::format($s['visitors'], 'count')) ?></td>
                        <td class="hidden text-right md:table-cell"><?= e(Chart::bytes($s['bytes'])) ?></td>
                        <td class="hidden text-right lg:table-cell">
                            <?php if ($s['errors'] > 0): ?>
                            <span class="<?= $errPct >= 1 ? 'font-medium text-danger-text' : 'text-fg' ?>"><?= e(Chart::format($s['errors'], 'count')) ?></span>
                            <span class="block text-[12px] text-subtle"><?= e($errPct < 0.1 ? 'under 0.1%' : rtrim(rtrim(number_format($errPct, 1), '0'), '.') . '%') ?></span>
                            <?php else: ?>
                            <span class="text-subtle">None</span>
                            <?php endif; ?>
                        </td>
                        <td class="hidden text-right lg:table-cell"><?= $s['avg_ms'] === null ? '<span class="text-subtle">—</span>' : '<span class="' . ($s['avg_ms'] >= 1000 ? 'font-medium text-warning-text' : 'text-fg') . '">' . e(Chart::format($s['avg_ms'], 'ms')) . '</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$sites): ?>
                    <?= Ui::emptyRow(6, 'No traffic yet', 'Sites show up here within 5 minutes of their first visits.', 'chart') ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
