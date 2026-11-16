<?php
/**
 * Package usage card. Expects $pkg (package row or null) and $usage (Quota::usage).
 * $compact = true shows only the counted limits in one row (list pages).
 */
$compact ??= false;
$rows = $pkg ? [
    ['Domains', $usage['domains'], (int) $pkg['max_domains']],
    ['Databases', $usage['databases'], (int) $pkg['max_databases']],
    ['Mailboxes', $usage['email_accounts'], (int) $pkg['max_email_accounts']],
    ['SFTP accounts', $usage['ftp_accounts'], (int) $pkg['max_ftp_accounts']],
] : [];
// Measured hourly (UsageService): site files + databases + mail, and this month's traffic.
$measured = Auth::role() === 'user' && !$compact ? Quota::measured((int) Auth::id()) : null;
?>
<section class="card" aria-labelledby="quota-title">
    <div class="card-header">
        <h2 id="quota-title" class="card-title">Package usage</h2>
        <?= Ui::badge($pkg['name'] ?? 'No package', $pkg ? 'accent' : 'warning') ?>
    </div>
    <div class="card-body">
    <?php if (!$pkg): ?>
        <p class="text-sm text-muted">No package is assigned to this account yet, so nothing can be created. Ask your hosting provider to assign one.</p>
    <?php else: ?>
        <?php if ($measured !== null): ?>
        <div class="mb-6 grid grid-cols-1 gap-6 md:grid-cols-2">
            <?php
            $diskPct = $measured['limit'] > 0 ? $measured['used'] / $measured['limit'] * 100 : 0;
            $bwPct = $measured['bw_limit'] > 0 ? $measured['bw_used'] / $measured['bw_limit'] * 100 : 0;
            echo Ui::meter(
                'Disk space',
                fmt_bytes((int) $measured['used']) . ' of ' . ($measured['limit'] > 0 ? fmt_bytes((int) $measured['limit']) : 'unlimited'),
                $diskPct,
                Ui::usageTone($diskPct, (bool) $measured['over']),
                'Files ' . fmt_bytes((int) $measured['files']) . ' · databases ' . fmt_bytes((int) $measured['databases']) . ' · mail ' . fmt_bytes((int) $measured['mail'])
            );
            echo Ui::meter(
                'Bandwidth this month',
                fmt_bytes((int) $measured['bw_used']) . ' of ' . ($measured['bw_limit'] > 0 ? fmt_bytes((int) $measured['bw_limit']) : 'unlimited'),
                $bwPct,
                Ui::usageTone($bwPct, (bool) $measured['bw_over']),
                $measured['bw_over'] ? 'Used up - your sites are paused until next month.' : ''
            );
            ?>
        </div>
        <?php endif; ?>
        <div class="grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-4">
            <?php foreach ($rows as [$label, $used, $limit]):
                $pct = Quota::unlimited($limit) ? 0 : ($limit > 0 ? $used / $limit * 100 : 100); ?>
            <?= Ui::meter($label, (int) $used . ' / ' . Quota::fmtCount($limit), $pct, $pct >= 100 ? 'warning' : 'accent') ?>
            <?php endforeach; ?>
        </div>
        <?php if ($measured !== null && $measured['over']): ?>
        <div class="mt-5"><?= Ui::alert('This account is over its disk space quota: uploads, new databases, mailboxes and domains are blocked until some space is freed.', 'danger') ?></div>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</section>
