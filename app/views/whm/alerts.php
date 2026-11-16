<?php
/** @var list<array{key:string,level:string,title:string,detail:string,href:?string}> $alerts */
/** @var string|null $healthLine first line of the health check result */
/** @var bool $emails whether new alerts are emailed */
View::slot('desc', e('Problems the panel can see right now. They clear by themselves once fixed.'));
View::slot('actions', '<a href="/whm/notifications" class="btn btn-secondary">' . icon('mail') . ($emails ? 'Emailed hourly' : 'Emails off') . '</a>');
$levels = [
    'danger' => ['x-circle', 'text-danger', 'Problem'],
    'warning' => ['alert', 'text-warning', 'Warning'],
    'info' => ['info', 'text-info', 'Note'],
];
$counts = array_count_values(array_column($alerts, 'level'));
$health = null;
if ($healthLine !== null && preg_match('/^(OK|FAIL) (\S+)/', $healthLine, $m)) {
    $health = ['ok' => $m[1] === 'OK', 'at' => strtotime($m[2]) ?: null];
}
?>
<div class="stack">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <?= Ui::stat('Problems', (int) ($counts['danger'] ?? 0), 'x-circle') ?>
        <?= Ui::stat('Warnings', (int) ($counts['warning'] ?? 0), 'alert') ?>
        <div class="stat">
            <div class="stat-icon"><?= icon('activity') ?></div>
            <div class="min-w-0">
                <p class="stat-label">Health check</p>
                <?php if ($health === null): ?>
                <p class="mt-1 text-sm text-muted">No result yet. It runs hourly on the server (jinnpanel-healthcheck.timer).</p>
                <?php else: ?>
                <p class="mt-1"><?= Ui::status($health['ok'] ? 'ok' : 'failed', $health['ok'] ? 'Passed' : 'Failed') ?></p>
                <p class="mt-1 text-xs text-muted"><?= $health['at'] ? e('Last run ' . fmt_ago((int) $health['at'])) : '' ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <section class="card" aria-labelledby="alerts-title">
        <?= Ui::cardHeader('Current alerts', id: 'alerts-title') ?>
        <?php if (!$alerts): ?>
        <?= Ui::empty('All clear', 'Services answer, backups ran, certificates are valid and no account is over its limits.', 'check-circle') ?>
        <?php else: ?>
        <ul class="divide-y divide-line">
            <?php foreach ($alerts as $a): [$ic, $tone, $word] = $levels[$a['level']] ?? $levels['info']; ?>
            <li class="flex items-start gap-3 px-5 py-4">
                <?= icon($ic, "mt-0.5 h-5 w-5 shrink-0 $tone") ?>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-fg"><span class="sr-only"><?= e($word) ?>: </span><?= e($a['title']) ?></p>
                    <?php if ($a['detail'] !== ''): ?><p class="mt-1 break-words text-sm text-muted"><?= e($a['detail']) ?></p><?php endif; ?>
                </div>
                <?php if (!empty($a['href'])): ?><a href="<?= e($a['href']) ?>" class="btn btn-secondary btn-sm shrink-0">Open</a><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <p class="text-sm text-muted">
        Checked: the hourly server health check, whether each service answers, disk space, accounts over their quotas,
        failed backups (2 days) and migrations (7 days), Let's Encrypt certificates expiring within 14 days, and routing rules waiting for review.
    </p>
</div>
