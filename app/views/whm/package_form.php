<?php
/** @var array<string,mixed>|null $pkg null = create */
/** @var int $accounts accounts using it */
$edit = $pkg !== null;
View::breadcrumbs([['Packages', '/whm/packages'], [$edit ? (string) $pkg['name'] : 'Create package']]);
View::slot('desc', e($edit
    ? 'Changes apply to every account on this package. Lowering a limit deletes nothing; accounts over it just can\'t add more.'
    : 'A set of limits for hosting accounts. Disk and bandwidth are enforced; counts limit what can be created.'));
View::script('package-form');
$isUnl = fn(string $k) => $edit && Quota::unlimited($pkg[$k]);
$v = fn(string $k, int $default) => $edit ? ($isUnl($k) ? '' : (string) (int) $pkg[$k]) : (string) $default;
$num = fn(int $min) => ['min' => $min, 'inputmode' => 'numeric'];
/** A limit: the number, and an "Unlimited" box under it (the number is ignored when ticked). */
$limit = function (string $col, string $label, int $default, int $min, array $o = []) use ($v, $isUnl, $num): string {
    $attrs = $num($min) + ['data-limit' => $col] + ($isUnl($col) ? ['placeholder' => 'Unlimited'] : []);
    return '<div class="' . e($o['wrap'] ?? '') . '">'
        . Ui::field($col, $label, ['type' => 'number', 'value' => $v($col, $default), 'class' => 'tabular-nums', 'attrs' => $attrs] + array_intersect_key($o, ['suffix' => 1, 'hint' => 1]))
        . Ui::checkbox('unlimited[' . $col . ']', 'Unlimited', $isUnl($col), ['wrap' => 'mt-2 text-muted', 'attrs' => ['data-unlimited' => $col]])
        . '</div>';
};
?>
<form method="post" action="<?= $edit ? '/whm/packages/' . (int) $pkg['id'] : '/whm/packages' ?>" class="max-w-3xl">
    <?= Csrf::field() ?>
    <div class="card">
        <?php if ($edit && $accounts > 0): ?>
        <div class="card-body border-b border-line pb-4"><?= Ui::alert('Used by ' . Ui::plural($accounts, 'account') . '. Saving changes their limits right away (hard disk quotas within the hour).', 'info') ?></div>
        <?php endif; ?>
        <div class="card-body space-y-6">
            <?= Ui::field('name', 'Package name', ['required' => true, 'value' => $edit ? (string) $pkg['name'] : null, 'placeholder' => 'Business', 'wrap' => 'max-w-sm']) ?>

            <div>
                <h2 class="section-title mb-3">Resources</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <?= $limit('disk_quota_mb', 'Disk space', 1024, 1, ['suffix' => 'MB', 'hint' => 'Files, databases and mail together. 1024 MB = 1 GB.']) ?>
                    <?= $limit('bandwidth_mb', 'Bandwidth per month', 10240, 1, ['suffix' => 'MB', 'hint' => 'Over it, the account\'s sites answer 509 until next month.']) ?>
                </div>
            </div>

            <div>
                <h2 class="section-title mb-3">Limits</h2>
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <?= $limit('max_domains', 'Domains', 1, 1) ?>
                    <?= $limit('max_databases', 'Databases', 1, 1) ?>
                    <?= $limit('max_email_accounts', 'Mailboxes', 5, 0) ?>
                    <?= $limit('max_ftp_accounts', 'SFTP logins', 1, 0) ?>
                </div>
            </div>

            <fieldset>
                <legend class="section-title float-left mb-1 w-full">Features</legend>
                <p class="mb-4 text-sm text-muted">What accounts on this package may use. Each account can be switched differently on its own page in Accounts.</p>
                <?php $pf = Features::forPackage($pkg); ?>
                <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <?php foreach (Features::LIST as $key => [$label, $what]): ?>
                    <?= Ui::checkbox('features[' . $key . ']', $label, $pf[$key], ['hint' => $what]) ?>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <?php if (Auth::isAdmin()): ?>
            <div>
                <h2 class="section-title mb-3">Resellers</h2>
                <?= $limit('max_accounts', 'Accounts a reseller on this package may create', 0, 0, ['hint' => '0 = none. For a reseller, this package\'s disk, bandwidth and domains are also what its customers\' packages may add up to.', 'wrap' => 'max-w-sm']) ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-footer">
            <a href="/whm/packages" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary"><?= icon($edit ? 'save' : 'plus') ?><?= $edit ? 'Save package' : 'Create package' ?></button>
        </div>
    </div>
</form>
