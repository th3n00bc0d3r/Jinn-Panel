<?php
/**
 * What a reseller has handed out of its own package. Expects $alloc
 * (ResellerService::allocation()) and optionally $allocTitle.
 */
$allocTitle ??= 'Reseller allocation';
$allocMeter = function (string $label, int $used, int $cap, callable $fmt): string {
    if (Quota::unlimited($cap)) {
        return Ui::meter($label, ($used < 0 ? 'Unlimited' : $fmt($used)) . ' of unlimited', 0);
    }
    if ($used < 0) {
        return Ui::meter($label, 'Unlimited of ' . $fmt($cap), 100, 'danger', 'An account has an unlimited package, which doesn\'t fit in this limit.');
    }
    $pct = $cap > 0 ? $used / $cap * 100 : ($used > 0 ? 100 : 0);
    return Ui::meter($label, $fmt($used) . ' of ' . $fmt($cap), $pct, $used > $cap ? 'danger' : Ui::usageTone($pct), $used > $cap ? 'Over - set by an administrator; new accounts or bigger packages are refused.' : '');
};
?>
<section class="card" aria-labelledby="alloc-title">
    <?= Ui::cardHeader($allocTitle, 'What the hosting accounts\' packages add up to, against the reseller\'s own package.', $alloc['package'] ? Ui::badge((string) $alloc['package']['name'], 'accent') : Ui::badge('No package', 'warning'), id: 'alloc-title') ?>
    <div class="card-body">
    <?php if (!$alloc['package']): ?>
        <p class="text-sm text-muted">Without a package of its own the reseller can't create accounts.</p>
    <?php else: ?>
        <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
            <?= $allocMeter('Accounts', $alloc['accounts']['used'], $alloc['accounts']['cap'], fn($v) => (string) $v) ?>
            <?= $allocMeter('Disk handed out', $alloc['disk']['used'], $alloc['disk']['cap'], fn($v) => Quota::fmtMb($v)) ?>
            <?= $allocMeter('Bandwidth handed out', $alloc['bandwidth']['used'], $alloc['bandwidth']['cap'], fn($v) => Quota::fmtMb($v)) ?>
            <?= $allocMeter('Domains handed out', $alloc['domains']['used'], $alloc['domains']['cap'], fn($v) => (string) $v) ?>
        </div>
    <?php endif; ?>
    </div>
</section>
