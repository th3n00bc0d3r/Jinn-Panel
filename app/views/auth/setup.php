<?php
/** @var array{services:array<string,bool>, hostname:string, ip:string} $server */
/** @var list<string> $errors */
/** @var array<string,string> $old */
View::slot('wide', '1');
View::slot('title', 'Set up ' . Config::APP_NAME);
View::slot('brand_lead', e('You are setting up'));
View::slot('brand_note', e('This page works once. After setup you sign in with the account you create here.'));
View::script('auth');
$down = in_array(false, $server['services'], true);
?>
<h1 class="auth-title">Welcome to <?= e(Config::APP_NAME) ?></h1>
<p class="auth-desc">One-time setup: create the administrator account for this server.</p>

<section class="mt-8" aria-labelledby="setup-services">
    <h2 id="setup-services" class="section-title mb-2">Services on this server</h2>
    <ul class="grid grid-cols-2 gap-2 sm:grid-cols-3">
        <?php foreach ($server['services'] as $name => $up): ?>
        <li class="flex min-w-0 items-center gap-2 rounded-[8px] border border-line bg-surface-2 px-3 py-2 text-[13px]">
            <?= $up ? icon('check-circle', 'h-4 w-4 shrink-0 text-success-text') : icon('x-circle', 'h-4 w-4 shrink-0 text-danger-text') ?>
            <span class="min-w-0 truncate text-fg"><?= e($name) ?></span>
            <span class="sr-only"><?= $up ? 'answering' : 'not answering' ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($down): ?>
    <div class="mt-3"><?= Ui::alert('One or more services aren\'t answering yet. You can still finish setup; check <code>systemctl status</code> for the ones marked with a cross before relying on them.', 'warning', true) ?></div>
    <?php endif; ?>
</section>

<?php if ($errors): ?>
<div class="mt-6"><?= Ui::alert('<ul class="list-inside list-disc space-y-0.5">' . implode('', array_map(fn($er) => '<li>' . e($er) . '</li>', $errors)) . '</ul>', 'danger', true) ?></div>
<?php endif; ?>

<form method="post" action="/setup" class="mt-8 space-y-5">
    <?= Csrf::field() ?>
    <?= Ui::field('setup_token', 'Setup token', ['required' => true, 'mono' => true, 'placeholder' => 'printed at the end of install.sh', 'hint' => 'Also on the server in /root/.jinnpanel/setup_token.', 'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'autofocus' => true]]) ?>
    <div class="grid gap-5 sm:grid-cols-2">
        <?= Ui::field('username', 'Admin username', ['required' => true, 'value' => $old['username'] ?? '', 'mono' => true, 'attrs' => ['autocomplete' => 'username', 'autocapitalize' => 'off']]) ?>
        <?= Ui::field('full_name', 'Full name', ['optional' => true, 'value' => $old['full_name'] ?? '']) ?>
    </div>
    <?= Ui::field('email', 'Admin email', ['type' => 'email', 'required' => true, 'value' => $old['email'] ?? '', 'hint' => 'For password resets and server alerts.']) ?>
    <?= Ui::password('password', 'Password', ['required' => true, 'strength' => true, 'minlength' => Passwords::MIN_LENGTH, 'autocomplete' => 'new-password', 'hint' => 'At least ' . Passwords::MIN_LENGTH . ' characters, not a common password. This account controls every domain, mailbox and server setting.']) ?>
    <?= Ui::password('password_confirm', 'Confirm password', ['required' => true, 'minlength' => Passwords::MIN_LENGTH, 'autocomplete' => 'new-password']) ?>
    <button type="submit" class="auth-submit">Create admin account and finish setup</button>
</form>

<p class="mt-8 border-t border-line pt-4 text-[12.5px] text-muted">
    Server <code class="text-fg"><?= e($server['hostname']) ?></code> · <code class="text-fg"><?= e($server['ip']) ?></code>
</p>
