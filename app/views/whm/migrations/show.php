<?php
/** @var array<string,mixed> $m */
/** @var list<array<string,mixed>> $items selected items, each with report_data */
/** @var bool $stalled */
/** @var string $log tail of the runner log */
/** @var array<string,mixed> $me */
include __DIR__ . '/_helpers.php';
$id = (int) $m['id'];
$finished = in_array($m['status'], MigrationService::FINISHED, true);
$active = in_array($m['status'], MigrationService::ACTIVE, true);
$retryable = $finished && MigrationService::canRetry($m) && (bool) array_filter($items, fn($i) => in_array($i['status'], ['failed', 'cancelled'], true));
$mailRestorable = fn($i) => $finished && MigrationService::canRetry($m) && in_array($i['status'], ['completed', 'completed_with_errors'], true) && $i['target_user_id'] !== null;
$mailFailed = fn($i) => (bool) array_filter($i['report_data']['email'] ?? [], fn($e) => in_array($e['status'] ?? '', ['failed', 'partial'], true));
$mailRestoreAll = (bool) array_filter($items, fn($i) => $mailRestorable($i) && $mailFailed($i));
$itemFinished = ['completed', 'completed_with_errors', 'failed', 'cancelled'];
$badges = [];
foreach (['pending', 'queued', 'running', 'backing_up', 'transferring', 'restoring', 'completed', 'completed_with_errors', 'failed', 'cancelled', 'stalled'] as $s) {
    $badges[$s] = migration_status_badge($s);
}

View::breadcrumbs([['cPanel Migration', '/whm/migrations'], ['Migration #' . $id]]);
View::slot('desc', e(migration_source_name($m) . ' · ' . migration_source_meta($m)));

ob_start();
if ($active): ?>
<form method="post" action="/whm/migrations/<?= $id ?>/cancel" data-confirm="Cancel this migration? The account currently being restored is rolled back; accounts already finished are kept." data-confirm-action="Cancel migration" data-confirm-danger>
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn-secondary"><?= icon('close') ?>Cancel migration</button>
</form>
<?php endif;
if ($retryable): ?>
<form method="post" action="/whm/migrations/<?= $id ?>/retry">
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn-primary"><?= icon('refresh') ?>Retry failed accounts</button>
</form>
<?php endif;
if ($mailRestoreAll): ?>
<form method="post" action="/whm/migrations/<?= $id ?>/restore-mail" data-confirm="Fetch every account's backup again and create the mailboxes (with their stored mail) that aren't on this server yet? Nothing else is changed." data-confirm-action="Restore mailboxes">
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn-secondary"><?= icon('mail') ?>Restore missing mailboxes</button>
</form>
<?php endif;
if (!empty($m['secret_enc']) && (!$active || $stalled)): ?>
<form method="post" action="/whm/migrations/<?= $id ?>/discard-secret" data-confirm="Delete the stored source credentials? You won't be able to retry this migration afterwards." data-confirm-action="Forget credentials">
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn-secondary"><?= icon('shield') ?>Forget credentials</button>
</form>
<?php endif;
if (!$active || $stalled): ?>
<?= Ui::deleteForm("/whm/migrations/$id/delete", 'Delete this migration record and its log? Migrated accounts are kept.', 'Delete migration record') ?>
<?php endif;
View::slot('actions', (string) ob_get_clean());

$total = count($items);
$doneCount = count(array_filter($items, fn($i) => in_array($i['status'], ['completed', 'completed_with_errors'], true)));
$overall = $total > 0 ? array_sum(array_map(fn($i) => in_array($i['status'], $itemFinished, true) ? 100 : max(0, min(100, (int) $i['progress'])), $items)) / $total : 0;
$overallTone = $stalled || $m['status'] === 'failed' ? 'danger' : ($m['status'] === 'completed_with_errors' ? 'warning' : ($m['status'] === 'completed' ? 'success' : 'accent'));
$when = fn(?string $t) => $t ? '<time datetime="' . e(date('c', (int) strtotime($t))) . '" title="' . e($t) . '">' . e(substr($t, 0, 16)) . '</time>' : '<span class="text-subtle">-</span>';

$sections = [
    'domains' => ['Domains', fn($d) => e($d['name']) . (!empty($d['type']) ? ' <span class="text-muted">' . e($d['type']) . '</span>' : '')],
    'databases' => ['Databases', fn($d) => e($d['name']) . (isset($d['size']) ? ' <span class="text-muted">' . e($d['size']) . '</span>' : '')],
    'db_users' => ['MySQL users', fn($d) => e($d['name'])
        . (!empty($d['databases']) ? ' <span class="text-muted">→ ' . e(implode(', ', $d['databases'])) . '</span>' : '')
        . (($d['password'] ?? '') === 'generated' && !empty($d['generated_password']) ? '<span class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-muted">New password ' . Ui::copyable((string) $d['generated_password']) . '</span>' : '')],
    'forwarders' => ['Forwarders', fn($d) => e($d['address'])],
    'ftp' => ['FTP accounts', fn($d) => e($d['name'])],
    'email' => ['Email accounts', fn($d) => e($d['address'])
        . (isset($d['messages']) ? ' <span class="text-muted">' . e(Ui::plural((int) $d['messages'], 'message')) . '</span>' : '')
        . (($d['password'] ?? '') === 'generated' && !empty($d['generated_password']) ? '<span class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-muted">New password ' . Ui::copyable((string) $d['generated_password']) . '</span>' : '')],
];
// For the report's one-line summary: "3 domains · 1 MySQL user".
$nouns = ['domains' => 'domain', 'databases' => 'database', 'db_users' => 'MySQL user', 'forwarders' => 'forwarder', 'ftp' => 'FTP account', 'email' => 'email account'];
?>
<?= migration_steps(3) ?>

<div class="stack">
    <section class="card" aria-labelledby="overview-title">
        <div class="card-body">
            <h2 id="overview-title" class="sr-only">Overview</h2>
            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:gap-10">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-3">
                        <span data-migration-badge><?= migration_status_badge($stalled ? 'stalled' : (string) $m['status']) ?></span>
                        <span class="text-sm text-muted"><span class="font-semibold tabular-nums text-fg" data-overall-done><?= $doneCount ?></span> of <?= e(Ui::plural($total, 'account')) ?> done</span>
                    </div>
                    <div class="mt-3" data-overall><?= Ui::progress($overall, 'Overall progress', $overallTone) ?></div>
                </div>
                <dl class="grid shrink-0 grid-cols-2 gap-x-8 gap-y-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs text-muted">Started</dt><dd class="mt-0.5 tabular-nums text-fg"><?= $when($m['started_at'] ?? null) ?></dd></div>
                    <div><dt class="text-xs text-muted">Finished</dt><dd class="mt-0.5 tabular-nums text-fg"><?= $when($m['finished_at'] ?? null) ?></dd></div>
                    <div class="col-span-2 sm:col-span-1"><dt class="text-xs text-muted">Source credentials</dt><dd class="mt-0.5 text-fg">
                        <?= $m['transfer_mode'] === 'file' ? 'None needed' : (!empty($m['secret_enc']) ? 'Stored, encrypted' : 'Deleted') ?></dd></div>
                </dl>
            </div>
            <?php if ($stalled): ?>
            <div class="mt-5"><?= Ui::alert('<p>The runner hasn\'t reported progress for over 10 minutes, so it has probably stopped. Cancel the migration, then use Retry.</p>'
                . '<p class="mt-1">On the server, ' . Ui::copyable('journalctl -u jinnpanel-migration-' . $id) . ' shows what happened.</p>', 'danger', true) ?></div>
            <?php elseif ($m['status'] === 'queued'): ?>
            <div class="mt-5" data-queued-note><?= Ui::alert('Waiting for the background worker to pick this up (normally within a few seconds).', 'info') ?></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="space-y-4" aria-labelledby="items-title">
        <h2 id="items-title" class="section-title">Accounts</h2>
        <?php foreach ($items as $it):
            $iid = (int) $it['id'];
            $r = $it['report_data'];
            $itemDone = in_array($it['status'], $itemFinished, true);
            $acct = $r['account'] ?? [];
            $tone = match ($it['status']) { 'failed' => 'danger', 'completed_with_errors' => 'warning', 'completed' => 'success', default => 'accent' };
            $failures = 0;
            $summary = [];
            foreach ($sections as $key => [$heading]) {
                if (!empty($r[$key])) {
                    $summary[] = Ui::plural(count($r[$key]), $nouns[$key]);
                    $failures += count(array_filter($r[$key], fn($row) => in_array($row['status'] ?? '', ['failed', 'partial'], true)));
                }
            }
            $notes = count($r['warnings'] ?? []);
            $hasReport = $itemDone && ($acct || !empty($r['warnings']) || !empty($r['domains']));
        ?>
        <article class="card" data-item="<?= $iid ?>" data-item-status="<?= e($it['status']) ?>" aria-labelledby="item-<?= $iid ?>">
            <div class="card-body">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <div class="flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-1">
                        <h3 id="item-<?= $iid ?>" class="text-sm font-semibold text-fg"><?= e($it['source_username']) ?></h3>
                        <?php if ((int) $it['is_reseller'] === 1): ?><?= Ui::badge('Reseller', 'accent') ?><?php endif; ?>
                        <?php if (!empty($it['source_domain'])): ?><span class="truncate text-sm text-muted"><?= e($it['source_domain']) ?></span><?php endif; ?>
                    </div>
                    <div class="ml-auto flex items-center gap-2">
                        <span data-item-badge><?= migration_status_badge((string) $it['status']) ?></span>
                        <?php if ($mailRestorable($it) && !empty($r['email'])): ?>
                        <form method="post" action="/whm/migrations/<?= $id ?>/restore-mail" data-confirm="Fetch this account's backup again and create the mailboxes (with their stored mail) that aren't on this server yet? Nothing else is changed." data-confirm-action="Restore mail">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="item" value="<?= $iid ?>">
                            <button type="submit" class="btn <?= $mailFailed($it) ? 'btn-secondary' : 'btn-ghost' ?> btn-sm"><?= icon('mail') ?>Restore mail</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mt-3" data-progress><?= Ui::progress((float) $it['progress'], 'Progress of ' . $it['source_username'], $tone) ?></div>
                <p class="mt-2 text-xs text-muted" data-step><?= e((string) $it['step']) ?></p>
                <p class="mt-1 text-sm text-danger-text" data-error <?= $it['error'] ? '' : 'hidden' ?>><?= e((string) $it['error']) ?></p>
            </div>

            <?php if ($hasReport): ?>
            <div class="space-y-5 border-t border-line px-5 py-4 text-sm">
                <?php if ($acct): ?>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 lg:grid-cols-4">
                    <div><dt class="text-xs text-muted">Login</dt><dd class="mt-0.5 font-medium text-fg"><?= e($acct['username'] ?? '') ?></dd></div>
                    <div><dt class="text-xs text-muted">Password</dt><dd class="mt-0.5 text-fg">
                        <?= ($acct['password'] ?? '') === 'generated' && !empty($acct['generated_password']) ? Ui::copyable((string) $acct['generated_password']) : 'Same as on cPanel' ?></dd></div>
                    <div><dt class="text-xs text-muted">Package</dt><dd class="mt-0.5 text-fg"><?= e($acct['package'] ?? 'none') ?></dd></div>
                    <div><dt class="text-xs text-muted">Owner</dt><dd class="mt-0.5 text-fg"><?= e($acct['owner'] ?? 'administrator') ?></dd></div>
                    <?php if (!empty($acct['reseller_login'])): ?>
                    <div class="col-span-2 lg:col-span-4"><dt class="text-xs text-muted">Reseller WHM login</dt><dd class="mt-0.5 text-fg"><span class="font-medium"><?= e($acct['reseller_login']) ?></span> <span class="text-muted">(same password)</span></dd></div>
                    <?php endif; ?>
                </dl>
                <?php endif; ?>

                <?php if (!empty($r['warnings'])): ?>
                <?php
                $wl = '';
                foreach ($r['warnings'] as $w) {
                    $wl .= '<li>' . e((string) $w) . '</li>';
                }
                echo Ui::alert('<p class="font-medium">' . e(Ui::plural($notes, 'thing needs', 'things need')) . ' attention</p><ul class="mt-1 list-disc space-y-1 pl-4">' . $wl . '</ul>', 'warning', true);
                ?>
                <?php endif; ?>

                <?php if ($summary): ?>
                <details class="disclosure" <?= $failures > 0 || $total <= 2 ? 'open' : '' ?>>
                    <summary>
                        <span class="min-w-0">Report <span class="font-normal text-muted">· <?= e(implode(' · ', $summary)) ?></span>
                        <?php if ($failures): ?><span class="ml-1"><?= Ui::badge(Ui::plural($failures, 'problem'), 'danger') ?></span><?php endif; ?></span>
                    </summary>
                    <div class="mt-4 grid grid-cols-1 gap-x-8 gap-y-5 lg:grid-cols-2">
                        <?php foreach ($sections as $key => [$heading, $fmt]): ?>
                        <?php if (empty($r[$key])) continue; ?>
                        <div class="min-w-0">
                            <h4 class="section-title mb-2"><?= e($heading) ?> <span class="tabular-nums">(<?= count($r[$key]) ?>)</span></h4>
                            <ul class="space-y-1.5">
                                <?php foreach ($r[$key] as $row): ?>
                                <li class="flex min-w-0 items-start gap-2">
                                    <span class="mt-0.5 shrink-0"><?= migration_result_mark((string) ($row['status'] ?? '')) ?></span>
                                    <span class="min-w-0 break-words text-fg"><?= $fmt($row) ?>
                                        <?php if (!empty($row['note'])): ?><span class="block text-xs text-muted"><?= e($row['note']) ?></span><?php endif; ?>
                                    </span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>

                <?php if (!empty($r['info'])): ?>
                <ul class="space-y-1 text-xs text-muted">
                    <?php foreach ($r['info'] as $w): ?><li class="flex gap-1.5"><?= icon('info', 'mt-px h-3.5 w-3.5 shrink-0 text-subtle') ?><span><?= e((string) $w) ?></span></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
        <?php if (!$items): ?>
        <div class="card"><?= Ui::empty('No accounts in this migration', 'Its accounts were removed. Delete this record and start a new migration.', 'users') ?></div>
        <?php endif; ?>
    </section>

    <details class="card disclosure" <?= $active ? 'open' : '' ?>>
        <summary class="px-5 py-4">Runner log</summary>
        <div class="border-t border-line p-5">
            <pre class="log" data-log><?= e($log !== '' ? $log : 'No output yet.') ?></pre>
        </div>
    </details>
</div>

<?php if (!$finished): View::script('migration-show'); ?>
<script type="application/json" id="migration-status"><?= json_encode(['url' => '/whm/migrations/' . $id . '/status', 'badges' => $badges], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
