<?php
/** @var array<string,mixed> $m the draft migration */
/** @var list<array<string,mixed>> $items */
/** @var array<int,list<string>> $conflicts item id => problems */
/** @var list<array<string,mixed>> $packages */
/** @var list<array<string,mixed>> $resellers */
/** @var array<string,mixed> $opt */
/** @var array<string,mixed> $me */
include __DIR__ . '/_helpers.php';
$id = (int) $m['id'];
View::breadcrumbs([['cPanel Migration', '/whm/migrations'], ['Choose accounts']]);
View::slot('desc', e('Pick the accounts to bring over and what to bring with them. Nothing is copied until you start.'));
View::slot('actions', '<form method="post" action="/whm/migrations/' . $id . '/delete" data-confirm="Discard this migration? Nothing has been copied yet." data-confirm-action="Discard" data-confirm-danger>'
    . Csrf::field() . '<button type="submit" class="btn btn-ghost">' . icon('trash') . 'Discard</button></form>');
View::script('migration-select');

$file = $m['transfer_mode'] === 'file';
$whm = $m['source_type'] !== 'cpanel';
$showOwnerPlan = $whm && !$file;
$selectable = count($items) - count($conflicts);
$hasResellers = (bool) array_filter($items, fn($i) => (int) $i['is_reseller'] === 1);
$cols = 2 + ($showOwnerPlan ? 2 : 0) + ($whm ? 1 : 0);

$packageOptions = ['' => 'Choose a package'];
foreach ($packages as $p) {
    $packageOptions[(int) $p['id']] = $p['name'] . ' · ' . Quota::fmtMb($p['disk_quota_mb']) . ' · ' . (Quota::unlimited($p['max_domains']) ? 'unlimited domains' : Ui::plural((int) $p['max_domains'], 'domain'));
}
$owners = [];
if ($m['source_type'] === 'root') {
    $owners['recreate'] = 'Keep the cPanel reseller structure';
}
$owners['none'] = 'Owned by the administrator';
foreach ($resellers as $r) {
    $owners['reseller:' . (int) $r['id']] = 'Owned by reseller ' . $r['username'];
}
$ownerSelected = $m['source_type'] === 'root' && ($opt['owner'] === 'recreate' || ($opt['owner'] === 'none' && $hasResellers)) ? 'recreate' : (string) $opt['owner'];
?>
<?= migration_steps(2) ?>

<form method="post" action="/whm/migrations/<?= $id ?>/start" class="stack" id="migration-select">
    <?= Csrf::field() ?>

    <div class="card flex flex-wrap items-center gap-x-8 gap-y-3 px-5 py-4 text-sm">
        <?php if ($file): ?>
        <div class="min-w-0">
            <p class="text-xs text-muted">Source</p>
            <p class="mt-0.5 font-medium text-fg">cPanel backup files</p>
        </div>
        <div class="min-w-0">
            <p class="text-xs text-muted">Folder</p>
            <p class="mt-0.5"><?= Ui::copyable(MigrationService::importDir()) ?></p>
        </div>
        <?php else: ?>
        <div class="min-w-0">
            <p class="text-xs text-muted">Source</p>
            <p class="mt-0.5 break-all font-medium text-fg"><?= e($m['source_user']) ?>@<?= e($m['source_host']) ?>:<?= (int) $m['source_port'] ?></p>
        </div>
        <div>
            <p class="text-xs text-muted">Login</p>
            <p class="mt-0.5 text-fg"><?= e(migration_source_label((string) $m['source_type'])) ?></p>
        </div>
        <div>
            <p class="text-xs text-muted">Transfer</p>
            <p class="mt-0.5 text-fg"><?= $m['transfer_mode'] === 'push' ? 'Push - the source uploads over SFTP' : 'Pull - this server downloads' ?></p>
        </div>
        <?php endif; ?>
        <a href="/whm/migrations/create" class="link sm:ml-auto"><?= $file ? 'Choose another source' : 'Connect to a different server' ?></a>
    </div>

    <section class="card" aria-labelledby="accounts-title">
        <div class="card-header">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <h2 id="accounts-title" class="card-title"><?= e(Ui::plural(count($items), 'account')) ?> found</h2>
                <?php if ($conflicts): ?><?= Ui::badge(count($conflicts) . ' can\'t be migrated', 'warning') ?><?php endif; ?>
            </div>
            <?php if (count($items) > 8): ?>
            <input type="search" class="input input-sm w-full sm:w-56" placeholder="Filter accounts…" aria-label="Filter accounts" data-filter="#accounts-table">
            <?php endif; ?>
        </div>
        <div class="table-wrap max-h-[32rem] overflow-y-auto">
            <table class="table table-tight" id="accounts-table">
                <thead>
                    <tr>
                        <th scope="col" class="sticky top-0 z-[1] w-10 !pr-0">
                            <input type="checkbox" class="h-4 w-4 align-middle" data-select-all aria-label="Select all accounts" title="Select all" <?= $selectable ? '' : 'disabled' ?>>
                        </th>
                        <th scope="col" class="sticky top-0 z-[1]">Account</th>
                        <?php if ($showOwnerPlan): ?>
                        <th scope="col" class="sticky top-0 z-[1] hidden md:table-cell">Owner</th>
                        <th scope="col" class="sticky top-0 z-[1] hidden md:table-cell">Plan</th>
                        <?php endif; ?>
                        <?php if ($whm): ?><th scope="col" class="sticky top-0 z-[1] text-right"><?= $file ? 'Archive' : 'Disk' ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it):
                        $src = (json_decode((string) $it['report'], true) ?: [])['source'] ?? [];
                        $problems = $conflicts[(int) $it['id']] ?? [];
                        $cb = 'acct-' . (int) $it['id'];
                        $sub = $file ? (string) ($src['file'] ?? '') : (string) ($it['source_domain'] ?? '');
                    ?>
                    <tr>
                        <td class="w-10 !pr-0">
                            <input type="checkbox" name="accounts[]" value="<?= e($it['source_username']) ?>" id="<?= $cb ?>" class="h-4 w-4 align-middle" data-account data-mb="<?= (int) ($src['disk_used_mb'] ?? 0) ?>"
                                <?= $problems ? 'disabled' : (count($items) === 1 ? 'checked' : '') ?>>
                        </td>
                        <td class="max-w-[24rem]">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <label for="<?= $cb ?>" class="font-medium <?= $problems ? 'text-subtle' : 'cursor-pointer text-fg' ?>"><?= e($it['source_username']) ?></label>
                                <?php if ((int) $it['is_reseller'] === 1): ?><?= Ui::badge('Reseller', 'accent') ?><?php endif; ?>
                                <?php if (!empty($src['suspended'])): ?><?= Ui::badge('Suspended', 'warning') ?><?php endif; ?>
                            </div>
                            <?php if ($sub !== ''): ?><p class="mt-0.5 truncate text-xs text-muted <?= $file ? 'font-mono' : '' ?>"><?= e($sub) ?></p><?php endif; ?>
                            <?php if ($problems): ?>
                            <p class="mt-1 flex items-start gap-1.5 text-xs text-danger-text"><?= icon('alert', 'mt-px h-3.5 w-3.5 shrink-0') ?><span><?= e(ucfirst(implode('; ', $problems))) ?>.</span></p>
                            <?php endif; ?>
                        </td>
                        <?php if ($showOwnerPlan): ?>
                        <td class="hidden text-muted md:table-cell"><?= e($it['source_owner'] ?? '') ?></td>
                        <td class="hidden text-muted md:table-cell"><?= e($it['source_plan'] ?? '') ?></td>
                        <?php endif; ?>
                        <?php if ($whm): ?>
                        <td class="whitespace-nowrap text-right tabular-nums text-muted"><?= isset($src['disk_used_mb']) ? e(fmt_bytes((int) $src['disk_used_mb'] * 1048576)) : '' ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$items): ?>
                    <?= Ui::emptyRow($cols, 'No accounts', 'This source offered no accounts to migrate. Connect with a different login.', 'users') ?>
                    <?php elseif (count($items) > 8): ?>
                    <tr data-filter-none data-filter-skip hidden><td colspan="<?= $cols ?>" class="py-8 text-center text-sm text-muted">No account matches that filter.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
        <section class="card" aria-labelledby="what-title">
            <div class="card-header"><h2 id="what-title" class="card-title">What to migrate</h2></div>
            <div class="card-body space-y-4">
                <?= Ui::checkbox('files', 'Domains and website files', (bool) $opt['files'], ['hint' => 'Main, addon and subdomains, each with its document root.']) ?>
                <?= Ui::checkbox('databases', 'MySQL databases and users', (bool) $opt['databases'], ['hint' => 'Same names, so site config files keep working; passwords kept where MariaDB can use them.']) ?>
                <?= Ui::checkbox('email_accounts', 'Email accounts', (bool) $opt['email_accounts'], ['attrs' => ['data-email-accounts' => true]]) ?>
                <?= Ui::checkbox('email_data', 'Stored email', (bool) $opt['email_data'], ['wrap' => 'pl-6', 'hint' => 'Every folder and message, with read/flagged state.', 'attrs' => ['data-email-data' => true]]) ?>
                <div class="space-y-4 border-t border-line pt-4">
                    <?= Ui::select('mail_passwords', 'Email passwords', [
                        'preserve' => 'Keep the existing passwords',
                        'generate' => 'Generate new ones (shown in the report)',
                    ], $opt['mail_passwords'] === 'generate' ? 'generate' : 'preserve') ?>
                    <?= Ui::select('catch_all', 'Default addresses (catch-all)', [
                        'reject' => 'Reject mail to unknown addresses (recommended)',
                        'keep' => 'Keep them as on cPanel',
                    ], ($opt['catch_all'] ?? 'reject') === 'keep' ? 'keep' : 'reject') ?>
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="here-title">
            <div class="card-header"><h2 id="here-title" class="card-title">Accounts on this server</h2></div>
            <div class="card-body space-y-4">
                <?= Ui::checkbox('match_packages', 'Use the package named like the cPanel plan, when there is one', (bool) $opt['match_packages']) ?>
                <?= Ui::select('package_id', 'Otherwise, use package', $packageOptions, $opt['package_id'] !== null ? (string) (int) $opt['package_id'] : '', [
                    'required' => true,
                    'hint' => 'Every hosting account needs a package: it sets its limits (disk, bandwidth, domains, databases, mailboxes).',
                ]) ?>
                <?php if ($me['role'] === 'admin'): ?>
                <?= Ui::select('owner', 'Owner', $owners, $ownerSelected, $m['source_type'] === 'root' ? [
                    'hint' => 'Keeping the structure turns each selected cPanel reseller into a JinnPanel reseller (WHM login <name>_whm, same password) that owns its customers. Select the reseller accounts too.',
                ] : []) ?>
                <?php else: ?>
                <p class="text-sm text-muted">Migrated accounts will be owned by you.</p>
                <?php endif; ?>
                <?= Ui::select('ssl_mode', 'HTTPS certificates', [
                    'auto' => 'Automatic - Let\'s Encrypt once each domain points here',
                    'letsencrypt' => 'Let\'s Encrypt for all (DNS already points here)',
                    'self_signed' => 'Self-signed only',
                ], in_array($opt['ssl_mode'], ['letsencrypt', 'self_signed'], true) ? (string) $opt['ssl_mode'] : 'auto') ?>
            </div>
        </section>
    </div>

    <details class="card disclosure" <?= $m['transfer_mode'] === 'push' ? 'open' : '' ?>>
        <summary class="px-5 py-4">Transfer settings</summary>
        <div class="grid grid-cols-1 gap-4 border-t border-line px-5 py-5 sm:grid-cols-2">
            <?php if ($m['transfer_mode'] === 'push'): ?>
            <?= Ui::field('public_host', 'This server\'s address as the source sees it', ['value' => (string) $opt['public_host'], 'mono' => true, 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
            <?= Ui::field('public_port', 'SFTP port', ['type' => 'number', 'value' => (string) (int) $opt['public_port'], 'attrs' => ['min' => 1, 'max' => 65535]]) ?>
            <?= Ui::field('first_byte_minutes', 'Give up if no backup arrives within', ['type' => 'number', 'value' => (string) (int) $opt['first_byte_minutes'], 'hint' => 'Minutes, 10 to 720.', 'attrs' => ['min' => 10, 'max' => 720]]) ?>
            <?php endif; ?>
            <?= Ui::field('timeout_hours', 'Maximum time per account', ['type' => 'number', 'value' => (string) (int) $opt['timeout_hours'], 'hint' => 'Hours, 1 to 72.', 'attrs' => ['min' => 1, 'max' => 72]]) ?>
        </div>
    </details>

    <div class="sticky bottom-4 z-10 flex flex-wrap items-center gap-x-4 gap-y-3 rounded-xl border border-line bg-surface px-5 py-3 shadow-pop">
        <p class="text-sm text-muted" aria-live="polite"><span class="font-semibold tabular-nums text-fg" data-selected-count>0</span> selected<span class="tabular-nums" data-selected-size></span></p>
        <p class="hidden flex-1 text-xs text-subtle md:block">Accounts are migrated one after another in the background. The source is only read from<?= $file ? '' : ' (in pull mode a backup file is left in each account\'s home directory)' ?>.</p>
        <button type="submit" class="btn btn-primary ml-auto" data-start disabled><?= icon('play') ?>Start migration</button>
    </div>
</form>
