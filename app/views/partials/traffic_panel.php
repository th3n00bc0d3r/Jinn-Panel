<?php
/**
 * Traffic panel: the range's total and its change, the range links, and
 * the busiest sites stacked per hour / 4 hours / day. Range links reload
 * only this panel (data-chart-region). Expects:
 * $traffic       StatsService::traffic(), or null when statistics aren't available
 * $trafficTitle  e.g. 'Requests to all sites'
 * $trafficFoot   optional HTML under the chart (a row of figures)
 * $trafficRegion false when a larger region around it should reload instead
 * $trafficLive   true to refresh it every 30 seconds (dashboards)
 */
$trafficFoot ??= '';
$trafficRegion ??= true;
$trafficLive ??= false;
if ($traffic === null): ?>
<section class="panel px-5 py-6 text-[13px] text-muted">Statistics aren't available yet: the database needs the update's migration (026).</section>
<?php return; endif;
$rangeName = StatsService::RANGES[$traffic['range']];
$fmt = $traffic['metric'] === 'bytes' ? 'bytes' : 'compact';
$change = StatsService::change((float) $traffic['total'], (float) $traffic['previous'], $rangeName);
$noun = ['requests' => 'Requests', 'visitors' => 'Visitors', 'bytes' => 'Data sent'][$traffic['metric']];
?>
<section class="panel" aria-labelledby="traffic-title"<?= $trafficRegion ? ' data-chart-region="traffic"' : '' ?><?= $trafficLive ? ' data-live="traffic"' : '' ?>>
    <div class="flex flex-wrap items-start justify-between gap-x-8 gap-y-3 px-5 pt-5">
        <div class="min-w-0">
            <h2 id="traffic-title" class="text-[13px] font-medium text-muted"><?= e($trafficTitle) ?>, last <?= e($rangeName) ?></h2>
            <p class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span class="figure" data-live-value><?= e(Chart::format((float) $traffic['total'], $fmt === 'bytes' ? 'bytes' : 'count')) ?></span>
                <?php if ($change): ?>
                <span class="text-[13px] text-muted"><?= $change[0] === 'same' ? '' : icon('arrow-up', 'mr-0.5 inline h-3.5 w-3.5 align-[-2px]' . ($change[0] === 'down' ? ' rotate-180' : '')) ?><?= e($change[1]) ?></span>
                <?php endif; ?>
            </p>
        </div>
        <?= Chart::ranges(StatsService::RANGES, $traffic['range'], 'Time range') ?>
    </div>
    <div class="px-5 pb-4 pt-5">
        <?php if ($traffic['series']): ?>
        <div class="mb-4"><?= Chart::legend($traffic['series']) ?></div>
        <?php endif; ?>
        <?= Chart::columns($traffic['series'], $traffic['stacks'], $traffic['labels'], $noun . ' per ' . $traffic['per'] . ' by site', [
            'height' => 240, 'fmt' => $fmt, 'annotate' => $traffic['spike'] ?? null,
            'table' => ['hour' => 'Hour', '4 hours' => 'From', 'day' => 'Day'][$traffic['per']],
        ]) ?>
    </div>
    <?= $trafficFoot ?>
</section>
