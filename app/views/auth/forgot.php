<?php
/** @var bool $sent */
/** @var string|null $error */
?>
<?php if ($sent): ?>
<div class="auth-icon bg-success-soft text-success-text"><?= icon('mail') ?></div>
<h1 class="auth-title">Check your email</h1>
<p class="mt-3 text-[14px] text-muted">If an account matches, we've sent a link to its email address. It works once, for the next hour.</p>
<p class="mt-3 text-[14px] text-muted">Nothing after a few minutes? Check the spam folder, or ask your hosting provider to reset the password for you.</p>
<a href="/login" class="btn btn-secondary btn-block mt-8 h-10 text-[14px]"><?= icon('arrow-left') ?>Back to sign in</a>
<?php else: ?>
<h1 class="auth-title">Reset your password</h1>
<p class="auth-desc">We'll email you a link to choose a new one.</p>

<?php if (!empty($error)): ?>
<div class="mt-6"><?= Ui::alert($error, 'danger') ?></div>
<?php endif; ?>

<form method="post" action="/login/forgot" class="mt-8 space-y-5">
    <?= Csrf::field() ?>
    <?= Ui::field('username', 'Username or email', ['required' => true, 'attrs' => ['autofocus' => true, 'autocomplete' => 'username', 'autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
    <button type="submit" class="auth-submit">Email me a link</button>
</form>

<p class="mt-8 text-[13px]"><a href="/login" class="link-muted inline-flex items-center gap-1.5"><?= icon('arrow-left', 'h-3.5 w-3.5') ?>Back to sign in</a></p>
<?php endif; ?>
