<?php include __DIR__ . '/_helpers.php'; ?>
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
    <p class="text-sm text-slate-500 max-w-2xl">
        Move accounts from a cPanel &amp; WHM server - site files, databases, email accounts and stored mail - in one click.
        A single cPanel account, all accounts of a WHM reseller<?= Auth::isAdmin() ? ', or a whole server through WHM root' : '' ?>.
    </p>
    <a href="/whm/migrations/create" class="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 transition-colors shrink-0">
        <?= icon('transfer', 'h-4 w-4') ?> New migration
    </a>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left font-medium px-5 py-3">#</th>
                <th class="text-left font-medium px-5 py-3">Source</th>
                <th class="text-left font-medium px-5 py-3">Accounts</th>
                <th class="text-left font-medium px-5 py-3">Status</th>
                <?php if ($me['role'] === 'admin'): ?><th class="text-left font-medium px-5 py-3">Started by</th><?php endif; ?>
                <th class="text-left font-medium px-5 py-3">Created</th>
                <th class="text-right font-medium px-5 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($migrations as $mg): ?>
            <?php $stalled = MigrationService::isStalled($mg); ?>
            <tr class="hover:bg-slate-50/70">
                <td class="px-5 py-3 text-slate-400"><?= (int) $mg['id'] ?></td>
                <td class="px-5 py-3">
                    <p class="font-medium text-slate-800"><?= e($mg['source_user']) ?>@<?= e($mg['source_host']) ?></p>
                    <p class="text-xs text-slate-400"><?= e(migration_source_label($mg['source_type'])) ?> &middot; <?= e($mg['transfer_mode']) ?> transfer</p>
                </td>
                <td class="px-5 py-3 text-slate-600">
                    <?php if ($mg['status'] === 'draft'): ?>
                        <span class="text-slate-400">not chosen yet</span>
                    <?php else: ?>
                        <?= (int) $mg['done_count'] ?> / <?= (int) $mg['item_count'] ?> done
                    <?php endif; ?>
                </td>
                <td class="px-5 py-3"><?= migration_status_badge($stalled ? 'stalled' : $mg['status']) ?></td>
                <?php if ($me['role'] === 'admin'): ?><td class="px-5 py-3 text-slate-500"><?= e($mg['creator']) ?></td><?php endif; ?>
                <td class="px-5 py-3 text-slate-500 whitespace-nowrap"><?= e(substr((string) $mg['created_at'], 0, 16)) ?></td>
                <td class="px-5 py-3 text-right">
                    <a href="/whm/migrations/<?= (int) $mg['id'] ?><?= $mg['status'] === 'draft' ? '/select' : '' ?>" class="text-indigo-600 hover:text-indigo-500 font-medium">
                        <?= $mg['status'] === 'draft' ? 'Continue' : 'Open' ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$migrations): ?>
            <tr><td colspan="7" class="px-5 py-12 text-center text-slate-400">
                No migrations yet. <a href="/whm/migrations/create" class="text-indigo-600 hover:text-indigo-500 font-medium">Connect to a cPanel server</a> to get started.
            </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
