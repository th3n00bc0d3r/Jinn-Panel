<?php
/** @var array{enabled:bool,hour:int,keep:int,s3:?array} $settings */
/** @var list<array<string,mixed>> $backups */
/** @var list<array<string,mixed>> $accounts */
/** @var int $free */
$card = 'bg-white rounded-xl border border-slate-200 shadow-sm';
$input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500';
$label = 'block text-xs font-medium text-slate-600 mb-1';
$s3 = $settings['s3'];
$badge = fn(string $st) => match ($st) {
    'done' => 'bg-emerald-50 text-emerald-700', 'failed' => 'bg-red-50 text-red-700',
    'running', 'restoring', 'queued' => 'bg-sky-50 text-sky-700', default => 'bg-slate-100 text-slate-600',
};
$busy = (bool) array_filter($backups, fn($b) => in_array($b['status'], ['queued', 'running', 'restoring'], true));
?>
<?php if ($busy): ?>
<div class="rounded-lg border border-sky-200 bg-sky-50 text-sky-800 text-sm px-4 py-3">A backup or restore is running... <script>setTimeout(function () { location.reload(); }, 8000);</script></div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <form method="post" action="/whm/backups/settings" class="<?= $card ?> p-5 lg:col-span-2 space-y-4">
        <?= Csrf::field() ?>
        <div>
            <h2 class="text-sm font-semibold text-slate-700">Schedule</h2>
            <p class="text-xs text-slate-400 mt-0.5">Every day at this hour, each hosting account (site files, databases, mail) and then the server itself (panel database, every service's configuration) are backed up to <code>/var/backups/jinnpanel</code>. <?= e(fmt_bytes($free)) ?> free there now.</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?> class="rounded border-slate-300"> Daily backups on</label>
            <div><label class="<?= $label ?>">At (server time)</label>
                <select name="hour" class="<?= $input ?> bg-white"><?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>" <?= $h === $settings['hour'] ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?></select></div>
            <div><label class="<?= $label ?>">Keep the newest</label>
                <input type="number" name="keep" min="1" max="30" value="<?= (int) $settings['keep'] ?>" class="<?= $input ?>"></div>
        </div>
        <div>
            <h3 class="text-sm font-semibold text-slate-700">Off-site copy (optional)</h3>
            <p class="text-xs text-slate-400 mt-0.5">Each backup is also uploaded to S3-compatible storage (AWS S3, Backblaze B2, Wasabi, Cloudflare R2, MinIO...) - a copy that survives losing this server. Leave the bucket empty to turn it off.</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div><label class="<?= $label ?>">Endpoint</label><input name="s3_endpoint" value="<?= e($s3['endpoint'] ?? '') ?>" placeholder="https://s3.eu-central-1.amazonaws.com" class="<?= $input ?>"></div>
            <div><label class="<?= $label ?>">Region</label><input name="s3_region" value="<?= e($s3['region'] ?? '') ?>" placeholder="eu-central-1" class="<?= $input ?>"></div>
            <div><label class="<?= $label ?>">Bucket</label><input name="s3_bucket" value="<?= e($s3['bucket'] ?? '') ?>" class="<?= $input ?>"></div>
            <div><label class="<?= $label ?>">Folder (prefix)</label><input name="s3_prefix" value="<?= e($s3['prefix'] ?? '') ?>" placeholder="jinnpanel" class="<?= $input ?>"></div>
            <div><label class="<?= $label ?>">Access key</label><input name="s3_access_key" value="<?= e($s3['access_key'] ?? '') ?>" autocomplete="off" class="<?= $input ?>"></div>
            <div><label class="<?= $label ?>">Secret key <?= !empty($s3['secret_enc']) ? '(saved - leave empty to keep)' : '' ?></label><input type="password" name="s3_secret" autocomplete="new-password" class="<?= $input ?>"></div>
        </div>
        <button class="rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2">Save</button>
    </form>

    <div class="<?= $card ?> p-5 space-y-3">
        <h2 class="text-sm font-semibold text-slate-700">Back up now</h2>
        <form method="post" action="/whm/backups/run" class="space-y-2">
            <?= Csrf::field() ?>
            <select name="target" class="<?= $input ?> bg-white">
                <option value="all">Every account, then the server</option>
                <option value="server">The server only (panel DB, configuration)</option>
                <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['username']) ?></option><?php endforeach; ?>
            </select>
            <button class="w-full rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium px-4 py-2">Start backup</button>
        </form>
        <p class="text-xs text-slate-400">Restores bring back the parts you choose into the account as it is now: site files are written over (nothing is deleted), databases are replaced, mail that's already there is skipped.</p>
    </div>
</div>

<div class="<?= $card ?> overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-700">Backups</h2></div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="text-left px-5 py-2">Taken</th><th class="text-left px-3 py-2">Of</th><th class="text-left px-3 py-2">Status</th><th class="text-left px-3 py-2">Size</th><th class="text-left px-3 py-2">Contents</th><th class="px-3 py-2"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($backups as $b): $parts = json_decode((string) $b['parts'], true) ?: []; $files = BackupService::files($b); ?>
                <tr class="align-top">
                    <td class="px-5 py-2 whitespace-nowrap text-slate-600"><?= e((string) $b['created_at']) ?></td>
                    <td class="px-3 py-2 whitespace-nowrap font-medium"><?= $b['kind'] === 'server' ? 'Server' : e((string) $b['username']) ?></td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <span class="text-xs font-medium rounded-full px-2 py-0.5 <?= $badge((string) $b['status']) ?>"><?= e((string) $b['status']) ?></span>
                        <?php if ($b['remote'] === 'uploaded'): ?><span class="text-xs text-emerald-700" title="Off-site copy uploaded">+ off-site</span><?php elseif ($b['remote'] === 'failed'): ?><span class="text-xs text-red-600">off-site failed</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap text-slate-600"><?= (int) $b['size_bytes'] > 0 ? e(fmt_bytes((int) $b['size_bytes'])) : '' ?></td>
                    <td class="px-3 py-2 text-xs text-slate-500">
                        <?php if ($b['kind'] === 'account' && $parts): ?>
                            <?= count($parts['domains'] ?? []) ?> site(s), <?= count($parts['databases'] ?? []) ?> database(s), <?= count($parts['mailboxes'] ?? []) ?> mailbox(es)
                        <?php endif; ?>
                        <?php if ($files): ?><div class="mt-1 flex flex-wrap gap-2"><?php foreach (array_keys($files) as $f): ?><a class="text-indigo-700 hover:underline" href="/whm/backups/<?= (int) $b['id'] ?>/download?file=<?= e(rawurlencode($f)) ?>"><?= e($f) ?></a><?php endforeach; ?></div><?php endif; ?>
                        <?php if ($b['log']): ?><details class="mt-1"><summary class="cursor-pointer">Log</summary><pre class="mt-1 whitespace-pre-wrap bg-slate-50 rounded p-2 max-h-48 overflow-y-auto"><?= e((string) $b['log']) ?></pre></details><?php endif; ?>
                    </td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">
                        <?php if ($b['kind'] === 'account' && $b['status'] === 'done' && $b['user_id'] !== null): ?>
                        <form method="post" action="/whm/backups/<?= (int) $b['id'] ?>/restore" class="inline-flex items-center gap-2 text-xs" data-confirm="Restore the chosen parts of this backup into <?= e((string) $b['username']) ?>? Files are written over, databases replaced.">
                            <?= Csrf::field() ?>
                            <label><input type="checkbox" name="parts[]" value="files" checked> files</label>
                            <label><input type="checkbox" name="parts[]" value="databases" checked> DBs</label>
                            <label><input type="checkbox" name="parts[]" value="mail" checked> mail</label>
                            <button class="font-medium text-indigo-700 hover:text-indigo-600">Restore</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$backups): ?><tr><td colspan="6" class="px-5 py-8 text-center text-slate-400">No backups yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
