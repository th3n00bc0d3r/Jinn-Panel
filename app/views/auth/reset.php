<?php
/** @var string|null $token null = the link isn't valid (any more) */
/** @var array<string,mixed>|null $row the account */
/** @var string|null $error */
$welcome = ($row['purpose'] ?? '') === 'welcome';
if ($token !== null) {
    View::script('auth');
}
?>
<?php if ($token === null): ?>
<div class="auth-icon bg-warning-soft text-warning-text"><?= icon('clock') ?></div>
<h1 class="auth-title">This link no longer works</h1>
<p class="mt-3 text-[14px] text-muted">Password links work once, and only for a limited time. Ask for a new one.</p>
<a href="/login/forgot" class="auth-submit mt-8">Send a new link</a>
<p class="mt-6 text-[13px]"><a href="/login" class="link-muted inline-flex items-center gap-1.5"><?= icon('arrow-left', 'h-3.5 w-3.5') ?>Back to sign in</a></p>
<?php else: ?>
<h1 class="auth-title"><?= $welcome ? 'Choose your password' : 'Choose a new password' ?></h1>
<p class="auth-desc">For <span class="font-medium text-fg"><?= e((string) $row['username']) ?></span>.<?= $welcome ? ' You sign in with it from now on.' : ' Other signed-in sessions will be signed out.' ?></p>

<?php if (!empty($error)): ?>
<div class="mt-6"><?= Ui::alert($error, 'danger') ?></div>
<?php endif; ?>

<form method="post" action="/login/reset" class="mt-8 space-y-5">
    <?= Csrf::field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <input type="text" name="username" value="<?= e((string) $row['username']) ?>" autocomplete="username" class="sr-only" tabindex="-1" aria-label="Username" readonly>
    <?= Ui::password('new_password', 'New password', ['required' => true, 'strength' => true, 'generate' => true, 'minlength' => Passwords::MIN_LENGTH, 'autocomplete' => 'new-password', 'autofocus' => true, 'hint' => 'At least ' . Passwords::MIN_LENGTH . ' characters, not a common password.']) ?>
    <?= Ui::password('new_password_confirm', 'New password again', ['required' => true, 'minlength' => Passwords::MIN_LENGTH, 'autocomplete' => 'new-password']) ?>
    <button type="submit" class="auth-submit">Save password</button>
</form>
<?php endif; ?>
