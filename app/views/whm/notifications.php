<?php
/** @var array{from:string, from_name:string, admin_email:string, welcome:bool, alerts:bool} $s */
/** @var string $from the From address in use */
/** @var list<string> $recipients where alerts go now */
/** @var array<string,mixed> $me */
View::slot('desc', e('The emails the panel sends by itself: welcome emails, password resets and server alerts.'));
?>
<div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
    <form method="post" action="/whm/notifications" class="card xl:col-span-2">
        <?= Csrf::field() ?>
        <?= Ui::cardHeader('Settings') ?>
        <div class="card-body space-y-6">
            <div>
                <h2 class="section-title mb-3">Sender</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <?= Ui::field('from', 'From address', [
                        'type' => 'email',
                        'value' => $s['from'],
                        'placeholder' => 'noreply@' . strtolower(Config::SERVER_HOSTNAME),
                        'hint' => 'Must be on a mail domain hosted on this server, or the mail server won\'t send it. Empty: ' . 'noreply@' . strtolower(Config::SERVER_HOSTNAME) . '.',
                    ]) ?>
                    <?= Ui::field('from_name', 'From name', ['value' => $s['from_name'], 'placeholder' => Config::APP_NAME, 'optional' => true, 'hint' => 'E.g. your hosting company\'s name.']) ?>
                </div>
            </div>
            <div>
                <h2 class="section-title mb-3">Emails</h2>
                <div class="space-y-4">
                    <?= Ui::checkbox('welcome', 'Welcome emails for new accounts', $s['welcome'], ['hint' => 'Offered when an account is created: where to sign in, the username, and a link to choose a password. Passwords are never emailed.']) ?>
                    <?= Ui::checkbox('alerts', 'Email new server alerts', $s['alerts'], ['hint' => 'Checked hourly. Each problem is emailed once when it appears (see Alerts).']) ?>
                    <?= Ui::checkbox('quota', 'Warn customers about their quota', $s['quota'] ?? true, ['hint' => 'Emails a hosting account at 90% of its disk or monthly bandwidth, and when it runs out. Once per level; bandwidth warnings start over each month.']) ?>
                    <?= Ui::checkbox('suspension', 'Tell customers when they are suspended or unsuspended', $s['suspension'] ?? true, ['hint' => 'With the reason you give when suspending from the account\'s page.']) ?>
                    <p class="text-sm text-muted">Password reset emails are always on: they're how people get back in.</p>
                </div>
            </div>
            <div>
                <h2 class="section-title mb-3">Alert recipients</h2>
                <?= Ui::field('admin_email', 'Send alerts to', [
                    'value' => $s['admin_email'],
                    'optional' => true,
                    'placeholder' => 'ops@example.com, me@example.com',
                    'hint' => 'Comma-separated. Empty: every active administrator\'s own email.',
                ]) ?>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary"><?= icon('save') ?>Save settings</button>
        </div>
    </form>

    <div class="stack">
        <section class="card">
            <?= Ui::cardHeader('In use now') ?>
            <dl class="dl card-body">
                <div><dt>From</dt><dd class="break-all"><code><?= e($from) ?></code></dd></div>
                <div><dt>Alerts go to</dt><dd class="break-all"><?= $recipients ? e(implode(', ', $recipients)) : '<span class="text-warning-text">nobody - no address set</span>' ?></dd></div>
            </dl>
        </section>

        <form method="post" action="/whm/notifications/test" class="card">
            <?= Csrf::field() ?>
            <?= Ui::cardHeader('Send a test', 'Check that mail from the panel arrives.') ?>
            <div class="card-body">
                <?= Ui::field('to', 'Send to', ['type' => 'email', 'required' => true, 'value' => (string) ($me['email'] ?? '')]) ?>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-secondary"><?= icon('mail') ?>Send test email</button>
            </div>
        </form>
    </div>
</div>
