<?php include __DIR__ . '/../partials/quota_bars.php'; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4">
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                <tr>
                    <th class="text-left font-medium px-5 py-3">Username</th>
                    <th class="text-left font-medium px-5 py-3">Home directory</th>
                    <th class="text-right font-medium px-5 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($accounts as $a): ?>
                <tr class="hover:bg-slate-50/70">
                    <td class="px-5 py-3 font-medium text-slate-700"><?= e($a['username']) ?></td>
                    <td class="px-5 py-3 font-mono text-xs text-slate-500"><?= e($a['home_dir']) ?></td>
                    <td class="px-5 py-3 text-right">
                        <form method="post" action="/cpanel/ftp/<?= (int) $a['id'] ?>/delete" data-confirm="Delete SFTP account <?= e($a['username']) ?>?">
                            <?= Csrf::field() ?>
                            <button class="p-2 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50"><?= icon('trash', 'h-4 w-4') ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$accounts): ?>
                <tr><td colspan="3" class="px-5 py-10 text-center text-slate-400">No FTP/SFTP accounts yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 h-fit">
        <h2 class="text-sm font-semibold text-slate-700 mb-3">Create an SFTP account</h2>
        <form method="post" action="/cpanel/ftp" class="space-y-3">
            <?= Csrf::field() ?>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Username</label>
                <input type="text" name="username" required placeholder="uploads"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Password</label>
                <input type="password" name="password" required minlength="8"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Restrict to domain (optional)</label>
                <select name="domain_id" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500 bg-white">
                    <option value="">Account root</option>
                    <?php foreach ($domains as $d): ?>
                    <option value="<?= (int) $d['id'] ?>"><?= e($d['domain_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="w-full rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium py-2.5 transition-colors">Create SFTP account</button>
            <p class="text-xs text-slate-400">Connect on port 2022 with any SFTP client.</p>
        </form>
    </div>
</div>
