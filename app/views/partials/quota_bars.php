<?php
/** Expects $pkg (package row or null) and $usage (array from Quota::usage). */
$rows = $pkg ? [
    ['label' => 'Domains', 'used' => $usage['domains'], 'limit' => (int) $pkg['max_domains']],
    ['label' => 'Databases', 'used' => $usage['databases'], 'limit' => (int) $pkg['max_databases']],
    ['label' => 'Email accounts', 'used' => $usage['email_accounts'], 'limit' => (int) $pkg['max_email_accounts']],
    ['label' => 'FTP accounts', 'used' => $usage['ftp_accounts'], 'limit' => (int) $pkg['max_ftp_accounts']],
] : [];
// Measured hourly (UsageService): site files + databases + mail, and this month's traffic.
$measured = Auth::role() === 'user' ? Quota::measured((int) Auth::id()) : null;
?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-sm font-semibold text-slate-700">Package usage</h2>
        <span class="text-xs font-medium text-sky-700 bg-sky-50 border border-sky-200 rounded-full px-2.5 py-0.5"><?= e($pkg['name'] ?? 'No package') ?></span>
    </div>
    <?php if (!$pkg): ?>
        <p class="text-sm text-slate-400">No package assigned - contact your provider to enable provisioning.</p>
    <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <?php foreach ($rows as $r): $pct = $r['limit'] > 0 ? min(100, round($r['used'] / $r['limit'] * 100)) : 100; ?>
        <div>
            <div class="flex justify-between text-xs text-slate-500 mb-1">
                <span><?= e($r['label']) ?></span>
                <span><?= (int) $r['used'] ?>/<?= (int) $r['limit'] ?></span>
            </div>
            <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
                <div class="h-full <?= $pct >= 100 ? 'bg-red-500' : 'bg-sky-500' ?>" style="width: <?= $pct ?>%"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($measured !== null): ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
        <?php foreach ([
            ['Disk space', $measured['used'], $measured['limit'], $measured['over'], 'Files ' . fmt_bytes($measured['files']) . ' · databases ' . fmt_bytes($measured['databases']) . ' · mail ' . fmt_bytes($measured['mail'])],
            ['Bandwidth this month', $measured['bw_used'], $measured['bw_limit'], $measured['bw_over'], $measured['bw_over'] ? 'Used up - your sites are paused until next month.' : ''],
        ] as [$label, $used, $limit, $over, $note]): $pct = $limit > 0 ? min(100, round($used / $limit * 100)) : 0; ?>
        <div>
            <div class="flex justify-between text-xs text-slate-500 mb-1">
                <span><?= e($label) ?></span>
                <span><?= e(fmt_bytes((int) $used)) ?> / <?= $limit > 0 ? e(fmt_bytes((int) $limit)) : 'unlimited' ?></span>
            </div>
            <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
                <div class="h-full <?= $over ? 'bg-red-500' : ($pct >= 90 ? 'bg-amber-500' : 'bg-sky-500') ?>" style="width: <?= $pct ?>%"></div>
            </div>
            <?php if ($note !== ''): ?><p class="mt-1 text-[11px] <?= $over ? 'text-red-600' : 'text-slate-400' ?>"><?= e($note) ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($measured['over']): ?>
    <p class="mt-3 text-xs text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2">Your account is over its disk space quota: uploads, new databases, mailboxes and domains are blocked until you free some space.</p>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>
