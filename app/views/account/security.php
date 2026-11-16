<?php
/** @var array<string,mixed> $me */
/** @var array{secret:string,uri:string,qr:?string}|null $setup two-factor setup in progress */
/** @var string $base /whm/security or /cpanel/security */
/** @var list<string>|null $codes new recovery codes, shown once */
/** @var int $codesLeft */
$on = !empty($me['totp_secret_enc']);
View::slot('desc', e('Your password and two-factor sign-in for ' . $me['username'] . '.'));
?>
<div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
    <?php if ($codes): ?>
    <section class="card border-warning/40 lg:col-span-2" aria-labelledby="codes-title">
        <div class="card-header">
            <div>
                <h2 id="codes-title" class="card-title">Save your recovery codes now</h2>
                <p class="card-desc">If you lose your phone, each code signs you in once instead of an authenticator code. They are shown only this time.</p>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" data-copy="<?= e(implode("\n", $codes)) ?>"><?= icon('copy') ?>Copy all</button>
        </div>
        <div class="card-body">
            <ol class="grid grid-cols-2 gap-x-6 gap-y-2 font-mono text-sm sm:grid-cols-5">
                <?php foreach ($codes as $c): ?><li class="rounded-md bg-surface-3 px-2.5 py-1.5 text-center text-fg"><?= e($c) ?></li><?php endforeach; ?>
            </ol>
            <p class="mt-4 text-sm text-muted">Keep them in a password manager or print them. Anyone with a code and your password can sign in.</p>
        </div>
    </section>
    <?php endif; ?>

    <form method="post" action="<?= e($base) ?>/password" class="card">
        <?= Csrf::field() ?>
        <?= Ui::cardHeader('Password', 'Changing it signs out your other sessions.') ?>
        <div class="card-body space-y-4">
            <input type="text" name="username" value="<?= e($me['username']) ?>" autocomplete="username" class="sr-only" tabindex="-1" aria-label="Username" readonly>
            <?= Ui::field('current_password', 'Current password', ['type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'current-password']]) ?>
            <?= Ui::field('new_password', 'New password', ['type' => 'password', 'required' => true, 'generate' => true, 'hint' => 'At least ' . Passwords::MIN_LENGTH . ' characters, not a common password.', 'attrs' => ['minlength' => Passwords::MIN_LENGTH, 'autocomplete' => 'new-password']]) ?>
            <?= Ui::field('new_password_confirm', 'New password again', ['type' => 'password', 'required' => true, 'attrs' => ['minlength' => Passwords::MIN_LENGTH, 'autocomplete' => 'new-password']]) ?>
        </div>
        <div class="card-footer"><button class="btn btn-primary"><?= icon('save') ?>Change password</button></div>
    </form>

    <section class="card" aria-labelledby="tf-title">
        <div class="card-header">
            <div>
                <h2 id="tf-title" class="card-title">Two-factor sign-in</h2>
                <p class="card-desc">A 6-digit code from an authenticator app at every sign-in, on top of your password.</p>
            </div>
            <?= Ui::status($on ? 'on' : 'off', $on ? 'On' : 'Off') ?>
        </div>

        <?php if ($on): ?>
        <div class="card-body space-y-5">
            <div class="flex items-start justify-between gap-4">
                <div class="text-sm">
                    <p class="font-medium text-fg">Recovery codes</p>
                    <p class="mt-0.5 <?= $codesLeft <= 2 ? 'text-warning-text' : 'text-muted' ?>"><?= $codesLeft === 0 ? 'None left - make new ones.' : e(Ui::plural($codesLeft, 'unused code')) . ' left.' ?></p>
                </div>
            </div>
            <form method="post" action="<?= e($base) ?>/2fa/recovery" class="flex flex-wrap items-end gap-3" data-confirm="Make new recovery codes? The old ones stop working." data-confirm-action="Make new codes">
                <?= Csrf::field() ?>
                <?= Ui::field('current_password', 'Your password', ['type' => 'password', 'required' => true, 'wrap' => 'min-w-[12rem] flex-1', 'attrs' => ['autocomplete' => 'current-password']]) ?>
                <button class="btn btn-secondary"><?= icon('refresh') ?>New recovery codes</button>
            </form>
            <form method="post" action="<?= e($base) ?>/2fa/disable" class="flex flex-wrap items-end gap-3 border-t border-line pt-5" data-confirm="Turn off two-factor sign-in? Your password alone will sign you in." data-confirm-action="Turn off" data-confirm-danger>
                <?= Csrf::field() ?>
                <?= Ui::field('current_password', 'Your password, to turn it off', ['type' => 'password', 'required' => true, 'wrap' => 'min-w-[12rem] flex-1', 'attrs' => ['autocomplete' => 'current-password']]) ?>
                <button class="btn btn-danger-ghost border border-danger/30">Turn off</button>
            </form>
        </div>
        <?php elseif ($setup !== null): ?>
        <div class="card-body">
            <ol class="space-y-6 text-sm">
                <li class="flex gap-4">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent-text">1</span>
                    <div class="min-w-0">
                        <p class="font-medium text-fg">Scan this code with your authenticator app</p>
                        <p class="mt-0.5 text-muted">Google Authenticator, Microsoft Authenticator, 1Password, Aegis…</p>
                        <?php if ($setup['qr'] !== null): ?>
                        <div class="mt-3 h-44 w-44 rounded-lg border border-line bg-white p-2 [&>svg]:h-full [&>svg]:w-full"><?= $setup['qr'] ?></div>
                        <?php endif; ?>
                        <p class="mt-3 text-muted">Can't scan? Enter this key:</p>
                        <p class="mt-1"><?= Ui::copyable(trim(chunk_split($setup['secret'], 4, ' '))) ?></p>
                        <p class="mt-2"><a href="<?= e($setup['uri']) ?>" class="link text-xs">Open in an authenticator app on this device</a></p>
                    </div>
                </li>
                <li class="flex gap-4">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent-text">2</span>
                    <form method="post" action="<?= e($base) ?>/2fa/confirm" class="min-w-0 flex-1">
                        <?= Csrf::field() ?>
                        <?= Ui::field('code', 'Enter the code it shows', ['required' => true, 'class' => 'max-w-[11rem] text-center font-mono tracking-[0.3em]', 'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'pattern' => '[0-9 ]{6,7}', 'maxlength' => 7]]) ?>
                        <button class="btn btn-primary mt-3"><?= icon('check') ?>Turn on</button>
                    </form>
                </li>
            </ol>
        </div>
        <?php else: ?>
        <div class="card-body text-sm text-muted">Even if someone learns your password, they can't sign in without your phone. You'll also get recovery codes for when the phone is lost.</div>
        <form method="post" action="<?= e($base) ?>/2fa/start" class="card-footer">
            <?= Csrf::field() ?>
            <button class="btn btn-primary"><?= icon('shield') ?>Set up two-factor sign-in</button>
        </form>
        <?php endif; ?>
    </section>
</div>
