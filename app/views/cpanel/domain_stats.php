<?php
/** @var array<string,mixed> $d the domain */
/** @var string $range */
/** @var array<string,mixed>|null $stats StatsService::domain() */
$tab = 'stats';
include __DIR__ . '/../partials/domain_tabs.php';
$rangeName = StatsService::RANGES[$range];
View::slot('desc', e('Who visited ' . $d['domain_name'] . ' and how it answered, from the web server\'s log (updated every 5 minutes).'));
if ($stats === null): ?>
<section class="panel px-5 py-6 text-[13px] text-muted">Statistics aren't available yet.</section>
<?php return; endif;
$sum = $stats['sum'];
$prev = $stats['prev'];
$per = $stats['per'];
$first = ['hour' => 'Hour', '4 hours' => 'From', 'day' => 'Day'][$per];
$avgMs = $sum['pages'] > 0 ? $sum['time_ms'] / $sum['pages'] : null;
$prevMs = ($prev['pages'] ?? 0) > 0 ? $prev['time_ms'] / $prev['pages'] : null;
$errPct = $sum['requests'] > 0 ? $sum['s5xx'] / $sum['requests'] * 100 : 0;
$empty = $sum['requests'] == 0;

// One figure with its change on the previous period, in words (not colour alone).
$figure = function (string $label, string $value, ?float $now, ?float $before, string $hint = '') use ($rangeName): string {
    $c = $now !== null && $before !== null ? StatsService::change($now, $before, $rangeName) : null;
    return '<div class="px-5 py-4"><p class="text-[13px] text-muted">' . e($label) . '</p><p class="figure-sm mt-1.5 whitespace-nowrap">' . e($value) . '</p>'
        . ($c ? '<p class="mt-1 text-[12px] text-subtle">' . ($c[0] === 'same' ? '' : icon('arrow-up', 'mr-0.5 inline h-3 w-3 align-[-1px]' . ($c[0] === 'down' ? ' rotate-180' : ''))) . e($c[1]) . '</p>' : ($hint !== '' ? '<p class="mt-1 text-[12px] text-subtle">' . e($hint) . '</p>' : ''))
        . '</div>';
};
$status = [['name' => 'Success (2xx)'], ['name' => 'Redirects (3xx)'], ['name' => 'Not found and other client errors (4xx)'], ['name' => 'Server errors (5xx)']];
?>
<div class="stack" data-chart-region="stats">
    <section class="panel" aria-labelledby="visitors-title">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5">
            <h2 id="visitors-title" class="text-[13px] font-medium text-muted">Last <?= e($rangeName) ?><?= $stats['since'] ? ', counted since ' . e(date('j M Y', strtotime((string) $stats['since']))) : '' ?></h2>
            <?= Chart::ranges(StatsService::RANGES, $range, 'Time range') ?>
        </div>
        <div class="panel-row mt-2 grid-cols-2 border-b border-line md:grid-cols-4">
            <?= $figure('Visitors', Chart::format($sum['visitors'], 'count'), $sum['visitors'], $prev['visitors'] ?? null) ?>
            <?= $figure('Requests', Chart::format($sum['requests'], 'count'), $sum['requests'], $prev['requests'] ?? null, Chart::format($sum['pages'], 'count') . ' of them pages') ?>
            <?= $figure('Data sent', Chart::bytes($sum['bytes']), $sum['bytes'], $prev['bytes'] ?? null) ?>
            <?= $figure('Page time', $avgMs === null ? '—' : Chart::format($avgMs, 'ms'), $avgMs, $prevMs, 'Average time to answer a page') ?>
        </div>
        <div class="px-5 pb-4 pt-5">
            <?php if ($empty): ?>
            <?= Ui::empty('No visits yet', 'Numbers appear here within 5 minutes of the first visit. Check the domain points at this server on the Overview tab.', 'chart') ?>
            <?php else: ?>
            <?= Chart::line([['name' => 'Visitors', 'values' => $stats['series']['visitors']]], $stats['labels'], 'Visitors per ' . $per . ', last ' . $rangeName, ['area' => true, 'height' => 220, 'fmt' => 'count', 'table' => $first]) ?>
            <p class="mt-2 text-[12px] text-subtle">A visitor is one browser on one device, counted once a day. Search engines and other robots are left out. No addresses are kept.</p>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!$empty): ?>
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section class="panel" aria-labelledby="status-title">
            <?= Ui::cardHeader('Answers by status', $sum['s5xx'] > 0 ? Chart::format($sum['s5xx'], 'count') . ' server errors, ' . ($errPct < 0.1 ? 'under 0.1%' : rtrim(rtrim(number_format($errPct, 1), '0'), '.') . '%') . ' of requests' : 'No server errors in this time', id: 'status-title') ?>
            <div class="card-body">
                <div class="mb-4"><?= Chart::legend($status) ?></div>
                <?= Chart::columns($status, $stats['series']['status'], $stats['labels'], 'Requests per ' . $per . ' by status', ['height' => 200, 'fmt' => 'count', 'table' => $first]) ?>
            </div>
        </section>
        <section class="panel" aria-labelledby="time-title">
            <?= Ui::cardHeader('Page time', $sum['slow'] > 0 ? Chart::format($sum['slow'], 'count') . ' pages took over a second' : 'Every page answered within a second', id: 'time-title') ?>
            <div class="card-body">
                <?= Chart::line([['name' => 'Average page time', 'values' => $stats['series']['time']]], $stats['labels'], 'Average time to answer a page, per ' . $per, ['height' => 200, 'fmt' => 'ms', 'table' => $first]) ?>
            </div>
        </section>
        <section class="panel" aria-labelledby="pages-title">
            <?= Ui::cardHeader('Top pages', 'Pages that answered, by requests; without the part after ?', id: 'pages-title') ?>
            <div class="card-body"><?= Chart::ranked(array_map(fn($p) => [$p[0], $p[1], Chart::format($p[1], 'count')], $stats['pages']), 'Top pages by requests') ?></div>
        </section>
        <section class="panel" aria-labelledby="refs-title">
            <?= Ui::cardHeader('Where visitors came from', 'Other sites that linked here; most visits come without one (typed or bookmarked)', id: 'refs-title') ?>
            <div class="card-body"><?= Chart::ranked(array_map(fn($p) => [$p[0], $p[1], Chart::format($p[1], 'count')], $stats['referrers']), 'Referring sites by visits') ?></div>
        </section>
        <section class="panel xl:col-span-2" aria-labelledby="data-title">
            <?= Ui::cardHeader('Data sent', 'What this site served per ' . $per . '; it counts towards your monthly bandwidth', id: 'data-title') ?>
            <div class="card-body">
                <?= Chart::columns([['name' => 'Data sent']], array_map(fn($v) => [$v], $stats['series']['bytes']), $stats['labels'], 'Data sent per ' . $per, ['height' => 180, 'fmt' => 'bytes', 'table' => $first]) ?>
            </div>
        </section>
    </div>
    <?php endif; ?>
</div>
