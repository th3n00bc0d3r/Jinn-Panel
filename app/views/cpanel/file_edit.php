<?php
/** @var array<string,mixed> $domain */
/** @var string $dir folder, relative to the site folder */
/** @var string $name '' = new file */
/** @var string $content */
/** @var int $size */
/** @var int|null $modified mtime when opened (null = new file) */
/** @var string $mode */
/** @var string $eol 'lf' | 'crlf' */
/** @var string|null $error a save that failed (the text is the one typed) */
View::script('file-edit');
View::slot('header', 'none');
$new = $name === '' || $modified === null;
$back = '/cpanel/files?domain_id=' . (int) $domain['id'] . '&path=' . rawurlencode($dir);
$path = '/var/www/' . $domain['domain_name'] . ($dir !== '' ? '/' . $dir : '') . '/' . ($name !== '' ? $name : '');
View::breadcrumbs([['File Manager', $back], [$name !== '' ? $name : 'New file']]);
$lines = substr_count($content, "\n") + 1;
?>
<form method="post" action="/cpanel/files/save" class="flex h-[calc(100vh-9rem)] min-h-[28rem] flex-col gap-4" data-editor>
    <?= Csrf::field() ?>
    <input type="hidden" name="domain_id" value="<?= (int) $domain['id'] ?>">
    <input type="hidden" name="path" value="<?= e($dir) ?>">
    <input type="hidden" name="eol" value="<?= e($eol) ?>">
    <input type="hidden" name="expect_mtime" value="<?= $modified !== null ? (int) $modified : '' ?>">

    <div class="flex flex-wrap items-center gap-3">
        <a href="<?= e($back) ?>" class="btn btn-ghost -ml-2" data-editor-leave><?= icon('arrow-left') ?>Files</a>
        <div class="min-w-0 flex-1">
            <?php if ($new): ?>
            <label for="fe-name" class="sr-only">File name</label>
            <div class="flex items-center gap-2">
                <span class="hidden truncate font-mono text-sm text-muted sm:inline">/var/www/<?= e($domain['domain_name']) ?><?= $dir !== '' ? '/' . e($dir) : '' ?>/</span>
                <input id="fe-name" name="name" value="<?= e($name) ?>" required class="input input-sm max-w-xs font-mono" placeholder="index.php" autocomplete="off" spellcheck="false" autofocus>
            </div>
            <?php else: ?>
            <input type="hidden" name="name" value="<?= e($name) ?>">
            <h1 class="truncate text-base font-semibold text-fg"><?= e($name) ?></h1>
            <p class="truncate font-mono text-xs text-muted" title="<?= e($path) ?>"><?= e($path) ?></p>
            <?php endif; ?>
        </div>
        <span class="hidden text-xs text-muted md:inline" data-editor-status aria-live="polite"><?= $new ? 'New file' : e(fmt_bytes($size) . ' · ' . strtoupper($eol) . ($mode !== '' ? ' · ' . $mode : '')) ?></span>
        <button type="submit" name="close" value="1" class="btn btn-secondary">Save &amp; close</button>
        <button type="submit" class="btn btn-primary"><?= icon('save') ?>Save <kbd class="kbd ml-1 hidden border-white/30 bg-white/10 text-white/80 sm:inline-flex">Ctrl S</kbd></button>
    </div>

    <?php if (!empty($error)): ?>
    <?= Ui::alert('Not saved: ' . $error, 'danger') ?>
    <?php endif; ?>

    <div class="card flex min-h-0 flex-1 overflow-hidden">
        <pre class="hidden select-none overflow-hidden border-r border-line bg-surface-2 px-3 py-3 text-right font-mono text-xs leading-5 text-subtle sm:block" aria-hidden="true" data-gutter><?= e(implode("\n", range(1, max(1, $lines)))) ?></pre>
        <label for="fe-content" class="sr-only">Contents of <?= e($name !== '' ? $name : 'the new file') ?></label>
        <textarea id="fe-content" name="content" class="min-h-0 flex-1 resize-none bg-surface px-3 py-3 font-mono text-xs leading-5 text-fg focus:outline-none" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" data-editor-text><?= "\n" . e($content) /* the parser drops a newline right after <textarea>: keep the file's own first line */ ?></textarea>
    </div>
    <p class="text-xs text-muted">Tab indents, Shift+Tab outdents. The file keeps its line endings and permissions. Saved changes are live on the site right away.</p>
</form>
