<div class="flex items-center justify-between mb-4">
    <p class="text-sm text-slate-500"><?= count($accounts) ?> account<?= count($accounts) === 1 ? '' : 's' ?></p>
    <a href="/whm/accounts/create" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 transition-colors">
        <?= icon('plus', 'h-4 w-4') ?> Create account
    </a>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left font-medium px-5 py-3">Username</th>
                <th class="text-left font-medium px-5 py-3">Role</th>
                <?php if ($me['role'] === 'admin'): ?><th class="text-left font-medium px-5 py-3">Reseller</th><?php endif; ?>
                <th class="text-left font-medium px-5 py-3">Package</th>
                <th class="text-left font-medium px-5 py-3">Status</th>
                <th class="text-right font-medium px-5 py-3">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($accounts as $a): ?>
            <tr class="hover:bg-slate-50/70">
                <td class="px-5 py-3">
                    <p class="font-medium text-slate-800"><?= e($a['username']) ?></p>
                    <p class="text-xs text-slate-400"><?= e($a['email']) ?></p>
                </td>
                <td class="px-5 py-3">
                    <span class="inline-flex text-xs font-medium capitalize rounded-full px-2 py-0.5 <?= $a['role'] === 'reseller' ? 'bg-violet-50 text-violet-700' : 'bg-slate-100 text-slate-600' ?>">
                        <?= e($a['role']) ?>
                    </span>
                </td>
                <?php if ($me['role'] === 'admin'): ?>
                <td class="px-5 py-3 text-slate-500"><?= e($a['reseller_username'] ?? '—') ?></td>
                <?php endif; ?>
                <td class="px-5 py-3 text-slate-600"><?= e($a['package_name'] ?? '—') ?></td>
                <td class="px-5 py-3">
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium <?= $a['status'] === 'active' ? 'text-emerald-700' : 'text-amber-700' ?>">
                        <span class="h-1.5 w-1.5 rounded-full <?= $a['status'] === 'active' ? 'bg-emerald-500' : 'bg-amber-500' ?>"></span>
                        <?= e($a['status']) ?>
                    </span>
                </td>
                <td class="px-5 py-3">
                    <div class="flex justify-end gap-1">
                        <?php if ($a['status'] === 'active'): ?>
                        <form method="post" action="/whm/accounts/<?= (int) $a['id'] ?>/suspend" data-confirm="Suspend <?= e($a['username']) ?>?">
                            <?= Csrf::field() ?>
                            <button class="p-2 rounded-md text-slate-400 hover:text-amber-600 hover:bg-amber-50" title="Suspend"><?= icon('pause', 'h-4 w-4') ?></button>
                        </form>
                        <?php else: ?>
                        <form method="post" action="/whm/accounts/<?= (int) $a['id'] ?>/unsuspend">
                            <?= Csrf::field() ?>
                            <button class="p-2 rounded-md text-slate-400 hover:text-emerald-600 hover:bg-emerald-50" title="Unsuspend"><?= icon('play', 'h-4 w-4') ?></button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="/whm/accounts/<?= (int) $a['id'] ?>/delete" data-confirm="Permanently delete <?= e($a['username']) ?> and all of its domains, databases, mailboxes and FTP accounts?">
                            <?= Csrf::field() ?>
                            <button class="p-2 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50" title="Delete"><?= icon('trash', 'h-4 w-4') ?></button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$accounts): ?>
            <tr><td colspan="6" class="px-5 py-10 text-center text-slate-400">No accounts yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
