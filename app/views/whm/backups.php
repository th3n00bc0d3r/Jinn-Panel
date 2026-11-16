<?php
/** @var array{enabled:bool,hour:int,keep:int,s3:?array} $settings */
/** @var list<array<string,mixed>> $backups newest first */
/** @var list<array<string,mixed>> $accounts id, username */
/** @var int $free bytes free where backups are kept */
$s3 = $settings['s3'];
View::slot('desc', e('Each hosting account (site files, databases, mail) and the server itself, backed up every day. Open a backup to download it or restore parts of it.'));
View::slot('actions', '<a href="#backup-now" class="btn btn-primary">' . icon('archive') . 'Back up now</a>');
View::script('whm-backups');

$who = fn(array $b) => $b['kind'] === 'server' ? 'the server' : (string) $b['username'];
$active = array_values(array_filter($backups, fn($b) => in_array($b['status'], ['queued', 'running', 'restoring'], true)));
$working = array_map(fn($b) => match ($b['status']) {
    'running' => 'backing up ' . $who($b),
    'restoring' => 'restoring ' . $who($b),
    default => $who($b) . ' queued',
}, array_slice($active, 0, 3));
if (count($active) > 3) {
    $working[] = (count($active) - 3) . ' more';
}

$status = fn(array $b) => match ((string) $b['status']) {
    'done' => Ui::status('done', 'Done'),
    'failed' => Ui::status('failed', 'Failed'),
    'running' => Ui::status('working', 'Backing up'),
    'restoring' => Ui::status('working', 'Restoring'),
    'queued' => Ui::status('queued', 'Queued'),
    default => Ui::status((string) $b['status']),
};
/** The last log line (containing $needle), without its "[12:00:00] " time. */
$logLine = function (?string $log, string $needle = ''): string {
    $lines = array_values(array_filter(explode("\n", (string) $log), fn($l) => trim($l) !== '' && ($needle === '' || stripos($l, $needle) !== false)));
    return $lines ? (string) preg_replace('/^\[[0-9:]+\]\s*/', '', (string) end($lines)) : '';
};
$taken = function (string $at): string {
    $ts = (int) strtotime($at);
    return '<time datetime="' . e(date('c', $ts)) . '" title="' . e($at) . '" class="font-medium text-fg">' . e(date(date('Y', $ts) === date('Y') ? 'j M, H:i' : 'j M Y, H:i', $ts)) . '</time>'
        . '<p class="text-xs text-muted">' . e(fmt_ago($at)) . '</p>';
};
$fileLabel = fn(string $f) => match (true) {
    $f === 'files.tar.gz' => 'Site files',
    $f === 'mail.tar.gz' => 'Mail',
    (bool) preg_match('/^db-(.+)\.sql\.gz$/', $f, $mm) => 'Database ' . $mm[1],
    default => $f,
};
$hours = [];
for ($h = 0; $h < 24; $h++) {
    $hours[$h] = sprintf('%02d:00', $h);
}
$s3Value = fn(string $field, string $key) => Ui::old($field) ?? (string) ($s3[$key] ?? '');
?>
<div class="stack">
    <?php if ($active): ?>
    <?= Ui::busy(ucfirst(implode(', ', $working)) . '.') ?>
    <?php endif; ?>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <section class="card xl:col-span-2" aria-labelledby="backups-title">
            <div class="card-header">
                <h2 id="backups-title" class="card-title"><?= e(Ui::plural(count($backups), 'backup')) ?></h2>
                <?php if (count($backups) > 8): ?>
                <input type="search" class="input input-sm w-full sm:w-56" placeholder="Filter by account or date…" aria-label="Filter backups" data-filter="#backups-table">
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table class="table" id="backups-table">
                    <thead>
                        <tr>
                            <th scope="col" class="hidden sm:table-cell">Taken</th>
                            <th scope="col">Backup of</th>
                            <th scope="col" class="hidden sm:table-cell">Status</th>
                            <th scope="col" class="hidden text-right md:table-cell">Size</th>
                            <th scope="col" class="text-right"><span class="sr-only">Details</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($backups as $b):
                        $bid = (int) $b['id'];
                        $st = (string) $b['status'];
                        $isServer = $b['kind'] === 'server';
                        $parts = json_decode((string) $b['parts'], true) ?: [];
                        $files = BackupService::files($b);
                        $restorable = !$isServer && $st === 'done' && $b['user_id'] !== null;
                        $reason = $st === 'failed' ? $logLine($b['log'], 'FAILED:') : '';
                        $reason = $reason !== '' ? (string) preg_replace('/^FAILED:\s*/', '', $reason) : '';
                        $nowDoing = in_array($st, ['running', 'restoring'], true) ? ucfirst((string) preg_replace('/^restore:\s*/', '', $logLine($b['log']))) : '';
                        $summary = $isServer
                            ? 'Panel database and configuration'
                            : ($parts ? implode(' · ', [
                                Ui::plural(count($parts['domains'] ?? []), 'site'),
                                Ui::plural(count($parts['databases'] ?? []), 'database'),
                                Ui::plural(count($parts['mailboxes'] ?? []), 'mailbox', 'mailboxes'),
                            ]) : '');
                        $label = ($isServer ? 'server' : $b['username']) . ' backup from ' . date('j M Y, H:i', (int) strtotime((string) $b['created_at']));
                    ?>
                        <?php ob_start(); ?>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <?= $status($b) ?>
                                    <?php if ($b['remote'] === 'uploaded'): ?><span title="A copy was uploaded to the off-site storage"><?= Ui::badge('Off-site', 'neutral') ?></span><?php endif; ?>
                                    <?php if ($b['remote'] === 'failed'): ?><span title="<?= e($logLine($b['log'], 'off-site')) ?>"><?= Ui::badge('Off-site failed', 'danger') ?></span><?php endif; ?>
                                </div>
                                <?php if ($reason !== ''): ?><p class="mt-1 line-clamp-2 text-xs text-danger-text" title="<?= e($reason) ?>"><?= e($reason) ?></p><?php endif; ?>
                                <?php if ($nowDoing !== ''): ?><p class="mt-1 truncate text-xs text-muted" title="<?= e($nowDoing) ?>"><?= e($nowDoing) ?></p><?php endif; ?>
                        <?php $statusHtml = (string) ob_get_clean(); ?>
                        <tr>
                            <td class="hidden whitespace-nowrap sm:table-cell"><?= $taken((string) $b['created_at']) ?></td>
                            <td class="max-w-[11rem] sm:max-w-[16rem]">
                                <div class="flex items-center gap-2">
                                    <?= icon($isServer ? 'server' : 'user', 'h-4 w-4 shrink-0 text-subtle') ?>
                                    <span class="truncate font-medium text-fg"><?= $isServer ? 'Server' : e((string) $b['username']) ?></span>
                                    <?php if (!$isServer && $b['user_id'] === null): ?><span title="This account has been deleted since"><?= Ui::badge('Deleted', 'neutral') ?></span><?php endif; ?>
                                </div>
                                <?php if ($summary !== ''): ?><p class="mt-0.5 truncate text-xs text-muted"><?= e($summary) ?></p><?php endif; ?>
                                <?php /* Phones: no Taken and Status columns - both go here. */ ?>
                                <p class="mt-0.5 text-xs text-muted sm:hidden"><?= e(date('j M, H:i', (int) strtotime((string) $b['created_at']))) ?> · <?= e(fmt_ago((string) $b['created_at'])) ?></p>
                                <div class="mt-2 sm:hidden"><?= $statusHtml ?></div>
                            </td>
                            <td class="hidden max-w-[16rem] sm:table-cell"><?= $statusHtml ?></td>
                            <td class="hidden whitespace-nowrap text-right tabular-nums text-muted md:table-cell"><?= (int) $b['size_bytes'] > 0 ? e(fmt_bytes((int) $b['size_bytes'])) : '<span class="text-subtle">-</span>' ?></td>
                            <td class="text-right">
                                <button type="button" class="btn btn-ghost btn-sm" data-backup-toggle aria-expanded="false" aria-controls="backup-<?= $bid ?>" aria-label="<?= e(($restorable ? 'Restore or download the ' : 'Details of the ') . $label) ?>">
                                    <?= $restorable ? 'Restore' : 'Details' ?><?= icon('chevron-down', 'transition-transform') ?>
                                </button>
                            </td>
                        </tr>
                        <tr id="backup-<?= $bid ?>" class="bg-surface-2 hover:bg-surface-2" data-filter-skip data-backup-detail hidden>
                            <td colspan="5" class="!py-5">
                                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                    <?php $leftEmpty = !$isServer && !$parts && !$files; ?>
                                    <?php if (!$leftEmpty): ?>
                                    <div class="min-w-0 space-y-4">
                                        <?php if ($isServer): ?>
                                        <div>
                                            <h3 class="section-title">Contents</h3>
                                            <p class="mt-1.5 text-sm text-muted">The panel database and the configuration of every service<?= $b['path'] ? ', kept in ' . Ui::copyable((string) $b['path']) . ' (root only)' : '' ?>.</p>
                                        </div>
                                        <?php elseif ($parts): ?>
                                        <div>
                                            <h3 class="section-title">Contents</h3>
                                            <dl class="mt-2 space-y-2 text-sm">
                                                <?php foreach ([['Sites', array_values((array) ($parts['domains'] ?? []))], ['Databases', array_values((array) ($parts['databases'] ?? []))], ['Mailboxes', array_keys((array) ($parts['mailboxes'] ?? []))]] as [$what, $list]): ?>
                                                <div class="flex flex-col gap-1 sm:flex-row sm:gap-3">
                                                    <dt class="w-24 shrink-0 text-muted"><?= e($what) ?></dt>
                                                    <dd class="flex min-w-0 flex-wrap gap-1">
                                                        <?php foreach (array_slice($list, 0, 8) as $x): ?><span class="chip"><?= e((string) $x) ?></span><?php endforeach; ?>
                                                        <?php if (count($list) > 8): ?><span class="chip text-muted">+<?= count($list) - 8 ?> more</span><?php endif; ?>
                                                        <?php if (!$list): ?><span class="text-subtle">None</span><?php endif; ?>
                                                    </dd>
                                                </div>
                                                <?php endforeach; ?>
                                            </dl>
                                        </div>
                                        <?php endif; ?>

                                        <?php if ($files): ?>
                                        <div>
                                            <h3 class="section-title">Download</h3>
                                            <ul class="mt-2 space-y-1">
                                                <?php foreach (array_keys($files) as $f): ?>
                                                <li><a class="link inline-flex items-center gap-2 text-sm" href="/whm/backups/<?= $bid ?>/download?file=<?= e(rawurlencode($f)) ?>"><?= icon('download', 'h-4 w-4') ?><?= e($fileLabel($f)) ?> <span class="font-mono text-xs font-normal text-muted"><?= e($f) ?></span></a></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>

                                    <div class="min-w-0 <?= $leftEmpty ? 'md:col-span-2' : '' ?>">
                                        <?php if ($restorable): ?>
                                        <form method="post" action="/whm/backups/<?= $bid ?>/restore" class="space-y-3" data-confirm="Restore the chosen parts of this backup into <?= e((string) $b['username']) ?>? Files are written over, databases replaced." data-confirm-action="Restore" data-confirm-danger>
                                            <?= Csrf::field() ?>
                                            <h3 class="section-title">Restore into <?= e((string) $b['username']) ?></h3>
                                            <?= Ui::checkbox('parts[]', 'Site files', true, ['value' => 'files', 'hint' => 'Written over the current ones; nothing is deleted.']) ?>
                                            <?= Ui::checkbox('parts[]', 'Databases', true, ['value' => 'databases', 'hint' => 'Replaced with the backed-up copy.']) ?>
                                            <?= Ui::checkbox('parts[]', 'Mail', true, ['value' => 'mail', 'hint' => 'Messages already in the mailbox are skipped.']) ?>
                                            <button type="submit" class="btn btn-secondary btn-sm"><?= icon('refresh') ?>Restore</button>
                                        </form>
                                        <?php elseif (!$isServer && $st === 'done' && $b['user_id'] === null): ?>
                                        <?= Ui::alert('The account has been deleted since, so this backup can\'t be restored here' . ($files ? ' - download its files instead.' : '.'), 'info') ?>
                                        <?php elseif ($st === 'failed'): ?>
                                        <?= Ui::alert('This backup didn\'t finish' . ($reason !== '' ? ': ' . $reason : '.') . ' Fix that, then back the account up again.', 'danger') ?>
                                        <?php elseif (in_array($st, ['queued', 'running', 'restoring'], true)): ?>
                                        <p class="text-sm text-muted">This runs in the background; the log fills in as it goes.</p>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($b['log'])): ?>
                                    <div class="min-w-0 md:col-span-2">
                                        <h3 class="section-title">Log</h3>
                                        <pre class="log mt-2 max-h-60"><?= e((string) $b['log']) ?></pre>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$backups): ?>
                        <?= Ui::emptyRow(5, 'No backups yet', $settings['enabled'] ? 'The first ones are made tonight at ' . sprintf('%02d:00', $settings['hour']) . ' - or back up now.' : 'Daily backups are off. Turn them on below, or back up now.', 'archive', '<a href="#backup-now" class="btn btn-primary">' . icon('archive') . 'Back up now</a>') ?>
                    <?php elseif (count($backups) > 8): ?>
                        <tr data-filter-none data-filter-skip hidden><td colspan="5" class="py-8 text-center text-sm text-muted">No backup matches that filter.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="stack">
            <section class="card scroll-mt-20" id="backup-now" aria-labelledby="run-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="run-title" class="card-title">Back up now</h2>
                        <p class="card-desc">Runs in the background; the list shows when it's done.</p>
                    </div>
                </div>
                <form method="post" action="/whm/backups/run" class="card-body space-y-4">
                    <?= Csrf::field() ?>
                    <div class="field">
                        <label for="backup-target" class="label">What to back up</label>
                        <select name="target" id="backup-target" class="select">
                            <option value="all">Every account, then the server</option>
                            <option value="server">The server only (panel database, configuration)</option>
                            <?php if ($accounts): ?>
                            <optgroup label="One account">
                                <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['username']) ?></option><?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><?= icon('archive') ?>Start backup</button>
                </form>
            </section>

            <section class="card scroll-mt-20" id="schedule" aria-labelledby="schedule-title">
                <div class="card-header">
                    <h2 id="schedule-title" class="card-title">Schedule and storage</h2>
                    <?= $settings['enabled'] ? Ui::badge('Daily at ' . sprintf('%02d:00', $settings['hour']), 'success', true) : Ui::badge('Off', 'neutral', true) ?>
                </div>
                <form method="post" action="/whm/backups/settings">
                    <?= Csrf::field() ?>
                    <div class="card-body space-y-5">
                        <?= Ui::checkbox('enabled', 'Back up every day', $settings['enabled'], ['hint' => 'Each account (site files, databases, mail), then the server (panel database, every service\'s configuration).']) ?>
                        <div class="grid grid-cols-2 gap-4">
                            <?= Ui::select('hour', 'At (server time)', $hours, (string) $settings['hour']) ?>
                            <?= Ui::field('keep', 'Keep the newest', ['type' => 'number', 'value' => (string) (int) $settings['keep'], 'hint' => '1 to 30, per account.', 'attrs' => ['min' => 1, 'max' => 30]]) ?>
                        </div>
                        <p class="flex items-start gap-2 text-xs text-muted"><?= icon('hard-drive', 'mt-px h-3.5 w-3.5 shrink-0') ?><span>Kept in <code class="text-fg"><?= e(BackupService::DIR) ?></code> on this server. <?= e(fmt_bytes($free)) ?> free there now.</span></p>

                        <details class="disclosure border-t border-line pt-5" <?= $s3 || Ui::old('s3_bucket') !== null ? 'open' : '' ?>>
                            <summary>
                                <span>Off-site copy <span class="font-normal text-subtle">(optional)</span></span>
                                <span class="ml-auto"><?= $s3 ? Ui::badge('On', 'success', true) : Ui::badge('Off', 'neutral', true) ?></span>
                            </summary>
                            <div class="mt-4 space-y-4">
                                <p class="text-xs text-muted">Each backup is also uploaded to S3-compatible storage (AWS S3, Backblaze B2, Wasabi, Cloudflare R2, MinIO…): a copy that survives losing this server. Leave the bucket empty to turn it off.</p>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-1">
                                    <?= Ui::field('s3_endpoint', 'Endpoint', ['value' => $s3Value('s3_endpoint', 'endpoint'), 'mono' => true, 'placeholder' => 'https://s3.eu-central-1.amazonaws.com', 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false', 'inputmode' => 'url']]) ?>
                                    <?= Ui::field('s3_region', 'Region', ['value' => $s3Value('s3_region', 'region'), 'placeholder' => 'eu-central-1', 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
                                    <?= Ui::field('s3_bucket', 'Bucket', ['value' => $s3Value('s3_bucket', 'bucket'), 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
                                    <?= Ui::field('s3_prefix', 'Folder (prefix)', ['value' => $s3Value('s3_prefix', 'prefix'), 'optional' => true, 'mono' => true, 'placeholder' => 'jinnpanel', 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
                                    <?= Ui::field('s3_access_key', 'Access key', ['value' => (string) ($s3['access_key'] ?? ''), 'mono' => true, 'attrs' => ['autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
                                    <?= Ui::field('s3_secret', 'Secret key', [
                                        'type' => 'password', 'mono' => true,
                                        'placeholder' => !empty($s3['secret_enc']) ? '•••••••• (saved)' : '',
                                        'hint' => !empty($s3['secret_enc']) ? 'Saved. Leave it empty to keep it.' : '',
                                        'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
                                    ]) ?>
                                </div>
                            </div>
                        </details>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary"><?= icon('save') ?>Save settings</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</div>
