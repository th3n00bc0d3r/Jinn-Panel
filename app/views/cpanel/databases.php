<?php
/** @var list<array<string,mixed>> $dbs */
/** @var list<string> $users */
/** @var array<string, array<string, list<string>>> $grants db_user => db_name => privileges */
/** @var list<string> $remote */
/** @var list<string> $privileges */
/** @var string $prefix */
/** @var string $serverHost */
/** @var string|null $loadError */
$card = 'bg-white rounded-xl border border-slate-200 shadow-sm';
$input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500';
$label = 'block text-xs font-medium text-slate-600 mb-1';
$btn = 'rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium px-4 py-2 transition-colors';
$prefixed = fn(string $name) => '<span class="text-slate-400">' . e($prefix) . '</span>' . e(substr($name, strlen($prefix)));
$byDb = [];
foreach ($grants as $u => $dbsOf) {
    foreach ($dbsOf as $db => $p) {
        $byDb[$db][$u] = $p;
    }
}
?>
<?php include __DIR__ . '/../partials/quota_bars.php'; ?>
<?php if ($loadError): ?><div class="rounded-lg border border-red-200 bg-red-50 text-red-800 text-sm px-4 py-3 mt-4">Couldn't read the privileges: <?= e($loadError) ?></div><?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4">
    <div class="lg:col-span-2 space-y-4">
        <div class="<?= $card ?> overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-center gap-3">
                <h2 class="text-sm font-semibold text-slate-700">Databases</h2>
                <form method="post" action="/cpanel/databases/phpmyadmin" class="ml-auto" target="_blank">
                    <?= Csrf::field() ?>
                    <button class="inline-flex items-center gap-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-medium px-3 py-1.5 transition-colors" <?= $dbs ? '' : 'disabled' ?>><?= icon('database', 'h-3.5 w-3.5') ?> phpMyAdmin</button>
                </form>
            </div>
            <ul class="divide-y divide-slate-100 text-sm">
                <?php foreach ($dbs as $d): ?>
                <li class="px-5 py-3 flex flex-wrap items-start gap-3">
                    <span class="font-mono text-slate-700 min-w-48"><?= $prefixed((string) $d['db_name']) ?></span>
                    <span class="flex flex-wrap gap-1.5 flex-1">
                        <?php foreach ($byDb[$d['db_name']] ?? [] as $u => $p): ?>
                        <form method="post" action="/cpanel/databases/users/action" class="inline-flex items-center gap-1 rounded-full bg-sky-50 text-sky-800 pl-2.5 pr-1 py-0.5 text-xs" title="<?= e(count($p) === count($privileges) ? 'All privileges' : implode(', ', $p)) ?>">
                            <?= Csrf::field() ?><input type="hidden" name="op" value="revoke"><input type="hidden" name="db_user" value="<?= e($u) ?>"><input type="hidden" name="db_id" value="<?= (int) $d['id'] ?>">
                            <?= e($u) ?><?= count($p) === count($privileges) ? '' : ' <span class="text-sky-500">(' . count($p) . ')</span>' ?>
                            <button class="rounded-full p-0.5 text-sky-400 hover:text-red-600" title="Remove <?= e($u) ?> from this database"><?= icon('close', 'h-3 w-3') ?></button>
                        </form>
                        <?php endforeach; ?>
                        <?php if (empty($byDb[$d['db_name']])): ?><span class="text-xs text-slate-400">No users - add one below.</span><?php endif; ?>
                    </span>
                    <form method="post" action="/cpanel/databases/<?= (int) $d['id'] ?>/delete" data-confirm="Delete database <?= e($d['db_name']) ?> and all its data? This can't be undone.">
                        <?= Csrf::field() ?>
                        <button class="p-1.5 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50" title="Delete database"><?= icon('trash', 'h-4 w-4') ?></button>
                    </form>
                </li>
                <?php endforeach; ?>
                <?php if (!$dbs): ?><li class="px-5 py-8 text-center text-slate-400">No databases yet.</li><?php endif; ?>
            </ul>
        </div>

        <div class="<?= $card ?> overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-700">MySQL users</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                <?php foreach ($users as $u): ?>
                <li class="px-5 py-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="font-mono text-slate-700"><?= $prefixed($u) ?></span>
                        <span class="text-xs text-slate-400"><?= count($grants[$u] ?? []) ?> database(s)</span>
                        <details class="text-xs">
                            <summary class="cursor-pointer text-slate-500 hover:text-slate-700">Change password</summary>
                            <form method="post" action="/cpanel/databases/users/action" class="mt-2 flex gap-2">
                                <?= Csrf::field() ?><input type="hidden" name="op" value="password"><input type="hidden" name="db_user" value="<?= e($u) ?>">
                                <input type="password" name="password" required minlength="10" placeholder="New password" class="<?= $input ?>">
                                <button class="<?= $btn ?>">Save</button>
                            </form>
                        </details>
                        <form method="post" action="/cpanel/databases/users/action" class="ml-auto" data-confirm="Delete MySQL user <?= e($u) ?>? Sites using it lose database access.">
                            <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="db_user" value="<?= e($u) ?>">
                            <button class="p-1.5 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50" title="Delete user"><?= icon('trash', 'h-4 w-4') ?></button>
                        </form>
                    </div>
                </li>
                <?php endforeach; ?>
                <?php if (!$users): ?><li class="px-5 py-6 text-center text-slate-400">No MySQL users yet.</li><?php endif; ?>
            </ul>
        </div>

        <?php if ($users && $dbs): ?>
        <div class="<?= $card ?> p-5">
            <h2 class="text-sm font-semibold text-slate-700 mb-3">Add a user to a database</h2>
            <form method="post" action="/cpanel/databases/users/action" class="space-y-3">
                <?= Csrf::field() ?><input type="hidden" name="op" value="grant">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="<?= $label ?>">User</label><select name="db_user" class="<?= $input ?> bg-white"><?php foreach ($users as $u): ?><option value="<?= e($u) ?>"><?= e($u) ?></option><?php endforeach; ?></select></div>
                    <div><label class="<?= $label ?>">Database</label><select name="db_id" class="<?= $input ?> bg-white"><?php foreach ($dbs as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['db_name']) ?></option><?php endforeach; ?></select></div>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="all" value="1" checked data-all-privs class="rounded border-slate-300 text-sky-600"> All privileges</label>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-1.5 text-xs text-slate-600" data-privs>
                    <?php foreach ($privileges as $p): ?>
                    <label class="flex items-center gap-1.5"><input type="checkbox" name="privileges[]" value="<?= e($p) ?>" checked class="rounded border-slate-300 text-sky-600"> <?= e($p) ?></label>
                    <?php endforeach; ?>
                </div>
                <button class="<?= $btn ?>">Save privileges</button>
                <p class="text-xs text-slate-400">Saving replaces the user's privileges on that database. Untick everything to remove the user from it.</p>
            </form>
        </div>
        <script>
        (function () {
            var all = document.querySelector('[data-all-privs]'), box = document.querySelector('[data-privs]');
            if (!all || !box) return;
            function sync() { box.querySelectorAll('input').forEach(function (i) { i.disabled = all.checked; if (all.checked) i.checked = true; }); box.classList.toggle('opacity-50', all.checked); }
            all.addEventListener('change', sync); sync();
        })();
        </script>
        <?php endif; ?>

        <div class="<?= $card ?> overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100">
                <h2 class="text-sm font-semibold text-slate-700">Remote MySQL</h2>
                <p class="text-xs text-slate-400 mt-0.5">Let apps on other servers connect to your databases. Only these addresses get through the firewall; your MySQL users log in from them with their usual passwords.</p>
            </div>
            <ul class="divide-y divide-slate-100 text-sm">
                <?php foreach ($remote as $h): ?>
                <li class="px-5 py-2.5 flex items-center gap-3">
                    <span class="font-mono text-slate-700"><?= e($h) ?></span>
                    <?php if ($h === '%'): ?><span class="text-xs text-amber-700">anywhere - every address can try to log in</span><?php endif; ?>
                    <form method="post" action="/cpanel/databases/remote" class="ml-auto"><?= Csrf::field() ?><input type="hidden" name="op" value="remove"><input type="hidden" name="host" value="<?= e($h) ?>">
                        <button class="p-1.5 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50" title="Remove"><?= icon('trash', 'h-4 w-4') ?></button></form>
                </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="/cpanel/databases/remote" class="border-t border-slate-100 px-5 py-3 flex gap-2">
                <?= Csrf::field() ?><input type="hidden" name="op" value="add">
                <input name="host" required placeholder="203.0.113.7, 203.0.113.0/24, 2001:db8::5 or %" class="<?= $input ?> font-mono">
                <button class="<?= $btn ?>">Add host</button>
            </form>
        </div>
    </div>

    <div class="space-y-4">
        <div class="<?= $card ?> p-5">
            <h2 class="text-sm font-semibold text-slate-700 mb-3">Create a database</h2>
            <form method="post" action="/cpanel/databases" class="space-y-3">
                <?= Csrf::field() ?>
                <div class="flex items-stretch rounded-lg border border-slate-300 overflow-hidden focus-within:ring-2 focus-within:ring-sky-500">
                    <span class="px-2 flex items-center text-slate-400 bg-slate-50 border-r border-slate-200 text-sm font-mono"><?= e($prefix) ?></span>
                    <input name="name" required placeholder="shop" class="flex-1 px-3 py-2 text-sm focus:outline-none font-mono">
                </div>
                <label class="flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" name="with_user" value="1" data-with-user class="rounded border-slate-300 text-sky-600"> Also create a user with the same name (all privileges)</label>
                <input type="password" name="password" minlength="10" placeholder="Password for that user" class="<?= $input ?> hidden" data-with-user-pw>
                <button class="w-full <?= $btn ?> py-2.5">Create database</button>
            </form>
        </div>
        <div class="<?= $card ?> p-5">
            <h2 class="text-sm font-semibold text-slate-700 mb-3">Create a MySQL user</h2>
            <form method="post" action="/cpanel/databases/users" class="space-y-3">
                <?= Csrf::field() ?>
                <div class="flex items-stretch rounded-lg border border-slate-300 overflow-hidden focus-within:ring-2 focus-within:ring-sky-500">
                    <span class="px-2 flex items-center text-slate-400 bg-slate-50 border-r border-slate-200 text-sm font-mono"><?= e($prefix) ?></span>
                    <input name="name" required placeholder="app" class="flex-1 px-3 py-2 text-sm focus:outline-none font-mono">
                </div>
                <input type="password" name="password" required minlength="10" placeholder="Password (10+ characters, letters and numbers)" class="<?= $input ?>">
                <button class="w-full <?= $btn ?> py-2.5">Create user</button>
            </form>
        </div>
        <div class="<?= $card ?> p-5 text-sm">
            <h2 class="text-sm font-semibold text-slate-700 mb-2">Connection details</h2>
            <dl class="space-y-1.5">
                <div><dt class="text-xs text-slate-400">From your sites</dt><dd class="font-mono">localhost : 3306</dd></div>
                <div><dt class="text-xs text-slate-400">Remote (allowed hosts only)</dt><dd class="font-mono"><?= e($serverHost) ?> : 3306</dd></div>
                <div><dt class="text-xs text-slate-400">Username / database</dt><dd>The full names, e.g. <span class="font-mono"><?= e($prefix) ?>app</span></dd></div>
            </dl>
        </div>
    </div>
</div>
<script>
(function () {
    var c = document.querySelector('[data-with-user]'), pw = document.querySelector('[data-with-user-pw]');
    if (c && pw) c.addEventListener('change', function () { pw.classList.toggle('hidden', !c.checked); pw.required = c.checked; });
})();
</script>
