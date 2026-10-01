<div class="max-w-xl">
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
    <?php if ($lastLog): ?>
    <p class="text-xs text-slate-400 mb-4 font-mono">Last applied: <?= e($lastLog) ?></p>
    <?php endif; ?>
    <form method="post" action="/whm/server-config/php" class="space-y-4">
        <?= Csrf::field() ?>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Memory limit</label>
                <input type="text" name="memory_limit" value="<?= e($current['memory_limit'] ?? '128M') ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Max execution time (s)</label>
                <input type="number" name="max_execution_time" value="<?= e($current['max_execution_time'] ?? '30') ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Upload max filesize</label>
                <input type="text" name="upload_max_filesize" value="<?= e($current['upload_max_filesize'] ?? '2M') ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Post max size</label>
                <input type="text" name="post_max_size" value="<?= e($current['post_max_size'] ?? '8M') ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
        </div>

        <hr class="border-slate-100">
        <h3 class="text-sm font-semibold text-slate-700">OPcache</h3>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Memory (MB)</label>
                <input type="number" name="opcache_memory" value="<?= e($current['opcache.memory_consumption'] ?? '128') ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Max accelerated files</label>
                <input type="number" name="opcache_max_files" value="<?= e($current['opcache.max_accelerated_files'] ?? '10000') ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
        </div>
        <?php $reval = ($current['opcache.validate_timestamps'] ?? '1') === '0' ? 'never' : (string) ($current['opcache.revalidate_freq'] ?? '2'); ?>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Check changed PHP files</label>
            <select name="opcache_revalidate" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <?php foreach (['0' => 'On every request (development)', '2' => 'Every 2 seconds (default)', '60' => 'Every minute', '300' => 'Every 5 minutes', 'never' => 'Never - only after "Clear cache" or a restart (fastest)'] as $v => $l): ?>
                <option value="<?= e((string) $v) ?>" <?= $reval === (string) $v ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="text-xs text-slate-400 mt-1">How soon uploaded PHP changes take effect. Customers can clear their site's cache in cPanel > Cache.</p>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="jit_enabled" value="1" <?= ($current['opcache.jit'] ?? 'off') !== 'off' ? 'checked' : '' ?> class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
            Enable JIT (tracing mode, 64MB buffer)
        </label>

        <div class="pt-2">
            <button type="submit" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2.5 transition-colors">Save &amp; restart PHP</button>
        </div>
    </form>
</div>
</div>
