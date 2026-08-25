<div class="max-w-xl">
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
    <?php if ($lastLog): ?>
    <p class="text-xs text-slate-400 mb-4 font-mono">Last applied: <?= e($lastLog) ?></p>
    <?php endif; ?>
    <form method="post" action="/whm/server-config/sftp" class="space-y-4">
        <?= Csrf::field() ?>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Max total connections</label>
            <p class="text-xs text-slate-400 mb-1.5">0 = unlimited. Server-wide across all SFTP accounts.</p>
            <input type="number" name="max_total_connections" value="<?= e((string) ($common['max_total_connections'] ?? 0)) ?>"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Max connections per host</label>
            <input type="number" name="max_per_host_connections" value="<?= e((string) ($common['max_per_host_connections'] ?? 20)) ?>"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Idle timeout (minutes)</label>
            <input type="number" name="idle_timeout" value="<?= e((string) ($common['idle_timeout'] ?? 15)) ?>"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div class="pt-2">
            <button type="submit" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2.5 transition-colors">Save &amp; restart SFTPGo</button>
        </div>
    </form>
</div>
</div>
