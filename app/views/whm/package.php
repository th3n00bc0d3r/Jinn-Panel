<?php
/** @var array<string,mixed> $pkg with owner_username */
/** @var list<array<string,mixed>> $accounts the accounts on it the viewer manages, with usage */
/** @var int $total accounts on it in all */
/** @var bool $editable */
/** @var list<array<string,mixed>> $others packages the accounts could move to */
$id = (int) $pkg['id'];
View::breadcrumbs([['Packages', '/whm/packages'], [(string) $pkg['name']]]);
View::slot('desc', e(($pkg['owner_id'] ? 'Reseller package of ' . $pkg['owner_username'] : 'Global package') . ' · used by ' . Ui::plural($total, 'account') . '.'));
View::slot('actions',
    '<form method="post" action="/whm/packages/' . $id . '/duplicate">' . Csrf::field() . '<button class="btn btn-secondary">' . icon('copy') . 'Duplicate</button></form>'
    . ($editable ? '<a href="/whm/packages/' . $id . '/edit" class="btn btn-primary">' . icon('edit') . 'Edit</a>' : ''));
$features = Features::forPackage($pkg);
$limits = [
    ['Disk', Quota::fmtMb($pkg['disk_quota_mb'])],
    ['Bandwidth / month', Quota::fmtMb($pkg['bandwidth_mb'])],
    ['Domains', Quota::fmtCount($pkg['max_domains'])],
    ['Databases', Quota::fmtCount($pkg['max_databases'])],
    ['Mailboxes', Quota::fmtCount($pkg['max_email_accounts'])],
    ['SFTP logins', Quota::fmtCount($pkg['max_ftp_accounts'])],
];
if ((int) $pkg['max_accounts'] !== 0) {
    $limits[] = ['Accounts (reseller)', Quota::fmtCount($pkg['max_accounts'])];
}
$disk = Quota::bytes($pkg['disk_quota_mb']);
$bwLimit = Quota::bytes($pkg['bandwidth_mb']);
$sumDisk = 0;
$sumBw = 0;
$month = date('Y-m');
foreach ($accounts as $a) {
    $sumDisk += (int) $a['used_bytes'];
    $sumBw += ($a['bw_month'] ?? '') === $month ? (int) $a['bw_bytes'] : 0;
}
?>
<div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
    <div class="stack xl:col-span-2">
        <section class="card" aria-labelledby="pkg-accounts">
            <?= Ui::cardHeader(Ui::plural(count($accounts), 'account') . ' on this package', count($accounts) < $total ? 'Only the ones you manage are listed.' : '', '', id: 'pkg-accounts') ?>
            <?php if (!$accounts): ?>
            <?= Ui::empty('No accounts on it', 'Give it to an account when creating or editing the account, or move accounts here from another package.', 'users') ?>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Account</th>
                            <th scope="col" class="hidden sm:table-cell">Disk</th>
                            <th scope="col" class="hidden md:table-cell">Bandwidth this month</th>
                            <th scope="col" class="hidden md:table-cell">Domains</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($accounts as $a):
                        $used = (int) $a['used_bytes'];
                        $bw = ($a['bw_month'] ?? '') === $month ? (int) $a['bw_bytes'] : 0; ?>
                    <tr>
                        <td>
                            <a href="/whm/accounts/<?= (int) $a['id'] ?>" class="font-medium text-fg hover:text-accent-text"><?= e($a['username']) ?></a>
                            <p class="text-xs text-muted"><?= e($a['email']) ?><?= $a['reseller_username'] ? ' · ' . e($a['reseller_username']) : '' ?></p>
                        </td>
                        <td class="hidden min-w-[9rem] sm:table-cell">
                            <?php if ($a['role'] === 'user'): ?>
                            <p class="mb-1 text-xs tabular-nums text-muted"><?= e(fmt_bytes($used)) ?><?= $disk ? ' of ' . e(fmt_bytes($disk)) : '' ?></p>
                            <?= $disk ? Ui::progress($used / $disk * 100, 'Disk of ' . $a['username'], Ui::usageTone($used / $disk * 100, (bool) $a['over_disk'])) : '' ?>
                            <?php else: ?><span class="text-subtle">—</span><?php endif; ?>
                        </td>
                        <td class="hidden min-w-[9rem] md:table-cell">
                            <?php if ($a['role'] === 'user'): ?>
                            <p class="mb-1 text-xs tabular-nums text-muted"><?= e(fmt_bytes($bw)) ?><?= $bwLimit ? ' of ' . e(fmt_bytes($bwLimit)) : '' ?></p>
                            <?= $bwLimit ? Ui::progress($bw / $bwLimit * 100, 'Bandwidth of ' . $a['username'], Ui::usageTone($bw / $bwLimit * 100, (bool) $a['over_bandwidth'])) : '' ?>
                            <?php else: ?><span class="text-subtle">—</span><?php endif; ?>
                        </td>
                        <td class="hidden tabular-nums text-muted md:table-cell"><?= (int) $a['domain_count'] ?></td>
                        <td><?= Ui::status((string) $a['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer justify-start gap-6 text-sm text-muted">
                <span>Total disk <strong class="font-medium tabular-nums text-fg"><?= e(fmt_bytes($sumDisk)) ?></strong></span>
                <span>Total bandwidth this month <strong class="font-medium tabular-nums text-fg"><?= e(fmt_bytes($sumBw)) ?></strong></span>
            </div>
            <?php endif; ?>
        </section>

        <?php if ($accounts && $others): ?>
        <form method="post" action="/whm/packages/<?= $id ?>/move" class="card" data-confirm="Move <?= e(Ui::plural(count($accounts), 'account')) ?> to the package you chose? Their limits and features change right away." data-confirm-action="Move accounts">
            <?= Csrf::field() ?>
            <?= Ui::cardHeader('Move these accounts', 'To another package, all at once - e.g. before deleting this one.') ?>
            <div class="card-body flex flex-wrap items-end gap-3">
                <?php
                $opts = [];
                foreach ($others as $o) {
                    $opts[(string) $o['id']] = $o['name'] . ' — ' . Quota::fmtMb($o['disk_quota_mb']) . ', ' . Quota::fmtCount($o['max_domains']) . ' domains';
                }
                echo Ui::select('package_id', 'Move to', $opts, null, ['wrap' => 'min-w-[16rem] flex-1']);
                ?>
                <button class="btn btn-secondary"><?= icon('transfer') ?>Move <?= e(Ui::plural(count($accounts), 'account')) ?></button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div class="stack">
        <section class="card" aria-labelledby="pkg-limits">
            <?= Ui::cardHeader('Limits', '', '', id: 'pkg-limits') ?>
            <dl class="dl card-body">
                <?php foreach ($limits as [$k, $val]): ?>
                <div><dt><?= e($k) ?></dt><dd class="font-medium tabular-nums"><?= $val === 'Unlimited' ? Ui::badge('Unlimited', 'accent') : e($val) ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </section>
        <section class="card" aria-labelledby="pkg-features">
            <?= Ui::cardHeader('Features', '', '', id: 'pkg-features') ?>
            <ul class="divide-y divide-line">
                <?php foreach (Features::LIST as $key => [$label]): ?>
                <li class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                    <span class="<?= $features[$key] ? 'text-fg' : 'text-muted' ?>"><?= e($label) ?></span>
                    <?= $features[$key] ? Ui::badge('Included', 'success', true) : Ui::badge('Off', 'neutral') ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</div>
