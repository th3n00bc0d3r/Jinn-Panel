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
$card = 'bg-white rounded-xl border border-slate-200 shadow-sm p-5';
$btn = 'inline-flex items-center gap-2 rounded-lg text-sm font-medium px-4 py-2 transition-colors';
$input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500';
$name = (string) $d['domain_name'];
$id = (int) $d['id'];
$rel = str_starts_with($docroot, $siteDir . '/') ? substr($docroot, strlen($siteDir) + 1) : 'public';
$st = $ssl['state'];
?>
<div class="space-y-4">
    <div>
        <a href="/cpanel/domains" class="text-sm text-slate-500 hover:text-sky-700">&larr; All domains</a>
        <div class="mt-1 flex flex-wrap items-center gap-3">
            <h2 class="text-lg font-semibold text-slate-800"><?= e($name) ?></h2>
            <a href="https://<?= e($name) ?>/" target="_blank" rel="noopener" class="text-sm text-sky-700 hover:underline">Open site &rarr;</a>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="<?= $card ?>">
            <h3 class="text-sm font-semibold text-slate-700 mb-3">Overview</h3>
            <dl class="text-sm space-y-2">
                <div class="flex gap-3"><dt class="w-36 shrink-0 text-slate-400">Website</dt><dd><a class="text-sky-700 hover:underline" href="https://<?= e($name) ?>/" target="_blank" rel="noopener">https://<?= e($name) ?></a> and www.<?= e($name) ?></dd></div>
                <div class="flex gap-3"><dt class="w-36 shrink-0 text-slate-400">Control panel</dt><dd><a class="text-sky-700 hover:underline" href="https://<?= e($name) ?>/jpanel" target="_blank" rel="noopener"><?= e($name) ?>/jpanel</a> <span class="block text-xs text-slate-400">Opens this panel at https://<?= e($name) ?>:2083 - share it with whoever manages the site.</span></dd></div>
                <div class="flex gap-3"><dt class="w-36 shrink-0 text-slate-400">PHP</dt><dd class="text-slate-700"><?= e($d['php_version'] === 'default' ? 'Default (8.5)' : 'PHP ' . $d['php_version']) ?></dd></div>
                <div class="flex gap-3"><dt class="w-36 shrink-0 text-slate-400">DNS points to</dt><dd class="font-mono text-slate-700"><?= e($addresses ? implode(', ', $addresses) : 'nothing') ?></dd></div>
                <div class="flex gap-3"><dt class="w-36 shrink-0 text-slate-400">Routes</dt><dd class="text-slate-700">
                    <?= $routes ? 'Custom rules' : 'Default routing' ?><?= isset($d['routes_sync']) && (int) $d['routes_sync'] === 1 ? ', follows .htaccess' : '' ?><?= !empty($d['routes_review']) ? ' <span class="text-amber-700">(needs review)</span>' : '' ?>
                    &middot; <a href="/cpanel/domains/<?= $id ?>/routes" class="text-sky-700 hover:underline">Routes &amp; .htaccess</a></dd></div>
                <div class="flex gap-3"><dt class="w-36 shrink-0 text-slate-400">Security</dt><dd><a href="/cpanel/domains/<?= $id ?>/exposed" class="text-sky-700 hover:underline">Check for exposed files</a> <span class="text-xs text-slate-400">(archives, database dumps, backups, logs anyone can download)</span></dd></div>
            </dl>
        </div>

        <div class="<?= $card ?>">
            <h3 class="text-sm font-semibold text-slate-700 mb-3">SSL certificate</h3>
            <p class="text-sm font-medium <?= $st === 'ok' ? 'text-emerald-700' : ($st === 'no_dns' ? 'text-red-700' : 'text-amber-700') ?>"><?= e($ssl['label']) ?></p>
            <p class="text-sm text-slate-500 mt-1"><?= e($ssl['detail']) ?></p>
            <?php if ($cert): ?>
            <p class="text-xs text-slate-400 mt-1">Issuer: <?= e($cert['issuer'] ?: 'unknown') ?> &middot; expires <?= e(gmdate('j M Y', $cert['valid_to'])) ?> (renewed automatically about 30 days before)</p>
            <?php endif; ?>
            <form method="post" action="/cpanel/domains/<?= $id ?>/autossl" class="mt-4">
                <?= Csrf::field() ?>
                <button class="<?= $btn ?> bg-sky-600 hover:bg-sky-500 text-white"><?= icon('shield', 'h-4 w-4') ?> Run AutoSSL</button>
                <span class="block text-xs text-slate-400 mt-1.5">Checks DNS and requests a Let's Encrypt certificate now - after fixing DNS, or if the certificate expired or is stuck.</span>
            </form>
        </div>

        <div class="<?= $card ?>">
            <h3 class="text-sm font-semibold text-slate-700 mb-1">Document root</h3>
            <p class="text-xs text-slate-400 mb-3">The folder the website is served from. Frameworks like Laravel serve from their <span class="font-mono">public/</span> folder.</p>
            <form method="post" action="/cpanel/domains/<?= $id ?>/docroot" class="flex items-stretch gap-2">
                <?= Csrf::field() ?>
                <span class="flex items-center rounded-lg bg-slate-50 border border-slate-200 px-2 text-xs font-mono text-slate-500"><?= e($siteDir) ?>/</span>
                <input name="docroot" value="<?= e($rel) ?>" required class="<?= $input ?> font-mono">
                <button class="<?= $btn ?> bg-slate-800 hover:bg-slate-700 text-white">Save</button>
            </form>
        </div>

        <div class="<?= $card ?>">
            <h3 class="text-sm font-semibold text-slate-700 mb-1">Aliases</h3>
            <p class="text-xs text-slate-400 mb-3">Other domain names that show this same site (cPanel's "parked domains"), with www. of each.</p>
            <?php if ($aliases): ?>
            <ul class="mb-3 flex flex-wrap gap-2">
                <?php foreach ($aliases as $al): ?>
                <li>
                    <form method="post" action="/cpanel/domains/<?= $id ?>/aliases/delete" class="inline-flex items-center gap-1 rounded-full bg-slate-100 pl-3 pr-1 py-1 text-xs text-slate-700" data-confirm="Stop serving this site on <?= e($al) ?>? Its DNS zone here is removed too.">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="alias" value="<?= e($al) ?>">
                        <?= e($al) ?>
                        <button class="rounded-full p-0.5 text-slate-400 hover:text-red-600" title="Remove"><?= icon('close', 'h-3 w-3') ?></button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <form method="post" action="/cpanel/domains/<?= $id ?>/aliases" class="flex gap-2">
                <?= Csrf::field() ?>
                <input name="alias" required placeholder="example.net" class="<?= $input ?>">
                <button class="<?= $btn ?> bg-slate-800 hover:bg-slate-700 text-white">Add</button>
            </form>
        </div>

        <div class="<?= $card ?> lg:col-span-2">
            <h3 class="text-sm font-semibold text-slate-700 mb-1">PHP settings</h3>
            <p class="text-xs text-slate-400 mb-3">
                Leave a field empty for the server default. Errors are logged to <span class="font-mono"><?= e($phpLog) ?></span>.
                Upload and post size limits are server-wide.<?= $d['php_version'] !== 'default' ? ' These apply to sites on the default PHP version only.' : '' ?>
            </p>
            <form method="post" action="/cpanel/domains/<?= $id ?>/php" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 items-end">
                <?= Csrf::field() ?>
                <?php foreach (PhpSettingsService::SETTINGS as $key => [$kind, $lbl]): ?>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1"><?= e($lbl) ?></label>
                    <?php if ($kind === 'bool'): ?>
                    <select name="<?= e($key) ?>" class="<?= $input ?> bg-white">
                        <option value="">Default</option>
                        <option value="On" <?= ($php[$key] ?? '') === 'On' ? 'selected' : '' ?>>On</option>
                        <option value="Off" <?= ($php[$key] ?? '') === 'Off' ? 'selected' : '' ?>>Off</option>
                    </select>
                    <?php else: ?>
                    <input name="<?= e($key) ?>" value="<?= e($php[$key] ?? '') ?>" placeholder="<?= e((string) ini_get($key)) ?>" class="<?= $input ?> font-mono">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div><button class="<?= $btn ?> bg-slate-800 hover:bg-slate-700 text-white">Save</button></div>
            </form>
        </div>

        <div class="<?= $card ?>">
            <h3 class="text-sm font-semibold text-slate-700 mb-1">Cache</h3>
            <p class="text-xs text-slate-400 mb-3">Clears the site's cached PHP code, its cached pages and its object cache keys - use it after uploading changed files if the old version still shows.
                Page cache: <span class="font-medium text-slate-600"><?= !empty($d['page_cache_ttl']) ? 'on (' . (int) $d['page_cache_ttl'] . ' s)' : 'off' ?></span> &middot; <a href="/cpanel/cache" class="text-sky-700 hover:underline">Cache settings</a></p>
            <form method="post" action="/cpanel/domains/<?= $id ?>/clear-cache">
                <?= Csrf::field() ?>
                <button class="<?= $btn ?> bg-slate-100 hover:bg-slate-200 text-slate-700"><?= icon('refresh', 'h-4 w-4') ?> Clear cache</button>
            </form>
        </div>
    </div>
</div>
