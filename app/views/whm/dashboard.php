<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <?php if (Auth::isAdmin()): ?>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Resellers</p>
        <p class="mt-2 text-3xl font-semibold text-slate-900" data-countup><?= (int) $resellerCount ?></p>
    </div>
    <?php endif; ?>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Hosting Accounts</p>
        <p class="mt-2 text-3xl font-semibold text-slate-900" data-countup><?= (int) $userCount ?></p>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Domains Hosted</p>
        <p class="mt-2 text-3xl font-semibold text-slate-900" data-countup><?= (int) $domainCount ?></p>
    </div>
</div>

<?php if ($stats): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
        <h2 class="text-sm font-semibold text-slate-700 mb-4">Server resources</h2>
        <div class="space-y-4">
            <div>
                <div class="flex justify-between text-xs text-slate-500 mb-1">
                    <span>Disk</span>
                    <span><?= fmt_bytes($stats['disk_used']) ?> / <?= fmt_bytes($stats['disk_total']) ?></span>
                </div>
                <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full bg-indigo-500" style="width: <?= $stats['disk_total'] > 0 ? min(100, round($stats['disk_used'] / $stats['disk_total'] * 100)) : 0 ?>%"></div>
                </div>
            </div>
            <div>
                <div class="flex justify-between text-xs text-slate-500 mb-1">
                    <span>Memory</span>
                    <span><?= fmt_bytes($stats['mem_used']) ?> / <?= fmt_bytes($stats['mem_total']) ?></span>
                </div>
                <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full bg-violet-500" style="width: <?= $stats['mem_total'] > 0 ? min(100, round($stats['mem_used'] / $stats['mem_total'] * 100)) : 0 ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
        <h2 class="text-sm font-semibold text-slate-700 mb-4">Services</h2>
        <div class="grid grid-cols-2 gap-3">
            <?php foreach ($stats['services'] as $svc => $status): ?>
            <div class="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                <span class="text-sm text-slate-700 capitalize"><?= e($svc) ?></span>
                <span class="inline-flex items-center gap-1.5 text-xs font-medium <?= $status === 'active' ? 'text-emerald-700' : 'text-red-700' ?>">
                    <span class="h-1.5 w-1.5 rounded-full <?= $status === 'active' ? 'bg-emerald-500' : 'bg-red-500' ?>"></span>
                    <?= e($status) ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($logServices): ?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 mt-4">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
        <h2 class="text-sm font-semibold text-slate-700">Live logs</h2>
        <div class="flex items-center gap-2">
            <form method="get" action="/whm">
                <select name="log" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <?php foreach ($logServices as $svc): ?>
                    <option value="<?= e($svc) ?>" <?= $selectedLog === $svc ? 'selected' : '' ?>><?= e($svc) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <form method="post" action="/whm/logs/pull">
                <?= Csrf::field() ?>
                <input type="hidden" name="service" value="<?= e($selectedLog) ?>">
                <button class="text-xs font-medium text-indigo-700 hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 rounded-lg px-3 py-1.5 transition-colors">Pull latest 1000 lines</button>
            </form>
        </div>
    </div>
    <pre class="text-xs font-mono text-slate-600 bg-slate-50 border border-slate-100 rounded-lg p-4 overflow-auto max-h-96 whitespace-pre-wrap"><?= e($logContent) ?></pre>
    <p class="text-xs text-slate-400 mt-2">Auto-refreshes every 5 seconds server-side. Reload the page to see the latest snapshot, or pull a deeper 1000-line history above.</p>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 mt-4">
    <h2 class="text-sm font-semibold text-slate-700 mb-2">Quick actions</h2>
    <div class="flex flex-wrap gap-3">
        <a href="/whm/accounts/create" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 transition-colors">
            <?= icon('plus', 'h-4 w-4') ?> Create account
        </a>
        <a href="/whm/packages/create" class="inline-flex items-center gap-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium px-4 py-2 transition-colors">
            <?= icon('plus', 'h-4 w-4') ?> Create package
        </a>
    </div>
</div>
