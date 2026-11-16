<?php
/**
 * Preview only (/__charts): every chart Chart:: draws, with sample data, so
 * a change to the kit can be checked in both themes and at phone width.
 * Not part of the panel.
 */
require_once __DIR__ . '/../fixtures/_sample_stats.php';

$range = in_array($_GET['range'] ?? '', ['24h', '7d', '30d'], true) ? $_GET['range'] : '24h';
$t = stats_traffic($range);
$per = ['24h' => 'hour', '7d' => '4 hours', '30d' => 'day'][$range];
$cpu = stats_series(7, 48, 0.6, 0.5);
$mem = stats_series(8, 48, 62, 0.08);
$hours = array_map(fn($i) => sprintf('%02d:%02d', (intdiv($i, 2) + 17) % 24, ($i % 2) * 30), range(0, 47));
$visitors = stats_series(31, 30, 560, 0.28, 0.35);
$days = array_map(fn($i) => date('j M', strtotime('2026-10-16') + $i * 86400), range(0, 29));
$bw = stats_series(9, 14, 120, 0.3, 0.4);
$status = [
    ['name' => '2xx', 'series' => 's1'],
    ['name' => '3xx', 'series' => 's2'],
    ['name' => '4xx', 'series' => 's3'],
    ['name' => '5xx', 'series' => 's4'],
];
$statusStacks = array_map(fn($i) => [round(800 + 300 * sin($i / 2)), round(120 + 40 * cos($i)), round(30 + 15 * sin($i * 1.3)), $i === 9 ? 42 : round(2 + abs(sin($i)) * 3)], range(0, 13));
$statusLabels = array_map(fn($i) => date('D j', strtotime('2026-11-01') + $i * 86400), range(0, 13));
$checks = array_map(fn($i) => $i >= 40 && $i <= 42 ? 'down' : ($i === 17 ? 'slow' : ($i < 3 ? null : 'up')), range(0, 47));

View::slot('desc', e('Preview only: every chart the panel can draw, with sample data. Hover or focus a chart and use the arrow keys.'));
?>
<div class="stack">
    <section class="card" aria-labelledby="g-columns" data-chart-region="traffic">
        <?= Ui::cardHeader('Stacked columns', 'Requests per ' . $per . ' by site; the spike is annotated. Range links swap only this card.', Chart::ranges(['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'], $range, 'Time range'), id: 'g-columns') ?>
        <div class="card-body">
            <div class="mb-4"><?= Chart::legend($t['series']) ?></div>
            <?= Chart::columns($t['series'], $t['stacks'], $t['labels'], 'Requests per ' . $per . ' by site', ['annotate' => [$t['spike'], 'shop.acme.test x3.4']]) ?>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card" aria-labelledby="g-area">
            <?= Ui::cardHeader('Area (one series)', 'Visitors per day for 30 days.', id: 'g-area') ?>
            <div class="card-body"><?= Chart::line([['name' => 'Visitors', 'values' => $visitors]], $days, 'Visitors per day', ['area' => true, 'table' => 'Day']) ?></div>
        </section>
        <section class="card" aria-labelledby="g-lines">
            <?= Ui::cardHeader('Lines (two series)', 'CPU load and memory in percent, fixed 0-100 axis.', id: 'g-lines') ?>
            <div class="card-body">
                <div class="mb-4"><?= Chart::legend([['name' => 'CPU'], ['name' => 'Memory']], true) ?></div>
                <?= Chart::line([['name' => 'CPU', 'values' => array_map(fn($v) => min(100, $v * 60), $cpu)], ['name' => 'Memory', 'values' => $mem]], $hours, 'CPU and memory over 24 hours', ['fmt' => 'pct', 'max' => 100]) ?>
            </div>
        </section>
    </div>

    <section class="card" aria-labelledby="g-status">
        <?= Ui::cardHeader('Four series and a status mix', 'Responses by status class per day. Series colours here are identity, not status.', id: 'g-status') ?>
        <div class="card-body">
            <div class="mb-4"><?= Chart::legend($status) ?></div>
            <?= Chart::columns($status, $statusStacks, $statusLabels, 'Responses by status class per day', ['height' => 200, 'table' => 'Day', 'fmt' => 'count']) ?>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card" aria-labelledby="g-small">
            <?= Ui::cardHeader('Sparklines and ring', '', id: 'g-small') ?>
            <div class="card-body space-y-5">
                <div class="flex items-center gap-4"><span class="w-24 text-[13px] text-muted">CPU</span><div class="w-28"><?= Chart::sparkline($cpu, 'CPU load over 24 hours') ?></div><span class="figure-sm ml-auto">0.62</span></div>
                <div class="flex items-center gap-4"><span class="w-24 text-[13px] text-muted">Bandwidth</span><div class="w-28"><?= Chart::sparkline($bw, 'Bandwidth per day', ['area' => true, 'series' => 's2']) ?></div><span class="figure-sm ml-auto">1.2 TB</span></div>
                <div class="flex items-center gap-4"><?= Chart::ring(41, 'Disk used', ['size' => 56]) ?><?= Chart::ring(91, 'Disk used', ['size' => 56, 'tone' => 'warning']) ?><?= Chart::ring(108, 'Disk used', ['size' => 56, 'tone' => 'danger']) ?></div>
            </div>
        </section>
        <section class="card" aria-labelledby="g-ranked">
            <?= Ui::cardHeader('Ranked', 'Top pages by requests.', id: 'g-ranked') ?>
            <div class="card-body"><?= Chart::ranked([['/', 18200, '18.2K'], ['/shop', 9400, '9,400'], ['/shop/cart', 3100, '3,100'], ['/wp-login.php', 2800, '2,800'], ['/about', 640, '640']], 'Top pages') ?></div>
        </section>
        <section class="card" aria-labelledby="g-uptime">
            <?= Ui::cardHeader('Uptime', 'One cell per 30-minute check.', id: 'g-uptime') ?>
            <div class="card-body space-y-4">
                <div><div class="mb-1.5 flex justify-between text-[13px]"><span class="text-fg">Web server</span><span class="tabular-nums text-muted">100%</span></div><?= Chart::uptime(array_fill(0, 48, 'up'), 'Web server: up for all of the last 24 hours') ?></div>
                <div><div class="mb-1.5 flex justify-between text-[13px]"><span class="text-fg">Mail</span><span class="tabular-nums text-muted">93.6%</span></div><?= Chart::uptime($checks, 'Mail: 93.6% up in the last 24 hours, down from 00:00 to 01:30') ?></div>
            </div>
        </section>
    </div>

    <section class="card" aria-labelledby="g-empty">
        <?= Ui::cardHeader('No data', 'What a chart shows before anything has been collected.', id: 'g-empty') ?>
        <div class="card-body"><?= Chart::line([], [], 'Nothing yet', ['height' => 120]) ?></div>
    </section>
</div>
