<?php
/** @var list<array<string,mixed>> $jobs with domain_name */
/** @var list<array<string,mixed>> $domains id, domain_name */
View::slot('desc', e('Run a PHP script on one of your sites, or fetch a URL, on a schedule. Times are server time (' . date('T') . ', now ' . date('H:i') . ').'));
View::slot('actions', '<a href="#add-cron" class="btn btn-primary">' . icon('plus') . 'Add cron job</a>');
View::script('cron');

$presets = ['*/5 * * * *' => 'Every 5 minutes', '*/15 * * * *' => 'Every 15 minutes', '0 * * * *' => 'Every hour', '0 0 * * *' => 'Every day at midnight', '0 3 * * 0' => 'Every Sunday at 03:00', '0 0 1 * *' => 'First of the month'];
$weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

/** The common schedules in words; '' for the rest (the expression is shown anyway). */
$describe = function (string $s) use ($presets, $weekdays): string {
    if (isset($presets[$s])) {
        return $presets[$s];
    }
    $p = explode(' ', $s);
    if (count($p) !== 5) {
        return '';
    }
    [$mi, $h, $dom, $mon, $dow] = $p;
    $at = fn() => sprintf('%02d:%02d', (int) $h, (int) $mi);
    $every = fn(int $n, string $unit) => $n === 1 ? "Every $unit" : "Every $n {$unit}s";
    if ($s === '* * * * *') {
        return 'Every minute';
    }
    if (preg_match('#^\*/(\d+)$#', $mi, $m) && "$h $dom $mon $dow" === '* * * *') {
        return $every((int) $m[1], 'minute');
    }
    if (ctype_digit($mi) && "$h $dom $mon $dow" === '* * * *') {
        return 'Every hour at :' . sprintf('%02d', (int) $mi);
    }
    if (ctype_digit($mi) && preg_match('#^\*/(\d+)$#', $h, $m) && "$dom $mon $dow" === '* * *') {
        return $every((int) $m[1], 'hour') . ' at :' . sprintf('%02d', (int) $mi);
    }
    if (ctype_digit($mi) && ctype_digit($h)) {
        if ("$dom $mon $dow" === '* * *') {
            return 'Every day at ' . $at();
        }
        if ("$dom $mon" === '* *' && ctype_digit($dow) && (int) $dow <= 7) {
            return 'Every ' . $weekdays[(int) $dow] . ' at ' . $at();
        }
        if (ctype_digit($dom) && "$mon $dow" === '* *') {
            return 'Day ' . (int) $dom . ' of every month at ' . $at();
        }
    }
    return '';
};

/** [label, tone] of the last run. */
$outcome = function (array $j): array {
    if ($j['last_status'] === null) {
        return ['Running', 'info'];
    }
    $s = (int) $j['last_status'];
    if ($s === -1) {
        return ['Timed out', 'danger'];
    }
    if ($j['kind'] === 'url') {
        return $s === 0 ? ['No response', 'danger'] : ['HTTP ' . $s, $s >= 200 && $s < 400 ? 'success' : 'danger'];
    }
    return $s === 0 ? ['OK', 'success'] : ['Exit code ' . $s, 'danger'];
};
$command = fn(array $j) => $j['kind'] === 'url' ? (string) $j['target'] : 'php ' . $j['target'] . ($j['args'] !== '' ? ' ' . $j['args'] : '');

/** Status badge, when, and the button that opens the output. */
$lastRun = function (array $j, bool $inline = false) use ($outcome): string {
    if (!$j['last_run_at']) {
        return '<span class="text-xs text-subtle">Not run yet</span>';
    }
    [$label, $tone] = $outcome($j);
    return '<div class="flex ' . ($inline ? 'flex-wrap items-center gap-x-2 gap-y-1' : 'flex-col items-start gap-1') . '">' . Ui::badge($label, $tone, true)
        . '<span class="text-xs text-muted"><span title="' . e((string) $j['last_run_at']) . '">' . e(fmt_ago((string) $j['last_run_at'])) . '</span> · '
        . '<button type="button" class="link" data-dialog-open="cron-out-' . (int) $j['id'] . '" aria-haspopup="dialog">Output</button></span></div>';
};

$kindOptions = ['php' => ['A PHP script on one of my sites', !$domains], 'url' => 'A URL, fetched like a visitor would'];
$kind = Ui::old('kind') ?? ($domains ? 'php' : 'url');
?>
<div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
    <section class="card xl:col-span-2" aria-labelledby="cron-title">
        <div class="card-header">
            <div class="min-w-0">
                <h2 id="cron-title" class="card-title"><?= e(Ui::plural(count($jobs), 'cron job')) ?></h2>
                <p class="card-desc">A run may take up to <?= (int) (CronService::TIMEOUT / 60) ?> minutes; a job isn't started again while it's still running.</p>
            </div>
            <span class="text-xs text-subtle"><?= count($jobs) ?> of <?= CronService::MAX_JOBS ?></span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Job</th>
                        <th scope="col" class="hidden md:table-cell">Last run</th>
                        <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $j):
                        $id = (int) $j['id'];
                        $on = (bool) (int) $j['enabled'];
                        $words = $describe((string) $j['schedule']); ?>
                    <tr>
                        <td class="min-w-[12rem]">
                            <p class="font-mono text-[0.8125rem] [overflow-wrap:anywhere] <?= $on ? 'text-fg' : 'text-muted' ?>"><?= e($command($j)) ?></p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                                <code class="chip"><?= e((string) $j['schedule']) ?></code>
                                <?php if ($words !== ''): ?><span><?= e($words) ?></span><?php endif; ?>
                                <?php if (!$on): ?><?= Ui::badge('Paused', 'warning') ?><?php endif; ?>
                            </div>
                            <div class="mt-2 md:hidden"><?= $lastRun($j, true) ?></div>
                        </td>
                        <td class="hidden whitespace-nowrap md:table-cell"><?= $lastRun($j) ?></td>
                        <td class="w-px">
                            <div class="flex items-center justify-end gap-1">
                                <form method="post" action="/cpanel/cron/<?= $id ?>/run">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn-secondary btn-sm" title="Starts within a few seconds">Run now</button>
                                </form>
                                <?= Ui::actionForm("/cpanel/cron/$id/toggle", $on ? 'pause' : 'play', $on ? 'Pause' : 'Resume') ?>
                                <?= Ui::deleteForm("/cpanel/cron/$id/delete", 'Delete this cron job? It won\'t run again.', 'Delete cron job') ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$jobs): ?>
                    <?= Ui::emptyRow(3, 'No cron jobs yet', 'Add one to run a script or fetch a URL on a schedule, e.g. WordPress\'s wp-cron.php every 5 minutes.', 'clock') ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php foreach ($jobs as $j): if (!$j['last_run_at']) { continue; } [$label, $tone] = $outcome($j); $id = (int) $j['id']; ?>
        <dialog id="cron-out-<?= $id ?>" class="dialog max-w-2xl" aria-labelledby="cron-out-<?= $id ?>-title">
            <div class="dialog-body">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 id="cron-out-<?= $id ?>-title" class="dialog-title">Output of the last run</h2>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted"><?= Ui::badge($label, $tone, true) ?><span><?= e((string) $j['last_run_at']) ?></span></p>
                    </div>
                    <?= Ui::iconButton('close', 'Close', ['type' => 'button', 'attrs' => ['data-dialog-close' => true]]) ?>
                </div>
                <p class="mt-3 font-mono text-xs text-muted [overflow-wrap:anywhere]"><?= e($command($j)) ?></p>
                <pre class="log mt-3"><?= e((string) ($j['last_output'] ?? '') ?: '(no output)') ?></pre>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" data-dialog-close data-dialog-focus>Close</button>
            </div>
        </dialog>
        <?php endforeach; ?>
    </section>

    <section class="card h-fit scroll-mt-20" id="add-cron" aria-labelledby="add-cron-title">
        <div class="card-header">
            <div class="min-w-0">
                <h2 id="add-cron-title" class="card-title">Add a cron job</h2>
                <p class="card-desc">Up to <?= CronService::MAX_JOBS ?> per account.</p>
            </div>
        </div>
        <form method="post" action="/cpanel/cron" class="card-body space-y-4">
            <?= Csrf::field() ?>
            <div class="space-y-2">
                <?= Ui::field('schedule', 'Schedule', [
                    'mono' => true, 'required' => true, 'value' => Ui::old('schedule') ?? '0 * * * *',
                    'hint' => 'minute hour day month weekday, e.g. */15 * * * * - or @hourly, @daily, @weekly.',
                    'attrs' => ['list' => 'cron-presets', 'autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off', 'data-cron-schedule' => true],
                ]) ?>
                <datalist id="cron-presets"><?php foreach ($presets as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></datalist>
                <div class="flex flex-wrap gap-1.5" data-cron-presets hidden>
                    <?php foreach ($presets as $v => $l): ?>
                    <button type="button" class="chip font-sans text-muted hover:bg-line hover:text-fg" data-schedule="<?= e($v) ?>"><?= e($l) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?= Ui::select('kind', 'Run', $kindOptions, $kind, ['attrs' => ['data-cron-kind' => true]]) ?>
            <?php if ($domains): ?>
            <div class="space-y-4" data-kind-group="php">
                <?= Ui::select('domain_id', 'Site folder', array_map(fn($d) => '/var/www/' . $d['domain_name'] . '/', array_column($domains, null, 'id'))) ?>
                <?= Ui::field('target', 'Script', [
                    'mono' => true, 'placeholder' => 'public/cron.php',
                    'hint' => 'A .php file inside the site folder, as a path from there.',
                    'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off'],
                ]) ?>
                <?= Ui::field('args', 'Arguments', ['mono' => true, 'optional' => true, 'placeholder' => '--quiet', 'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off', 'maxlength' => 255]]) ?>
            </div>
            <?php endif; ?>
            <div data-kind-group="url">
                <?= Ui::field('url', 'URL', ['mono' => true, 'placeholder' => 'https://example.com/wp-cron.php', 'attrs' => ['inputmode' => 'url', 'autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off']]) ?>
            </div>
            <button type="submit" class="btn btn-primary btn-block"><?= icon('plus') ?>Add cron job</button>
            <p class="hint">PHP scripts run as your account with the site's PHP settings, from the script's folder. Shell commands aren't available.</p>
        </form>
    </section>
</div>
