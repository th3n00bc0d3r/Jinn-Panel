<?php
/** @var list<array<string,mixed>> $domains */
/** @var array<string,mixed>|null $domain */
/** @var int $domainId */
/** @var string $currentRel relative to the site folder */
/** @var string $docrootRel the document root, relative to the site folder */
/** @var list<array<string,mixed>> $entries */
$hidden = fn() => Csrf::field() . '<input type="hidden" name="domain_id" value="' . (int) $domainId . '"><input type="hidden" name="path" value="' . e($currentRel) . '">';
$link = fn(string $rel) => '/cpanel/files?domain_id=' . (int) $domainId . '&path=' . rawurlencode($rel);
$isArchive = fn(string $n) => (bool) preg_match('/\.(zip|tar\.gz|tgz|tar)$/i', $n);
$chip = 'inline-flex items-center gap-1.5 rounded-lg text-xs font-medium px-3 py-1.5 transition-colors';
?>
<div class="flex flex-wrap items-center gap-3 mb-4">
    <form method="get" action="/cpanel/files" class="flex items-center gap-2">
        <select name="domain_id" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-sky-500">
            <?php foreach ($domains as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $domainId ? 'selected' : '' ?>><?= e($d['domain_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($domain): ?>
    <nav class="text-sm text-slate-500 flex flex-wrap items-center gap-1">
        <a href="<?= e($link('')) ?>" class="hover:text-sky-700 font-medium font-mono">/var/www/<?= e($domain['domain_name']) ?></a>
        <?php $accum = ''; foreach (array_filter(explode('/', $currentRel)) as $part): $accum = ltrim("$accum/$part", '/'); ?>
        <span>/</span><a href="<?= e($link($accum)) ?>" class="hover:text-sky-700 font-mono"><?= e($part) ?></a>
        <?php endforeach; ?>
        <?php if ($currentRel !== $docrootRel): ?><a href="<?= e($link($docrootRel)) ?>" class="ml-2 text-xs text-sky-700 hover:underline">go to the website folder</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>

<?php if (!$domain): ?>
<p class="text-slate-400 text-sm">Add a domain first to manage its files.</p>
<?php else: ?>

<div class="flex flex-wrap gap-3 mb-4">
    <form method="post" action="/cpanel/files/upload" enctype="multipart/form-data" class="flex items-center gap-2 bg-white rounded-lg border border-slate-200 px-3 py-2">
        <?= $hidden() ?>
        <input type="file" name="file[]" multiple required class="text-xs">
        <button class="text-xs font-medium text-white bg-sky-600 hover:bg-sky-500 rounded-md px-3 py-1.5 transition-colors">Upload</button>
    </form>
    <form method="post" action="/cpanel/files/mkdir" class="flex items-center gap-2 bg-white rounded-lg border border-slate-200 px-3 py-2">
        <?= $hidden() ?>
        <input type="text" name="name" required placeholder="New folder name" class="text-sm focus:outline-none w-40">
        <button class="text-xs font-medium text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-md px-3 py-1.5 transition-colors">Create folder</button>
    </form>
</div>

<form method="post" action="/cpanel/files/action" id="fm" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <?= $hidden() ?>
    <input type="hidden" name="op" value="">
    <input type="hidden" name="dest" value="">
    <div class="px-5 py-2.5 border-b border-slate-100 flex flex-wrap items-center gap-2 text-sm" data-toolbar>
        <span class="text-slate-500 mr-2"><span data-count>0</span> selected</span>
        <button type="button" data-op="download" class="<?= $chip ?> bg-slate-100 hover:bg-slate-200 text-slate-700"><?= icon('download', 'h-3.5 w-3.5') ?> Download</button>
        <button type="button" data-op="compress" class="<?= $chip ?> bg-slate-100 hover:bg-slate-200 text-slate-700">Compress to .zip</button>
        <button type="button" data-op="move" class="<?= $chip ?> bg-slate-100 hover:bg-slate-200 text-slate-700">Move</button>
        <button type="button" data-op="copy" class="<?= $chip ?> bg-slate-100 hover:bg-slate-200 text-slate-700">Copy</button>
        <button type="button" data-op="chmod" class="<?= $chip ?> bg-slate-100 hover:bg-slate-200 text-slate-700">Permissions</button>
        <button type="button" data-op="delete" class="<?= $chip ?> bg-red-50 hover:bg-red-100 text-red-700"><?= icon('trash', 'h-3.5 w-3.5') ?> Delete</button>
    </div>
    <div class="hidden px-5 py-3 border-b border-slate-100 bg-slate-50 flex-wrap items-end gap-3 text-sm" data-chmod>
        <label class="text-xs text-slate-600">Files <input name="file_mode" value="644" class="block w-20 rounded-md border border-slate-300 px-2 py-1 font-mono"></label>
        <label class="text-xs text-slate-600">Folders <input name="dir_mode" value="755" class="block w-20 rounded-md border border-slate-300 px-2 py-1 font-mono"></label>
        <label class="flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" name="recursive" value="1" class="rounded border-slate-300"> Also everything inside selected folders</label>
        <button type="button" data-apply-chmod class="<?= $chip ?> bg-sky-600 hover:bg-sky-500 text-white">Apply</button>
        <span class="text-xs text-slate-400">644/755 suits most sites. Files the web server must change need write for the owner (6xx).</span>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="px-5 py-3 w-10"><input type="checkbox" data-all class="rounded border-slate-300 text-sky-600 focus:ring-sky-500" title="Select all"></th>
                <th class="text-left font-medium px-3 py-3">Name</th>
                <th class="text-left font-medium px-3 py-3">Size</th>
                <th class="text-left font-medium px-3 py-3">Modified</th>
                <th class="text-left font-medium px-3 py-3">Permissions</th>
                <th class="text-right font-medium px-5 py-3">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php if ($currentRel !== ''): ?>
            <tr><td></td><td class="px-3 py-2" colspan="5"><a href="<?= e($link(dirname($currentRel) === '.' ? '' : dirname($currentRel))) ?>" class="text-sky-700 hover:underline">.. (up)</a></td></tr>
            <?php endif; ?>
            <?php foreach ($entries as $it): ?>
            <?php $rel = trim($currentRel . '/' . $it['name'], '/'); ?>
            <tr class="hover:bg-slate-50/70">
                <td class="px-5 py-2"><input type="checkbox" name="names[]" value="<?= e($it['name']) ?>" data-item class="rounded border-slate-300 text-sky-600 focus:ring-sky-500"></td>
                <td class="px-3 py-2">
                    <?php if ($it['is_dir']): ?>
                    <a href="<?= e($link($rel)) ?>" class="inline-flex items-center gap-2 font-medium text-sky-700 hover:underline"><?= icon('folder', 'h-4 w-4') ?> <?= e($it['name']) ?></a>
                    <?php if ($rel === $docrootRel): ?><span class="ml-1 text-xs text-slate-400">(website)</span><?php endif; ?>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-2 text-slate-700"><span class="h-4 w-4 shrink-0"></span> <?= e($it['name']) ?><?= $it['is_link'] ? ' <span class="text-xs text-slate-400">(link)</span>' : '' ?></span>
                    <?php endif; ?>
                </td>
                <td class="px-3 py-2 text-slate-500 whitespace-nowrap"><?= $it['is_dir'] ? '—' : fmt_bytes($it['size']) ?></td>
                <td class="px-3 py-2 text-slate-500 whitespace-nowrap"><?= date('Y-m-d H:i', $it['modified']) ?></td>
                <td class="px-3 py-2 font-mono text-xs text-slate-500" title="<?= e($it['perms']) ?>"><?= e($it['mode']) ?></td>
                <td class="px-5 py-2">
                    <div class="flex justify-end items-center gap-1">
                        <?php if ($isArchive($it['name'])): ?>
                        <button type="button" data-row-op="extract" data-name="<?= e($it['name']) ?>" class="text-xs font-medium text-sky-700 hover:text-sky-600 px-2 py-1">Extract</button>
                        <?php endif; ?>
                        <button type="button" data-row-op="rename" data-name="<?= e($it['name']) ?>" class="text-xs font-medium text-slate-500 hover:text-slate-700 px-2 py-1">Rename</button>
                        <?php if (!$it['is_dir']): ?>
                        <a href="/cpanel/files/download?domain_id=<?= (int) $domainId ?>&path=<?= rawurlencode($currentRel) ?>&names[]=<?= rawurlencode($it['name']) ?>" class="p-2 rounded-md text-slate-400 hover:text-sky-600 hover:bg-sky-50" title="Download"><?= icon('download', 'h-4 w-4') ?></a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$entries): ?>
            <tr><td colspan="6" class="px-5 py-10 text-center text-slate-400">Empty folder.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('fm');
    var items = function () { return Array.prototype.slice.call(form.querySelectorAll('[data-item]')); };
    var checked = function () { return items().filter(function (i) { return i.checked; }); };
    var toolbar = form.querySelector('[data-toolbar]');
    function update() {
        var n = checked().length;
        form.querySelector('[data-count]').textContent = n;
        toolbar.querySelectorAll('[data-op]').forEach(function (b) { b.disabled = n === 0; b.classList.toggle('opacity-40', n === 0); });
    }
    form.querySelector('[data-all]').addEventListener('change', function (e) { items().forEach(function (i) { i.checked = e.target.checked; }); update(); });
    items().forEach(function (i) { i.addEventListener('change', update); });
    function submit(op, dest) { form.elements["op"].value = op; form.elements["dest"].value = dest || ''; form.submit(); }
    var here = <?= json_encode($currentRel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    toolbar.querySelectorAll('[data-op]').forEach(function (b) {
        b.addEventListener('click', function () {
            var n = checked().length, op = b.dataset.op;
            if (!n) return;
            if (op === 'download') {
                var q = '?domain_id=<?= (int) $domainId ?>&path=' + encodeURIComponent(here);
                checked().forEach(function (i) { q += '&names[]=' + encodeURIComponent(i.value); });
                location.href = '/cpanel/files/download' + q;
            } else if (op === 'delete') {
                if (confirm('Delete ' + n + ' item(s)? Folders are deleted with everything inside. This can\'t be undone.')) submit('delete');
            } else if (op === 'move' || op === 'copy') {
                var t = prompt((op === 'move' ? 'Move' : 'Copy') + ' ' + n + ' item(s) to which folder? (relative to /var/www/<?= e($domain['domain_name']) ?>)', here);
                if (t !== null) submit(op, t);
            } else if (op === 'compress') {
                var z = prompt('Name of the .zip to create in this folder:', 'archive.zip');
                if (z) submit('compress', z);
            } else if (op === 'chmod') {
                var box = form.querySelector('[data-chmod]'); box.classList.toggle('hidden'); box.classList.toggle('flex');
            }
        });
    });
    form.querySelector('[data-apply-chmod]').addEventListener('click', function () { submit('chmod'); });
    form.querySelectorAll('[data-row-op]').forEach(function (b) {
        b.addEventListener('click', function () {
            items().forEach(function (i) { i.checked = i.value === b.dataset.name; });
            if (b.dataset.rowOp === 'extract') {
                if (confirm('Extract ' + b.dataset.name + ' into this folder? Files with the same names are overwritten.')) submit('extract');
            } else {
                var nn = prompt('New name for ' + b.dataset.name + ':', b.dataset.name);
                if (nn && nn !== b.dataset.name) submit('rename', nn); else update();
            }
        });
    });
    update();
})();
</script>
<?php endif; ?>
