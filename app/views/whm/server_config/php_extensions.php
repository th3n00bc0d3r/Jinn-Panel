<?php
/** @var array{updated:?int, installed:list<string>, available:list<string>} $list */
/** @var string|null $last */
/** @var bool $pending */
$failed = $last !== null && str_contains($last, 'FAILED');
$btn = 'text-xs font-medium rounded-md px-2.5 py-1 transition-colors';
$row = function (string $ext, bool $installed) use ($btn): string {
    $desc = PhpExtensionService::DESCRIPTIONS[$ext] ?? '';
    $required = in_array($ext, PhpExtensionService::REQUIRED, true);
    $form = $installed && $required
        ? '<span class="text-xs text-slate-400">required</span>'
        : '<form method="post" action="/whm/server-config/php-extensions"' . ($installed ? ' data-confirm="Remove php-zts-' . e($ext) . '? Sites using it will break. The web server restarts."' : '') . '>'
            . Csrf::field() . '<input type="hidden" name="ext" value="' . e($ext) . '">'
            . '<button name="op" value="' . ($installed ? 'remove' : 'install') . '" class="' . $btn . ' ' . ($installed ? 'text-red-700 hover:bg-red-50' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100') . '">' . ($installed ? 'Remove' : 'Install') . '</button></form>';
    return '<li class="flex items-center gap-3 px-5 py-2"><span class="font-mono text-sm text-slate-800 w-40">' . e($ext) . '</span><span class="text-xs text-slate-500 flex-1">' . e($desc) . '</span>' . $form . '</li>';
};
?>
<div class="space-y-4">
    <div class="flex flex-wrap items-start gap-3">
        <p class="text-sm text-slate-500 max-w-3xl">
            Extensions for the default PHP (<?= e(PHP_VERSION) ?>). They're loaded by the web server for every site on the default version - PHP can't enable an
            extension for one site only. Installing or removing one restarts the web server (a second or two). Alternative PHP versions have their own.
        </p>
        <form method="post" action="/whm/server-config/php-extensions" class="ml-auto">
            <?= Csrf::field() ?>
            <button name="op" value="refresh" class="text-sm font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-lg px-3 py-2">Refresh list</button>
        </form>
    </div>
    <?php if ($pending): ?>
    <div class="rounded-lg border border-sky-200 bg-sky-50 text-sky-800 text-sm px-4 py-3">Working... <script>setTimeout(function () { location.reload(); }, 5000);</script></div>
    <?php elseif ($last !== null): ?>
    <div class="rounded-lg border px-4 py-2.5 text-xs font-mono <?= $failed ? 'border-red-200 bg-red-50 text-red-700' : 'border-slate-200 bg-slate-50 text-slate-500' ?>">Last change: <?= e($last) ?></div>
    <?php endif; ?>
    <?php if ($list['updated'] === null): ?>
    <p class="text-sm text-slate-400">Loading the package list (reload in a few seconds)...</p>
    <?php else: ?>
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <h2 class="px-5 py-3 border-b border-slate-100 text-sm font-semibold text-slate-800">Installed (<?= count($list['installed']) ?>)</h2>
            <ul class="divide-y divide-slate-100"><?php foreach ($list['installed'] as $x) echo $row($x, true); ?></ul>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <h2 class="px-5 py-3 border-b border-slate-100 text-sm font-semibold text-slate-800">Available (<?= count($list['available']) ?>)</h2>
            <ul class="divide-y divide-slate-100 max-h-[40rem] overflow-y-auto"><?php foreach ($list['available'] as $x) echo $row($x, false); ?></ul>
        </div>
    </div>
    <p class="text-xs text-slate-400">List from <?= e(date('Y-m-d H:i', (int) $list['updated'])) ?>.</p>
    <?php endif; ?>
</div>
