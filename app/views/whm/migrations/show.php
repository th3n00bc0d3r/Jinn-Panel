<?php
include __DIR__ . '/_helpers.php';
$finished = in_array($m['status'], MigrationService::FINISHED, true);
$active = in_array($m['status'], MigrationService::ACTIVE, true);
$retryable = $finished && MigrationService::canRetry($m) && (bool) array_filter($items, fn($i) => in_array($i['status'], ['failed', 'cancelled'], true));
$mailRestorable = fn($i) => $finished && MigrationService::canRetry($m) && in_array($i['status'], ['completed', 'completed_with_errors'], true) && $i['target_user_id'] !== null;
$mailFailed = fn($i) => (bool) array_filter($i['report_data']['email'] ?? [], fn($e) => in_array($e['status'] ?? '', ['failed', 'partial'], true));
$mailRestoreAll = (bool) array_filter($items, fn($i) => $mailRestorable($i) && $mailFailed($i));
$btn = 'inline-flex items-center gap-2 rounded-lg text-sm font-medium px-4 py-2 transition-colors';
$badges = [];
foreach (['pending', 'queued', 'running', 'backing_up', 'transferring', 'restoring', 'completed', 'completed_with_errors', 'failed', 'cancelled', 'stalled'] as $s) {
    $badges[$s] = migration_status_badge($s);
}
$secretLine = function (?string $pw): string {
    return $pw ? ' <span class="font-mono text-xs bg-slate-100 text-slate-800 rounded px-1.5 py-0.5 select-all">' . e($pw) . '</span>' : '';
};
?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
    <div class="flex flex-col lg:flex-row lg:items-center gap-4">
        <div class="flex-1 min-w-0 space-y-1">
            <div class="flex items-center gap-3">
                <p class="font-semibold text-slate-800 truncate"><?= e($m['source_user']) ?>@<?= e($m['source_host']) ?></p>
                <span data-migration-badge><?= migration_status_badge($stalled ? 'stalled' : $m['status']) ?></span>
            </div>
            <p class="text-xs text-slate-500">
                <?= e(migration_source_label($m['source_type'])) ?> &middot; <?= e($m['transfer_mode']) ?> transfer
                <?php if ($m['started_at']): ?> &middot; started <?= e(substr((string) $m['started_at'], 0, 16)) ?><?php endif; ?>
                <?php if ($m['finished_at']): ?> &middot; finished <?= e(substr((string) $m['finished_at'], 0, 16)) ?><?php endif; ?>
                &middot; <?= $m['transfer_mode'] === 'file' ? 'from backup files on this server' : (!empty($m['secret_enc']) ? 'source credentials stored (encrypted)' : 'source credentials deleted') ?>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php if ($active): ?>
            <form method="post" action="/whm/migrations/<?= (int) $m['id'] ?>/cancel" data-confirm="Cancel this migration? The account currently being restored is rolled back; accounts already finished are kept.">
                <?= Csrf::field() ?>
                <button class="<?= $btn ?> bg-slate-100 hover:bg-red-50 text-slate-700 hover:text-red-700"><?= icon('close', 'h-4 w-4') ?> Cancel</button>
            </form>
            <?php endif; ?>
            <?php if ($retryable): ?>
            <form method="post" action="/whm/migrations/<?= (int) $m['id'] ?>/retry">
                <?= Csrf::field() ?>
                <button class="<?= $btn ?> bg-indigo-600 hover:bg-indigo-500 text-white"><?= icon('play', 'h-4 w-4') ?> Retry failed accounts</button>
            </form>
            <?php endif; ?>
            <?php if ($mailRestoreAll): ?>
            <form method="post" action="/whm/migrations/<?= (int) $m['id'] ?>/restore-mail" data-confirm="Fetch every account's backup again and create the mailboxes (with their stored mail) that aren't on this server yet? Nothing else is changed.">
                <?= Csrf::field() ?>
                <button class="<?= $btn ?> bg-white border border-slate-300 hover:bg-slate-50 text-slate-700"><?= icon('mail', 'h-4 w-4') ?> Restore missing mailboxes</button>
            </form>
            <?php endif; ?>
            <?php if (!empty($m['secret_enc']) && (!$active || $stalled)): ?>
            <form method="post" action="/whm/migrations/<?= (int) $m['id'] ?>/discard-secret" data-confirm="Delete the stored source credentials? You won't be able to retry this migration afterwards.">
                <?= Csrf::field() ?>
                <button class="<?= $btn ?> bg-slate-100 hover:bg-slate-200 text-slate-700"><?= icon('shield', 'h-4 w-4') ?> Forget credentials</button>
            </form>
            <?php endif; ?>
            <?php if (!$active || $stalled): ?>
            <form method="post" action="/whm/migrations/<?= (int) $m['id'] ?>/delete" data-confirm="Delete this migration record and its log? Migrated accounts are kept.">
                <?= Csrf::field() ?>
                <button class="p-2 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50" title="Delete record"><?= icon('trash', 'h-4 w-4') ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($stalled): ?>
    <div class="mt-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm px-4 py-3">
        The runner hasn't reported progress for over 10 minutes, so it has probably stopped. Cancel the migration, then use Retry.
        On the server, <span class="font-mono">journalctl -u jinnpanel-migration-<?= (int) $m['id'] ?></span> shows what happened.
    </div>
    <?php elseif ($m['status'] === 'queued'): ?>
    <div class="mt-4 rounded-lg bg-sky-50 border border-sky-200 text-sky-800 text-sm px-4 py-3" data-queued-note>
        Waiting for the background worker to pick this up (normally within a few seconds).
    </div>
    <?php endif; ?>
</div>

<?php foreach ($items as $it): ?>
<?php
    $r = $it['report_data'];
    $itemDone = in_array($it['status'], ['completed', 'completed_with_errors', 'failed', 'cancelled'], true);
    $acct = $r['account'] ?? [];
?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden" data-item="<?= (int) $it['id'] ?>" data-item-status="<?= e($it['status']) ?>">
    <div class="px-5 py-4">
        <div class="flex flex-wrap items-center gap-3">
            <p class="font-semibold text-slate-800"><?= e($it['source_username']) ?></p>
            <?php if ((int) $it['is_reseller'] === 1): ?><span class="inline-flex text-xs font-medium rounded-full px-2 py-0.5 bg-violet-50 text-violet-700">reseller</span><?php endif; ?>
            <p class="text-sm text-slate-400"><?= e($it['source_domain'] ?? '') ?></p>
            <span class="ml-auto" data-item-badge><?= migration_status_badge($it['status']) ?></span>
            <?php if ($mailRestorable($it) && !empty($it['report_data']['email'])): ?>
            <form method="post" action="/whm/migrations/<?= (int) $m['id'] ?>/restore-mail" data-confirm="Fetch this account's backup again and create the mailboxes (with their stored mail) that aren't on this server yet? Nothing else is changed.">
                <?= Csrf::field() ?>
                <input type="hidden" name="item" value="<?= (int) $it['id'] ?>">
                <button class="text-xs font-medium <?= $mailFailed($it) ? 'text-indigo-600 hover:text-indigo-500' : 'text-slate-500 hover:text-slate-700' ?>">Restore mail</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="mt-3 h-1.5 rounded-full bg-slate-100 overflow-hidden">
            <div data-bar class="h-full rounded-full transition-all duration-500 <?= $it['status'] === 'failed' ? 'bg-red-500' : ($it['status'] === 'completed_with_errors' ? 'bg-amber-500' : 'bg-indigo-500') ?>" style="width: <?= max(0, min(100, (int) $it['progress'])) ?>%"></div>
        </div>
        <p class="mt-2 text-xs text-slate-500" data-step><?= e((string) $it['step']) ?></p>
        <p class="mt-1 text-sm text-red-700 <?= $it['error'] ? '' : 'hidden' ?>" data-error><?= e((string) $it['error']) ?></p>
    </div>

    <?php if ($itemDone && ($acct || !empty($r['warnings']) || !empty($r['domains']))): ?>
    <div class="border-t border-slate-100 px-5 py-4 space-y-4 text-sm">
        <?php if ($acct): ?>
        <div class="grid gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-4">
            <p><span class="text-slate-400">Login</span> <span class="font-medium text-slate-800"><?= e($acct['username'] ?? '') ?></span></p>
            <p><span class="text-slate-400">Password</span>
                <?= ($acct['password'] ?? '') === 'generated' ? 'new:' . $secretLine($acct['generated_password'] ?? null) : '<span class="text-slate-700">same as on cPanel</span>' ?></p>
            <p><span class="text-slate-400">Package</span> <span class="text-slate-700"><?= e($acct['package'] ?? 'none') ?></span></p>
            <p><span class="text-slate-400">Owner</span> <span class="text-slate-700"><?= e($acct['owner'] ?? 'administrator') ?></span></p>
            <?php if (!empty($acct['reseller_login'])): ?>
            <p class="sm:col-span-2 lg:col-span-4"><span class="text-slate-400">Reseller WHM login</span> <span class="font-medium text-slate-800"><?= e($acct['reseller_login']) ?></span> <span class="text-slate-500">(same password)</span></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
        $sections = [
            'domains' => ['Domains', fn($d) => e($d['name']) . ' <span class="text-slate-400">' . e($d['type'] ?? '') . '</span>'],
            'databases' => ['Databases', fn($d) => e($d['name']) . (isset($d['size']) ? ' <span class="text-slate-400">' . e($d['size']) . '</span>' : '')],
            'db_users' => ['MySQL users', fn($d) => e($d['name'])
                . (!empty($d['databases']) ? ' <span class="text-slate-400">&rarr; ' . e(implode(', ', $d['databases'])) . '</span>' : '')
                . (($d['password'] ?? '') === 'generated' ? ' &middot; new password:' . $secretLine($d['generated_password'] ?? null) : '')],
            'forwarders' => ['Forwarders', fn($d) => e($d['address'])],
            'ftp' => ['FTP accounts', fn($d) => e($d['name'])],
            'email' => ['Email accounts', fn($d) => e($d['address'])
                . (isset($d['messages']) ? ' <span class="text-slate-400">' . (int) $d['messages'] . ' messages</span>' : '')
                . (($d['password'] ?? '') === 'generated' ? ' &middot; new password:' . $secretLine($d['generated_password'] ?? null) : '')],
        ];
        ?>
        <div class="grid gap-4 lg:grid-cols-2">
        <?php foreach ($sections as $key => [$heading, $fmt]): ?>
            <?php if (empty($r[$key])) continue; ?>
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1.5"><?= e($heading) ?></h3>
                <ul class="space-y-1">
                    <?php foreach ($r[$key] as $row): ?>
                    <li>
                        <span class="font-medium <?= migration_result_class((string) ($row['status'] ?? '')) ?>"><?= e((string) ($row['status'] ?? '')) ?></span>
                        <span class="text-slate-700"><?= $fmt($row) ?></span>
                        <?php if (!empty($row['note'])): ?><span class="block text-xs text-slate-500 pl-1"><?= e($row['note']) ?></span><?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
        </div>

        <?php if (!empty($r['warnings'])): ?>
        <div class="rounded-lg bg-amber-50 border border-amber-200 text-amber-900 px-4 py-3">
            <ul class="list-disc list-inside space-y-1"><?php foreach ($r['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>
        <?php if (!empty($r['info'])): ?>
        <ul class="text-xs text-slate-500 space-y-1"><?php foreach ($r['info'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<details class="bg-slate-950 rounded-xl shadow-sm overflow-hidden" <?= $active ? 'open' : '' ?>>
    <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-300">Runner log</summary>
    <pre data-log class="px-5 pb-4 text-xs leading-relaxed text-slate-300 font-mono whitespace-pre-wrap break-all max-h-96 overflow-y-auto"><?= e($log !== '' ? $log : 'No output yet.') ?></pre>
</details>

<?php if (!$finished): ?>
<script>
(function () {
    var url = '/whm/migrations/<?= (int) $m['id'] ?>/status';
    var badges = <?= json_encode($badges, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var logEl = document.querySelector('[data-log]');
    var done = ['completed', 'completed_with_errors', 'failed', 'cancelled'];
    function tick() {
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                var reload = d.finished;
                document.querySelector('[data-migration-badge]').innerHTML = badges[d.stalled ? 'stalled' : d.status] || d.status;
                if (d.status !== 'queued') {
                    var q = document.querySelector('[data-queued-note]');
                    if (q) q.remove();
                }
                d.items.forEach(function (it) {
                    var card = document.querySelector('[data-item="' + it.id + '"]');
                    if (!card) return;
                    if (card.dataset.itemStatus !== it.status && done.indexOf(it.status) !== -1) reload = true;
                    card.querySelector('[data-item-badge]').innerHTML = badges[it.status] || it.status;
                    card.querySelector('[data-bar]').style.width = Math.max(0, Math.min(100, it.progress)) + '%';
                    card.querySelector('[data-step]').textContent = it.step;
                    var err = card.querySelector('[data-error]');
                    err.textContent = it.error || '';
                    err.classList.toggle('hidden', !it.error);
                });
                if (logEl && d.log) {
                    var atBottom = logEl.scrollTop + logEl.clientHeight >= logEl.scrollHeight - 20;
                    logEl.textContent = d.log;
                    if (atBottom) logEl.scrollTop = logEl.scrollHeight;
                }
                if (reload) { window.location.reload(); return; }
                setTimeout(tick, 3000);
            })
            .catch(function () { setTimeout(tick, 6000); });
    }
    if (logEl) logEl.scrollTop = logEl.scrollHeight;
    setTimeout(tick, 2000);
})();
</script>
<?php endif; ?>
