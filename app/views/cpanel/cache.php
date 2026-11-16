<?php
/** @var list<array<string,mixed>> $domains each with cached_pages, static, static_entries */
/** @var array{user:string,password:string,prefix:string}|null $creds */
/** @var int|null $keys */
View::slot('desc', e('Make your sites faster: an object cache for your apps, ready-made copies of static files, and whole pages served without running PHP.'));

$first = $domains[0]['domain_name'] ?? 'example.com';
$count = fn(int $n, string $one) => number_format($n) . ' ' . ($n === 1 ? $one : $one . 's');
$pageTtls = [60 => '1 minute', 300 => '5 minutes', 900 => '15 minutes', 3600 => '1 hour', 21600 => '6 hours', 86400 => '1 day'];
if ($creds) {
    $wp = "define('WP_REDIS_HOST', '" . CacheService::VALKEY_HOST . "');\n"
        . "define('WP_REDIS_PORT', " . CacheService::VALKEY_PORT . ");\n"
        . "define('WP_REDIS_USERNAME', '" . $creds['user'] . "');\n"
        . "define('WP_REDIS_PASSWORD', '" . $creds['password'] . "');\n"
        . "define('WP_REDIS_PREFIX', '" . $creds['prefix'] . $first . ":');\n"
        . "define('WP_REDIS_SELECTIVE_FLUSH', true);";
    $laravel = "CACHE_STORE=redis\n"
        . 'REDIS_HOST=' . CacheService::VALKEY_HOST . "\n"
        . 'REDIS_USERNAME=' . $creds['user'] . "\n"
        . 'REDIS_PASSWORD=' . $creds['password'] . "\n"
        . 'REDIS_PREFIX=' . $creds['prefix'] . $first . ':';
}
?>
<div class="stack">
    <section class="card" aria-labelledby="object-title">
        <div class="card-header">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 id="object-title" class="card-title">Object cache</h2>
                    <?= $creds ? Ui::badge('On', 'success', true) : Ui::badge('Off', 'neutral') ?>
                </div>
                <p class="card-desc">A Redis-compatible in-memory store where WordPress, Laravel and other apps keep database results and sessions.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <?php if (!$creds && !Features::has('object_cache')): ?>
                <span class="flex items-center gap-1.5 text-sm text-muted"><?= icon('lock', 'h-4 w-4') ?>Not in your package</span>
                <?php elseif (!$creds): ?>
                <form method="post" action="/cpanel/cache/object">
                    <?= Csrf::field() ?>
                    <button type="submit" name="op" value="enable" class="btn btn-primary"><?= icon('bolt') ?>Turn on</button>
                </form>
                <?php else: ?>
                <form method="post" action="/cpanel/cache/object" data-confirm="Delete everything in your object cache? Your sites fill it again as they're used." data-confirm-action="Flush">
                    <?= Csrf::field() ?>
                    <button type="submit" name="op" value="flush" class="btn btn-secondary btn-sm"><?= icon('refresh') ?>Flush</button>
                </form>
                <form method="post" action="/cpanel/cache/object" data-confirm="Set a new password? Sites using the old one stop caching until you update them." data-confirm-action="New password">
                    <?= Csrf::field() ?>
                    <button type="submit" name="op" value="reset" class="btn btn-secondary btn-sm"><?= icon('key') ?>New password</button>
                </form>
                <form method="post" action="/cpanel/cache/object" data-confirm="Turn the object cache off and delete its contents? Sites set up to use it stop caching." data-confirm-action="Turn off" data-confirm-danger>
                    <?= Csrf::field() ?>
                    <button type="submit" name="op" value="disable" class="btn btn-danger-ghost btn-sm">Turn off</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($creds): ?>
        <div class="card-body grid gap-6 lg:grid-cols-5">
            <div class="min-w-0 lg:col-span-2">
                <dl class="dl">
                    <div><dt class="sm:w-28">Host</dt><dd><?= Ui::copyable(CacheService::VALKEY_HOST) ?></dd></div>
                    <div><dt class="sm:w-28">Port</dt><dd><?= Ui::copyable((string) CacheService::VALKEY_PORT) ?></dd></div>
                    <div><dt class="sm:w-28">Username</dt><dd><?= Ui::copyable($creds['user']) ?></dd></div>
                    <div><dt class="sm:w-28">Password</dt><dd><?= Ui::copyable($creds['password']) ?></dd></div>
                    <div><dt class="sm:w-28">Key prefix</dt><dd><code><?= e($creds['prefix']) ?>&lt;domain&gt;:</code></dd></div>
                    <div><dt class="sm:w-28">In use</dt><dd class="tabular-nums"><?= $keys === null ? 'Unknown' : e($count($keys, 'key')) ?></dd></div>
                </dl>
                <p class="mt-4 text-xs text-muted">Your login can only use keys that start with your prefix. Give each site its own domain in the prefix. When memory runs short, the least recently used keys are dropped.</p>
            </div>
            <div class="min-w-0 space-y-4 lg:col-span-3">
                <div>
                    <div class="mb-1.5 flex items-start justify-between gap-3">
                        <p class="text-xs text-muted"><span class="font-medium text-fg">WordPress</span> - install the "Redis Object Cache" plugin, add this to wp-config.php, then enable it in the plugin's settings.</p>
                        <?= Ui::copy($wp, 'Copy the WordPress settings') ?>
                    </div>
                    <pre class="log whitespace-pre break-normal"><?= e($wp) ?></pre>
                </div>
                <div>
                    <div class="mb-1.5 flex items-start justify-between gap-3">
                        <p class="text-xs text-muted"><span class="font-medium text-fg">Laravel</span> - in .env:</p>
                        <?= Ui::copy($laravel, 'Copy the Laravel settings') ?>
                    </div>
                    <pre class="log whitespace-pre break-normal"><?= e($laravel) ?></pre>
                </div>
            </div>
        </div>
        <?php else: ?>
        <p class="card-body text-sm text-muted">Turn it on to get a login for your apps. WordPress needs the free "Redis Object Cache" plugin; Laravel and most frameworks support it out of the box.</p>
        <?php endif; ?>
    </section>

    <?php if (!$domains): ?>
    <section class="card">
        <?= Ui::empty('No domains yet', 'The static file cache and the page cache are set per site. Add a domain first.', 'globe', '<a href="/cpanel/domains#add-domain" class="btn btn-secondary btn-sm">' . icon('plus') . 'Add a domain</a>') ?>
    </section>
    <?php else: ?>
    <section class="card" aria-labelledby="static-title">
        <div class="card-header">
            <div class="min-w-0">
                <h2 id="static-title" class="card-title">Static file cache</h2>
                <p class="card-desc max-w-3xl">Keeps compressed, ready-to-send copies of your sites' static files (HTML, CSS, JavaScript, SVG, fonts…). Edits in the File Manager show up right away; files uploaded over SFTP once the lifetime runs out, or when you clear the cache.</p>
            </div>
        </div>
        <ul class="divide-y divide-line">
            <?php foreach ($domains as $d):
                $id = (int) $d['id'];
                $son = (int) $d['static_cache_ttl'] > 0;
                $ttlId = Ui::id('sttl'); ?>
            <li class="flex flex-col gap-3 px-5 py-4 text-sm lg:flex-row lg:items-center lg:gap-6">
                <div class="min-w-0 lg:w-52 lg:shrink-0">
                    <p class="truncate font-medium text-fg"><?= e($d['domain_name']) ?></p>
                    <p class="mt-0.5 text-xs text-muted"><?= !$son ? 'Off' : ($d['static_entries'] !== null ? e($count((int) $d['static_entries'], 'file')) . ' cached' : 'On') ?></p>
                </div>
                <form method="post" action="/cpanel/cache/static/<?= $id ?>" class="flex flex-1 flex-wrap items-center gap-x-5 gap-y-3">
                    <?= Csrf::field() ?>
                    <div class="flex items-center gap-2">
                        <?= Ui::checkbox('enabled', 'Cache files for', $son) ?>
                        <select name="ttl" id="<?= e($ttlId) ?>" class="select select-sm w-auto" aria-label="How long <?= e($d['domain_name']) ?> keeps a cached file">
                            <?php foreach (CacheService::STATIC_TTLS as $v => $l): ?>
                            <option value="<?= (int) $v ?>"<?= ($son ? (int) $d['static_cache_ttl'] : 300) === $v ? ' selected' : '' ?>><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?= Ui::checkbox('browser', 'Browser caching', (bool) (int) $d['browser_cache']) ?>
                    <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                </form>
                <form method="post" action="/cpanel/cache/static/<?= $id ?>" class="lg:ml-auto">
                    <?= Csrf::field() ?>
                    <button type="submit" name="op" value="clear" class="btn btn-ghost btn-sm" aria-label="Clear the static file cache of <?= e($d['domain_name']) ?>"<?= $son ? '' : ' disabled title="The cache is off"' ?>><?= icon('refresh') ?>Clear</button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="rounded-b-xl border-t border-line bg-surface-2 px-5 py-3 text-xs text-muted">Browser caching tells visitors' browsers to keep images for 30 days and CSS and JavaScript for a day. Turn it off while you're changing them a lot.</p>
    </section>

    <section class="card" aria-labelledby="page-title">
        <div class="card-header">
            <div class="min-w-0">
                <h2 id="page-title" class="card-title">Page cache</h2>
                <p class="card-desc max-w-3xl">Stores whole pages for visitors who aren't logged in, so PHP doesn't run for them. Only plain page views are cached: never logged-in users, carts, forms, or pages that set a cookie. Changes show up once the lifetime runs out, or right away when you clear the cache.</p>
            </div>
        </div>
        <ul class="divide-y divide-line">
            <?php foreach ($domains as $d):
                $id = (int) $d['id'];
                $on = !empty($d['page_cache_ttl']);
                $ttlId = Ui::id('pttl'); ?>
            <li class="flex flex-col gap-3 px-5 py-4 text-sm lg:flex-row lg:items-center lg:gap-6">
                <div class="min-w-0 lg:w-52 lg:shrink-0">
                    <p class="truncate font-medium text-fg"><?= e($d['domain_name']) ?></p>
                    <p class="mt-0.5 text-xs text-muted"><?= $on ? e($count((int) $d['cached_pages'], 'page')) . ' cached' : ($d['static'] ? 'Static site' : 'Off') ?></p>
                </div>
                <?php if ($d['static'] && !$on): ?>
                <p class="flex-1 text-sm text-muted">No PHP on this site, so there are no pages to cache: the static file cache does the job.</p>
                <?php else: ?>
                <form method="post" action="/cpanel/cache/domains/<?= $id ?>" class="flex flex-1 flex-wrap items-center gap-x-5 gap-y-3">
                    <?= Csrf::field() ?>
                    <div class="flex items-center gap-2">
                        <?= Ui::checkbox('enabled', 'Cache pages for', $on) ?>
                        <select name="ttl" id="<?= e($ttlId) ?>" class="select select-sm w-auto" aria-label="How long <?= e($d['domain_name']) ?> keeps a cached page">
                            <?php foreach ($pageTtls as $v => $l): ?>
                            <option value="<?= (int) $v ?>"<?= ($on ? (int) $d['page_cache_ttl'] : 300) === $v ? ' selected' : '' ?>><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                    <?php if ($on && $d['static']): ?><span class="text-xs text-warning-text">This site has no PHP, so there's nothing to cache.</span><?php endif; ?>
                </form>
                <?php endif; ?>
                <form method="post" action="/cpanel/cache/domains/<?= $id ?>" class="lg:ml-auto">
                    <?= Csrf::field() ?>
                    <button type="submit" name="op" value="clear" class="btn btn-ghost btn-sm" title="Empties every cache of this site: static files, pages, object-cache keys and PHP's compiled code" aria-label="Clear all caches of <?= e($d['domain_name']) ?>"><?= icon('refresh') ?>Clear all</button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="rounded-b-xl border-t border-line bg-surface-2 px-5 py-3 text-xs text-muted">"Clear all" empties every cache of the site at once: static files, pages, its object-cache keys and PHP's compiled code (OPcache).</p>
    </section>
    <?php endif; ?>
</div>
