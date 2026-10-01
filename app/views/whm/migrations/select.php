<?php
include __DIR__ . '/_helpers.php';
$input = 'w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500';
$check = 'rounded border-slate-300 text-indigo-600 focus:ring-indigo-500';
$selectable = count($items) - count($conflicts);
$hasResellers = (bool) array_filter($items, fn($i) => (int) $i['is_reseller'] === 1);
?>
<form method="post" action="/whm/migrations/<?= (int) $m['id'] ?>/start" class="space-y-4" id="migration-select">
    <?= Csrf::field() ?>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm px-5 py-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
        <?php if ($m['transfer_mode'] === 'file'): ?>
        <div><span class="text-slate-400">Source</span> <span class="font-medium text-slate-800">cPanel backup files in <span class="font-mono"><?= e(MigrationService::importDir()) ?></span></span></div>
        <div class="text-slate-500">Disk = size of the backup archive.</div>
        <?php else: ?>
        <div><span class="text-slate-400">Source</span> <span class="font-medium text-slate-800"><?= e($m['source_user']) ?>@<?= e($m['source_host']) ?>:<?= (int) $m['source_port'] ?></span></div>
        <div><span class="text-slate-400">Type</span> <span class="font-medium text-slate-800"><?= e(migration_source_label($m['source_type'])) ?></span></div>
        <div><span class="text-slate-400">Transfer</span> <span class="font-medium text-slate-800"><?= $m['transfer_mode'] === 'push' ? 'Push (source uploads over SFTP)' : 'Pull (this server downloads)' ?></span></div>
        <?php endif; ?>
    </div>

    <!-- Accounts -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="flex items-center justify-between px-5 py-3 border-b border-slate-100">
            <h2 class="text-sm font-semibold text-slate-800">Accounts <span class="text-slate-400 font-normal">(<?= count($items) ?> found)</span></h2>
            <p class="text-sm text-slate-500"><span data-selected-count>0</span> selected<span data-selected-size></span></p>
        </div>
        <div class="overflow-x-auto max-h-[28rem] overflow-y-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide sticky top-0">
                <tr>
                    <th class="px-5 py-3 w-10"><input type="checkbox" data-select-all class="<?= $check ?>" title="Select all" <?= $selectable ? '' : 'disabled' ?>></th>
                    <th class="text-left font-medium px-3 py-3">Username</th>
                    <th class="text-left font-medium px-3 py-3">Main domain</th>
                    <?php if ($m['source_type'] !== 'cpanel'): ?>
                    <th class="text-left font-medium px-3 py-3">Owner</th>
                    <th class="text-left font-medium px-3 py-3">Plan</th>
                    <th class="text-right font-medium px-3 py-3">Disk</th>
                    <?php endif; ?>
                    <th class="text-left font-medium px-5 py-3">Notes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($items as $it): ?>
                <?php
                    $src = (json_decode((string) $it['report'], true) ?: [])['source'] ?? [];
                    $problems = $conflicts[(int) $it['id']] ?? [];
                ?>
                <tr class="<?= $problems ? 'bg-slate-50/60' : 'hover:bg-slate-50/70' ?>">
                    <td class="px-5 py-2.5">
                        <input type="checkbox" name="accounts[]" value="<?= e($it['source_username']) ?>" data-account data-mb="<?= (int) ($src['disk_used_mb'] ?? 0) ?>"
                               class="<?= $check ?>" <?= $problems ? 'disabled' : (count($items) === 1 ? 'checked' : '') ?>>
                    </td>
                    <td class="px-3 py-2.5">
                        <span class="font-medium <?= $problems ? 'text-slate-400' : 'text-slate-800' ?>"><?= e($it['source_username']) ?></span>
                        <?php if ((int) $it['is_reseller'] === 1): ?><span class="ml-1 inline-flex text-xs font-medium rounded-full px-2 py-0.5 bg-violet-50 text-violet-700">reseller</span><?php endif; ?>
                        <?php if (!empty($src['suspended'])): ?><span class="ml-1 inline-flex text-xs font-medium rounded-full px-2 py-0.5 bg-amber-50 text-amber-700">suspended</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-2.5 text-slate-600"><?= e($it['source_domain'] ?? '') ?></td>
                    <?php if ($m['source_type'] !== 'cpanel'): ?>
                    <td class="px-3 py-2.5 text-slate-500"><?= e($it['source_owner'] ?? '') ?></td>
                    <td class="px-3 py-2.5 text-slate-500"><?= e($it['source_plan'] ?? '') ?></td>
                    <td class="px-3 py-2.5 text-right text-slate-500 whitespace-nowrap"><?= isset($src['disk_used_mb']) ? e(fmt_bytes((int) $src['disk_used_mb'] * 1048576)) : '' ?></td>
                    <?php endif; ?>
                    <td class="px-5 py-2.5 text-xs <?= $problems ? 'text-red-600' : 'text-slate-400' ?>"><?= e(implode('; ', $problems)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Options -->
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 space-y-3">
            <h2 class="text-sm font-semibold text-slate-800">What to migrate</h2>
            <label class="flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" name="files" value="1" class="mt-0.5 <?= $check ?>" <?= $opt['files'] ? 'checked' : '' ?>>
                <span>Domains and website files <span class="block text-xs text-slate-400">Main, addon and subdomains, each with its document root.</span></span></label>
            <label class="flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" name="databases" value="1" class="mt-0.5 <?= $check ?>" <?= $opt['databases'] ? 'checked' : '' ?>>
                <span>MySQL databases and users <span class="block text-xs text-slate-400">Same names, so site config files keep working; passwords kept where MariaDB can use them.</span></span></label>
            <label class="flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" name="email_accounts" value="1" data-email-accounts class="mt-0.5 <?= $check ?>" <?= $opt['email_accounts'] ? 'checked' : '' ?>>
                <span>Email accounts</span></label>
            <label class="flex items-start gap-2 text-sm text-slate-700 pl-6"><input type="checkbox" name="email_data" value="1" data-email-data class="mt-0.5 <?= $check ?>" <?= $opt['email_data'] ? 'checked' : '' ?>>
                <span>Stored email <span class="block text-xs text-slate-400">Every folder and message, with read/flagged state.</span></span></label>
            <div class="pt-2">
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Email passwords</label>
                <select name="mail_passwords" class="<?= $input ?>">
                    <option value="preserve" <?= $opt['mail_passwords'] === 'preserve' ? 'selected' : '' ?>>Keep the existing passwords</option>
                    <option value="generate" <?= $opt['mail_passwords'] === 'generate' ? 'selected' : '' ?>>Generate new passwords (shown in the report)</option>
                </select>
            </div>
            <div class="pt-2">
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Default addresses (catch-all)</label>
                <select name="catch_all" class="<?= $input ?>">
                    <option value="reject" <?= ($opt['catch_all'] ?? 'reject') !== 'keep' ? 'selected' : '' ?>>Reject mail to unknown addresses (recommended)</option>
                    <option value="keep" <?= ($opt['catch_all'] ?? '') === 'keep' ? 'selected' : '' ?>>Keep them as on cPanel</option>
                </select>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 space-y-4">
            <h2 class="text-sm font-semibold text-slate-800">Accounts on this server</h2>
            <div>
                <label class="flex items-center gap-2 text-sm text-slate-700 mb-2"><input type="checkbox" name="match_packages" value="1" class="<?= $check ?>" <?= $opt['match_packages'] ? 'checked' : '' ?>>
                    Use the package with the same name as the cPanel plan, when there is one</label>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Otherwise assign package</label>
                <select name="package_id" class="<?= $input ?>">
                    <option value="">No package</option>
                    <?php foreach ($packages as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= (int) ($opt['package_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> &middot; <?= (int) $p['disk_quota_mb'] ?>MB / <?= (int) $p['max_domains'] ?> domains</option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-xs text-slate-400">Accounts without a package can't add new domains, databases or mailboxes until you assign one.</p>
            </div>
            <?php if ($me['role'] === 'admin'): ?>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Owner</label>
                <select name="owner" class="<?= $input ?>">
                    <?php if ($m['source_type'] === 'root'): ?>
                    <option value="recreate" <?= ($opt['owner'] === 'recreate' || ($opt['owner'] === 'none' && $hasResellers)) ? 'selected' : '' ?>>Keep the cPanel reseller structure</option>
                    <?php endif; ?>
                    <option value="none" <?= $opt['owner'] === 'none' && !($m['source_type'] === 'root' && $hasResellers) ? 'selected' : '' ?>>Owned by the administrator</option>
                    <?php foreach ($resellers as $r): ?>
                    <option value="reseller:<?= (int) $r['id'] ?>">Owned by reseller <?= e($r['username']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($m['source_type'] === 'root'): ?>
                <p class="mt-1 text-xs text-slate-400">Keeping the structure turns each selected cPanel reseller into a JinnPanel reseller (WHM login <span class="font-mono">&lt;name&gt;_whm</span>, same password) that owns its customers. Select the reseller accounts too.</p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <p class="text-xs text-slate-500">Migrated accounts will be owned by you.</p>
            <?php endif; ?>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">HTTPS certificates for migrated domains</label>
                <select name="ssl_mode" class="<?= $input ?>">
                    <option value="auto" <?= !in_array($opt['ssl_mode'], ['letsencrypt', 'self_signed'], true) ? 'selected' : '' ?>>Automatic - Let's Encrypt for domains that point here, the rest once their DNS moves</option>
                    <option value="letsencrypt" <?= $opt['ssl_mode'] === 'letsencrypt' ? 'selected' : '' ?>>Let's Encrypt for all (DNS already points here)</option>
                    <option value="self_signed" <?= $opt['ssl_mode'] === 'self_signed' ? 'selected' : '' ?>>Self-signed only</option>
                </select>
            </div>
        </div>
    </div>

    <details class="bg-white rounded-xl border border-slate-200 shadow-sm px-5 py-4" <?= $m['transfer_mode'] === 'push' ? 'open' : '' ?>>
        <summary class="cursor-pointer text-sm font-semibold text-slate-800">Transfer settings</summary>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <?php if ($m['transfer_mode'] === 'push'): ?>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">This server's address as the source sees it</label>
                <input type="text" name="public_host" value="<?= e((string) $opt['public_host']) ?>" class="<?= $input ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">SFTP port</label>
                <input type="number" name="public_port" min="1" max="65535" value="<?= (int) $opt['public_port'] ?>" class="<?= $input ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Give up if no backup arrives within (minutes)</label>
                <input type="number" name="first_byte_minutes" min="10" max="720" value="<?= (int) $opt['first_byte_minutes'] ?>" class="<?= $input ?>">
            </div>
            <?php endif; ?>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Maximum time per account (hours)</label>
                <input type="number" name="timeout_hours" min="1" max="72" value="<?= (int) $opt['timeout_hours'] ?>" class="<?= $input ?>">
            </div>
        </div>
    </details>

    <div class="flex flex-wrap items-center gap-3">
        <button type="submit" data-start class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium px-5 py-2.5 transition-colors" disabled>
            <?= icon('play', 'h-4 w-4') ?> Start migration
        </button>
        <a href="/whm/migrations/create" class="rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium px-5 py-2.5 transition-colors">Connect to a different server</a>
        <p class="text-xs text-slate-400">Accounts are migrated one after another in the background. The source server is only read from, never changed (a backup file is left in each account's home directory in pull mode).</p>
    </div>
</form>

<form method="post" action="/whm/migrations/<?= (int) $m['id'] ?>/delete" class="mt-2" data-confirm="Discard this migration? Nothing has been copied yet.">
    <?= Csrf::field() ?>
    <button class="text-xs text-slate-400 hover:text-red-600">Discard this migration</button>
</form>

<script>
(function () {
    var form = document.getElementById('migration-select');
    var boxes = Array.prototype.slice.call(form.querySelectorAll('[data-account]:not(:disabled)'));
    var all = form.querySelector('[data-select-all]');
    var countEl = form.querySelector('[data-selected-count]');
    var sizeEl = form.querySelector('[data-selected-size]');
    var start = form.querySelector('[data-start]');
    var mailAcc = form.querySelector('[data-email-accounts]');
    var mailData = form.querySelector('[data-email-data]');
    function fmt(mb) { return mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : mb + ' MB'; }
    function sync() {
        var n = 0, mb = 0;
        boxes.forEach(function (b) { if (b.checked) { n++; mb += parseInt(b.dataset.mb || '0', 10); } });
        countEl.textContent = n;
        sizeEl.textContent = mb > 0 ? ' \u00b7 ' + fmt(mb) : '';
        start.disabled = n === 0;
        if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
        mailData.disabled = !mailAcc.checked;
    }
    if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); sync(); });
    form.addEventListener('change', sync);
    sync();
})();
</script>
