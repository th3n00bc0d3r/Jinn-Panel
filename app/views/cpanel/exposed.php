<?php
/** @var array<string,mixed> $d */
/** @var array{items: list<array{path:string,size:int,kind:string,dir:bool}>, truncated:bool} $scan */
/** @var string $docroot */
/** @var string $private */
$fmt = fn(int $b) => $b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : ($b >= 1048576 ? round($b / 1048576, 1) . ' MB' : ($b >= 1024 ? round($b / 1024) . ' KB' : $b . ' B'));
$id = (int) $d['id'];
$items = $scan['items'];
?>
<div class="space-y-4">
    <div>
        <a href="/cpanel/domains/<?= $id ?>" class="text-sm text-slate-500 hover:text-sky-700">&larr; <?= e($d['domain_name']) ?></a>
        <h2 class="text-lg font-semibold text-slate-800 mt-1">Exposed files</h2>
        <p class="text-sm text-slate-500 max-w-3xl">
            Files in <span class="font-mono"><?= e($docroot) ?></span> that anyone who knows (or guesses) the name can download: site archives, database dumps,
            backup copies, logs and data exports. Some may be intentional downloads - keep those. "Move to private" moves the rest to
            <span class="font-mono"><?= e($private) ?>/</span>, outside the web folder (still in the File Manager and over SFTP).
        </p>
    </div>

    <?php if (!$items): ?>
    <div class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 text-sm px-4 py-3">Nothing found<?= $scan['truncated'] ? ' in the part of the site scanned (it is very large)' : '' ?>.</div>
    <?php else: ?>
    <form method="post" action="/cpanel/domains/<?= $id ?>/exposed" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden" data-confirm="Move the selected files out of the web folder? Links to them stop working.">
        <?= Csrf::field() ?>
        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-3">
            <span class="text-sm font-semibold text-slate-700"><?= count($items) ?> found</span>
            <?php if ($scan['truncated']): ?><span class="text-xs text-amber-700">The site is large - only part of it was scanned.</span><?php endif; ?>
            <button class="ml-auto rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium px-4 py-2">Move selected to private</button>
        </div>
        <div class="overflow-x-auto max-h-[36rem] overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide sticky top-0">
                    <tr><th class="px-5 py-2.5 w-10"></th><th class="text-left font-medium px-3 py-2.5">Path</th><th class="text-left font-medium px-3 py-2.5">What</th><th class="text-right font-medium px-5 py-2.5">Size</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($items as $it): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-2"><input type="checkbox" name="paths[]" value="<?= e($it['path']) ?>" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500"></td>
                        <td class="px-3 py-2 font-mono text-slate-700 break-all">
                            <?php if (!$it['dir']): ?><a href="https://<?= e($d['domain_name']) ?>/<?= e(implode('/', array_map('rawurlencode', explode('/', $it['path'])))) ?>" target="_blank" rel="noopener" class="hover:underline"><?= e($it['path']) ?></a><?php else: ?><?= e($it['path']) ?>/<?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-slate-500"><?= e($it['kind']) ?></td>
                        <td class="px-5 py-2 text-right text-slate-500 whitespace-nowrap"><?= e($fmt($it['size'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
    <?php endif; ?>
</div>
