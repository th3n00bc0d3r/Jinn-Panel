<p class="text-sm text-slate-500 mb-4">
    Sites run their PHP in PHP-FPM, one pool per hosting account running as that account's own Linux user. Each
    additional version is its own PHP-FPM service with its own binaries and common extensions; domains can be assigned
    to any installed version individually from cPanel &gt; Domains. The default version (PHP 8.5) is the main install
    and can't be removed here.
</p>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden mb-4">
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left font-medium px-5 py-3">Version</th>
                <th class="text-left font-medium px-5 py-3">Status</th>
                <th class="text-left font-medium px-5 py-3">Runs as</th>
                <th class="text-right font-medium px-5 py-3">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <tr class="hover:bg-slate-50/70">
                <td class="px-5 py-3 font-medium text-slate-800">PHP 8.5 <span class="text-xs text-slate-400 font-normal">(default)</span></td>
                <td class="px-5 py-3"><span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>active</span></td>
                <td class="px-5 py-3 text-slate-500 font-mono text-xs">jinnpanel-php-fpm@default</td>
                <td class="px-5 py-3 text-right text-slate-300 text-xs">Cannot be removed</td>
            </tr>
            <?php foreach ($installed as $v): ?>
            <tr class="hover:bg-slate-50/70">
                <td class="px-5 py-3 font-medium text-slate-800">PHP <?= e($v['version']) ?></td>
                <td class="px-5 py-3">
                    <?php $s = $v['status']; $color = ['active' => 'emerald', 'installing' => 'amber', 'removing' => 'amber', 'failed' => 'red'][$s] ?? 'slate'; ?>
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-<?= $color ?>-700">
                        <span class="h-1.5 w-1.5 rounded-full bg-<?= $color ?>-500"></span><?= e($s) ?>
                    </span>
                </td>
                <td class="px-5 py-3 text-slate-500 font-mono text-xs">jinnpanel-php-fpm@<?= e(str_replace('.', '', (string) $v['version'])) ?></td>
                <td class="px-5 py-3 text-right">
                    <form method="post" action="/whm/server-config/php-versions/<?= e($v['version']) ?>/remove" data-confirm="Remove PHP <?= e($v['version']) ?>? Domains must be moved off it first.">
                        <?= Csrf::field() ?>
                        <button class="p-2 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50"><?= icon('trash', 'h-4 w-4') ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($available): ?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 max-w-md">
    <h2 class="text-sm font-semibold text-slate-700 mb-3">Install a version</h2>
    <form method="post" action="/whm/server-config/php-versions" class="flex items-center gap-3">
        <?= Csrf::field() ?>
        <select name="version" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <?php foreach ($available as $v): ?>
            <option value="<?= e($v) ?>">PHP <?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 transition-colors whitespace-nowrap">Install</button>
    </form>
    <p class="text-xs text-slate-400 mt-2">Downloads the official static-php PHP-FPM build and its extensions and starts it - usually done within a minute or two.</p>
</div>
<?php else: ?>
<p class="text-sm text-slate-400">All supported alt versions are installed.</p>
<?php endif; ?>
