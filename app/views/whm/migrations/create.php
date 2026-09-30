<?php
$input = 'w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500';
$types = [
    'cpanel' => ['Single cPanel account', 'Migrate one account. Log in with that account\'s cPanel username.'],
    'reseller' => ['WHM reseller', 'Migrate any or all accounts owned by a reseller.'],
];
if ($canRoot) {
    $types['root'] = ['WHM root (whole server)', 'Migrate any accounts on the server, including resellers and their customers.'];
}
?>
<div class="grid gap-4 lg:grid-cols-3">
<div class="lg:col-span-2">
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
    <?php if (!empty($errors)): ?>
    <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
        <ul class="list-disc list-inside space-y-0.5">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" action="/whm/migrations/connect" class="space-y-5" id="migration-connect">
        <?= Csrf::field() ?>

        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1.5">What are you migrating from?</label>
            <div class="grid gap-2 <?= $canRoot ? 'sm:grid-cols-3' : 'sm:grid-cols-2' ?>">
                <?php foreach ($types as $key => [$label, $hint]): ?>
                <label class="relative flex cursor-pointer rounded-lg border border-slate-300 p-3 hover:border-indigo-400 has-[:checked]:border-indigo-600 has-[:checked]:ring-1 has-[:checked]:ring-indigo-600">
                    <input type="radio" name="source_type" value="<?= e($key) ?>" class="sr-only" <?= $old['type'] === $key ? 'checked' : '' ?>>
                    <span>
                        <span class="block text-sm font-medium text-slate-800"><?= e($label) ?></span>
                        <span class="block text-xs text-slate-500 mt-0.5"><?= e($hint) ?></span>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div class="col-span-2">
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Source server</label>
                <input type="text" name="host" required value="<?= e($old['hostRaw']) ?>" placeholder="server.oldhost.com or 198.51.100.7" class="<?= $input ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Port</label>
                <input type="number" name="port" min="1" max="65535" value="<?= e($old['portRaw']) ?>" placeholder="2083" data-port class="<?= $input ?>">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Username</label>
                <input type="text" name="user" value="<?= e($old['user']) ?>" placeholder="cPanel username" data-user autocomplete="off" class="<?= $input ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Sign in with</label>
                <select name="auth_type" class="<?= $input ?>">
                    <option value="token" <?= $old['authType'] === 'token' ? 'selected' : '' ?>>API token (recommended)</option>
                    <option value="password" <?= $old['authType'] === 'password' ? 'selected' : '' ?>>Password</option>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1.5">API token or password</label>
            <input type="password" name="secret" required autocomplete="new-password" class="<?= $input ?>">
            <p class="mt-1 text-xs text-slate-400">Stored encrypted only while the migration needs it, then deleted automatically.</p>
        </div>

        <details class="rounded-lg border border-slate-200 px-4 py-3" <?= $old['mode'] !== 'auto' || !$old['verifyTls'] ? 'open' : '' ?>>
            <summary class="cursor-pointer text-sm font-medium text-slate-700">Advanced</summary>
            <div class="mt-4 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1.5">How the backups get here</label>
                    <select name="transfer_mode" class="<?= $input ?>">
                        <option value="auto" <?= $old['mode'] === 'auto' ? 'selected' : '' ?>>Automatic</option>
                        <option value="pull" <?= $old['mode'] === 'pull' ? 'selected' : '' ?>>Pull - this server downloads each backup from the source</option>
                        <option value="push" <?= $old['mode'] === 'push' ? 'selected' : '' ?>>Push - the source uploads each backup here over SFTP</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-400">
                        Automatic uses pull, except for a single cPanel account signed in with an API token, which can only push.
                        Push needs the source to reach <span class="font-mono"><?= e($serverIp) ?></span> on port 2022.
                    </p>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="verify_tls" value="1" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" <?= $old['verifyTls'] ? 'checked' : '' ?>>
                    Verify the source's TLS certificate (untick for a self-signed certificate)
                </label>
            </div>
        </details>

        <div class="flex gap-3 pt-1">
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2.5 transition-colors">
                <?= icon('transfer', 'h-4 w-4') ?> Connect &amp; list accounts
            </button>
            <a href="/whm/migrations" class="rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium px-5 py-2.5 transition-colors">Cancel</a>
        </div>
        <p class="text-xs text-slate-400">Connecting only lists the accounts. Nothing is copied until you choose accounts and start the migration.</p>
    </form>
</div>
</div>

<div class="space-y-4">
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 text-sm text-slate-600 space-y-3">
        <h2 class="font-semibold text-slate-800">Getting an API token</h2>
        <p><span class="font-medium text-slate-700">Single account:</span> in cPanel open <span class="italic">Security &rsaquo; Manage API Tokens</span> and create a token. Use the cPanel username.</p>
        <p><span class="font-medium text-slate-700">Reseller or root:</span> in WHM open <span class="italic">Development &rsaquo; Manage API Tokens</span>. Give the token full access to the accounts, including the ability to log in to them. Use the reseller's username, or <span class="font-mono">root</span>.</p>
        <p>Revoke the token on the old server when the migration is done.</p>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 text-sm text-slate-600 space-y-2">
        <h2 class="font-semibold text-slate-800">What gets migrated</h2>
        <p>Main, addon and subdomains with their files; MySQL databases and users (with their passwords); email accounts (with their passwords) and all stored mail.</p>
        <p class="text-slate-500">Not migrated: parked domains, forwarders, autoresponders, cron jobs, custom DNS records and SSL certificates. The report lists everything that needs attention.</p>
    </div>
</div>
</div>

<script>
(function () {
    var form = document.getElementById('migration-connect');
    var port = form.querySelector('[data-port]');
    var user = form.querySelector('[data-user]');
    function sync() {
        var t = (form.querySelector('input[name=source_type]:checked') || {}).value || 'cpanel';
        port.placeholder = t === 'cpanel' ? '2083' : '2087';
        user.placeholder = t === 'root' ? 'root' : (t === 'reseller' ? 'WHM reseller username' : 'cPanel username');
    }
    form.addEventListener('change', sync);
    sync();
})();
</script>
