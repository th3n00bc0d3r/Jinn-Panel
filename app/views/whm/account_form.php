<?php
/** @var array<string,mixed>|null $account null = create */
/** @var list<array<string,mixed>> $packages */
/** @var list<string> $roles roles that may be chosen */
/** @var bool $welcome whether welcome emails are on (WHM > Notifications) */
$edit = $account !== null;
if ($edit) {
    View::breadcrumbs([['Accounts', '/whm/accounts'], [(string) $account['username'], '/whm/accounts/' . (int) $account['id']], ['Edit']]);
} else {
    View::breadcrumbs([['Accounts', '/whm/accounts'], ['Create account']]);
    View::slot('desc', e('A hosting account gets a Linux user, its own PHP and a panel at <domain>/jpanel. A reseller gets WHM to create accounts of its own.'));
}
$pkgOptions = ['' => $edit && $account['role'] === 'reseller' ? 'No package' : 'Choose a package…'];
foreach ($packages as $p) {
    $pkgOptions[(string) $p['id']] = $p['name'] . ' — ' . Quota::fmtMb($p['disk_quota_mb']) . ', ' . Quota::fmtCount($p['max_domains']) . ' domain' . ((int) $p['max_domains'] === 1 ? '' : 's');
}
$roleText = [
    'user' => ['Hosting account', 'Websites, mail and databases for a customer.'],
    'reseller' => ['Reseller', 'Creates and manages its own hosting accounts in WHM.'],
    'admin' => ['Administrator', 'Full control of the server. Give this only to people you trust with root.'],
];
$curRole = Ui::old('role') ?? ($edit ? (string) $account['role'] : 'user');
?>
<form method="post" action="<?= $edit ? '/whm/accounts/' . (int) $account['id'] : '/whm/accounts' ?>" class="max-w-3xl">
    <?= Csrf::field() ?>
    <div class="card">
        <?php if (!$edit && count($roles) > 1): ?>
        <fieldset class="card-body border-b border-line">
            <legend class="label float-left w-full">Account type</legend>
            <div class="grid gap-3 sm:grid-cols-3">
                <?php foreach ($roles as $r): ?>
                <label class="flex cursor-pointer gap-3 rounded-lg border border-line p-3 text-sm transition-colors hover:bg-surface-2 has-[:checked]:border-accent has-[:checked]:bg-accent-soft">
                    <input type="radio" name="role" value="<?= e($r) ?>" class="mt-0.5" <?= $curRole === $r ? 'checked' : '' ?>>
                    <span><span class="block font-medium text-fg"><?= e($roleText[$r][0]) ?></span><span class="mt-0.5 block text-xs text-muted"><?= e($roleText[$r][1]) ?></span></span>
                </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php elseif (!$edit): ?>
        <input type="hidden" name="role" value="user">
        <?php endif; ?>

        <div class="card-body grid gap-5 sm:grid-cols-2">
            <?php if ($edit): ?>
            <div class="field">
                <p class="label">Username</p>
                <p class="flex h-[38px] items-center gap-2 text-sm"><code><?= e($account['username']) ?></code> <?= Ui::badge($roleText[$account['role']][0] ?? $account['role'], 'neutral') ?></p>
                <p class="hint">Usernames can't be changed: they name the account's Linux user, folders and databases.</p>
            </div>
            <?php else: ?>
            <?= Ui::field('username', 'Username', [
                'required' => true,
                'mono' => true,
                'hint' => 'Lowercase letters and digits, 3-16 characters, starting with a letter (resellers and admins: up to 32, underscores allowed). It can\'t be changed later.',
                'attrs' => ['pattern' => '[A-Za-z][A-Za-z0-9_]{2,31}', 'autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false'],
            ]) ?>
            <?php endif; ?>
            <?= Ui::field('full_name', 'Full name or company', ['optional' => true, 'value' => $edit ? (string) ($account['full_name'] ?? '') : null]) ?>
            <?= Ui::field('email', 'Contact email', [
                'type' => 'email',
                'required' => true,
                'value' => $edit ? (string) $account['email'] : null,
                'hint' => 'For the welcome email and password resets. It can also be used to sign in.',
            ]) ?>
            <?= Ui::field('password', $edit ? 'New password' : 'Password', [
                'type' => 'password',
                'required' => !$edit,
                'optional' => $edit,
                'generate' => true,
                'hint' => $edit ? 'Leave empty to keep the current one. Setting one signs the account out everywhere.' : 'At least ' . Passwords::MIN_LENGTH . ' characters, not a common password.',
                'attrs' => ['minlength' => Passwords::MIN_LENGTH, 'autocomplete' => 'new-password'],
            ]) ?>
            <?php if (!$edit || $account['role'] !== 'admin'): ?>
            <div class="sm:col-span-2">
                <?= Ui::select('package_id', 'Package', $pkgOptions, $edit ? (string) ($account['package_id'] ?? '') : null, [
                    'hint' => $edit
                        ? 'The package sets the account\'s limits. A smaller one doesn\'t delete anything; the account just can\'t add more until it\'s under the limits.'
                        : 'Hosting accounts need one; for resellers it sets how many accounts they may create. Administrators don\'t use packages.',
                ]) ?>
                <?php if (!$packages): ?><p class="hint"><a href="/whm/packages/create" class="link">Create a package</a> first.</p><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!$edit): ?>
        <div class="card-body border-t border-line">
            <?= Ui::checkbox('send_welcome', 'Send a welcome email', $welcome, [
                'hint' => $welcome ? 'With where to sign in, the username and a link to choose their own password. The password above is never emailed.' : 'Welcome emails are off in WHM > Notifications.',
                'attrs' => $welcome ? [] : ['disabled' => true],
            ]) ?>
        </div>
        <?php endif; ?>

        <div class="card-footer">
            <a href="<?= $edit ? '/whm/accounts/' . (int) $account['id'] : '/whm/accounts' ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary"><?= icon($edit ? 'save' : 'user-plus') ?><?= $edit ? 'Save changes' : 'Create account' ?></button>
        </div>
    </div>
</form>
