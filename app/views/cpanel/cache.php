<?php
/** @var list<array<string,mixed>> $domains each with cached_pages */
/** @var array{user:string,password:string,prefix:string}|null $creds */
/** @var int|null $keys */
$card = 'bg-white rounded-xl border border-slate-200 shadow-sm';
$btn = 'inline-flex items-center gap-2 rounded-lg text-sm font-medium px-4 py-2 transition-colors';
$pre = 'text-xs font-mono bg-slate-50 rounded-lg p-3 overflow-x-auto border border-slate-100 text-slate-700';
$first = $domains[0]['domain_name'] ?? 'example.com';
?>
<div class="space-y-4">
    <div class="<?= $card ?> p-5">
        <div class="flex flex-wrap items-start gap-3">
            <div class="flex-1 min-w-64">
                <h2 class="text-sm font-semibold text-slate-700">Object cache (Redis-compatible)</h2>
                <p class="text-xs text-slate-400 mt-0.5 max-w-2xl">A fast in-memory store for WordPress, Laravel and other apps to cache database results and sessions. Your login can only use keys starting with your prefix. When memory runs short, the least recently used keys are dropped.</p>
            </div>
            <form method="post" action="/cpanel/cache/object" class="flex gap-2">
                <?= Csrf::field() ?>
                <?php if (!$creds): ?>
                <button name="op" value="enable" class="<?= $btn ?> bg-sky-600 hover:bg-sky-500 text-white"><?= icon('bolt', 'h-4 w-4') ?> Turn on</button>
                <?php else: ?>
                <button name="op" value="flush" class="<?= $btn ?> bg-slate-100 hover:bg-slate-200 text-slate-700" data-confirm="Delete everything in your object cache?">Flush</button>
                <button name="op" value="reset" class="<?= $btn ?> bg-slate-100 hover:bg-slate-200 text-slate-700" data-confirm="Set a new password? Sites using the old one stop caching until you update them.">New password</button>
                <button name="op" value="disable" class="<?= $btn ?> text-red-700 hover:bg-red-50" data-confirm="Turn the object cache off and delete its contents?">Turn off</button>
                <?php endif; ?>
            </form>
        </div>
        <?php if ($creds): ?>
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <dl class="text-sm space-y-1.5">
                <div class="flex gap-3"><dt class="w-24 text-slate-400">Host</dt><dd class="font-mono"><?= e(CacheService::VALKEY_HOST) ?></dd></div>
                <div class="flex gap-3"><dt class="w-24 text-slate-400">Port</dt><dd class="font-mono"><?= CacheService::VALKEY_PORT ?></dd></div>
                <div class="flex gap-3"><dt class="w-24 text-slate-400">Username</dt><dd class="font-mono"><?= e($creds['user']) ?></dd></div>
                <div class="flex gap-3"><dt class="w-24 text-slate-400">Password</dt><dd class="font-mono break-all"><?= e($creds['password']) ?></dd></div>
                <div class="flex gap-3"><dt class="w-24 text-slate-400">Key prefix</dt><dd class="font-mono"><?= e($creds['prefix']) ?>&lt;domain&gt;:</dd></div>
                <div class="flex gap-3"><dt class="w-24 text-slate-400">In use</dt><dd><?= $keys === null ? '?' : number_format($keys) ?> keys</dd></div>
            </dl>
            <div class="space-y-3">
                <div>
                    <p class="text-xs font-medium text-slate-600 mb-1">WordPress - install the "Redis Object Cache" plugin, add to wp-config.php, then enable it:</p>
                    <pre class="<?= $pre ?>">define('WP_REDIS_HOST', '<?= e(CacheService::VALKEY_HOST) ?>');
define('WP_REDIS_PORT', <?= CacheService::VALKEY_PORT ?>);
define('WP_REDIS_USERNAME', '<?= e($creds['user']) ?>');
define('WP_REDIS_PASSWORD', '<?= e($creds['password']) ?>');
define('WP_REDIS_PREFIX', '<?= e($creds['prefix'] . $first) ?>:');
define('WP_REDIS_SELECTIVE_FLUSH', true);</pre>
                </div>
                <div>
                    <p class="text-xs font-medium text-slate-600 mb-1">Laravel - in .env:</p>
                    <pre class="<?= $pre ?>">CACHE_STORE=redis
REDIS_HOST=<?= e(CacheService::VALKEY_HOST) ?>

REDIS_USERNAME=<?= e($creds['user']) ?>

REDIS_PASSWORD=<?= e($creds['password']) ?>

REDIS_PREFIX=<?= e($creds['prefix'] . $first) ?>:</pre>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="<?= $card ?> overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
            <h2 class="text-sm font-semibold text-slate-700">Static file cache</h2>
            <p class="text-xs text-slate-400 mt-0.5 max-w-3xl">Keeps ready-to-send, compressed copies of your sites' static files (HTML, CSS, JavaScript, SVG, fonts...), so they go out without being compressed again for every visitor. Edits made in the File Manager show up right away; files uploaded over SFTP after the lifetime runs out, or when you clear the cache. Browser caching tells visitors' browsers to keep images for 30 days and CSS/JS for a day; turn it off while you're changing them a lot.</p>
        </div>
        <ul class="divide-y divide-slate-100 text-sm">
            <?php foreach ($domains as $d): ?>
            <?php $son = (int) $d['static_cache_ttl'] > 0; ?>
            <li class="px-5 py-3 flex flex-wrap items-center gap-3">
                <span class="font-medium text-slate-700 min-w-48"><?= e($d['domain_name']) ?></span>
                <form method="post" action="/cpanel/cache/static/<?= (int) $d['id'] ?>" class="flex flex-wrap items-center gap-2">
                    <?= Csrf::field() ?>
                    <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="enabled" value="1" <?= $son ? 'checked' : '' ?> class="rounded border-slate-300 text-sky-600 focus:ring-sky-500"> Cache</label>
                    <select name="ttl" class="rounded-lg border border-slate-300 px-2 py-1.5 text-xs bg-white">
                        <?php foreach (CacheService::STATIC_TTLS as $v => $l): ?>
                        <option value="<?= $v ?>" <?= ($son ? (int) $d['static_cache_ttl'] : 300) === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="browser" value="1" <?= (int) $d['browser_cache'] ? 'checked' : '' ?> class="rounded border-slate-300 text-sky-600 focus:ring-sky-500"> Browser caching</label>
                    <button class="rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-medium px-3 py-1.5">Save</button>
                </form>
                <span class="text-xs text-slate-400"><?= $son && $d['static_entries'] !== null ? number_format((int) $d['static_entries']) . ' files cached' : ($son ? '' : 'off') ?></span>
                <form method="post" action="/cpanel/cache/static/<?= (int) $d['id'] ?>" class="ml-auto">
                    <?= Csrf::field() ?>
                    <button name="op" value="clear" class="inline-flex items-center gap-1.5 text-xs font-medium text-sky-700 hover:text-sky-600" <?= $son ? '' : 'disabled' ?>><?= icon('refresh', 'h-3.5 w-3.5') ?> Clear</button>
                </form>
            </li>
            <?php endforeach; ?>
            <?php if (!$domains): ?><li class="px-5 py-6 text-center text-slate-400">Add a domain first.</li><?php endif; ?>
        </ul>
    </div>

    <div class="<?= $card ?> overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
            <h2 class="text-sm font-semibold text-slate-700">Page cache</h2>
            <p class="text-xs text-slate-400 mt-0.5 max-w-3xl">Stores whole pages for visitors who aren't logged in, so PHP doesn't run for them. Only plain page views are cached: never logged-in users, carts, forms, or pages that set a cookie. Changes show up after the lifetime runs out, or right away when you clear the cache.</p>
        </div>
        <ul class="divide-y divide-slate-100 text-sm">
            <?php foreach ($domains as $d): ?>
            <?php $on = !empty($d['page_cache_ttl']); ?>
            <li class="px-5 py-3 flex flex-wrap items-center gap-3">
                <span class="font-medium text-slate-700 min-w-48"><?= e($d['domain_name']) ?></span>
                <?php if ($d['static'] && !$on): ?>
                <span class="text-xs text-slate-500">Static site (no PHP) - the static file cache above does the job, no page cache needed.</span>
                <?php else: ?>
                <form method="post" action="/cpanel/cache/domains/<?= (int) $d['id'] ?>" class="flex flex-wrap items-center gap-2">
                    <?= Csrf::field() ?>
                    <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="enabled" value="1" <?= $on ? 'checked' : '' ?> class="rounded border-slate-300 text-sky-600 focus:ring-sky-500"> On</label>
                    <select name="ttl" class="rounded-lg border border-slate-300 px-2 py-1.5 text-xs bg-white">
                        <?php foreach ([60 => '1 minute', 300 => '5 minutes', 900 => '15 minutes', 3600 => '1 hour', 21600 => '6 hours', 86400 => '1 day'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= (int) ($d['page_cache_ttl'] ?? 300) === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-medium px-3 py-1.5">Save</button>
                </form>
                <span class="text-xs text-slate-400"><?= $on ? number_format((int) $d['cached_pages']) . ' pages cached' : '' ?><?= $on && $d['static'] ? ' - this site has no PHP, so there\'s nothing to cache' : '' ?></span>
                <?php endif; ?>
                <form method="post" action="/cpanel/cache/domains/<?= (int) $d['id'] ?>" class="ml-auto">
                    <?= Csrf::field() ?>
                    <button name="op" value="clear" class="inline-flex items-center gap-1.5 text-xs font-medium text-sky-700 hover:text-sky-600"><?= icon('refresh', 'h-3.5 w-3.5') ?> Clear cache</button>
                </form>
            </li>
            <?php endforeach; ?>
            <?php if (!$domains): ?><li class="px-5 py-6 text-center text-slate-400">Add a domain first.</li><?php endif; ?>
        </ul>
    </div>
</div>
