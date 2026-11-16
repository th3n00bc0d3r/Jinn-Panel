<?php
/** @var array<string,mixed> $d */
/** @var array{state:string,label:string,detail:string} $ssl */
/** @var array{valid_to:int,issuer:string}|null $cert */
/** @var list<string> $addresses */
/** @var string $siteDir */
/** @var string $docroot */
/** @var bool $routes */
/** @var array<string,string> $php */
/** @var string $phpLog */
/** @var list<string> $aliases */
/** @var list<string> $phpVersions */
$tab = 'overview';
include __DIR__ . '/../partials/domain_tabs.php';
$name = (string) $d['domain_name'];
$id = (int) $d['id'];
$rel = str_starts_with($docroot, $siteDir . '/') ? substr($docroot, strlen($siteDir) + 1) : 'public';
$st = $ssl['state'];
$sslTone = $st === 'ok' ? 'success' : ($st === 'no_dns' ? 'danger' : 'warning');
$pointsHere = in_array(Config::SERVER_IP, $addresses, true) || (Config::SERVER_IPV6 !== '' && in_array(Config::SERVER_IPV6, $addresses, true));
$sslChoice = $d['ssl_mode'] === 'letsencrypt' ? 'letsencrypt' : ((int) $d['ssl_pinned'] === 1 ? 'self_signed' : 'auto');
$can = Features::current();
$phpOptions = ['default' => 'Default (PHP 8.5)'];
foreach ($can['php_versions'] ? ($phpVersions ?? []) : [] as $v) {
    $phpOptions[$v] = 'PHP ' . $v;
}
if (!isset($phpOptions[$d['php_version']])) {
    $phpOptions[$d['php_version']] = 'PHP ' . $d['php_version'];
}
?>
<div class="stack">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="stat">
            <div class="stat-icon"><?= icon('shield') ?></div>
            <div class="min-w-0">
                <p class="stat-label">Certificate</p>
                <p class="mt-1"><?= Ui::badge($ssl['label'], $sslTone, true) ?></p>
                <p class="mt-1 text-xs text-muted"><?= $cert ? e('Expires ' . gmdate('j M Y', $cert['valid_to'])) : e($ssl['detail']) ?></p>
            </div>
        </div>
        <div class="stat">
            <div class="stat-icon"><?= icon('globe') ?></div>
            <div class="min-w-0">
                <p class="stat-label">DNS</p>
                <p class="mt-1"><?= $addresses ? Ui::badge($pointsHere ? 'Points here' : 'Points elsewhere', $pointsHere ? 'success' : 'warning', true) : Ui::badge('Not resolving', 'danger', true) ?></p>
                <p class="mt-1 truncate font-mono text-xs text-muted" title="<?= e(implode(', ', $addresses)) ?>"><?= e($addresses ? implode(', ', $addresses) : 'No A/AAAA record found') ?></p>
            </div>
        </div>
        <div class="stat">
            <div class="stat-icon"><?= icon('code') ?></div>
            <div class="min-w-0">
                <p class="stat-label">PHP</p>
                <p class="stat-value text-lg"><?= e($d['php_version'] === 'default' ? 'Default (8.5)' : 'PHP ' . $d['php_version']) ?></p>
                <p class="mt-0.5 text-xs text-muted"><?= $routes ? 'Custom routing rules' : 'Default routing' ?><?= !empty($d['routes_review']) ? ' · needs review' : '' ?></p>
            </div>
        </div>
    </div>

    <?php if (!$pointsHere): ?>
    <?= Ui::alert('<strong>Point the domain at this server</strong> so the site and its certificate work: at your registrar, set an A record for <code>' . e($name) . '</code> and <code>www.' . e($name) . '</code> to <code>' . e(Config::SERVER_IP) . '</code>' . (Config::SERVER_IPV6 !== '' ? ' (and AAAA to <code>' . e(Config::SERVER_IPV6) . '</code>)' : '') . ', or use this server\'s nameservers. Then run AutoSSL below.', 'warning', true) ?>
    <?php endif; ?>

    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
        <form method="post" action="/cpanel/domains/<?= $id ?>/settings" class="card">
            <?= Csrf::field() ?>
            <input type="hidden" name="back" value="domain">
            <?= Ui::cardHeader('PHP version and certificate') ?>
            <div class="card-body space-y-4">
                <?= Ui::select('php_version', 'PHP version', $phpOptions, (string) $d['php_version'], ['hint' => $can['php_versions'] ? 'The site switches within a few seconds. Check it afterwards: older code may not run on newer PHP.' : 'Your package runs sites on the default PHP version.']) ?>
                <?= Ui::select('ssl_mode', 'Certificate', [
                    'auto' => 'Automatic (recommended)',
                    'letsencrypt' => 'Let\'s Encrypt only',
                    'self_signed' => 'Self-signed only',
                ], $sslChoice, ['hint' => 'Automatic: Let\'s Encrypt once the domain points here, self-signed until then.']) ?>
            </div>
            <div class="card-footer"><button class="btn btn-primary"><?= icon('save') ?>Save</button></div>
        </form>

        <section class="card">
            <?= Ui::cardHeader('SSL certificate', $ssl['detail']) ?>
            <div class="card-body">
                <dl class="dl">
                    <div><dt>Status</dt><dd><?= Ui::badge($ssl['label'], $sslTone, true) ?></dd></div>
                    <?php if ($cert): ?>
                    <div><dt>Issuer</dt><dd><?= e($cert['issuer'] ?: 'unknown') ?></dd></div>
                    <div><dt>Expires</dt><dd><?= e(gmdate('j M Y', $cert['valid_to'])) ?> <span class="text-muted">· renewed about 30 days before</span></dd></div>
                    <?php endif; ?>
                    <div><dt>Covers</dt><dd><code><?= e($name) ?></code>, <code>www.<?= e($name) ?></code></dd></div>
                </dl>
            </div>
            <form method="post" action="/cpanel/domains/<?= $id ?>/autossl" class="card-footer justify-between">
                <?= Csrf::field() ?>
                <span class="text-xs text-muted">Checks DNS and asks for a Let's Encrypt certificate now.</span>
                <button class="btn btn-secondary"><?= icon('refresh') ?>Run AutoSSL</button>
            </form>
        </section>

        <form method="post" action="/cpanel/domains/<?= $id ?>/docroot" class="card">
            <?= Csrf::field() ?>
            <?= Ui::cardHeader('Document root', 'The folder the website is served from. Laravel and similar frameworks serve from their public/ folder.') ?>
            <div class="card-body">
                <?= Ui::field('docroot', 'Folder', ['value' => $rel, 'required' => true, 'mono' => true, 'addon' => $siteDir . '/', 'hint' => 'Create the folder in the File Manager first.']) ?>
            </div>
            <div class="card-footer"><button class="btn btn-primary"><?= icon('save') ?>Save</button></div>
        </form>

        <section class="card">
            <?= Ui::cardHeader('Aliases', 'Other domain names that show this same site (cPanel\'s parked domains), each with its www.') ?>
            <div class="card-body space-y-4">
                <?php if ($aliases): ?>
                <ul class="flex flex-wrap gap-2">
                    <?php foreach ($aliases as $al): ?>
                    <li class="inline-flex items-center gap-1 rounded-full bg-surface-3 py-0.5 pl-3 pr-0.5 text-sm">
                        <span class="font-mono text-xs"><?= e($al) ?></span>
                        <form method="post" action="/cpanel/domains/<?= $id ?>/aliases/delete" data-confirm="Stop serving this site on <?= e($al) ?>? Its DNS zone here is removed too." data-confirm-action="Remove alias" data-confirm-danger>
                            <?= Csrf::field() ?>
                            <input type="hidden" name="alias" value="<?= e($al) ?>">
                            <button class="rounded-full p-1 text-subtle hover:bg-danger-soft hover:text-danger-text" aria-label="Remove <?= e($al) ?>" title="Remove"><?= icon('close', 'h-3.5 w-3.5') ?></button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="text-sm text-muted">No aliases.</p>
                <?php endif; ?>
                <?php if ($can['aliases']): ?>
                <form method="post" action="/cpanel/domains/<?= $id ?>/aliases" class="flex items-end gap-2">
                    <?= Csrf::field() ?>
                    <?= Ui::field('alias', 'Add an alias', ['required' => true, 'placeholder' => 'example.net', 'wrap' => 'flex-1', 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
                    <button class="btn btn-secondary"><?= icon('plus') ?>Add</button>
                </form>
                <?php else: ?>
                <p class="flex items-center gap-2 text-sm text-muted"><?= icon('lock', 'h-4 w-4') ?>Adding aliases isn't in your package.</p>
                <?php endif; ?>
            </div>
        </section>

        <form method="post" action="/cpanel/domains/<?= $id ?>/php" class="card lg:col-span-2">
            <?= Csrf::field() ?>
            <?= Ui::cardHeader('PHP settings', 'Leave a field empty for the server default. Upload and post size limits are server-wide.' . ($d['php_version'] !== 'default' ? ' These apply to sites on the default PHP version only.' : '')) ?>
            <div class="card-body grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <?php foreach (PhpSettingsService::SETTINGS as $key => [$kind, $lbl]): ?>
                <?php if ($kind === 'bool'): ?>
                <?= Ui::select($key, $lbl, ['' => 'Default', 'On' => 'On', 'Off' => 'Off'], (string) ($php[$key] ?? '')) ?>
                <?php else: ?>
                <?= Ui::field($key, $lbl, ['value' => (string) ($php[$key] ?? ''), 'placeholder' => (string) ini_get($key), 'mono' => true]) ?>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="card-footer justify-between">
                <span class="min-w-0 truncate text-xs text-muted">Errors are logged to <code><?= e($phpLog) ?></code></span>
                <button class="btn btn-primary"><?= icon('save') ?>Save</button>
            </div>
        </form>

        <section class="card">
            <?= Ui::cardHeader('Cache', 'Page cache: ' . (!empty($d['page_cache_ttl']) ? 'on, ' . (int) $d['page_cache_ttl'] . ' s' : 'off') . '.') ?>
            <div class="card-body text-sm text-muted">Clears the site's cached PHP code, cached pages and object cache keys. Use it after uploading changed files if the old version still shows.</div>
            <div class="card-footer justify-between">
                <a href="/cpanel/cache" class="link text-sm">Cache settings</a>
                <form method="post" action="/cpanel/domains/<?= $id ?>/clear-cache">
                    <?= Csrf::field() ?>
                    <button class="btn btn-secondary"><?= icon('refresh') ?>Clear cache</button>
                </form>
            </div>
        </section>

        <section class="card">
            <?= Ui::cardHeader('Access') ?>
            <dl class="dl card-body">
                <div><dt>Website</dt><dd><a class="link" href="https://<?= e($name) ?>/" target="_blank" rel="noopener">https://<?= e($name) ?></a></dd></div>
                <div><dt>Control panel</dt><dd><?= Ui::copyable($name . '/jpanel') ?><p class="mt-1 text-xs text-muted">Share it with whoever manages the site; it opens this panel on the domain's own address.</p></dd></div>
                <div><dt>Files</dt><dd><code><?= e($siteDir) ?></code></dd></div>
            </dl>
        </section>

        <section class="card border-danger/30 lg:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-4 p-5">
                <div class="min-w-0 text-sm">
                    <p class="font-medium text-danger-text">Remove this domain</p>
                    <p class="text-muted">Its website, DNS zone and aliases stop. Files on disk are kept.</p>
                </div>
                <form method="post" action="/cpanel/domains/<?= $id ?>/delete" data-confirm="Remove <?= e($name) ?>? The site goes offline and its DNS zone is deleted. Files on disk are kept." data-confirm-action="Remove domain" data-confirm-danger>
                    <?= Csrf::field() ?>
                    <button class="btn btn-danger"><?= icon('trash') ?>Remove domain</button>
                </form>
            </div>
        </section>
    </div>
</div>
