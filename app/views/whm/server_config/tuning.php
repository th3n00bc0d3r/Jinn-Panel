<?php
$labels = ['balanced' => 'Balanced', 'performance' => 'Performance', 'extreme' => 'Extreme'];
$descriptions = [
    'balanced' => 'Conservative resource use, safest for a box also doing other things.',
    'performance' => 'Noticeably more headroom for traffic and concurrency.',
    'extreme' => 'As fast as this hardware responsibly allows, with a safety margin so nothing gets starved.',
];
?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 mb-4">
    <h2 class="text-sm font-semibold text-slate-700 mb-3">Detected hardware</h2>
    <div class="grid grid-cols-3 gap-4 text-sm">
        <div><p class="text-xs text-slate-400 uppercase tracking-wide">CPU cores</p><p class="text-lg font-semibold text-slate-800"><?= (int) $hardware['cpus'] ?></p></div>
        <div><p class="text-xs text-slate-400 uppercase tracking-wide">Memory</p><p class="text-lg font-semibold text-slate-800"><?= fmt_bytes($hardware['mem_total']) ?></p></div>
        <div><p class="text-xs text-slate-400 uppercase tracking-wide">Disk</p><p class="text-lg font-semibold text-slate-800"><?= fmt_bytes($hardware['disk_total']) ?></p></div>
    </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 mb-4">
    <h2 class="text-sm font-semibold text-slate-700 mb-3">Tuning profile</h2>
    <div class="grid grid-cols-3 rounded-lg border border-slate-200 overflow-hidden mb-2">
        <?php foreach ($labels as $key => $label): ?>
        <a href="/whm/server-config/tuning?preview=<?= e($key) ?>"
           class="text-center py-2.5 text-sm font-medium transition-colors <?= $preview === $key ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50' ?>">
            <?= e($label) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <p class="text-xs text-slate-500"><?= e($descriptions[$preview]) ?></p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-slate-700 mb-3">MariaDB</h3>
        <dl class="text-sm space-y-1.5 font-mono">
            <?php foreach ($computed['mysql'] as $k => $v): ?>
            <div class="flex justify-between"><dt class="text-slate-500"><?= e($k) ?></dt><dd class="text-slate-800"><?= e((string) $v) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-slate-700 mb-3">PHP &amp; OPcache</h3>
        <dl class="text-sm space-y-1.5 font-mono">
            <?php foreach (array_merge($computed['php'], $computed['opcache']) as $k => $v): ?>
            <div class="flex justify-between"><dt class="text-slate-500"><?= e($k) ?></dt><dd class="text-slate-800"><?= e((string) $v) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-slate-700 mb-3">Stalwart Mail</h3>
        <dl class="text-sm space-y-1.5 font-mono">
            <?php foreach ($computed['stalwartSystem'] as $k => $v): ?>
            <div class="flex justify-between"><dt class="text-slate-500"><?= e($k) ?></dt><dd class="text-slate-800"><?= e((string) $v) ?></dd></div>
            <?php endforeach; ?>
            <div class="flex justify-between"><dt class="text-slate-500">cache sizes</dt><dd class="text-slate-800"><?= fmt_bytes($computed['stalwartCache']['accounts']) ?> each</dd></div>
        </dl>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-slate-700 mb-3">SFTPGo</h3>
        <dl class="text-sm space-y-1.5 font-mono">
            <?php foreach ($computed['sftpgo'] as $k => $v): ?>
            <div class="flex justify-between"><dt class="text-slate-500"><?= e($k) ?></dt><dd class="text-slate-800"><?= e((string) $v) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
    <form method="post" action="/whm/server-config/tuning" data-confirm="Apply <?= e($labels[$preview]) ?> tuning across MariaDB, PHP, Stalwart and SFTPGo? MariaDB and PHP will restart.">
        <?= Csrf::field() ?>
        <input type="hidden" name="profile" value="<?= e($preview) ?>">
        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2.5 transition-colors">
            <?= icon('sliders', 'h-4 w-4') ?> Apply <?= e($labels[$preview]) ?> tuning
        </button>
    </form>
</div>
