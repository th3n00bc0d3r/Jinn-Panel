<?php
/** @var list<array<string,mixed>> $migrations with creator, item_count, done_count */
/** @var array<string,string> $backupFiles username => archive path (admin only) */
/** @var string $importDir */
/** @var list<array<string,mixed>> $s3Fetches newest first (admin only) */
/** @var array<string,mixed> $me */
include __DIR__ . '/_helpers.php';
$isAdmin = $me['role'] === 'admin';
View::slot('desc', e('Move accounts from a cPanel & WHM server - site files, databases, email accounts and stored mail. A single cPanel account, all accounts of a WHM reseller'
    . (Auth::isAdmin() ? ', a whole server through WHM root, or cPanel backup files' : '') . '.'));
View::slot('actions', '<a href="/whm/migrations/create" class="btn btn-primary">' . icon('transfer') . 'New migration</a>');

$fetching = $s3Fetches && in_array($s3Fetches[0]['status'], ['queued', 'running'], true);
$running = array_filter($migrations, fn($mg) => in_array($mg['status'], MigrationService::ACTIVE, true) && !MigrationService::isStalled($mg));
$when = fn(string $t) => '<time datetime="' . e(date('c', (int) strtotime($t))) . '" title="' . e($t) . '">' . e(fmt_ago($t)) . '</time>';
?>
<div class="stack">
    <?php if ($fetching || $running): ?>
    <?= Ui::busy(trim(
        ($fetching ? 'Fetching backups from S3 into the import folder. ' : '')
        . ($running ? Ui::plural(count($running), 'migration is', 'migrations are') . ' running in the background.' : '')
    )) ?>
    <?php endif; ?>

    <?php if (Auth::isAdmin() && ($backupFiles || $s3Fetches)): ?>
    <section class="card" id="from-files" aria-labelledby="files-title">
        <div class="card-header">
            <div class="min-w-0">
                <h2 id="files-title" class="card-title">Backup files on this server</h2>
                <p class="card-desc">cPanel full backups waiting in <?= Ui::copyable($importDir) ?></p>
            </div>
            <?php if ($backupFiles): ?>
            <form method="post" action="/whm/migrations/from-files">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-primary"><?= icon('users') ?>Choose accounts</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="card-body grid grid-cols-1 gap-6 <?= $s3Fetches ? 'lg:grid-cols-2' : '' ?>">
            <div class="min-w-0">
                <?php if ($backupFiles): ?>
                <p class="text-sm font-medium text-fg"><?= e(Ui::plural(count($backupFiles), 'account')) ?> found</p>
                <ul class="mt-2 flex flex-wrap gap-1.5" aria-label="Accounts with a backup file">
                    <?php foreach (array_slice(array_keys($backupFiles), 0, 16) as $u): ?>
                    <li class="chip" title="<?= e(basename((string) $backupFiles[$u])) ?>"><?= e($u) ?></li>
                    <?php endforeach; ?>
                    <?php if (count($backupFiles) > 16): ?><li class="chip text-muted">+<?= count($backupFiles) - 16 ?> more</li><?php endif; ?>
                </ul>
                <?php else: ?>
                <p class="text-sm text-muted">No backup files there yet<?= $fetching ? ' - they appear here once the S3 fetch has downloaded them.' : '.' ?></p>
                <?php endif; ?>
            </div>

            <?php if ($s3Fetches): ?>
            <div class="min-w-0 lg:border-l lg:border-line lg:pl-6">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="section-title">Fetches from S3</h3>
                    <a href="/whm/migrations/create#s3" class="link text-xs">Fetch more</a>
                </div>
                <ul class="mt-3 space-y-3">
                    <?php foreach ($s3Fetches as $f): $fst = (string) $f['status']; ?>
                    <li class="text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <span class="min-w-0 truncate font-mono text-xs text-fg" title="s3://<?= e($f['bucket'] . '/' . $f['prefix']) ?>">s3://<?= e($f['bucket'] . '/' . $f['prefix']) ?></span>
                            <?= match ($fst) {
                                'done' => Ui::status('done', 'Done'),
                                'failed' => Ui::status('failed'),
                                'running' => Ui::status('working', 'Fetching'),
                                default => Ui::status('pending', 'Queued'),
                            } ?>
                        </div>
                        <p class="mt-0.5 text-xs <?= $fst === 'failed' ? 'text-danger-text' : 'text-muted' ?>">
                            <?= e((string) $f['progress']) ?><?= $f['progress'] ? ' · ' : '' ?><?= $when((string) $f['created_at']) ?>
                        </p>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="card" aria-labelledby="migrations-title">
        <div class="card-header">
            <h2 id="migrations-title" class="card-title"><?= e(Ui::plural(count($migrations), 'migration')) ?></h2>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Source</th>
                        <th scope="col" class="hidden sm:table-cell">Accounts</th>
                        <th scope="col" class="hidden sm:table-cell">Status</th>
                        <th scope="col" class="hidden md:table-cell">Created</th>
                        <th scope="col" class="hidden text-right sm:table-cell"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($migrations as $mg):
                        $id = (int) $mg['id'];
                        $draft = $mg['status'] === 'draft';
                        $stalled = MigrationService::isStalled($mg);
                        $href = '/whm/migrations/' . $id . ($draft ? '/select' : '');
                        $total = (int) $mg['item_count'];
                        $done = (int) $mg['done_count'];
                        $badge = migration_status_badge($stalled ? 'stalled' : (string) $mg['status']);
                        $count = $draft ? 'Accounts not chosen yet' : $done . ' of ' . $total . ' done';
                    ?>
                    <tr>
                        <td class="max-w-[18rem] sm:max-w-[24rem]">
                            <a href="<?= e($href) ?>" class="block truncate font-medium text-fg hover:text-accent-text"><?= e(migration_source_name($mg)) ?></a>
                            <p class="mt-0.5 truncate text-xs text-muted"><span class="tabular-nums">#<?= $id ?></span> · <?= e(migration_source_meta($mg)) ?></p>
                            <div class="mt-2 flex flex-wrap items-center gap-2 sm:hidden"><?= $badge ?><span class="text-xs text-muted"><?= e($count) ?></span></div>
                        </td>
                        <td class="hidden min-w-[7.5rem] sm:table-cell">
                            <?php if ($draft): ?>
                            <span class="whitespace-nowrap text-sm text-subtle">Not chosen yet</span>
                            <?php else: ?>
                            <p class="mb-1.5 whitespace-nowrap text-sm tabular-nums text-fg"><?= $done ?> <span class="text-muted">of <?= $total ?> done</span></p>
                            <?= Ui::progress($total > 0 ? $done / $total * 100 : 0, 'Accounts done in migration #' . $id, $mg['status'] === 'failed' || $stalled ? 'danger' : ($done === $total && $total > 0 ? 'success' : 'accent')) ?>
                            <?php endif; ?>
                        </td>
                        <td class="hidden sm:table-cell"><?= $badge ?></td>
                        <td class="hidden whitespace-nowrap text-muted md:table-cell">
                            <?= $when((string) $mg['created_at']) ?>
                            <?php if ($isAdmin): ?><p class="text-xs text-subtle">by <?= e((string) $mg['creator']) ?></p><?php endif; ?>
                        </td>
                        <td class="hidden text-right sm:table-cell">
                            <a href="<?= e($href) ?>" class="btn <?= $draft ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= $draft ? 'Continue' : 'Open' ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$migrations): ?>
                    <?= Ui::emptyRow(5, 'No migrations yet', 'Connect to a cPanel server' . (Auth::isAdmin() ? ' or import cPanel backup files' : '') . ' to bring its accounts over. Nothing is copied until you choose the accounts.', 'transfer', '<a href="/whm/migrations/create" class="btn btn-primary">' . icon('transfer') . 'New migration</a>') ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
