<?php
/** @var array{updated:?int, installed:list<string>, available:list<string>} $list */
/** @var string|null $last last line of the last extension job: "[time] OK" or "[time] FAILED: ..." */
/** @var bool $pending a change or a list refresh is waiting for the worker */
$failed = $last !== null && str_contains($last, 'FAILED');
$lastAt = null;
$lastMsg = '';
if ($last !== null && preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $last, $m)) {
    $lastAt = strtotime($m[1]) ?: null;
    $lastMsg = (string) preg_replace('/^FAILED:\s*/', '', trim($m[2]));
}

View::slot('desc', e('Extensions for the default PHP (' . PHP_VERSION . '). They load for every site on the default version - PHP can\'t turn one on for a single site. Installing or removing one restarts the web server (a second or two). Other PHP versions bring their own.'));
View::slot('actions', '<form method="post" action="/whm/server-config/php-extensions">' . Csrf::field()
    . '<button type="submit" name="op" value="refresh" class="btn btn-secondary">' . icon('refresh') . 'Refresh list</button></form>');

$row = function (string $ext, bool $installed): string {
    $desc = PhpExtensionService::DESCRIPTIONS[$ext] ?? '';
    if ($installed && in_array($ext, PhpExtensionService::REQUIRED, true)) {
        $action = '<span title="Needed by the panel or by most sites, so it can\'t be removed here">' . Ui::badge('Required') . '</span>';
    } else {
        $confirm = $installed
            ? ' data-confirm="Remove php-zts-' . e($ext) . '? Sites using it will break. The web server restarts." data-confirm-title="Remove ' . e($ext) . '?" data-confirm-action="Remove" data-confirm-danger'
            : ' data-confirm="Install php-zts-' . e($ext) . '? The web server restarts when it\'s done (a second or two)." data-confirm-title="Install ' . e($ext) . '?" data-confirm-action="Install"';
        $action = '<form method="post" action="/whm/server-config/php-extensions"' . $confirm . '>' . Csrf::field()
            . '<input type="hidden" name="ext" value="' . e($ext) . '">'
            . ($installed
                ? '<button type="submit" name="op" value="remove" class="btn btn-danger-ghost btn-sm" aria-label="Remove ' . e($ext) . '">Remove</button>'
                : '<button type="submit" name="op" value="install" class="btn btn-secondary btn-sm" aria-label="Install ' . e($ext) . '">' . icon('download') . 'Install</button>')
            . '</form>';
    }
    return '<tr><td class="min-w-0"><p class="font-mono text-sm text-fg">' . e($ext) . '</p>'
        . ($desc !== '' ? '<p class="mt-0.5 text-xs text-muted">' . e($desc) . '</p>' : '')
        . '</td><td class="w-px whitespace-nowrap text-right">' . $action . '</td></tr>';
};
$tables = [
    ['ext-installed', 'Installed', $list['installed'], true],
    ['ext-available', 'Available', $list['available'], false],
];
?>
<div class="stack">
    <?php if ($pending): ?>
    <?= Ui::busy($list['updated'] === null ? 'Fetching the list of extensions from the package repositories…' : 'Applying a change to the extensions (or refreshing the list)…') ?>
    <?php elseif ($failed): ?>
    <?= Ui::alert('<p class="font-medium">The last change didn\'t apply' . ($lastAt !== null ? ' (' . e(fmt_ago($lastAt)) . ')' : '') . '.</p><p class="mt-1 break-words font-mono text-xs">' . e($lastMsg !== '' ? $lastMsg : $last) . '</p>', 'danger', true) ?>
    <?php endif; ?>

    <?php if ($list['updated'] === null): ?>
    <div class="card">
        <?= $pending
            ? Ui::empty('Loading the list of extensions', 'The server is asking the package manager which extensions exist. This takes a few seconds.', 'layers')
            : Ui::empty('No list of extensions yet', 'Refresh the list to ask the package manager which extensions exist. If it stays empty, check that the panel\'s worker is running.', 'layers') ?>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
        <?php foreach ($tables as [$tid, $heading, $names, $isInstalled]): ?>
        <section class="card" aria-labelledby="<?= e($tid) ?>-title">
            <div class="card-header">
                <h2 id="<?= e($tid) ?>-title" class="card-title"><?= e($heading) ?> <span class="font-normal text-muted">(<?= count($names) ?>)</span></h2>
                <?php if (count($names) > 8): ?>
                <input type="search" class="input input-sm w-full sm:w-44" placeholder="Filter…" aria-label="Filter <?= e(strtolower($heading)) ?> extensions" data-filter="#<?= e($tid) ?>">
                <?php endif; ?>
            </div>
            <div class="table-wrap lg:max-h-[36rem] lg:overflow-y-auto">
                <table class="table table-tight" id="<?= e($tid) ?>">
                    <thead>
                        <tr>
                            <th scope="col" class="sticky top-0 z-10">Extension</th>
                            <th scope="col" class="sticky top-0 z-10 text-right"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($names as $x) { echo $row((string) $x, $isInstalled); } ?>
                        <?php if (!$names): ?>
                        <?= $isInstalled
                            ? Ui::emptyRow(2, 'None installed', 'Install extensions from the list of available ones.', 'layers')
                            : Ui::emptyRow(2, 'Nothing else to install', 'Every php-zts extension in the package repositories is installed.', 'check') ?>
                        <?php else: ?>
                        <tr data-filter-none data-filter-skip hidden><td colspan="2" class="py-8 text-center text-sm text-muted">No extension matches that filter.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer justify-start text-xs text-muted">
                <?php if ($isInstalled): ?>
                <span><?= $lastAt !== null && !$pending && !$failed ? e('Last change finished ' . fmt_ago($lastAt) . '. ') : '' ?>"Required" ones are needed by the panel or most sites.</span>
                <?php else: ?>
                <span title="<?= e(date('Y-m-d H:i', (int) $list['updated'])) ?>">List from <?= e(fmt_ago((int) $list['updated'])) ?>. Refresh it to see newly published packages.</span>
                <?php endif; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
