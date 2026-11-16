<?php
/** @var array<string,mixed> $d */
/** @var array{files:array<string,string>, generated:array, current:array{route:?string,site:?string}} $o */
/** @var string|null $last */
/** @var bool $pending */
$tab = 'routes';
include __DIR__ . '/../partials/domain_tabs.php';
View::slot('desc', e('How requests to this site are handled. This server doesn\'t read Apache .htaccess files: their rewrites, redirects, access rules, error pages and headers are translated into rules the web server runs.'));
View::script('routes');

$id = (int) $d['id'];
$g = $o['generated'];
$notes = array_values(array_filter($g['notes'] ?? [], fn($n) => ($n['status'] ?? '') !== 'translated'));
$active = $o['current']['route'] !== null || $o['current']['site'] !== null;
$sync = $d['routes_sync'] === null ? null : (int) $d['routes_sync'] === 1;
$review = !empty($d['routes_review']);
// The worker's last line for this domain: "[2026-11-13T09:12:00+00:00] OK" or "[...] FAILED: reason".
$lastAt = null;
$lastMsg = $last;
if ($last !== null && preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $last, $m)) {
    [$lastAt, $lastMsg] = [$m[1], $m[2]];
}
$failed = $lastMsg !== null && str_starts_with($lastMsg, 'FAILED');
$htaccess = fn(string $rel): string => ($rel !== '' ? $rel . '/' : '') . '.htaccess';
$code = 'max-h-96 overflow-auto bg-surface p-3 font-mono text-xs leading-relaxed text-fg';
$noAuto = ['wrap' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off', 'autocomplete' => 'off'];
?>
<div class="stack">
    <?php if ($pending): ?>
    <?= Ui::busy('Applying your change: the web server checks the rules first, then loads them.') ?>
    <?php elseif ($failed): ?>
    <?= Ui::alert(
        '<p class="font-medium">The last change was not applied' . ($lastAt ? ' (' . e(fmt_ago($lastAt)) . ')' : '') . '.</p>'
        . '<p class="mt-1 break-words font-mono text-xs">' . e((string) preg_replace('/^FAILED:\s*/', '', (string) $lastMsg)) . '</p>'
        . '<p class="mt-1">The rules from before it are still in use.</p>',
        'danger',
        true
    ) ?>
    <?php endif; ?>
    <?php if ($review && !$pending): ?>
    <?= Ui::alert('Part of the site\'s .htaccess can\'t be translated exactly, so it wasn\'t applied by itself. Check the notes under <a href="#translation" class="font-medium underline underline-offset-2">Translated from .htaccess</a>, then use the translation if it is right or adjust the rules yourself.', 'warning', true) ?>
    <?php endif; ?>

    <section class="card" aria-label="Routing status">
        <div class="grid grid-cols-1 divide-y divide-line md:grid-cols-2 md:divide-x md:divide-y-0">
            <div class="p-5">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="card-title">Rules in use</h2>
                    <?= $active ? Ui::badge('Custom rules', 'accent') : Ui::badge('Default routing', 'neutral') ?>
                    <?= $review ? Ui::badge('Needs review', 'warning', true) : '' ?>
                </div>
                <p class="mt-2 text-sm text-muted">
                    <?= $active
                        ? 'The rules below run for every request, in order.'
                        : 'No rules of its own: existing files are served and everything else goes to <span class="font-mono">index.php</span>, which suits most PHP apps.' ?>
                </p>
                <?php if ($last !== null && !$failed && !$pending): ?>
                <p class="mt-3 flex items-center gap-1.5 text-xs text-muted" title="<?= e($last) ?>"><?= icon('check-circle', 'h-3.5 w-3.5 text-success') ?>Last change applied<?= $lastAt ? ' ' . e(fmt_ago($lastAt)) : '' ?>.</p>
                <?php endif; ?>
            </div>
            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="card-title">Follow .htaccess</h2>
                        <?= $sync === null ? Ui::badge('Checking…', 'info', true) : ($sync ? Ui::badge('On', 'success', true) : Ui::badge('Off', 'neutral', true)) ?>
                    </div>
                    <p class="mt-2 text-sm text-muted">
                        <?php if ($sync): ?>
                        When a .htaccess file changes (File Manager, SFTP, or an app such as WordPress writing it), its translation goes live within a minute.
                        If part of it can't be translated exactly, the rules in use stay and this page says what to review. Editing the rules yourself turns this off.
                        <?php elseif ($sync === false): ?>
                        Changes to .htaccess files are not applied here until you choose "Use these rules" or turn this on.
                        <?php else: ?>
                        Checked within a minute: on when the rules in use are already the translation of the site's .htaccess.
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($d['routes_sync_note'])): ?>
                    <p class="mt-2 text-xs text-fg"><?= e((string) $d['routes_sync_note']) ?>
                        <?php if (!empty($d['routes_synced_at'])): ?><span class="text-muted" title="<?= e((string) $d['routes_synced_at']) ?>">(<?= e(fmt_ago((string) $d['routes_synced_at'])) ?>)</span><?php endif; ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($sync): ?>
                <form method="post" action="/cpanel/domains/<?= $id ?>/routes" class="shrink-0">
                    <?= Csrf::field() ?>
                    <button type="submit" name="action" value="sync_off" class="btn btn-secondary btn-sm">Stop following</button>
                </form>
                <?php else: ?>
                <form method="post" action="/cpanel/domains/<?= $id ?>/routes" class="shrink-0" data-confirm="Replace the rules in use with the translation of .htaccess, now and whenever it changes? Anything that can't be translated exactly is never applied automatically." data-confirm-title="Follow .htaccess?" data-confirm-action="Follow .htaccess">
                    <?= Csrf::field() ?>
                    <button type="submit" name="action" value="sync_on" class="btn btn-primary btn-sm"><?= icon('refresh') ?>Follow .htaccess</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="card scroll-mt-20" id="translation" aria-labelledby="translation-title">
        <div class="card-header">
            <div class="min-w-0">
                <h2 id="translation-title" class="card-title">Translated from .htaccess</h2>
                <p class="card-desc"><?= $o['files'] ? e(Ui::plural(count($o['files']), '.htaccess file') . ' in the document root, and the rules they translate to.') : 'Nothing to translate.' ?></p>
            </div>
            <?php if ($o['files']): ?>
            <form method="post" action="/cpanel/domains/<?= $id ?>/routes"<?= $active ? ' data-confirm="Replace the rules in use with the ones translated from .htaccess?" data-confirm-action="Use these rules"' : '' ?>>
                <?= Csrf::field() ?>
                <button type="submit" name="action" value="generated" class="btn btn-secondary btn-sm"><?= icon('check') ?>Use these rules</button>
            </form>
            <?php endif; ?>
        </div>
        <?php if (!$o['files']): ?>
        <?= Ui::empty('No .htaccess files', 'The site\'s document root has none, so the rules in use are all there is. Upload one, or write rules below.', 'file-text') ?>
        <?php else: ?>
        <div class="card-body space-y-6">
            <?php if ($notes): ?>
            <div>
                <h3 class="flex items-center gap-2 text-sm font-medium text-fg"><?= icon('alert', 'h-4 w-4 text-warning') ?><?= e(Ui::plural(count($notes), 'thing')) ?> to look at</h3>
                <ul class="mt-3 divide-y divide-line rounded-lg border border-line">
                    <?php foreach ($notes as $n): $where = $htaccess((string) $n['file']) . ':' . (int) $n['line']; ?>
                    <li class="flex flex-col gap-1.5 px-4 py-3 sm:flex-row sm:items-start sm:gap-4">
                        <div class="flex shrink-0 items-center gap-2 sm:w-60">
                            <?= match ($n['status']) {
                                'unsupported' => Ui::badge('Unsupported', 'danger'),
                                'ignored' => Ui::badge('Ignored', 'neutral'),
                                default => Ui::badge(ucfirst((string) $n['status']), 'warning'),
                            } ?>
                            <span class="min-w-0 truncate font-mono text-xs text-muted" title="<?= e($where) ?>"><?= e($where) ?></span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-mono text-xs text-fg" title="<?= e((string) $n['directive']) ?>"><?= e((string) $n['directive']) ?></p>
                            <p class="mt-0.5 text-sm text-muted"><?= e((string) $n['message']) ?></p>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="min-w-0 space-y-3">
                    <h3 class="section-title">Source</h3>
                    <?php foreach ($o['files'] as $rel => $text): ?>
                    <figure class="overflow-hidden rounded-lg border border-line">
                        <figcaption class="flex items-center gap-2 border-b border-line bg-surface-2 px-3 py-2">
                            <?= icon('file-text', 'h-3.5 w-3.5 shrink-0 text-subtle') ?>
                            <span class="min-w-0 truncate font-mono text-xs text-muted"><?= e($htaccess((string) $rel)) ?></span>
                        </figcaption>
                        <pre class="<?= $code ?>"><?= e($text) ?></pre>
                    </figure>
                    <?php endforeach; ?>
                </div>
                <div class="min-w-0 space-y-3">
                    <h3 class="section-title">Generated rules</h3>
                    <?php foreach (['route' => 'Routing rules', 'site' => 'Site rules (headers, error pages)'] as $key => $label):
                        $text = (string) ($g[$key] ?? '');
                        if ($key === 'site' && trim($text) === '') {
                            continue;
                        } ?>
                    <figure class="overflow-hidden rounded-lg border border-line">
                        <figcaption class="flex items-center justify-between gap-2 border-b border-line bg-surface-2 px-3 py-1">
                            <span class="flex min-w-0 items-center gap-2"><?= icon('code', 'h-3.5 w-3.5 shrink-0 text-subtle') ?><span class="truncate text-xs text-muted"><?= e($label) ?></span></span>
                            <?php if (trim($text) !== ''): ?><?= Ui::copy($text, 'Copy ' . strtolower($label)) ?><?php endif; ?>
                        </figcaption>
                        <?php if (trim($text) === ''): ?>
                        <p class="bg-surface p-3 text-xs text-muted">No routing rules needed: the default applies.</p>
                        <?php else: ?>
                        <pre class="<?= $code ?>"><?= e($text) ?></pre>
                        <?php endif; ?>
                    </figure>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <section class="card scroll-mt-20" id="rules" aria-labelledby="rules-title">
        <div class="card-header">
            <div class="min-w-0">
                <h2 id="rules-title" class="card-title">Edit the rules in use</h2>
                <p class="card-desc">Checked before they go live: if the web server rejects them, the previous rules stay.<?= $sync ? ' Saving edits of your own turns off following .htaccess, so they aren\'t replaced.' : '' ?></p>
            </div>
            <?php if ($active): ?>
            <form method="post" action="/cpanel/domains/<?= $id ?>/routes" data-confirm="Remove the custom rules and go back to the default routing?" data-confirm-title="Reset to default?" data-confirm-action="Reset to default" data-confirm-danger>
                <?= Csrf::field() ?>
                <button type="submit" name="action" value="reset" class="btn btn-danger-ghost btn-sm"><?= icon('refresh') ?>Reset to default</button>
            </form>
            <?php endif; ?>
        </div>
        <?php if (!Features::has('routes')): ?>
        <div class="card-body">
            <p class="flex items-start gap-2 text-sm text-muted"><?= icon('lock', 'mt-0.5 h-4 w-4 shrink-0') ?><span>Editing the rules by hand isn't in your package. Following .htaccess (above) keeps working, and you can reset to the default routing.</span></p>
            <?php if ($active): ?>
            <pre class="log mt-4" tabindex="0" aria-label="Routing rules in use"><?= e(trim(($o['current']['route'] ?? '') . "\n\n" . ($o['current']['site'] ?? ''))) ?></pre>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <form method="post" action="/cpanel/domains/<?= $id ?>/routes" data-routes-editor>
            <div class="card-body space-y-5">
                <?= Csrf::field() ?>
                <?= Ui::textarea('route', 'Routing rules', Ui::old('route') ?? ($o['current']['route'] ?? ''), [
                    'rows' => 14,
                    'mono' => true,
                    'class' => 'leading-relaxed',
                    'placeholder' => 'Empty: the default routing (existing files, then index.php)',
                    'hint' => 'Run in order for every request; must end with php_server.',
                    'attrs' => $noAuto,
                ]) ?>
                <?= Ui::textarea('site', 'Site rules', Ui::old('site') ?? ($o['current']['site'] ?? ''), [
                    'rows' => 6,
                    'mono' => true,
                    'class' => 'leading-relaxed',
                    'hint' => 'Headers and error pages.',
                    'attrs' => $noAuto,
                ]) ?>
            </div>
            <div class="card-footer justify-between">
                <p class="text-xs text-muted">Only routing directives are allowed: no file paths outside the site, no proxying.</p>
                <button type="submit" name="action" value="save" class="btn btn-primary"><?= icon('save') ?>Save my edits</button>
            </div>
        </form>
        <?php endif; ?>
    </section>
</div>
