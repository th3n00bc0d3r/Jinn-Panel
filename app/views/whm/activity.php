<?php
/** @var list<array<string,mixed>> $rows newest first, with actor_name, target_name */
/** @var string $q */
/** @var int|null $next id to continue from (older entries), or null */
View::slot('desc', e('Sign-ins, and every change made through the panel: who did it, to which account, and from which address.'));
$chip = function (string $action): string {
    if (preg_match('/(failed|blocked|denied)/', $action)) {
        return 'chip bg-danger-soft text-danger-text';
    }
    if (preg_match('/(delete|remove|suspend|disable|reset)/', $action)) {
        return 'chip bg-warning-soft text-warning-text';
    }
    return 'chip';
};
$search = fn(string $term) => '/whm/activity?' . http_build_query(['q' => $term]);
?>
<section class="card" aria-labelledby="activity-title">
    <div class="card-header">
        <div class="min-w-0">
            <h2 id="activity-title" class="card-title"><?= $q !== '' ? 'Entries matching “' . e($q) . '”' : 'Recent activity' ?></h2>
            <p class="card-desc">Newest first<?= $rows ? ' · ' . e(Ui::plural(count($rows), 'entry', 'entries')) . ' on this page' : '' ?>.</p>
        </div>
        <form method="get" action="/whm/activity" class="flex w-full gap-2 sm:w-auto" role="search">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Action, user, detail or IP" aria-label="Search the activity log" class="input input-sm min-w-0 flex-1 sm:w-64">
            <button type="submit" class="btn btn-secondary btn-sm"><?= icon('search') ?>Search</button>
            <?php if ($q !== ''): ?><a href="/whm/activity" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="table-wrap">
        <table class="table table-tight">
            <thead>
                <tr>
                    <th scope="col" class="hidden md:table-cell">When</th>
                    <th scope="col" class="hidden md:table-cell">Who</th>
                    <th scope="col">Action</th>
                    <th scope="col" class="hidden md:table-cell">Detail</th>
                    <th scope="col" class="hidden lg:table-cell">Account</th>
                    <th scope="col" class="hidden lg:table-cell">From</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $at = (string) $r['created_at'];
                    $actor = $r['actor_name'] ?? ($r['actor_id'] ? '#' . $r['actor_id'] : null);
                    $target = (string) ($r['target_name'] ?? '');
                    $ip = (string) ($r['ip'] ?? '');
                    $action = (string) $r['action'];
                ?>
                <tr>
                    <td class="hidden whitespace-nowrap text-muted md:table-cell">
                        <time datetime="<?= e(date('c', (int) strtotime($at))) ?>" title="<?= e($at) ?>"><?= e(fmt_ago($at)) ?></time>
                    </td>
                    <td class="hidden whitespace-nowrap md:table-cell">
                        <?php if ($actor !== null && isset($r['actor_name'])): ?>
                        <a href="<?= e($search((string) $actor)) ?>" class="font-medium text-fg hover:text-accent-text" title="Show only entries mentioning <?= e((string) $actor) ?>"><?= e((string) $actor) ?></a>
                        <?php elseif ($actor !== null): ?>
                        <span class="font-medium text-fg"><?= e((string) $actor) ?></span>
                        <?php else: ?>
                        <span class="text-subtle" title="No signed-in user">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="md:whitespace-nowrap">
                        <span class="<?= $chip($action) ?> break-all"><?= e($action) ?></span>
                        <?php /* Phones: one stacked entry instead of the other columns. */ ?>
                        <div class="mt-1.5 md:hidden">
                            <?php if (!empty($r['detail'])): ?><p class="break-words text-muted"><?= e((string) $r['detail']) ?></p><?php endif; ?>
                            <p class="mt-0.5 text-xs text-subtle">
                                <time datetime="<?= e(date('c', (int) strtotime($at))) ?>" title="<?= e($at) ?>"><?= e(fmt_ago($at)) ?></time>
                                <?= ' · ' . e($actor !== null ? (string) $actor : 'not signed in') ?><?= $target !== '' ? ' · account ' . e($target) : '' ?><?= $ip !== '' ? ' · <span class="font-mono">' . e($ip) . '</span>' : '' ?>
                            </p>
                        </div>
                    </td>
                    <td class="hidden min-w-[14rem] max-w-[32rem] md:table-cell">
                        <p class="break-words text-muted"><?= e((string) ($r['detail'] ?? '')) ?></p>
                        <?php if ($target !== '' || $ip !== ''): ?>
                        <p class="mt-0.5 text-xs text-subtle lg:hidden">
                            <?= $target !== '' ? 'Account ' . e($target) : '' ?><?= $target !== '' && $ip !== '' ? ' · ' : '' ?><?= $ip !== '' ? '<span class="font-mono">' . e($ip) . '</span>' : '' ?>
                        </p>
                        <?php endif; ?>
                    </td>
                    <td class="hidden whitespace-nowrap lg:table-cell"><?= $target !== '' ? e($target) : '<span class="text-subtle">-</span>' ?></td>
                    <td class="hidden whitespace-nowrap lg:table-cell">
                        <?php if ($ip !== ''): ?>
                        <a href="<?= e($search($ip)) ?>" class="font-mono text-xs text-muted hover:text-accent-text" title="Show only entries from <?= e($ip) ?>"><?= e($ip) ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <?= $q !== ''
                    ? Ui::emptyRow(6, 'Nothing matches “' . $q . '”', 'Search looks in actions, details and usernames, and for an exact IP address.', 'search', '<a href="/whm/activity" class="btn btn-secondary">Show everything</a>')
                    : Ui::emptyRow(6, 'Nothing recorded yet', 'Sign-ins and changes made in the panel show up here.', 'activity') ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($next !== null): ?>
    <div class="card-footer justify-between">
        <span class="text-xs text-muted">There are older entries.</span>
        <a href="/whm/activity?<?= e(http_build_query(['q' => $q, 'before' => $next])) ?>" class="btn btn-secondary btn-sm">Older entries<?= icon('chevron-right') ?></a>
    </div>
    <?php endif; ?>
</section>
