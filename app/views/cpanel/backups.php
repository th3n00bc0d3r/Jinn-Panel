<?php
/** @var list<array<string,mixed>> $backups */
/** @var array{enabled:bool,hour:int,keep:int,s3:?array} $settings */
$card = 'bg-white rounded-xl border border-slate-200 shadow-sm';
$busy = (bool) array_filter($backups, fn($b) => in_array($b['status'], ['queued', 'running', 'restoring'], true));
$labels = ['files.tar.gz' => 'Site files', 'mail.tar.gz' => 'Mail'];
?>
<?php if ($busy): ?>
<div class="rounded-lg border border-sky-200 bg-sky-50 text-sky-800 text-sm px-4 py-3">Working on it... <script>setTimeout(function () { location.reload(); }, 8000);</script></div>
<?php endif; ?>

<div class="<?= $card ?> p-5 flex flex-wrap items-center gap-4">
    <div class="flex-1 min-w-[16rem]">
        <h2 class="text-sm font-semibold text-slate-700">Backups of your account</h2>
        <p class="text-xs text-slate-400 mt-0.5">
            Your websites' files, databases and mail.
            <?= $settings['enabled'] ? 'Taken automatically every day at ' . sprintf('%02d:00', $settings['hour']) . ' (server time); the newest ' . (int) $settings['keep'] . ' are kept.' : 'Automatic backups are off on this server.' ?>
        </p>
    </div>
    <form method="post" action="/cpanel/backups">
        <?= Csrf::field() ?>
        <button class="rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium px-4 py-2" <?= $busy ? 'disabled' : '' ?>>Back up now</button>
    </form>
</div>

<div class="<?= $card ?> overflow-hidden">
    <ul class="divide-y divide-slate-100 text-sm">
        <?php foreach ($backups as $b): $files = BackupService::files($b); ?>
        <li class="px-5 py-3">
            <div class="flex flex-wrap items-center gap-3">
                <span class="font-medium text-slate-800"><?= e((string) $b['created_at']) ?></span>
                <span class="text-xs font-medium rounded-full px-2 py-0.5 <?= $b['status'] === 'done' ? 'bg-emerald-50 text-emerald-700' : ($b['status'] === 'failed' ? 'bg-red-50 text-red-700' : 'bg-sky-50 text-sky-700') ?>"><?= e((string) $b['status']) ?></span>
                <?php if ((int) $b['size_bytes'] > 0): ?><span class="text-xs text-slate-500"><?= e(fmt_bytes((int) $b['size_bytes'])) ?></span><?php endif; ?>
                <span class="ml-auto flex flex-wrap gap-3 text-xs">
                    <?php foreach (array_keys($files) as $f): ?>
                    <a class="text-sky-700 hover:underline" href="/cpanel/backups/<?= (int) $b['id'] ?>/download?file=<?= e(rawurlencode($f)) ?>"><?= icon('download', 'h-3.5 w-3.5 inline') ?> <?= e($labels[$f] ?? 'Database ' . preg_replace('/^db-|\.sql\.gz$/', '', $f)) ?></a>
                    <?php endforeach; ?>
                </span>
            </div>
            <?php if ($b['status'] === 'done'): ?>
            <form method="post" action="/cpanel/backups/<?= (int) $b['id'] ?>/restore" class="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-600" data-confirm="Restore the chosen parts from this backup? Your current files are written over (nothing is deleted) and the databases are replaced with the backup's.">
                <?= Csrf::field() ?>
                <span class="text-slate-400">Restore:</span>
                <label><input type="checkbox" name="parts[]" value="files" checked> site files</label>
                <label><input type="checkbox" name="parts[]" value="databases"> databases</label>
                <label><input type="checkbox" name="parts[]" value="mail"> mail</label>
                <button class="font-medium text-sky-700 hover:text-sky-600">Restore</button>
            </form>
            <?php elseif ($b['status'] === 'failed' && $b['log']): ?>
            <p class="mt-1 text-xs text-red-600"><?= e((string) (array_slice(explode("\n", (string) $b['log']), -1)[0] ?? '')) ?></p>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
        <?php if (!$backups): ?><li class="px-5 py-8 text-center text-slate-400">No backups yet.</li><?php endif; ?>
    </ul>
</div>
