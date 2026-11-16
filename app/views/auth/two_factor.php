<?php
/** @var string|null $error */
/** @var bool $recovery show the recovery-code form first (its error is about a recovery code) */
$recovery ??= false;
View::slot('title', 'Two-factor sign-in');
View::slot('brand_note', e('Lost your phone and your recovery codes? Your hosting provider can turn two-factor off for you.'));
View::script('two-factor');
?>
<h1 class="auth-title">Two-factor sign-in</h1>

<form method="post" action="/login/2fa" class="mt-1.5<?= $recovery ? ' js-hide' : '' ?>" data-tf="code">
    <?= Csrf::field() ?>
    <p class="text-[14px] text-muted">Enter the 6-digit code from your authenticator app.</p>
    <?php if (!empty($error) && !$recovery): ?>
    <div class="mt-6"><?= Ui::alert($error, 'danger') ?></div>
    <?php endif; ?>
    <div class="field mt-8">
        <label for="tf-code" class="label">Authentication code</label>
        <?php /* Six boxes with JavaScript (pages/two-factor.js); this input gets their digits. */ ?>
        <input type="text" name="code" id="tf-code" class="input h-12 text-center font-mono text-lg tracking-[0.4em]" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7"<?= $recovery ? '' : ' required autofocus' ?><?= !empty($error) && !$recovery ? ' aria-invalid="true"' : '' ?> data-otp>
    </div>
    <button type="submit" class="auth-submit mt-5">Verify</button>
</form>

<?php /* Without JavaScript both forms show, one under the other. */ ?>
<form method="post" action="/login/2fa" class="mt-12 [.js_&]:mt-1.5<?= $recovery ? '' : ' js-hide' ?>" data-tf="recovery">
    <?= Csrf::field() ?>
    <p class="text-[14px] text-muted">Lost your phone? Enter one of the recovery codes you saved when you turned on two-factor. Each works once.</p>
    <?php if (!empty($error) && $recovery): ?>
    <div class="mt-6"><?= Ui::alert($error, 'danger') ?></div>
    <?php endif; ?>
    <div class="mt-8">
        <?= Ui::field('recovery_code', 'Recovery code', ['required' => $recovery, 'mono' => true, 'placeholder' => 'xxxx-xxxx-xxxx', 'class' => 'h-12 text-center tracking-wider', 'attrs' => ['autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false', 'autofocus' => $recovery]]) ?>
    </div>
    <button type="submit" class="auth-submit mt-5">Sign in with recovery code</button>
</form>

<div class="mt-8 flex items-center justify-between gap-4 text-[13px]">
    <a href="/login" class="link-muted inline-flex items-center gap-1.5"><?= icon('arrow-left', 'h-3.5 w-3.5') ?>Start over</a>
    <button type="button" class="link hidden [.js_&]:inline" data-tf-switch><?= $recovery ? 'Use the authenticator app' : 'Use a recovery code' ?></button>
</div>
<noscript><p class="mt-4 text-[12.5px] text-muted">Without JavaScript both forms are shown: use either.</p></noscript>
