<?php
/** @var list<string> $errors */
/** @var array{type:string,hostRaw:string,portRaw:string,user:string,authType:string,verifyTls:bool,mode:string} $old */
/** @var bool $canRoot */
/** @var string $serverIp */
include __DIR__ . '/_helpers.php';
View::breadcrumbs([['cPanel Migration', '/whm/migrations'], ['New migration']]);
View::slot('desc', e('Where are the accounts now? Nothing is copied until you have chosen the accounts and started the migration.'));
View::script('migration-create');

$admin = Auth::isAdmin();
$importDir = MigrationService::importDir();
$backupFiles ??= []; // from the controller (MigrationService::availableBackupFiles)

$types = [
    'cpanel' => ['Single cPanel account', 'One account. Sign in with that account\'s cPanel username.'],
    'reseller' => ['WHM reseller', 'Any or all accounts owned by a reseller.'],
];
if ($canRoot) {
    $types['root'] = ['WHM root', 'Any account on the server, including resellers and their customers.'];
}
$sources = [
    'live' => ['server', 'A cPanel server', 'Connect with an API token or password. The old server makes the backups and they are copied here.'],
    'files' => ['archive', 'Backup files', 'cPanel full backups (cpmove files) already copied to this server.'],
    's3' => ['download', 'Backups in S3', 'Fetch cPanel backups from AWS S3, R2, Wasabi, Backblaze or MinIO first.'],
];
// The source cards only switch which panel shows (CSS :has, no JavaScript needed).
$choice = 'flex cursor-pointer items-start gap-3 rounded-xl border border-line bg-surface p-4 shadow-card transition-colors hover:border-line-strong '
    . 'has-[:checked]:border-accent has-[:checked]:ring-1 has-[:checked]:ring-accent has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-accent';
$option = 'flex cursor-pointer flex-col rounded-lg border border-line-strong bg-surface p-3 transition-colors hover:bg-surface-2 '
    . 'has-[:checked]:border-accent has-[:checked]:bg-accent-soft has-[:checked]:ring-1 has-[:checked]:ring-accent has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-accent';
?>
<?= migration_steps(1) ?>

<div class="group/src">
    <?php if ($admin): ?>
    <fieldset class="mb-6">
        <legend class="mb-3 text-sm font-medium text-fg">Where are the accounts?</legend>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <?php foreach ($sources as $key => [$ico, $label, $text]): ?>
            <label class="<?= $choice ?>" for="src-<?= $key ?>">
                <input type="radio" name="source" id="src-<?= $key ?>" value="<?= $key ?>" class="sr-only" <?= $key === 'live' ? 'checked' : '' ?>>
                <span class="stat-icon h-9 w-9"><?= icon($ico) ?></span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-fg"><?= e($label) ?></span>
                    <span class="mt-0.5 block text-xs text-muted"><?= e($text) ?></span>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <?php endif; ?>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <!-- From a live cPanel / WHM server -->
            <section class="card scroll-mt-20 group-has-[#src-files:checked]/src:hidden group-has-[#src-s3:checked]/src:hidden" id="live" aria-labelledby="live-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="live-title" class="card-title">Connect to the cPanel server</h2>
                        <p class="card-desc">Connecting only lists the accounts this login can see.</p>
                    </div>
                </div>
                <form method="post" action="/whm/migrations/connect" id="migration-connect">
                    <?= Csrf::field() ?>
                    <div class="card-body space-y-5">
                        <?php if (!empty($errors)): ?>
                        <?php
                        $list = '';
                        foreach ($errors as $err) {
                            $list .= '<li>' . e($err) . '</li>';
                        }
                        echo Ui::alert('<p class="font-medium">' . (count($errors) === 1 ? 'That didn\'t work:' : 'Fix these and try again:') . '</p><ul class="mt-1 list-disc space-y-0.5 pl-4">' . $list . '</ul>', 'danger', true);
                        ?>
                        <?php endif; ?>

                        <fieldset>
                            <legend class="label">What kind of login is it?</legend>
                            <div class="grid grid-cols-1 gap-2 <?= $canRoot ? 'sm:grid-cols-3' : 'sm:grid-cols-2' ?>">
                                <?php foreach ($types as $key => [$label, $hint]): ?>
                                <label class="<?= $option ?>" for="type-<?= e($key) ?>">
                                    <input type="radio" name="source_type" id="type-<?= e($key) ?>" value="<?= e($key) ?>" class="sr-only" <?= $old['type'] === $key ? 'checked' : '' ?>>
                                    <span class="text-sm font-medium text-fg"><?= e($label) ?></span>
                                    <span class="mt-0.5 text-xs text-muted"><?= e($hint) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <div class="grid grid-cols-3 gap-4">
                            <?= Ui::field('host', 'Source server', [
                                'value' => $old['hostRaw'], 'required' => true, 'wrap' => 'col-span-2',
                                'placeholder' => 'server.oldhost.com or 198.51.100.7',
                                'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false', 'autocomplete' => 'off', 'inputmode' => 'url'],
                            ]) ?>
                            <?= Ui::field('port', 'Port', [
                                'type' => 'number', 'value' => $old['portRaw'], 'placeholder' => '2083', 'optional' => true,
                                'attrs' => ['min' => 1, 'max' => 65535, 'data-port' => true],
                            ]) ?>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <?= Ui::field('user', 'Username', [
                                'value' => $old['user'], 'placeholder' => 'cPanel username',
                                'attrs' => ['autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false', 'data-user' => true],
                            ]) ?>
                            <?= Ui::select('auth_type', 'Sign in with', [
                                'token' => 'API token (recommended)',
                                'password' => 'Password',
                            ], $old['authType'], ['attrs' => ['data-auth-type' => true]]) ?>
                        </div>

                        <?= Ui::field('secret', 'API token or password', [
                            'id' => 'mig-secret', 'type' => 'password', 'required' => true,
                            'hint' => 'Stored encrypted only while the migration needs it, then deleted automatically.',
                            'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
                        ]) ?>

                        <details class="disclosure rounded-lg border border-line px-4 py-3" <?= $old['mode'] !== 'auto' || !$old['verifyTls'] ? 'open' : '' ?>>
                            <summary>Advanced</summary>
                            <div class="mt-4 space-y-4">
                                <?= Ui::select('transfer_mode', 'How the backups get here', [
                                    'auto' => 'Automatic',
                                    'pull' => 'Pull - this server downloads each backup from the source',
                                    'push' => 'Push - the source uploads each backup here over SFTP',
                                ], $old['mode'], ['hint' => 'Automatic uses pull, except for a single cPanel account signed in with an API token, which can only push. Push needs the source to reach ' . $serverIp . ' on port 2022.']) ?>
                                <?= Ui::checkbox('verify_tls', 'Verify the source\'s TLS certificate', (bool) $old['verifyTls'], ['hint' => 'Untick it for a server with a self-signed certificate.']) ?>
                            </div>
                        </details>
                    </div>
                    <div class="card-footer">
                        <a href="/whm/migrations" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><?= icon('transfer') ?>Connect and list accounts</button>
                    </div>
                </form>
            </section>

            <?php if ($admin): ?>
            <!-- From backup files already on this server -->
            <section class="card hidden scroll-mt-20 group-has-[#src-files:checked]/src:block" id="files" aria-labelledby="files-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="files-title" class="card-title">Restore from backup files</h2>
                        <p class="card-desc">cPanel full backups already on this server: copied from off-site storage, cPanel's scheduled backups, or cpmove files.</p>
                    </div>
                </div>
                <div class="card-body space-y-5 text-sm">
                    <ol class="space-y-3 text-muted">
                        <li class="flex gap-3">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-surface-3 text-xs font-semibold text-fg">1</span>
                            <span class="min-w-0">Copy the archives into <?= Ui::copyable($importDir) ?>, readable by <code>frankenphp</code>.</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-surface-3 text-xs font-semibold text-fg">2</span>
                            <span class="min-w-0">Name them <code>cpmove-&lt;user&gt;.tar.gz</code>, <code>backup-&lt;date&gt;_&lt;user&gt;.tar.gz</code> or <code>&lt;user&gt;.tar.gz</code> - as cPanel does.</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-surface-3 text-xs font-semibold text-fg">3</span>
                            <span class="min-w-0">Choose which of them to restore, and how, on the next page.</span>
                        </li>
                    </ol>
                    <?php if ($backupFiles): ?>
                    <div class="rounded-lg border border-line bg-surface-2 p-4">
                        <p class="font-medium text-fg"><?= e(Ui::plural(count($backupFiles), 'account')) ?> found</p>
                        <ul class="mt-2 flex flex-wrap gap-1.5" aria-label="Accounts with a backup file">
                            <?php foreach (array_slice(array_keys($backupFiles), 0, 24) as $u): ?>
                            <li class="chip" title="<?= e(basename((string) $backupFiles[$u])) ?>"><?= e($u) ?></li>
                            <?php endforeach; ?>
                            <?php if (count($backupFiles) > 24): ?><li class="chip text-muted">+<?= count($backupFiles) - 24 ?> more</li><?php endif; ?>
                        </ul>
                    </div>
                    <?php else: ?>
                    <?= Ui::alert('No backup files in the import folder yet. Copy them there and reload this page - or fetch them from S3.', 'info') ?>
                    <?php endif; ?>
                </div>
                <form method="post" action="/whm/migrations/from-files" class="card-footer">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-primary" <?= $backupFiles ? '' : 'disabled' ?>><?= icon('users') ?>Choose accounts</button>
                </form>
            </section>

            <!-- Fetch backups from S3 into the import folder -->
            <section class="card hidden scroll-mt-20 group-has-[#src-s3:checked]/src:block" id="s3" aria-labelledby="s3-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="s3-title" class="card-title">Fetch backups from S3</h2>
                        <p class="card-desc">Downloads every cpmove-* and backup-*.tar.gz under the prefix into the import folder. Then you choose the accounts.</p>
                    </div>
                </div>
                <form method="post" action="/whm/migrations/s3-fetch" autocomplete="off">
                    <?= Csrf::field() ?>
                    <div class="card-body grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <?= Ui::field('bucket', 'Bucket', ['required' => true, 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
                        <?= Ui::field('prefix', 'Folder (prefix)', ['optional' => true, 'mono' => true, 'placeholder' => 'cpanel/2026-09-30/', 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
                        <?= Ui::field('region', 'Region', ['optional' => true, 'placeholder' => 'us-east-1', 'hint' => '"auto" for Cloudflare R2.', 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
                        <?= Ui::field('endpoint', 'Endpoint', ['optional' => true, 'mono' => true, 'placeholder' => 'https://s3.eu-central-003.backblazeb2.com', 'hint' => 'Leave empty for AWS.', 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false', 'inputmode' => 'url']]) ?>
                        <?= Ui::field('access_key', 'Access key', ['type' => 'password', 'required' => true, 'mono' => true, 'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false']]) ?>
                        <?= Ui::field('secret_key', 'Secret key', ['type' => 'password', 'required' => true, 'mono' => true, 'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false']]) ?>
                        <p class="hint sm:col-span-2">A read-only key is enough. The secret key is stored encrypted only until the fetch ends. Progress shows on the cPanel Migration page.</p>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary"><?= icon('download') ?>Fetch into the import folder</button>
                    </div>
                </form>
            </section>
            <?php endif; ?>
        </div>

        <aside class="flex flex-col gap-6" aria-label="Help">
            <section class="card group-has-[#src-files:checked]/src:hidden group-has-[#src-s3:checked]/src:hidden">
                <div class="card-header"><h2 class="card-title">Getting an API token</h2></div>
                <div class="card-body space-y-3 text-sm text-muted">
                    <p><span class="font-medium text-fg">Single account:</span> in cPanel open <em>Security › Manage API Tokens</em> and create a token. Use the cPanel username.</p>
                    <p><span class="font-medium text-fg">Reseller or root:</span> in WHM open <em>Development › Manage API Tokens</em>. Give the token full access to the accounts, including logging in to them. Use the reseller's username, or <code>root</code>.</p>
                    <p>Revoke the token on the old server when the migration is done.</p>
                </div>
            </section>
            <section class="card">
                <div class="card-header"><h2 class="card-title">What gets migrated</h2></div>
                <div class="card-body space-y-3 text-sm text-muted">
                    <p>Main, addon and subdomains with their files, DNS records, PHP settings and <code>.htaccess</code> rules (as Routes); parked domains as aliases; MySQL databases and users (with their passwords); email accounts (with their passwords) and all stored mail; forwarders, autoresponders and the default address; FTP accounts (as SFTP) and cron jobs.</p>
                    <p class="text-subtle">Not migrated: SSL certificates (Let's Encrypt issues new ones once DNS points here), password-protected directories, Node.js/Python apps, and views, triggers and stored procedures. The report lists everything that needs attention.</p>
                </div>
            </section>
        </aside>
    </div>
</div>
