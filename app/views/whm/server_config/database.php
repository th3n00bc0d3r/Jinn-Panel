<div class="max-w-xl">
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
    <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs px-3 py-2">
        Applying these restarts MariaDB - a brief interruption for every site and mailbox using it.
    </div>
    <?php if ($lastLog): ?>
    <p class="text-xs text-slate-400 mb-4 font-mono">Last applied: <?= e($lastLog) ?></p>
    <?php endif; ?>
    <form method="post" action="/whm/server-config/database" class="space-y-4">
        <?= Csrf::field() ?>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">InnoDB buffer pool size</label>
            <p class="text-xs text-slate-400 mb-1.5">The single biggest lever for MySQL performance - how much data can be cached in memory.</p>
            <input type="text" name="innodb_buffer_pool_size" value="<?= e($current['innodb_buffer_pool_size'] ?? '256M') ?>"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Max connections</label>
                <input type="number" name="max_connections" value="<?= e($current['max_connections'] ?? '100') ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Thread cache size</label>
                <input type="number" name="thread_cache_size" value="<?= e($current['thread_cache_size'] ?? '8') ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Table open cache</label>
                <input type="number" name="table_open_cache" value="<?= e($current['table_open_cache'] ?? '400') ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
        </div>
        <div class="pt-2">
            <button type="submit" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2.5 transition-colors">Save &amp; restart MariaDB</button>
        </div>
    </form>
</div>
</div>
