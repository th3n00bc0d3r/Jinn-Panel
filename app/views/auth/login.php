<?php
/** @var string|null $error */
View::script('auth');
// After a failed attempt the username stays, so only the password is typed again.
$username = !empty($error) && is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
?>
<h1 class="auth-title">Sign in</h1>
<p class="auth-desc">With your username or email address.</p>

<?php if (!empty($error)): ?>
<div class="mt-6"><?= Ui::alert($error, 'danger') ?></div>
<?php endif; ?>

<form method="post" action="/login" class="mt-8 space-y-5">
    <?= Csrf::field() ?>
    <?= Ui::field('username', 'Username or email', ['required' => true, 'value' => $username !== '' ? $username : null, 'attrs' => ['autofocus' => $username === '', 'autocomplete' => 'username', 'autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
    <?= Ui::password('password', 'Password', ['id' => 'login-password', 'required' => true, 'autocomplete' => 'current-password', 'autofocus' => $username !== '', 'link' => ['/login/forgot', 'Forgot it?']]) ?>
    <button type="submit" class="auth-submit">Sign in</button>
</form>

<p class="mt-8 text-[13px] text-subtle">Two-factor codes are asked for on the next step, if you turned them on.</p>
