<div class="flex flex-wrap items-center gap-3 mb-4">
    <form method="get" action="/cpanel/files" class="flex items-center gap-2">
        <select name="domain_id" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-sky-500">
            <?php foreach ($domains as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= $d['id'] === $domainId ? 'selected' : '' ?>><?= e($d['domain_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ($domain): ?>
    <nav class="text-sm text-slate-500 flex items-center gap-1">
        <a href="/cpanel/files?domain_id=<?= (int) $domainId ?>&path=" class="hover:text-sky-700 font-medium">/</a>
        <?php
        $parts = array_filter(explode('/', $currentRel));
        $accum = '';
        foreach ($parts as $part):
            $accum .= '/' . $part;
        ?>
        <span>/</span>
        <a href="/cpanel/files?domain_id=<?= (int) $domainId ?>&path=<?= rawurlencode(ltrim($accum, '/')) ?>" class="hover:text-sky-700"><?= e($part) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
</div>

<?php if (!$domain): ?>
<p class="text-slate-400 text-sm">Add a domain first to manage its files.</p>
<?php else: ?>

<div class="flex flex-wrap gap-3 mb-4">
    <form method="post" action="/cpanel/files/upload" enctype="multipart/form-data" class="flex items-center gap-2 bg-white rounded-lg border border-slate-200 px-3 py-2">
        <?= Csrf::field() ?>
        <input type="hidden" name="domain_id" value="<?= (int) $domainId ?>">
        <input type="hidden" name="path" value="<?= e($currentRel) ?>">
        <input type="file" name="file" required class="text-xs">
        <button class="text-xs font-medium text-white bg-sky-600 hover:bg-sky-500 rounded-md px-3 py-1.5 transition-colors">Upload</button>
    </form>

    <form method="post" action="/cpanel/files/mkdir" class="flex items-center gap-2 bg-white rounded-lg border border-slate-200 px-3 py-2">
        <?= Csrf::field() ?>
        <input type="hidden" name="domain_id" value="<?= (int) $domainId ?>">
        <input type="hidden" name="path" value="<?= e($currentRel) ?>">
        <input type="text" name="name" required placeholder="New folder name" class="text-sm focus:outline-none w-40">
        <button class="text-xs font-medium text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-md px-3 py-1.5 transition-colors">Create folder</button>
    </form>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left font-medium px-5 py-3">Name</th>
                <th class="text-left font-medium px-5 py-3">Size</th>
                <th class="text-left font-medium px-5 py-3">Modified</th>
                <th class="text-right font-medium px-5 py-3">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($entries as $it): ?>
            <tr class="hover:bg-slate-50/70">
                <td class="px-5 py-2.5">
                    <?php if ($it['is_dir']): ?>
                    <a href="/cpanel/files?domain_id=<?= (int) $domainId ?>&path=<?= rawurlencode(trim($currentRel . '/' . $it['name'], '/')) ?>" class="inline-flex items-center gap-2 font-medium text-sky-700 hover:underline">
                        <?= icon('folder', 'h-4 w-4') ?> <?= e($it['name']) ?>
                    </a>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-2 text-slate-700">
                        <span class="h-4 w-4 shrink-0"></span> <?= e($it['name']) ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td class="px-5 py-2.5 text-slate-500"><?= $it['is_dir'] ? '—' : fmt_bytes($it['size']) ?></td>
                <td class="px-5 py-2.5 text-slate-500"><?= date('Y-m-d H:i', $it['modified']) ?></td>
                <td class="px-5 py-2.5">
                    <div class="flex justify-end gap-1">
                        <?php if (!$it['is_dir']): ?>
                        <a href="/cpanel/files/download?domain_id=<?= (int) $domainId ?>&path=<?= rawurlencode($currentRel) ?>&name=<?= rawurlencode($it['name']) ?>"
                           class="p-2 rounded-md text-slate-400 hover:text-sky-600 hover:bg-sky-50"><?= icon('download', 'h-4 w-4') ?></a>
                        <?php endif; ?>
                        <form method="post" action="/cpanel/files/delete" data-confirm="Delete <?= e($it['name']) ?>?">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="domain_id" value="<?= (int) $domainId ?>">
                            <input type="hidden" name="path" value="<?= e($currentRel) ?>">
                            <input type="hidden" name="name" value="<?= e($it['name']) ?>">
                            <button class="p-2 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50"><?= icon('trash', 'h-4 w-4') ?></button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$entries): ?>
            <tr><td colspan="4" class="px-5 py-10 text-center text-slate-400">Empty folder.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
