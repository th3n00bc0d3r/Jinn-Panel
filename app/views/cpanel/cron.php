<?php
/** @var list<array<string,mixed>> $jobs */
/** @var list<array<string,mixed>> $domains */
$card = 'bg-white rounded-xl border border-slate-200 shadow-sm';
$input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500';
$label = 'block text-xs font-medium text-slate-600 mb-1';
$presets = ['*/5 * * * *' => 'Every 5 minutes', '*/15 * * * *' => 'Every 15 minutes', '0 * * * *' => 'Every hour', '0 0 * * *' => 'Every day at midnight', '0 3 * * 0' => 'Every Sunday at 03:00', '0 0 1 * *' => 'First of the month'];
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 <?= $card ?> overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
            <h2 class="text-sm font-semibold text-slate-700">Cron jobs</h2>
            <p class="text-xs text-slate-400 mt-0.5">Times are server time (<?= e(date('T')) ?>, now <?= e(date('H:i')) ?>). Each run may take up to <?= (int) (CronService::TIMEOUT / 60) ?> minutes; a job isn't started again while it's still running.</p>
        </div>
        <ul class="divide-y divide-slate-100 text-sm">
            <?php foreach ($jobs as $j): ?>
            <?php $ok = $j['last_status'] !== null && ((int) $j['last_status'] === 0 || ($j['kind'] === 'url' && (int) $j['last_status'] >= 200 && (int) $j['last_status'] < 400)); ?>
            <li class="px-5 py-3 <?= (int) $j['enabled'] ? '' : 'opacity-60' ?>">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-xs rounded bg-slate-100 px-1.5 py-0.5 text-slate-700"><?= e($j['schedule']) ?></span>
                    <span class="font-mono text-slate-700 break-all"><?= e($j['kind'] === 'url' ? $j['target'] : 'php ' . $j['target'] . ($j['args'] !== '' ? ' ' . $j['args'] : '')) ?></span>
                    <?php if (!(int) $j['enabled']): ?><span class="text-xs font-medium rounded-full px-2 py-0.5 bg-slate-100 text-slate-600">Paused</span><?php endif; ?>
                    <span class="ml-auto flex items-center gap-1">
                        <form method="post" action="/cpanel/cron/<?= (int) $j['id'] ?>/run"><?= Csrf::field() ?><button class="text-xs font-medium text-sky-700 hover:text-sky-600 px-2 py-1">Run now</button></form>
                        <form method="post" action="/cpanel/cron/<?= (int) $j['id'] ?>/toggle"><?= Csrf::field() ?><button class="text-xs font-medium text-slate-500 hover:text-slate-700 px-2 py-1"><?= (int) $j['enabled'] ? 'Pause' : 'Resume' ?></button></form>
                        <form method="post" action="/cpanel/cron/<?= (int) $j['id'] ?>/delete" data-confirm="Delete this cron job?"><?= Csrf::field() ?><button class="p-1.5 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50" title="Delete"><?= icon('trash', 'h-4 w-4') ?></button></form>
                    </span>
                </div>
                <?php if ($j['last_run_at']): ?>
                <details class="mt-1">
                    <summary class="cursor-pointer text-xs <?= $ok ? 'text-emerald-700' : 'text-red-700' ?>">
                        Last run <?= e((string) $j['last_run_at']) ?> &middot;
                        <?= $j['last_status'] === null ? 'running' : ((int) $j['last_status'] === -1 ? 'timed out' : ($j['kind'] === 'url' ? 'HTTP ' . (int) $j['last_status'] : 'exit code ' . (int) $j['last_status'])) ?>
                    </summary>
                    <pre class="mt-1 text-xs font-mono bg-slate-50 rounded-lg p-3 overflow-x-auto max-h-60 border border-slate-100"><?= e((string) ($j['last_output'] ?? '') ?: '(no output)') ?></pre>
                </details>
                <?php else: ?>
                <p class="text-xs text-slate-400 mt-1">Not run yet.</p>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
            <?php if (!$jobs): ?>
            <li class="px-5 py-10 text-center text-slate-400">No cron jobs.</li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="<?= $card ?> p-5 h-fit">
        <h2 class="text-sm font-semibold text-slate-700 mb-3">Add a cron job</h2>
        <form method="post" action="/cpanel/cron" class="space-y-3">
            <?= Csrf::field() ?>
            <div>
                <label class="<?= $label ?>">Schedule</label>
                <input name="schedule" required list="cron-presets" value="0 * * * *" class="<?= $input ?> font-mono">
                <datalist id="cron-presets"><?php foreach ($presets as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></datalist>
                <p class="text-xs text-slate-400 mt-1">minute hour day month weekday, e.g. <span class="font-mono">*/15 * * * *</span> - or @hourly, @daily, @weekly.</p>
            </div>
            <div>
                <label class="<?= $label ?>">Run</label>
                <select name="kind" class="<?= $input ?> bg-white">
                    <option value="php">A PHP script on one of my sites</option>
                    <option value="url">A URL (fetched like a visitor would)</option>
                </select>
            </div>
            <div>
                <label class="<?= $label ?>">PHP script - site and path inside its folder</label>
                <select name="domain_id" class="<?= $input ?> bg-white mb-2">
                    <?php foreach ($domains as $d): ?><option value="<?= (int) $d['id'] ?>">/var/www/<?= e($d['domain_name']) ?>/</option><?php endforeach; ?>
                </select>
                <input name="target" placeholder="public/cron.php" class="<?= $input ?> font-mono">
                <input name="args" placeholder="arguments (optional)" class="<?= $input ?> font-mono mt-2">
            </div>
            <div>
                <label class="<?= $label ?>">URL</label>
                <input name="url" placeholder="https://example.com/wp-cron.php" class="<?= $input ?> font-mono">
            </div>
            <button class="w-full rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium py-2.5 transition-colors">Add cron job</button>
            <p class="text-xs text-slate-400">PHP scripts run with your site's PHP settings, from the script's folder. Shell commands aren't available.</p>
        </form>
    </div>
</div>
