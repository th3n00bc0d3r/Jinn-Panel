<?php include __DIR__ . '/_helpers.php'; ?>
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
    <p class="text-sm text-slate-500 max-w-2xl">
        Move accounts from a cPanel &amp; WHM server - site files, databases, email accounts and stored mail - in one click.
        A single cPanel account, all accounts of a WHM reseller<?= Auth::isAdmin() ? ', or a whole server through WHM root' : '' ?>.
    </p>
    <a href="/whm/migrations/create" class="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 transition-colors shrink-0">
        <?= icon('transfer', 'h-4 w-4') ?> New migration
    </a>
</div>

<?php if (Auth::isAdmin()): ?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 mb-4">
    <div class="flex flex-col md:flex-row md:items-start gap-4">
        <div class="flex-1">
            <h2 class="text-sm font-semibold text-slate-800">From backup files</h2>
            <p class="text-sm text-slate-500 mt-1">
                Restore cPanel full backups already on this server - copied from offsite storage, cPanel's scheduled backups or cpmove files.
                Put them in <span class="font-mono text-slate-700"><?= e($importDir) ?></span> (readable by frankenphp), named
                <span class="font-mono">cpmove-&lt;user&gt;.tar.gz</span>, <span class="font-mono">backup-&lt;date&gt;_&lt;user&gt;.tar.gz</span> or <span class="font-mono">&lt;user&gt;.tar.gz</span>.
            </p>
            <?php if ($backupFiles): ?>
            <p class="text-xs text-slate-400 mt-2"><?= count($backupFiles) ?> found: <?= e(implode(', ', array_keys($backupFiles))) ?></p>
            <?php endif; ?>
        </div>
        <form method="post" action="/whm/migrations/from-files" class="shrink-0">
            <?= Csrf::field() ?>
            <button class="inline-flex items-center gap-2 rounded-lg <?= $backupFiles ? 'bg-indigo-600 hover:bg-indigo-500 text-white' : 'bg-slate-100 text-slate-400 cursor-not-allowed' ?> text-sm font-medium px-4 py-2 transition-colors" <?= $backupFiles ? '' : 'disabled' ?>>
                <?= icon('upload', 'h-4 w-4') ?> Choose accounts
            </button>
        </form>
    </div>
    <details class="mt-4 border-t border-slate-100 pt-4" <?= $s3Fetches && in_array($s3Fetches[0]['status'], ['queued', 'running'], true) ? 'open' : '' ?>>
        <summary class="cursor-pointer text-sm font-medium text-indigo-700 hover:text-indigo-600">Fetch backups from S3 (AWS, R2, Wasabi, Backblaze, MinIO)</summary>
        <form method="post" action="/whm/migrations/s3-fetch" class="mt-3 grid gap-3 md:grid-cols-3" autocomplete="off">
            <?= Csrf::field() ?>
            <div><label class="block text-xs font-medium text-slate-600 mb-1">Bucket</label><input name="bucket" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            <div><label class="block text-xs font-medium text-slate-600 mb-1">Prefix (folder)</label><input name="prefix" placeholder="cpanel/2026-09-30/" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono"></div>
            <div><label class="block text-xs font-medium text-slate-600 mb-1">Region</label><input name="region" placeholder="us-east-1 (auto for R2)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            <div><label class="block text-xs font-medium text-slate-600 mb-1">Endpoint (blank for AWS)</label><input name="endpoint" placeholder="https://s3.eu-central-003.backblazeb2.com" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono"></div>
            <div><label class="block text-xs font-medium text-slate-600 mb-1">Access key</label><input name="access_key" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono"></div>
            <div><label class="block text-xs font-medium text-slate-600 mb-1">Secret key</label><input type="password" name="secret_key" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono"></div>
            <div class="md:col-span-3 flex flex-wrap items-center gap-3">
                <button class="rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium px-4 py-2">Fetch into the import folder</button>
                <span class="text-xs text-slate-400">Downloads every cpmove-/backup-*.tar.gz under the prefix. The secret key is stored encrypted only until the fetch ends; a read-only key is enough.</span>
            </div>
        </form>
        <?php if ($s3Fetches): ?>
        <ul class="mt-4 space-y-1 text-xs">
            <?php foreach ($s3Fetches as $f): ?>
            <li class="flex flex-wrap gap-2">
                <span class="font-medium <?= $f['status'] === 'done' ? 'text-emerald-700' : ($f['status'] === 'failed' ? 'text-red-700' : 'text-amber-700') ?>"><?= e($f['status']) ?></span>
                <span class="font-mono text-slate-600">s3://<?= e($f['bucket'] . '/' . $f['prefix']) ?></span>
                <span class="text-slate-500"><?= e((string) $f['progress']) ?></span>
                <span class="text-slate-400 ml-auto"><?= e((string) $f['created_at']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if (in_array($s3Fetches[0]['status'], ['queued', 'running'], true)): ?>
        <script>setTimeout(function () { location.reload(); }, 8000);</script>
        <?php endif; ?>
        <?php endif; ?>
    </details>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left font-medium px-5 py-3">#</th>
                <th class="text-left font-medium px-5 py-3">Source</th>
                <th class="text-left font-medium px-5 py-3">Accounts</th>
                <th class="text-left font-medium px-5 py-3">Status</th>
                <?php if ($me['role'] === 'admin'): ?><th class="text-left font-medium px-5 py-3">Started by</th><?php endif; ?>
                <th class="text-left font-medium px-5 py-3">Created</th>
                <th class="text-right font-medium px-5 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($migrations as $mg): ?>
            <?php $stalled = MigrationService::isStalled($mg); ?>
            <tr class="hover:bg-slate-50/70">
                <td class="px-5 py-3 text-slate-400"><?= (int) $mg['id'] ?></td>
                <td class="px-5 py-3">
                    <p class="font-medium text-slate-800"><?= e($mg['source_user']) ?>@<?= e($mg['source_host']) ?></p>
                    <p class="text-xs text-slate-400"><?= e(migration_source_label($mg['source_type'])) ?> &middot; <?= e($mg['transfer_mode']) ?> transfer</p>
                </td>
                <td class="px-5 py-3 text-slate-600">
                    <?php if ($mg['status'] === 'draft'): ?>
                        <span class="text-slate-400">not chosen yet</span>
                    <?php else: ?>
                        <?= (int) $mg['done_count'] ?> / <?= (int) $mg['item_count'] ?> done
                    <?php endif; ?>
                </td>
                <td class="px-5 py-3"><?= migration_status_badge($stalled ? 'stalled' : $mg['status']) ?></td>
                <?php if ($me['role'] === 'admin'): ?><td class="px-5 py-3 text-slate-500"><?= e($mg['creator']) ?></td><?php endif; ?>
                <td class="px-5 py-3 text-slate-500 whitespace-nowrap"><?= e(substr((string) $mg['created_at'], 0, 16)) ?></td>
                <td class="px-5 py-3 text-right">
                    <a href="/whm/migrations/<?= (int) $mg['id'] ?><?= $mg['status'] === 'draft' ? '/select' : '' ?>" class="text-indigo-600 hover:text-indigo-500 font-medium">
                        <?= $mg['status'] === 'draft' ? 'Continue' : 'Open' ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$migrations): ?>
            <tr><td colspan="7" class="px-5 py-12 text-center text-slate-400">
                No migrations yet. <a href="/whm/migrations/create" class="text-indigo-600 hover:text-indigo-500 font-medium">Connect to a cPanel server</a> to get started.
            </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
