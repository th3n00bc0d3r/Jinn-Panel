<?php
/** @var list<array<string,mixed>> $backups */
/** @var array{enabled:bool,hour:int,keep:int,s3:?array} $settings */
$busy = (bool) array_filter($backups, fn($b) => in_array($b['status'], ['queued', 'running', 'restoring'], true));
View::slot('desc', e('Copies of your websites\' files, databases and mail, to download or to restore.'));
View::slot('actions', '<form method="post" action="/cpanel/backups">' . Csrf::field()
    . '<button type="submit" class="btn btn-primary"' . ($busy ? ' disabled title="A backup or restore is running"' : '') . '>' . icon('archive') . 'Back up now</button></form>');
View::script('backups');

$labels = ['files.tar.gz' => 'Site files', 'mail.tar.gz' => 'Mail'];
$fileLabel = fn(string $f) => $labels[$f] ?? 'Database ' . preg_replace('/^db-|\.sql\.gz$/', '', $f);
$statuses = ['done' => ['Done', 'success'], 'failed' => ['Failed', 'danger'], 'queued' => ['Queued', 'neutral'], 'running' => ['Running', 'info'], 'restoring' => ['Restoring', 'info']];
$when = fn(string $ts) => date('j M Y, H:i', (int) strtotime($ts));
/** "3 sites · 2 databases · 4 mailboxes" from the backup's meta (parts). */
$contents = function (?string $parts): string {
    $m = json_decode((string) $parts, true);
    if (!is_array($m)) {
        return '';
    }
    $bits = [];
    foreach ([['domains', 'site'], ['databases', 'database'], ['mailboxes', 'mailbox', 'mailboxes']] as $k) {
        if (!empty($m[$k[0]]) && is_array($m[$k[0]])) {
            $bits[] = Ui::plural(count($m[$k[0]]), $k[1], $k[2] ?? null);
        }
    }
    return implode(' · ', $bits);
};
/** The download links of a finished backup. */
$downloads = function (array $b, array $files) use ($fileLabel): string {
    $html = '';
    foreach (array_keys($files) as $f) {
        $html .= '<a href="/cpanel/backups/' . (int) $b['id'] . '/download?file=' . e(rawurlencode($f)) . '" class="btn btn-ghost btn-sm -ml-1">' . icon('download') . e($fileLabel($f)) . '</a>';
    }
    return $html;
};

$schedule = $settings['enabled']
    ? 'Taken automatically every day at ' . sprintf('%02d:00', $settings['hour']) . ' (server time); the newest ' . (int) $settings['keep'] . ' are kept.'
    : 'Automatic backups are off on this server.';
$schedule .= ' You can take one yourself every 6 hours.' . ($settings['s3'] ? ' A copy of each is also stored off-site.' : '');
?>
<div class="stack">
    <?php if ($busy): ?>
    <?= Ui::busy('A backup or restore is running. It can take a few minutes for a large account.') ?>
    <?php endif; ?>

    <section class="card" aria-labelledby="backups-title">
        <div class="card-header">
            <div class="min-w-0">
                <h2 id="backups-title" class="card-title"><?= e(Ui::plural(count($backups), 'backup')) ?></h2>
                <p class="card-desc"><?= e($schedule) ?></p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Backup</th>
                        <th scope="col" class="hidden sm:table-cell">Status</th>
                        <th scope="col" class="hidden sm:table-cell">Size</th>
                        <th scope="col" class="hidden md:table-cell">Download</th>
                        <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $b):
                        $id = (int) $b['id'];
                        $files = BackupService::files($b);
                        [$label, $tone] = $statuses[$b['status']] ?? [ucfirst((string) $b['status']), 'neutral'];
                        $what = $contents($b['parts'] ?? null);
                        $size = (int) $b['size_bytes'] > 0 ? fmt_bytes((int) $b['size_bytes']) : ''; ?>
                    <tr>
                        <td class="sm:min-w-[11rem]">
                            <p class="flex flex-wrap items-center gap-x-2 gap-y-1"><span class="whitespace-nowrap font-medium text-fg"><?= e($when((string) $b['created_at'])) ?></span><span class="sm:hidden"><?= Ui::badge($label, $tone, true) ?></span><?php if (($b['remote'] ?? null) === 'failed'): ?><span class="text-xs text-warning-text sm:hidden">Off-site copy failed</span><?php endif; ?></p>
                            <p class="mt-0.5 text-xs text-muted">
                                <?= e(fmt_ago((string) $b['created_at'])) ?><?php if ($size !== ''): ?><span class="sm:hidden"> · <?= e($size) ?></span><?php endif; ?><?php if ($what !== ''): ?> · <?= e($what) ?><?php endif; ?>
                            </p>
                            <?php if ($b['status'] === 'failed' && $b['log']): ?>
                            <p class="mt-1 break-words text-xs text-danger-text"><?= e((string) (array_slice(explode("\n", trim((string) $b['log'])), -1)[0] ?? '')) ?></p>
                            <?php endif; ?>
                            <?php if ($files): ?>
                            <div class="mt-1.5 flex flex-wrap gap-x-1 md:hidden"><?= $downloads($b, $files) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="hidden sm:table-cell">
                            <div class="flex flex-col items-start gap-1">
                                <?= Ui::badge($label, $tone, true) ?>
                                <?php if (($b['remote'] ?? null) === 'uploaded'): ?>
                                <span class="text-xs text-muted">Copied off-site</span>
                                <?php elseif (($b['remote'] ?? null) === 'failed'): ?>
                                <span class="text-xs text-warning-text">Off-site copy failed</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="hidden whitespace-nowrap tabular-nums text-muted sm:table-cell"><?= $size !== '' ? e($size) : '<span class="text-subtle">-</span>' ?></td>
                        <td class="hidden md:table-cell">
                            <?php if ($files): ?>
                            <div class="flex flex-wrap gap-x-1"><?= $downloads($b, $files) ?></div>
                            <?php else: ?>
                            <span class="text-xs text-subtle"><?= $b['status'] === 'done' ? 'Files no longer on the server' : '-' ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="w-px">
                            <?php if ($b['status'] === 'done'): ?>
                            <div class="flex justify-end">
                                <button type="button" class="btn btn-secondary btn-sm" data-dialog-open="restore-<?= $id ?>" aria-haspopup="dialog" aria-label="Restore from the backup of <?= e($when((string) $b['created_at'])) ?>"<?= $busy ? ' disabled title="Wait for the running job to finish"' : '' ?>><?= icon('refresh') ?><span class="sr-only sm:not-sr-only">Restore</span></button>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$backups): ?>
                    <?= Ui::emptyRow(5, 'No backups yet', $settings['enabled'] ? 'The first one is taken automatically at ' . sprintf('%02d:00', $settings['hour']) . ' (server time), or take one now with "Back up now".' : 'Take one now with "Back up now".', 'archive') ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php foreach ($backups as $b): if ($b['status'] !== 'done') { continue; } $id = (int) $b['id']; $what = $contents($b['parts'] ?? null); ?>
        <dialog id="restore-<?= $id ?>" class="dialog" aria-labelledby="restore-<?= $id ?>-title">
            <form method="post" action="/cpanel/backups/<?= $id ?>/restore">
                <?= Csrf::field() ?>
                <div class="dialog-body">
                    <h2 id="restore-<?= $id ?>-title" class="dialog-title">Restore from <?= e($when((string) $b['created_at'])) ?></h2>
                    <p class="dialog-text">Choose what to bring back<?= $what !== '' ? ' (this backup has ' . e(str_replace(' · ', ', ', $what)) . ')' : '' ?>. It runs in the background.</p>
                    <fieldset class="mt-5 space-y-4">
                        <legend class="sr-only">Parts to restore</legend>
                        <?= Ui::checkbox('parts[]', 'Site files', true, ['value' => 'files', 'hint' => 'Written over your current files. Nothing is deleted: files added since stay.']) ?>
                        <?= Ui::checkbox('parts[]', 'Databases', false, ['value' => 'databases', 'hint' => 'Each database is replaced with the backup\'s copy. Changes made since are lost.']) ?>
                        <?= Ui::checkbox('parts[]', 'Mail', false, ['value' => 'mail', 'hint' => 'Messages missing from your mailboxes are put back.']) ?>
                    </fieldset>
                </div>
                <div class="dialog-footer">
                    <button type="button" class="btn btn-secondary" data-dialog-close data-dialog-focus>Cancel</button>
                    <button type="submit" class="btn btn-danger">Restore</button>
                </div>
            </form>
        </dialog>
        <?php endforeach; ?>
    </section>
</div>
