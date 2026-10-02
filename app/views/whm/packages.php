<div class="flex items-center justify-between mb-4">
    <p class="text-sm text-slate-500"><?= count($packages) ?> package<?= count($packages) === 1 ? '' : 's' ?></p>
    <a href="/whm/packages/create" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 transition-colors">
        <?= icon('plus', 'h-4 w-4') ?> Create package
    </a>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($packages as $p): ?>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 flex flex-col">
        <div class="flex items-start justify-between">
            <div>
                <h3 class="font-semibold text-slate-800"><?= e($p['name']) ?></h3>
                <p class="text-xs text-slate-400 mt-0.5"><?= $p['owner_id'] ? 'Custom · ' . e($p['owner_username'] ?? '') : 'Global package' ?> · <?= (int) $p['account_count'] ?> account<?= (int) $p['account_count'] === 1 ? '' : 's' ?></p>
            </div>
            <?php if ((int) $p['account_count'] === 0): ?>
            <form method="post" action="/whm/packages/<?= (int) $p['id'] ?>/delete" data-confirm="Delete package &quot;<?= e($p['name']) ?>&quot;?">
                <?= Csrf::field() ?>
                <button class="p-1.5 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50"><?= icon('trash', 'h-4 w-4') ?></button>
            </form>
            <?php else: ?>
            <span class="p-1.5 text-slate-300 cursor-not-allowed" title="In use by <?= (int) $p['account_count'] ?> account<?= (int) $p['account_count'] === 1 ? '' : 's' ?>"><?= icon('trash', 'h-4 w-4') ?></span>
            <?php endif; ?>
        </div>
        <dl class="mt-4 space-y-1.5 text-sm text-slate-600">
            <div class="flex justify-between"><dt>Disk quota</dt><dd class="font-medium text-slate-800"><?= (int) $p['disk_quota_mb'] ?> MB</dd></div>
            <div class="flex justify-between"><dt>Bandwidth</dt><dd class="font-medium text-slate-800"><?= (int) $p['bandwidth_mb'] ?> MB</dd></div>
            <div class="flex justify-between"><dt>Domains</dt><dd class="font-medium text-slate-800"><?= (int) $p['max_domains'] ?></dd></div>
            <div class="flex justify-between"><dt>Databases</dt><dd class="font-medium text-slate-800"><?= (int) $p['max_databases'] ?></dd></div>
            <div class="flex justify-between"><dt>Email accounts</dt><dd class="font-medium text-slate-800"><?= (int) $p['max_email_accounts'] ?></dd></div>
            <div class="flex justify-between"><dt>FTP accounts</dt><dd class="font-medium text-slate-800"><?= (int) $p['max_ftp_accounts'] ?></dd></div>
            <?php if ((int) ($p['max_accounts'] ?? 0) > 0): ?><div class="flex justify-between"><dt>Accounts (reseller)</dt><dd class="font-medium text-slate-800"><?= (int) $p['max_accounts'] ?></dd></div><?php endif; ?>
        </dl>
    </div>
    <?php endforeach; ?>
    <?php if (!$packages): ?>
    <p class="text-slate-400 text-sm">No packages yet.</p>
    <?php endif; ?>
</div>
