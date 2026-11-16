<?php
/** @var list<array<string,mixed>> $domains */
/** @var array<string,mixed>|null $domain */
/** @var int $domainId */
/** @var string $currentRel relative to the site folder */
/** @var string $docrootRel the document root, relative to the site folder */
/** @var list<array<string,mixed>> $entries */
View::script('files');
$hidden = fn() => Csrf::field() . '<input type="hidden" name="domain_id" value="' . (int) $domainId . '"><input type="hidden" name="path" value="' . e($currentRel) . '">';
$link = fn(string $rel) => '/cpanel/files?domain_id=' . (int) $domainId . '&path=' . rawurlencode($rel);
$editLink = fn(string $n) => '/cpanel/files/edit?domain_id=' . (int) $domainId . '&path=' . rawurlencode($currentRel) . '&name=' . rawurlencode($n);
$isArchive = fn(string $n) => (bool) preg_match('/\.(zip|tar\.gz|tgz|tar\.bz2|tar)$/i', $n);
$isText = fn(string $n, int $size) => $size <= 1048576 && (
    (bool) preg_match('/\.(php|phtml|html?|css|js|mjs|json|txt|md|xml|svg|ini|env|ya?ml|conf|log|sql|csv|py|sh|twig|tpl|vue|ts|tsx|jsx|scss|less|htaccess|htpasswd|user\.ini|lock|rb|pl|go|rs|java|c|h|toml|sample|dist|example)$/i', $n)
    || (bool) preg_match('/^\.[A-Za-z0-9_.-]+$/', $n)
    || !str_contains($n, '.'));
View::slot('desc', e('Files of your sites, as the site itself sees them. Everything stays inside the domain\'s folder.'));
?>
<?php if (!$domain): ?>
<div class="card"><?= Ui::empty('No domains yet', 'Add a domain first: each domain has its own folder of files.', 'folder', '<a href="/cpanel/domains#add-domain" class="btn btn-primary">' . icon('plus') . 'Add domain</a>') ?></div>
<?php else: ?>
<div class="stack">
    <div class="card flex flex-wrap items-center gap-3 p-3">
        <form method="get" action="/cpanel/files" class="shrink-0">
            <label for="fm-domain" class="sr-only">Domain</label>
            <select id="fm-domain" name="domain_id" class="select w-56" data-autosubmit>
                <?php foreach ($domains as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $domainId ? 'selected' : '' ?>><?= e($d['domain_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <nav aria-label="Folder" class="min-w-0 flex-1">
            <ol class="flex flex-wrap items-center gap-1 font-mono text-sm">
                <li><a href="<?= e($link('')) ?>" class="rounded px-1 text-muted hover:bg-surface-3 hover:text-fg">/var/www/<?= e($domain['domain_name']) ?></a></li>
                <?php $accum = ''; $parts = array_values(array_filter(explode('/', $currentRel), 'strlen')); foreach ($parts as $i => $part): $accum = ltrim("$accum/$part", '/'); ?>
                <li class="flex items-center gap-1"><span class="text-subtle" aria-hidden="true">/</span>
                    <?php if ($i === count($parts) - 1): ?><span class="px-1 font-medium text-fg" aria-current="page"><?= e($part) ?></span>
                    <?php else: ?><a href="<?= e($link($accum)) ?>" class="rounded px-1 text-muted hover:bg-surface-3 hover:text-fg"><?= e($part) ?></a><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </nav>
        <?php if ($currentRel !== $docrootRel): ?>
        <a href="<?= e($link($docrootRel)) ?>" class="btn btn-ghost btn-sm"><?= icon('home') ?>Website folder</a>
        <?php endif; ?>
    </div>

    <form method="post" action="/cpanel/files/action" id="fm" class="card" data-here="<?= e($currentRel) ?>" data-domain-id="<?= (int) $domainId ?>" data-site="/var/www/<?= e($domain['domain_name']) ?>">
        <?= $hidden() ?>
        <input type="hidden" name="op" value="">
        <input type="hidden" name="dest" value="">

        <div class="card-header gap-2 py-3">
            <div class="flex items-center gap-2" data-selection hidden>
                <span class="mr-1 text-sm text-muted"><span data-count>0</span> selected</span>
                <button type="button" data-op="download" class="btn btn-secondary btn-sm"><?= icon('download') ?>Download</button>
                <button type="button" data-op="compress" class="btn btn-secondary btn-sm"><?= icon('archive') ?>Zip</button>
                <button type="button" data-op="move" class="btn btn-secondary btn-sm">Move</button>
                <button type="button" data-op="copy" class="btn btn-secondary btn-sm"><?= icon('copy') ?>Copy</button>
                <button type="button" data-op="chmod" class="btn btn-secondary btn-sm">Permissions</button>
                <button type="button" data-op="delete" class="btn btn-danger-ghost btn-sm"><?= icon('trash') ?>Delete</button>
            </div>
            <p class="text-sm text-muted" data-no-selection><?= e(Ui::plural(count($entries), 'item')) ?> · drop files here to upload</p>
            <div class="flex flex-wrap items-center gap-2" data-folder-actions>
                <a href="/cpanel/files/edit?domain_id=<?= (int) $domainId ?>&amp;path=<?= rawurlencode($currentRel) ?>&amp;new=1" class="btn btn-secondary btn-sm"><?= icon('file') ?>New file</a>
                <button type="button" class="btn btn-secondary btn-sm" data-mkdir><?= icon('folder') ?>New folder</button>
                <label for="fm-upload-input" class="btn btn-primary btn-sm cursor-pointer"><?= icon('upload') ?>Upload</label>
            </div>
        </div>

        <div class="hidden flex-wrap items-end gap-4 border-b border-line bg-surface-2 px-5 py-4" data-chmod>
            <?= Ui::field('file_mode', 'Files', ['value' => '644', 'mono' => true, 'class' => 'input-sm w-24', 'attrs' => ['pattern' => '[0-7]{3}']]) ?>
            <?= Ui::field('dir_mode', 'Folders', ['value' => '755', 'mono' => true, 'class' => 'input-sm w-24', 'attrs' => ['pattern' => '[0-7]{3}']]) ?>
            <?= Ui::checkbox('recursive', 'Also everything inside the selected folders') ?>
            <button type="button" data-apply-chmod class="btn btn-primary btn-sm">Apply</button>
            <p class="w-full text-xs text-muted">644 / 755 suits most sites. Files the website itself must change need write for the owner (6xx).</p>
        </div>

        <div class="table-wrap" data-dropzone>
            <table class="table table-tight">
                <thead>
                    <tr>
                        <th scope="col" class="w-10"><input type="checkbox" data-all aria-label="Select all"></th>
                        <th scope="col">Name</th>
                        <th scope="col" class="hidden sm:table-cell">Size</th>
                        <th scope="col" class="hidden md:table-cell">Modified</th>
                        <th scope="col" class="hidden lg:table-cell">Permissions</th>
                        <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($currentRel !== ''): ?>
                    <tr>
                        <td></td>
                        <td colspan="5"><a href="<?= e($link(dirname($currentRel) === '.' ? '' : dirname($currentRel))) ?>" class="inline-flex items-center gap-2 text-muted hover:text-fg"><?= icon('arrow-left', 'h-4 w-4') ?>Up one folder</a></td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($entries as $it):
                        $rel = trim($currentRel . '/' . $it['name'], '/');
                        $text = !$it['is_dir'] && !$it['is_link'] && $isText((string) $it['name'], (int) $it['size']); ?>
                    <tr>
                        <td><input type="checkbox" name="names[]" value="<?= e($it['name']) ?>" data-item aria-label="Select <?= e($it['name']) ?>"></td>
                        <td class="max-w-[24rem]">
                            <?php if ($it['is_dir']): ?>
                            <a href="<?= e($link($rel)) ?>" class="inline-flex max-w-full items-center gap-2 font-medium text-fg hover:text-accent-text"><?= icon('folder', 'h-4 w-4 shrink-0 text-accent-text') ?><span class="truncate"><?= e($it['name']) ?></span></a>
                            <?php if ($rel === $docrootRel): ?> <?= Ui::badge('website', 'accent') ?><?php endif; ?>
                            <?php elseif ($text): ?>
                            <a href="<?= e($editLink((string) $it['name'])) ?>" class="inline-flex max-w-full items-center gap-2 text-fg hover:text-accent-text" title="Edit"><?= icon('file-text', 'h-4 w-4 shrink-0 text-subtle') ?><span class="truncate"><?= e($it['name']) ?></span></a>
                            <?php else: ?>
                            <span class="inline-flex max-w-full items-center gap-2 text-fg"><?= icon('file', 'h-4 w-4 shrink-0 text-subtle') ?><span class="truncate"><?= e($it['name']) ?></span><?= $it['is_link'] ? ' ' . Ui::badge('link', 'neutral') : '' ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="hidden whitespace-nowrap tabular-nums text-muted sm:table-cell"><?= $it['is_dir'] || $it['is_link'] ? '—' : e(fmt_bytes((int) $it['size'])) ?></td>
                        <td class="hidden whitespace-nowrap text-muted md:table-cell" title="<?= e(date('Y-m-d H:i:s', (int) $it['modified'])) ?>"><?= e(fmt_ago((int) $it['modified'])) ?></td>
                        <td class="hidden font-mono text-xs text-muted lg:table-cell" title="<?= e($it['perms']) ?>"><?= e($it['mode']) ?></td>
                        <td>
                            <div class="flex items-center justify-end gap-0.5">
                                <?php if ($text): ?><?= Ui::iconLink($editLink((string) $it['name']), 'edit', 'Edit ' . $it['name']) ?><?php endif; ?>
                                <?php if ($isArchive((string) $it['name'])): ?>
                                <button type="button" data-row-op="extract" data-name="<?= e($it['name']) ?>" class="btn btn-ghost btn-sm">Extract</button>
                                <?php endif; ?>
                                <button type="button" data-row-op="rename" data-name="<?= e($it['name']) ?>" class="btn btn-ghost btn-sm" aria-label="Rename <?= e($it['name']) ?>">Rename</button>
                                <?php if (!$it['is_dir']): ?>
                                <?= Ui::iconLink('/cpanel/files/download?domain_id=' . (int) $domainId . '&path=' . rawurlencode($currentRel) . '&names[]=' . rawurlencode((string) $it['name']), 'download', 'Download ' . $it['name']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$entries): ?>
                    <?= Ui::emptyRow(6, 'This folder is empty', 'Upload files, drop them here, or create a file or folder.', 'folder') ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<form method="post" action="/cpanel/files/upload" enctype="multipart/form-data" id="fm-upload" class="mt-4 flex flex-wrap items-center gap-3 text-sm" data-upload>
    <?= $hidden() ?>
    <label for="fm-upload-input" class="text-muted">Upload to this folder:</label>
    <input id="fm-upload-input" type="file" name="file[]" multiple required class="text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface-3 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-fg">
    <button class="btn btn-secondary btn-sm">Upload</button>
</form>

<form method="post" action="/cpanel/files/mkdir" id="fm-mkdir" class="mt-3 flex flex-wrap items-end gap-3" data-mkdir-form>
    <?= $hidden() ?>
    <?= Ui::field('name', 'New folder', ['required' => true, 'placeholder' => 'images', 'class' => 'input-sm', 'attrs' => ['autocomplete' => 'off']]) ?>
    <button class="btn btn-secondary btn-sm">Create folder</button>
</form>
<?php endif; ?>
