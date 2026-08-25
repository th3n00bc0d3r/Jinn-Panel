<p class="text-sm text-slate-500 mb-4">
    Direct access to Stalwart's own settings - the same structured configuration system its setup wizard uses,
    grouped for convenience. Changes take effect immediately, no restart required.
</p>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <?php foreach ($groups as $groupName => $objects): ?>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-slate-700 mb-3"><?= e($groupName) ?></h3>
        <div class="space-y-1">
            <?php foreach ($objects as $obj): ?>
            <a href="/whm/server-config/mail/<?= e($obj) ?>" class="flex items-center justify-between rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-indigo-50 hover:text-indigo-700 transition-colors">
                <span><?= e(str_replace('x:', '', $obj)) ?></span>
                <span class="text-slate-300">&rarr;</span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
