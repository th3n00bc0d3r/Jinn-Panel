<?php
/** @var list<array<string,mixed>> $dbs */
/** @var list<string> $users */
/** @var array<string, array<string, list<string>>> $grants db_user => db_name => privileges */
/** @var list<string> $remote */
/** @var list<string> $privileges */
/** @var string $prefix */
/** @var string $serverHost */
/** @var string|null $loadError */
View::slot('desc', e('Databases for your sites, and the MySQL users they sign in with.'));
$dbAccess = Features::has('database_access');
View::slot('actions',
    ($dbAccess ? '<form method="post" action="/cpanel/databases/phpmyadmin" target="_blank">' . Csrf::field()
    . '<button type="submit" class="btn btn-secondary"' . ($dbs ? ' title="Opens in a new tab, signed in"' : ' disabled title="Create a database first"') . '>' . icon('external') . 'phpMyAdmin</button></form>' : '')
    . '<a href="#add-database" class="btn btn-primary">' . icon('plus') . 'Create database</a>');
View::script('databases');

/** A prefixed name with the account prefix dimmed. */
$named = function (string $name) use ($prefix): string {
    if ($prefix === '' || !str_starts_with($name, $prefix)) {
        return '<span class="font-mono text-[0.8125rem] text-fg">' . e($name) . '</span>';
    }
    return '<span class="font-mono text-[0.8125rem]"><span class="text-subtle">' . e($prefix) . '</span><span class="text-fg">' . e(substr($name, strlen($prefix))) . '</span></span>';
};
$allCount = count($privileges);
$privText = fn(array $p) => count($p) >= $allCount ? 'all privileges' : ($p === ['SELECT'] ? 'read only' : count($p) . ' of ' . $allCount . ' privileges');

// db_name => user => privileges, and the same keyed by database id for the access form.
$byDb = [];
foreach ($grants as $u => $dbsOf) {
    foreach ($dbsOf as $db => $p) {
        $byDb[$db][$u] = $p;
    }
}
$dbIds = array_column($dbs, 'id', 'db_name');
$grantsById = [];
foreach ($grants as $u => $dbsOf) {
    foreach ($dbsOf as $db => $p) {
        if (isset($dbIds[$db])) {
            $grantsById[$u][(string) $dbIds[$db]] = array_values($p);
        }
    }
}

$compact = true;
$dbFull = $pkg && !Quota::unlimited($pkg['max_databases']) && (int) $usage['databases'] >= (int) $pkg['max_databases'];
?>
<div class="stack">
    <?php include __DIR__ . '/../partials/quota_bars.php'; ?>
    <?php if ($loadError): ?>
    <?= Ui::alert('Couldn\'t read who has access to which database, so that is missing below: ' . $loadError, 'danger') ?>
    <?php endif; ?>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <div class="stack xl:col-span-2">
            <section class="card" aria-labelledby="dbs-title">
                <div class="card-header">
                    <h2 id="dbs-title" class="card-title"><?= e(Ui::plural(count($dbs), 'database')) ?></h2>
                    <?php if (count($dbs) > 5): ?>
                    <input type="search" class="input input-sm w-full sm:w-56" placeholder="Filter databases…" aria-label="Filter databases" data-filter="#dbs-table">
                    <?php endif; ?>
                </div>
                <div class="table-wrap">
                    <table class="table" id="dbs-table">
                        <thead>
                            <tr>
                                <th scope="col">Database</th>
                                <th scope="col" class="hidden sm:table-cell">Users with access</th>
                                <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dbs as $d):
                                $id = (int) $d['id'];
                                $name = (string) $d['db_name'];
                                $access = $byDb[$name] ?? []; ?>
                            <tr>
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center gap-1"><?= $named($name) ?><?= Ui::copy($name, 'Copy ' . $name) ?></div>
                                    <p class="mt-1 whitespace-normal text-xs text-muted sm:hidden"><?= $access ? e(implode(', ', array_keys($access))) : 'No users yet' ?></p>
                                </td>
                                <td class="hidden sm:table-cell">
                                    <div class="flex flex-wrap gap-1.5">
                                        <?php foreach ($access as $u => $p): ?>
                                        <form method="post" action="/cpanel/databases/users/action" class="chip gap-1 pr-0.5" title="<?= e(count($p) >= $allCount ? 'All privileges' : implode(', ', $p)) ?>" data-confirm="Remove <?= e($u) ?>'s access to <?= e($name) ?>? Sites that sign in as <?= e($u) ?> can't use this database any more." data-confirm-action="Remove access" data-confirm-danger>
                                            <?= Csrf::field() ?><input type="hidden" name="op" value="revoke"><input type="hidden" name="db_user" value="<?= e($u) ?>"><input type="hidden" name="db_id" value="<?= $id ?>">
                                            <span><?= e($u) ?><?php if (count($p) < $allCount): ?> <span class="font-sans text-muted">· <?= e($privText($p)) ?></span><?php endif; ?></span>
                                            <button type="submit" class="rounded p-0.5 text-subtle hover:bg-surface-2 hover:text-danger-text" title="Remove <?= e($u) ?>'s access" aria-label="Remove <?= e($u) ?>'s access to <?= e($name) ?>"><?= icon('close', 'h-3 w-3') ?></button>
                                        </form>
                                        <?php endforeach; ?>
                                        <?php if (!$access): ?>
                                        <span class="text-xs text-subtle">No users yet · <a href="<?= $users ? '#privileges' : '#add-user' ?>" class="link">give one access</a></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="w-px">
                                    <div class="flex justify-end">
                                        <?= Ui::deleteForm("/cpanel/databases/$id/delete", 'Delete the database ' . $name . ' and all its data? This can\'t be undone.', 'Delete database') ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$dbs): ?>
                            <?= Ui::emptyRow(3, 'No databases yet', 'Create one for a site, with a user of the same name to sign in with.', 'database') ?>
                            <?php else: ?>
                            <tr data-filter-none data-filter-skip hidden><td colspan="3" class="py-8 text-center text-sm text-muted">No database matches that filter.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card" aria-labelledby="users-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="users-title" class="card-title"><?= e(Ui::plural(count($users), 'MySQL user')) ?></h2>
                        <p class="card-desc">Your sites sign in to MySQL as one of these.</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">User</th>
                                <th scope="col" class="hidden sm:table-cell">Databases</th>
                                <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $i => $u): $of = array_keys($grants[$u] ?? []); ?>
                            <tr>
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center gap-1"><?= $named($u) ?><?= Ui::copy($u, 'Copy ' . $u) ?></div>
                                    <p class="mt-1 text-xs text-muted sm:hidden"><?= e(Ui::plural(count($of), 'database')) ?></p>
                                </td>
                                <td class="hidden text-muted sm:table-cell">
                                    <?php if ($of): ?>
                                    <div class="flex flex-wrap gap-1.5"><?php foreach ($of as $db): ?><span class="chip"><?= e($db) ?></span><?php endforeach; ?></div>
                                    <?php else: ?>
                                    <span class="text-xs text-subtle">None</span>
                                    <?php endif; ?>
                                </td>
                                <td class="w-px">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" class="btn btn-secondary btn-sm" data-dialog-open="dbuser-<?= (int) $i ?>" aria-haspopup="dialog" aria-label="Change the password of <?= e($u) ?>"><?= icon('key') ?><span class="hidden sm:inline">Password</span></button>
                                        <?= Ui::deleteForm('/cpanel/databases/users/action', 'Delete the MySQL user ' . $u . '? Sites that sign in as it lose their database connection.', 'Delete user', ['op' => 'delete', 'db_user' => $u]) ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$users): ?>
                            <?= Ui::emptyRow(3, 'No MySQL users yet', 'Create a user, then give it access to a database.', 'user') ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php foreach ($users as $i => $u): ?>
                <dialog id="dbuser-<?= (int) $i ?>" class="dialog" aria-labelledby="dbuser-<?= (int) $i ?>-title">
                    <form method="post" action="/cpanel/databases/users/action">
                        <?= Csrf::field() ?><input type="hidden" name="op" value="password"><input type="hidden" name="db_user" value="<?= e($u) ?>">
                        <div class="dialog-body space-y-4">
                            <div>
                                <h2 id="dbuser-<?= (int) $i ?>-title" class="dialog-title">Change the password of <?= e($u) ?></h2>
                                <p class="dialog-text">Put the new password in your site's settings too (e.g. wp-config.php), or the site loses its database connection.</p>
                            </div>
                            <?= Ui::field('password', 'New password', [
                                'type' => 'password', 'generate' => true, 'required' => true,
                                'hint' => 'At least 10 characters, with letters and numbers.',
                                'attrs' => ['minlength' => 10, 'autocomplete' => 'new-password', 'data-dialog-focus' => true],
                            ]) ?>
                        </div>
                        <div class="dialog-footer">
                            <button type="button" class="btn btn-secondary" data-dialog-close>Cancel</button>
                            <button type="submit" class="btn btn-primary">Change password</button>
                        </div>
                    </form>
                </dialog>
                <?php endforeach; ?>
            </section>

            <?php if ($users && $dbs): ?>
            <section class="card scroll-mt-20" id="privileges" aria-labelledby="privileges-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="privileges-title" class="card-title">Give a user access</h2>
                        <p class="card-desc">Saving replaces what the user may do in that database. Untick every privilege to remove its access.</p>
                    </div>
                </div>
                <form method="post" action="/cpanel/databases/users/action" class="card-body space-y-4" data-grant-form>
                    <?= Csrf::field() ?><input type="hidden" name="op" value="grant">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <?= Ui::select('db_user', 'User', array_combine($users, $users), null, ['attrs' => ['data-grant-user' => true]]) ?>
                        <?= Ui::select('db_id', 'Database', array_column($dbs, 'db_name', 'id'), null, ['attrs' => ['data-grant-db' => true]]) ?>
                    </div>
                    <p class="text-xs text-muted" data-grant-now hidden></p>
                    <?= Ui::checkbox('all', 'All privileges', true, ['attrs' => ['data-all-privs' => true], 'hint' => 'What a site usually needs. Untick to choose them one by one.']) ?>
                    <fieldset class="grid grid-cols-1 gap-x-4 gap-y-2 rounded-lg border border-line bg-surface-2 p-4 sm:grid-cols-2 lg:grid-cols-3" data-privs>
                        <legend class="sr-only">Privileges</legend>
                        <?php foreach ($privileges as $p): ?>
                        <?= Ui::checkbox('privileges[]', $p, true, ['value' => $p]) ?>
                        <?php endforeach; ?>
                    </fieldset>
                    <button type="submit" class="btn btn-primary">Save privileges</button>
                </form>
                <script type="application/json" id="db-grants"><?= json_encode(['grants' => (object) $grantsById, 'all' => $allCount], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            </section>
            <?php endif; ?>

            <section class="card" aria-labelledby="remote-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="remote-title" class="card-title">Remote MySQL</h2>
                        <p class="card-desc">Let apps on other servers connect to your databases. Only these addresses get through the firewall; they sign in with your MySQL users' usual passwords.</p>
                    </div>
                </div>
                <?php if ($remote): ?>
                <ul class="divide-y divide-line">
                    <?php foreach ($remote as $h): ?>
                    <li class="flex items-center gap-3 px-5 py-2.5">
                        <code class="mono min-w-0 truncate text-fg"><?= e($h) ?></code>
                        <?php if ($h === '%'): ?><?= Ui::badge('Anywhere', 'warning', true) ?><span class="hidden text-xs text-muted sm:inline">every address can try to sign in</span><?php endif; ?>
                        <div class="ml-auto">
                            <?= Ui::actionForm('/cpanel/databases/remote', 'trash', 'Remove ' . $h, 'Stop allowing connections from ' . ($h === '%' ? 'anywhere' : $h) . '? Apps there can no longer reach your databases.', ['op' => 'remove', 'host' => $h], true) ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="px-5 py-4 text-sm text-muted">None: only your sites on this server can connect.</p>
                <?php endif; ?>
                <?php if (!$dbAccess): ?>
                <p class="flex items-center gap-2 rounded-b-xl border-t border-line bg-surface-2 px-5 py-4 text-sm text-muted"><?= icon('lock', 'h-4 w-4') ?>Remote MySQL access isn't in your package.</p>
                <?php else: ?>
                <form method="post" action="/cpanel/databases/remote" class="flex flex-col gap-3 rounded-b-xl border-t border-line bg-surface-2 px-5 py-4 sm:flex-row sm:items-start">
                    <?= Csrf::field() ?><input type="hidden" name="op" value="add">
                    <?= Ui::field('host', 'Allow connections from', [
                        'mono' => true, 'required' => true, 'placeholder' => '203.0.113.7', 'wrap' => 'flex-1',
                        'hint' => 'An IP address (203.0.113.7, 2001:db8::5), an IPv4 range (203.0.113.0/24), or % for anywhere.',
                        'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false', 'autocomplete' => 'off'],
                    ]) ?>
                    <button type="submit" class="btn btn-secondary shrink-0 sm:mt-[1.625rem]"><?= icon('plus') ?>Add host</button>
                </form>
                <?php endif; ?>
            </section>
        </div>

        <div class="stack">
            <section class="card scroll-mt-20" id="add-database" aria-labelledby="add-database-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="add-database-title" class="card-title">Create a database</h2>
                        <p class="card-desc">Names start with <code><?= e($prefix) ?></code>, your account's prefix.</p>
                    </div>
                </div>
                <form method="post" action="/cpanel/databases" class="card-body space-y-4">
                    <?= Csrf::field() ?>
                    <?php if ($dbFull): ?>
                    <?= Ui::alert('All ' . (int) $pkg['max_databases'] . ' databases of your package are in use. Delete one, or ask your hosting provider for more.', 'warning') ?>
                    <?php endif; ?>
                    <?= Ui::field('name', 'Database name', [
                        'addon' => $prefix, 'mono' => true, 'required' => true, 'placeholder' => 'shop',
                        'hint' => 'Letters, numbers and _, starting with a letter.',
                        'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false', 'autocomplete' => 'off'],
                    ]) ?>
                    <?= Ui::checkbox('with_user', 'Also create a user with the same name', false, ['attrs' => ['data-with-user' => true], 'hint' => 'It gets all privileges on the new database.']) ?>
                    <div data-with-user-pw hidden>
                        <?= Ui::field('password', 'Password for that user', [
                            'type' => 'password', 'generate' => true,
                            'hint' => 'At least 10 characters, with letters and numbers.',
                            'attrs' => ['minlength' => 10, 'autocomplete' => 'new-password'],
                        ]) ?>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><?= icon('plus') ?>Create database</button>
                </form>
            </section>

            <section class="card scroll-mt-20" id="add-user" aria-labelledby="add-user-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="add-user-title" class="card-title">Create a MySQL user</h2>
                        <p class="card-desc">Then give it access to a database.</p>
                    </div>
                </div>
                <form method="post" action="/cpanel/databases/users" class="card-body space-y-4">
                    <?= Csrf::field() ?>
                    <?= Ui::field('name', 'Username', [
                        'addon' => $prefix, 'mono' => true, 'required' => true, 'placeholder' => 'app',
                        'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false', 'autocomplete' => 'off'],
                    ]) ?>
                    <?= Ui::field('password', 'Password', [
                        'type' => 'password', 'generate' => true, 'required' => true,
                        'hint' => 'At least 10 characters, with letters and numbers.',
                        'attrs' => ['minlength' => 10, 'autocomplete' => 'new-password'],
                    ]) ?>
                    <button type="submit" class="btn btn-secondary btn-block"><?= icon('user-plus') ?>Create user</button>
                </form>
            </section>

            <section class="card" aria-labelledby="connect-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="connect-title" class="card-title">Connecting</h2>
                        <p class="card-desc">For your site's settings, e.g. wp-config.php or .env.</p>
                    </div>
                </div>
                <dl class="card-body space-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-medium text-muted">Host, from your sites</dt>
                        <dd class="mt-1 flex flex-wrap items-center gap-x-3 text-fg"><?= Ui::copyable('localhost') ?><span class="text-muted">port <span class="font-mono">3306</span></span></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-muted">Host, from allowed remote addresses</dt>
                        <dd class="mt-1 flex flex-wrap items-center gap-x-3 text-fg"><?= Ui::copyable($serverHost) ?><span class="text-muted">port <span class="font-mono">3306</span></span></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-muted">Database and username</dt>
                        <dd class="mt-1 text-fg">The full names, with the prefix, e.g. <code><?= e($prefix) ?>app</code></dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>
</div>
