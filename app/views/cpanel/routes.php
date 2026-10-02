<?php
/** @var array<string,mixed> $d */
/** @var array{files:array<string,string>, generated:array, current:array{route:?string,site:?string}} $o */
/** @var string|null $last */
/** @var bool $pending */
$card = 'bg-white rounded-xl border border-slate-200 shadow-sm';
$btn = 'inline-flex items-center gap-2 rounded-lg text-sm font-medium px-4 py-2 transition-colors';
$code = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono leading-relaxed text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500';
$id = (int) $d['id'];
$g = $o['generated'];
$notes = array_values(array_filter($g['notes'] ?? [], fn($n) => ($n['status'] ?? '') !== 'translated'));
$active = $o['current']['route'] !== null || $o['current']['site'] !== null;
$failed = $last !== null && str_starts_with($last, 'FAILED');
$sync = $d['routes_sync'] === null ? null : (int) $d['routes_sync'] === 1;
?>
<div class="space-y-4">
    <div>
        <a href="/cpanel/domains/<?= $id ?>" class="text-sm text-slate-500 hover:text-sky-700">&larr; <?= e($d['domain_name']) ?></a>
        <h2 class="text-lg font-semibold text-slate-800 mt-1">Routes</h2>
        <p class="text-sm text-slate-500 max-w-3xl">
            This server doesn't read Apache <span class="font-mono">.htaccess</span> files. Their rewrites, redirects, access rules, error pages and headers
            are translated into rules the web server runs. Without rules, a site gets the default: existing files are served and everything else goes to <span class="font-mono">index.php</span>.
        </p>
    </div>

    <?php if ($pending): ?>
    <div class="rounded-lg border border-sky-200 bg-sky-50 text-sky-800 text-sm px-4 py-3">Applying your change... <script>setTimeout(function () { location.reload(); }, 4000);</script></div>
    <?php elseif ($last !== null): ?>
    <div class="rounded-lg border px-4 py-3 text-sm <?= $failed ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
        Last change: <?= e($failed ? $last : 'applied.') ?>
    </div>
    <?php endif; ?>

    <div class="<?= $card ?> p-5">
        <div class="flex flex-wrap items-center gap-3">
            <h3 class="text-sm font-semibold text-slate-700">In use</h3>
            <span class="text-xs font-medium rounded-full px-2 py-0.5 <?= $active ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-600' ?>"><?= $active ? 'Custom rules' : 'Default routing' ?></span>
            <?php if (!empty($d['routes_review'])): ?><span class="text-xs font-medium rounded-full px-2 py-0.5 bg-amber-50 text-amber-700">Needs review</span><?php endif; ?>
        </div>
        <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 flex flex-wrap items-start gap-3">
            <div class="flex-1 min-w-[16rem] text-sm">
                <p class="font-medium text-slate-700">
                    Follow .htaccess:
                    <span class="text-xs font-medium rounded-full px-2 py-0.5 <?= $sync ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-200 text-slate-600' ?>"><?= $sync === null ? 'checking...' : ($sync ? 'on' : 'off') ?></span>
                </p>
                <p class="text-xs text-slate-500 mt-1">
                    <?php if ($sync): ?>
                    When a .htaccess file changes (File Manager, SFTP, or an app such as WordPress writing it), its translation goes live within a minute.
                    If part of it can't be translated exactly, the rules in use stay and this page says what to review. Editing the rules below turns this off.
                    <?php elseif ($sync === false): ?>
                    Changes to .htaccess files are not applied here until you choose "Use these rules" or turn this on.
                    <?php else: ?>
                    Checked within a minute: on when the rules in use are already the translation of the site's .htaccess.
                    <?php endif; ?>
                </p>
                <?php if (!empty($d['routes_sync_note'])): ?>
                <p class="text-xs text-slate-600 mt-1"><?= e((string) $d['routes_sync_note']) ?>
                    <?php if (!empty($d['routes_synced_at'])): ?><span class="text-slate-400">(<?= e((string) $d['routes_synced_at']) ?>)</span><?php endif; ?></p>
                <?php endif; ?>
            </div>
            <form method="post" action="/cpanel/domains/<?= $id ?>/routes">
                <?= Csrf::field() ?>
                <?php if ($sync): ?>
                <button name="action" value="sync_off" class="<?= $btn ?> bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200">Stop following</button>
                <?php else: ?>
                <button name="action" value="sync_on" class="<?= $btn ?> bg-slate-800 hover:bg-slate-700 text-white" data-confirm="Replace the rules in use with the translation of .htaccess, now and whenever it changes? (Anything that can't be translated exactly is never applied automatically.)">Follow .htaccess</button>
                <?php endif; ?>
            </form>
        </div>
        <form method="post" action="/cpanel/domains/<?= $id ?>/routes" class="mt-3 space-y-3">
            <?= Csrf::field() ?>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Routing rules <span class="text-slate-400 font-normal">(run in order for every request; must end with php_server)</span></label>
                <textarea name="route" rows="14" spellcheck="false" class="<?= $code ?>"><?= e($o['current']['route'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Site rules <span class="text-slate-400 font-normal">(headers and error pages)</span></label>
                <textarea name="site" rows="6" spellcheck="false" class="<?= $code ?>"><?= e($o['current']['site'] ?? '') ?></textarea>
            </div>
            <div class="flex flex-wrap gap-2">
                <button name="action" value="save" class="<?= $btn ?> bg-sky-600 hover:bg-sky-500 text-white">Save my edits</button>
                <?php if ($active): ?>
                <button name="action" value="reset" class="<?= $btn ?> bg-slate-100 hover:bg-slate-200 text-slate-700" data-confirm="Remove the custom rules and go back to the default routing?">Reset to default</button>
                <?php endif; ?>
            </div>
            <p class="text-xs text-slate-400">Rules are checked before they go live; if the web server rejects them, the previous rules stay. Only routing directives are allowed (no file paths outside the site, no proxying).</p>
        </form>
    </div>

    <div class="<?= $card ?> p-5">
        <div class="flex flex-wrap items-center gap-3">
            <h3 class="text-sm font-semibold text-slate-700">Translated from .htaccess</h3>
            <span class="text-xs text-slate-400"><?= count($o['files']) ?> file<?= count($o['files']) === 1 ? '' : 's' ?> found</span>
            <?php if ($o['files']): ?>
            <form method="post" action="/cpanel/domains/<?= $id ?>/routes" class="ml-auto">
                <?= Csrf::field() ?>
                <button name="action" value="generated" class="<?= $btn ?> bg-slate-800 hover:bg-slate-700 text-white" <?= $active ? 'data-confirm="Replace the rules in use with the ones translated from .htaccess?"' : '' ?>>Use these rules</button>
            </form>
            <?php endif; ?>
        </div>
        <?php if (!$o['files']): ?>
        <p class="text-sm text-slate-400 mt-2">No .htaccess files in the site's document root.</p>
        <?php else: ?>
        <?php if ($notes): ?>
        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="text-xs font-semibold text-amber-900 mb-1"><?= count($notes) ?> thing<?= count($notes) === 1 ? '' : 's' ?> to look at</p>
            <ul class="space-y-1 text-xs text-amber-900">
                <?php foreach ($notes as $n): ?>
                <li><span class="font-mono"><?= e(($n['file'] !== '' ? $n['file'] . '/' : '') . '.htaccess') ?>:<?= (int) $n['line'] ?></span>
                    <span class="font-medium <?= $n['status'] === 'unsupported' ? 'text-red-700' : 'text-amber-800' ?>"><?= e($n['status']) ?></span>
                    <span class="font-mono text-amber-700"><?= e(mb_strimwidth((string) $n['directive'], 0, 90, '...')) ?></span> - <?= e((string) $n['message']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <div class="mt-3 grid gap-3 lg:grid-cols-2">
            <div>
                <p class="text-xs font-medium text-slate-600 mb-1">.htaccess</p>
                <?php foreach ($o['files'] as $rel => $text): ?>
                <p class="text-xs font-mono text-slate-400 mt-2"><?= e(($rel !== '' ? $rel . '/' : '') . '.htaccess') ?></p>
                <pre class="text-xs font-mono bg-slate-50 rounded-lg p-3 overflow-x-auto max-h-80 border border-slate-100"><?= e($text) ?></pre>
                <?php endforeach; ?>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-600 mb-1">Generated rules</p>
                <pre class="text-xs font-mono bg-slate-50 rounded-lg p-3 overflow-x-auto max-h-[32rem] border border-slate-100"><?= e((string) $g['route']) ?></pre>
                <?php if (trim((string) $g['site']) !== ''): ?>
                <p class="text-xs font-medium text-slate-600 mt-3 mb-1">Generated site rules</p>
                <pre class="text-xs font-mono bg-slate-50 rounded-lg p-3 overflow-x-auto border border-slate-100"><?= e((string) $g['site']) ?></pre>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
