<?php
/** Expects $pkg (package row or null) and $usage (array from Quota::usage). */
$rows = $pkg ? [
    ['label' => 'Domains', 'used' => $usage['domains'], 'limit' => (int) $pkg['max_domains']],
    ['label' => 'Databases', 'used' => $usage['databases'], 'limit' => (int) $pkg['max_databases']],
    ['label' => 'Email accounts', 'used' => $usage['email_accounts'], 'limit' => (int) $pkg['max_email_accounts']],
    ['label' => 'FTP accounts', 'used' => $usage['ftp_accounts'], 'limit' => (int) $pkg['max_ftp_accounts']],
] : [];
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
    <?php endif; ?>
</div>
