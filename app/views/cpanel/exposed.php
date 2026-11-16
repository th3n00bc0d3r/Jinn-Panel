<?php
/** @var array<string,mixed> $d the domain row */
/** @var array{items: list<array{path:string,size:int,kind:string,dir:bool}>, truncated:bool} $scan */
/** @var string $docroot */
/** @var string $private */
$tab = 'exposed';
$id = (int) $d['id'];
$items = $scan['items'];
View::slot('desc', e('Files in the web folder that anyone who knows (or guesses) the name can download: site archives, database dumps, backup copies, logs and data exports.'));
View::script('exposed');
$fmt = fn(int $b) => $b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : ($b >= 1048576 ? round($b / 1048576, 1) . ' MB' : ($b >= 1024 ? round($b / 1024) . ' KB' : $b . ' B'));
$url = fn(string $path) => 'https://' . $d['domain_name'] . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
?>
<?php include __DIR__ . '/../partials/domain_tabs.php'; ?>

<div class="stack">
    <?php if (!$items): ?>
    <section class="card">
        <?= Ui::empty(
            'Nothing exposed',
            'No archives, database dumps, backup copies, logs or data exports were found in ' . $docroot . ($scan['truncated'] ? ' - in the part of the site that was scanned (it is very large).' : '.'),
            'shield'
        ) ?>
    </section>
    <?php else: ?>
    <form method="post" action="/cpanel/domains/<?= $id ?>/exposed" class="card" data-confirm="Move the selected files out of the web folder? Links to them stop working." data-confirm-action="Move to private" aria-labelledby="exposed-title" data-exposed>
        <?= Csrf::field() ?>
        <div class="card-header">
            <div class="min-w-0 max-w-3xl">
                <h2 id="exposed-title" class="card-title"><?= e(Ui::plural(count($items), 'item')) ?> found</h2>
                <p class="card-desc">Keep the ones that are meant to be downloaded. "Move to private" puts the rest in <code><?= e($private) ?>/</code>, outside the web folder - still in the File Manager and over SFTP.</p>
            </div>
            <button type="submit" class="btn btn-primary" data-exposed-submit><?= icon('lock') ?><span data-exposed-label>Move selected to private</span></button>
        </div>
        <?php if ($scan['truncated']): ?>
        <div class="border-b border-line px-5 py-3"><?= Ui::alert('The site is very large, so only part of it was scanned. Move these, then scan again to find more.', 'warning') ?></div>
        <?php endif; ?>
        <div class="table-wrap sm:max-h-[36rem] sm:overflow-y-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col" class="sticky top-0 z-10 w-10 pr-0"><input type="checkbox" class="block h-4 w-4" aria-label="Select all" data-exposed-all hidden></th>
                        <th scope="col" class="sticky top-0 z-10">Path</th>
                        <th scope="col" class="sticky top-0 z-10 hidden sm:table-cell">What it is</th>
                        <th scope="col" class="sticky top-0 z-10 text-right">Size</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                    <tr>
                        <td class="w-10 pr-0"><input type="checkbox" name="paths[]" value="<?= e($it['path']) ?>" class="block h-4 w-4" aria-label="Select <?= e($it['path']) ?>" data-exposed-item></td>
                        <td class="max-w-md">
                            <div class="flex items-start gap-2">
                                <?= icon($it['dir'] ? 'folder' : 'file', 'mt-0.5 h-4 w-4 shrink-0 text-subtle') ?>
                                <?php if (!$it['dir']): ?>
                                <a href="<?= e($url($it['path'])) ?>" target="_blank" rel="noopener" class="font-mono text-[0.8125rem] text-fg [overflow-wrap:anywhere] hover:text-accent-text hover:underline" title="Open it the way a visitor could"><?= e($it['path']) ?></a>
                                <?php else: ?>
                                <span class="font-mono text-[0.8125rem] text-fg [overflow-wrap:anywhere]"><?= e($it['path']) ?>/</span>
                                <?php endif; ?>
                            </div>
                            <p class="mt-0.5 pl-6 text-xs text-muted sm:hidden"><?= e(ucfirst($it['kind'])) ?></p>
                        </td>
                        <td class="hidden whitespace-nowrap text-muted sm:table-cell"><?= e(ucfirst($it['kind'])) ?></td>
                        <td class="whitespace-nowrap text-right tabular-nums text-muted"><?= e($fmt((int) $it['size'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
    <?php endif; ?>
</div>
