<?php
/** @var list<array<string,mixed>> $packages with owner_username, account_count */
View::slot('desc', 'Limits that hosting accounts are created with. Changing a package changes the limits of every account on it.');
View::slot('actions', '<a href="/whm/packages/create" class="btn btn-primary">' . icon('plus') . 'Create package</a>');

?>
<?php if (!$packages): ?>
<div class="card"><?= Ui::empty('No packages yet', 'Create a package before creating hosting accounts: every account takes its limits from one.', 'box', '<a href="/whm/packages/create" class="btn btn-primary">' . icon('plus') . 'Create package</a>') ?></div>
<?php else: ?>
<div class="grid-cards">
    <?php foreach ($packages as $p): $n = (int) $p['account_count']; $id = (int) $p['id']; ?>
    <article class="card flex flex-col">
        <div class="flex items-start justify-between gap-3 px-5 pt-5">
            <div class="min-w-0">
                <h2 class="truncate text-base font-semibold"><a href="/whm/packages/<?= $id ?>" class="text-fg hover:text-accent-text"><?= e($p['name']) ?></a></h2>
                <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-muted">
                    <?= $p['owner_id'] ? Ui::badge('Reseller · ' . ($p['owner_username'] ?? ''), 'neutral') : Ui::badge('Global', 'accent') ?>
                    <span><?= e(Ui::plural($n, 'account')) ?></span>
                </p>
            </div>
        </div>
        <dl class="grid flex-1 grid-cols-2 content-start gap-x-4 gap-y-4 px-5 py-5 text-sm">
            <div><dt class="text-xs text-muted">Disk</dt><dd class="mt-0.5 font-medium tabular-nums text-fg"><?= e(Quota::fmtMb($p['disk_quota_mb'])) ?></dd></div>
            <div><dt class="text-xs text-muted">Bandwidth / month</dt><dd class="mt-0.5 font-medium tabular-nums text-fg"><?= e(Quota::fmtMb($p['bandwidth_mb'])) ?></dd></div>
            <div><dt class="text-xs text-muted">Domains</dt><dd class="mt-0.5 font-medium tabular-nums text-fg"><?= e(Quota::fmtCount($p['max_domains'])) ?></dd></div>
            <div><dt class="text-xs text-muted">Databases</dt><dd class="mt-0.5 font-medium tabular-nums text-fg"><?= e(Quota::fmtCount($p['max_databases'])) ?></dd></div>
            <div><dt class="text-xs text-muted">Mailboxes</dt><dd class="mt-0.5 font-medium tabular-nums text-fg"><?= e(Quota::fmtCount($p['max_email_accounts'])) ?></dd></div>
            <div><dt class="text-xs text-muted">SFTP accounts</dt><dd class="mt-0.5 font-medium tabular-nums text-fg"><?= e(Quota::fmtCount($p['max_ftp_accounts'])) ?></dd></div>
            <?php if ((int) ($p['max_accounts'] ?? 0) !== 0): ?>
            <div><dt class="text-xs text-muted">Accounts (reseller)</dt><dd class="mt-0.5 font-medium tabular-nums text-fg"><?= e(Quota::fmtCount($p['max_accounts'])) ?></dd></div>
            <?php endif; ?>
        </dl>
        <?php $pf = Features::forPackage($p); $off = array_keys(array_filter($pf, fn($on) => !$on)); ?>
        <div class="border-t border-line px-5 py-3 text-sm">
            <?php if (!$off): ?>
            <p class="flex items-center gap-2 text-muted"><?= icon('check-circle', 'h-4 w-4 text-success') ?>All <?= count($pf) ?> features</p>
            <?php else: ?>
            <p class="text-muted"><?= count($pf) - count($off) ?> of <?= count($pf) ?> features · without:</p>
            <p class="mt-1.5 flex flex-wrap gap-1.5"><?php foreach ($off as $k): ?><?= Ui::badge(Features::LIST[$k][0], 'neutral') ?><?php endforeach; ?></p>
            <?php endif; ?>
        </div>
        <div class="card-footer justify-between">
            <div class="flex gap-2">
                <a href="/whm/packages/<?= $id ?>" class="btn btn-secondary btn-sm">Open</a>
                <?php if (Auth::isAdmin() || (int) $p['owner_id'] === (int) Auth::id()): ?><a href="/whm/packages/<?= $id ?>/edit" class="btn btn-ghost btn-sm"><?= icon('edit') ?>Edit</a><?php endif; ?>
            </div>
            <?php if ($n === 0): ?>
            <?= Ui::deleteForm("/whm/packages/$id/delete", 'Delete the package "' . $p['name'] . '"? This can\'t be undone.', 'Delete package') ?>
            <?php else: ?>
            <span class="text-xs text-subtle" title="A package can be deleted once no account uses it">In use</span>
            <?php endif; ?>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
