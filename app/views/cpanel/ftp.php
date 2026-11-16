<?php
/** @var list<array<string,mixed>> $accounts SFTP logins (with public_keys) */
/** @var list<array<string,mixed>> $domains */
View::slot('desc', e('Logins for SFTP clients like FileZilla, Cyberduck or WinSCP. Plain FTP is not offered: SFTP is encrypted.'));
View::slot('actions', '<a href="#add-sftp" class="btn btn-primary">' . icon('plus') . 'Create login</a>');
$host = Config::SERVER_HOSTNAME;
$port = (int) Config::SFTP_PUBLIC_PORT;
?>
<div class="stack">
    <?php $compact = true; include __DIR__ . '/../partials/quota_bars.php'; ?>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <div class="stack xl:col-span-2">
            <section class="card" aria-labelledby="sftp-title">
                <?= Ui::cardHeader(Ui::plural(count($accounts), 'SFTP login'), id: 'sftp-title') ?>
                <?php if (!$accounts): ?>
                <?= Ui::empty('No SFTP logins yet', 'Create one to upload files with an SFTP client.', 'folder-up') ?>
                <?php else: ?>
                <ul class="divide-y divide-line">
                    <?php foreach ($accounts as $a):
                        $keys = array_values(array_filter(explode("\n", (string) ($a['public_keys'] ?? ''))));
                        $kid = 'keys-' . (int) $a['id']; ?>
                    <li class="px-5 py-4">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-2"><?= Ui::copyable((string) $a['username']) ?> <?= $keys ? Ui::badge(Ui::plural(count($keys), 'SSH key'), 'success') : '' ?></p>
                                <p class="mt-0.5 truncate font-mono text-xs text-muted"><?= e($a['home_dir']) ?></p>
                            </div>
                            <?= Ui::deleteForm('/cpanel/ftp/' . (int) $a['id'] . '/delete', 'Delete the SFTP login ' . $a['username'] . '? Programs using it stop connecting. Files are not touched.', 'Delete ' . $a['username']) ?>
                        </div>
                        <?php if ($keys || Features::has('ssh_keys')): ?>
                        <details class="mt-2">
                            <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded text-sm text-muted hover:text-fg [&::-webkit-details-marker]:hidden"><?= icon('key', 'h-4 w-4') ?><?= $keys ? 'SSH keys' : 'Add an SSH key' ?><?= icon('chevron-down', 'h-3.5 w-3.5') ?></summary>
                            <form method="post" action="/cpanel/ftp/<?= (int) $a['id'] ?>/keys" class="mt-3 space-y-3 rounded-lg border border-line bg-surface-2 p-4">
                                <?= Csrf::field() ?>
                                <?= Ui::textarea('public_keys', 'Public keys for ' . $a['username'], implode("\n", $keys), [
                                    'mono' => true,
                                    'rows' => 4,
                                    'placeholder' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA… me@laptop',
                                    'hint' => 'One per line - the contents of a .pub file (e.g. ~/.ssh/id_ed25519.pub), never the private key. Empty removes them. The password keeps working too.',
                                ]) ?>
                                <button class="btn btn-primary btn-sm"><?= icon('save') ?>Save keys</button>
                                <?php $keyFile = '~/.ssh/' . preg_replace('/[^a-z0-9_-]/i', '-', Config::SERVER_HOSTNAME) . '_' . preg_replace('/[^a-z0-9_-]/i', '-', (string) $a['username']); ?>
                                <div class="border-t border-line pt-3 text-[13px]">
                                    <p class="font-medium text-fg">No key yet? On your computer (macOS, Linux, or Windows PowerShell):</p>
                                    <ol class="mt-2 space-y-2 text-muted">
                                        <li>1. Make a key pair: <?php $cmd = 'ssh-keygen -t ed25519 -f ' . $keyFile . ' -C "' . $a['username'] . '@' . Config::SERVER_HOSTNAME . '"'; ?><span class="mt-1 flex items-start gap-1"><code class="min-w-0 break-all font-mono text-[12.5px] text-fg"><?= e($cmd) ?></code><?= Ui::copy($cmd) ?></span></li>
                                        <li>2. Paste the contents of <code class="mono"><?= e($keyFile) ?>.pub</code> above and save. The file without .pub is your private key: keep it on your computer.</li>
                                        <li>3. Connect with the key: <?php $cmd = 'sftp -i ' . $keyFile . ' -P ' . (int) Config::SFTP_PUBLIC_PORT . ' ' . $a['username'] . '@' . Config::SERVER_HOSTNAME; ?><span class="mt-1 flex items-start gap-1"><code class="min-w-0 break-all font-mono text-[12.5px] text-fg"><?= e($cmd) ?></code><?= Ui::copy($cmd) ?></span></li>
                                    </ol>
                                </div>
                            </form>
                        </details>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>

            <section class="card" aria-labelledby="connect-title">
                <?= Ui::cardHeader('Connecting', 'Use these settings in your SFTP client.', id: 'connect-title') ?>
                <dl class="dl card-body">
                    <div><dt>Protocol</dt><dd>SFTP (SSH File Transfer)</dd></div>
                    <div><dt>Server</dt><dd><?= Ui::copyable($host) ?></dd></div>
                    <div><dt>Port</dt><dd><?= Ui::copyable((string) $port) ?></dd></div>
                    <div><dt>Username</dt><dd>The login's full name above (it starts with your account name)</dd></div>
                    <div><dt>Command line</dt><dd><?= Ui::copyable('sftp -P ' . $port . ' ' . ($accounts[0]['username'] ?? 'LOGIN') . '@' . $host) ?></dd></div>
                </dl>
            </section>
        </div>

        <section class="card h-fit scroll-mt-20" id="add-sftp" aria-labelledby="add-sftp-title">
            <?= Ui::cardHeader('Create an SFTP login', id: 'add-sftp-title') ?>
            <form method="post" action="/cpanel/ftp" class="card-body space-y-4">
                <?= Csrf::field() ?>
                <?= Ui::field('username', 'Name', ['required' => true, 'placeholder' => 'uploads', 'mono' => true, 'hint' => 'The full login name is your account name, an underscore and this.', 'attrs' => ['autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
                <?= Ui::field('password', 'Password', ['type' => 'password', 'required' => true, 'generate' => true, 'attrs' => ['minlength' => Passwords::MIN_LENGTH, 'autocomplete' => 'new-password']]) ?>
                <?php
                $opts = ['' => 'All my sites (each as /<domain>)'];
                foreach ($domains as $d) {
                    $opts[(string) $d['id']] = 'Only ' . $d['domain_name'];
                }
                echo Ui::select('domain_id', 'Access', $opts, '', ['hint' => 'A login for one site can\'t see your other sites.']);
                ?>
                <button type="submit" class="btn btn-primary btn-block"><?= icon('plus') ?>Create login</button>
            </form>
        </section>
    </div>
</div>
