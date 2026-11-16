<?php
/** @var list<array<string,mixed>> $accounts one page: with package_name, disk_quota_mb, reseller_username, used_bytes, disk_pct, over_disk, over_bandwidth, domain_count, traffic_14d */
/** @var array<string,mixed> $me */
/** @var list<array<string,mixed>> $packages packages the viewer may give */
/** @var array{q:string, status:string, role:string, package:int, reseller:string, sort:string, dir:string, page:int} $filters */
/** @var array{total:int, active:int, suspended:int, over_limits:int} $counts per status, within the other filters */
/** @var int $total accounts matching every filter */
/** @var int $pages */
/** @var array<int, list<float>> $sparks requests per day, last 14 days, per hosting account */
/** @var list<array{id:int, username:string}> $resellers (admins) */
$admin = $me['role'] === 'admin';
$packages ??= [];
$sparks ??= [];
$resellers ??= [];
$filters ??= ['q' => '', 'status' => '', 'role' => '', 'package' => 0, 'reseller' => '', 'sort' => '', 'dir' => 'asc', 'page' => 1];
$total ??= count($accounts);
$pages ??= 1;
$counts ??= ['total' => $total, 'active' => 0, 'suspended' => 0, 'over_limits' => 0];
View::script('accounts');
$selectable = count(array_filter($accounts, fn($a) => (int) $a['id'] !== (int) $me['id']));
View::slot('desc', e($admin ? 'Every login on this server: administrators, resellers and hosting accounts.' : 'The hosting accounts you created.'));
View::slot('actions', '<a href="/whm/accounts/create" class="btn btn-primary">' . icon('user-plus') . 'Create account</a>');
$roleBadge = fn(string $r) => match ($r) {
    'admin' => Ui::badge('Admin', 'accent'),
    'reseller' => Ui::badge('Reseller', 'info'),
    default => '',
};
$filtered = $filters['q'] !== '' || $filters['status'] !== '' || $filters['role'] !== '' || $filters['package'] > 0 || $filters['reseller'] !== '';

// A link to this list with some choices changed (and back to page 1).
$link = function (array $change) use ($filters): string {
    $q = array_filter(array_merge($filters, ['page' => 1], $change), fn($v) => $v !== '' && $v !== 0 && $v !== null);
    if (($q['page'] ?? 1) === 1) {
        unset($q['page']);
    }
    $defaultDir = ($q['sort'] ?? '') === 'name' ? 'asc' : 'desc';
    if (($q['dir'] ?? $defaultDir) === $defaultDir || empty($q['sort'])) {
        unset($q['dir']);
    }
    return '/whm/accounts' . ($q ? '?' . http_build_query($q) : '');
};
$th = function (string $key, string $label, string $cls = '') use ($filters, $link): string {
    $on = $filters['sort'] === $key;
    $dir = $on ? $filters['dir'] : null;
    $next = $on ? ($dir === 'asc' ? 'desc' : 'asc') : ($key === 'name' ? 'asc' : 'desc');
    return '<th scope="col" class="' . $cls . '"' . ($on ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '') . '>'
        . '<a href="' . e($link(['sort' => $key, 'dir' => $next])) . '" class="th-sort">' . e($label)
        . ($on ? icon('chevron-down', 'h-3.5 w-3.5' . ($dir === 'asc' ? ' rotate-180' : '')) : '') . '</a></th>';
};
$cols = 5 + ($admin ? 1 : 0) + ($selectable ? 1 : 0);
$first = ($filters['page'] - 1) * AccountController::PER_PAGE + 1;
?>
<?php if ($selectable): ?>
<form method="post" action="/whm/accounts/bulk" id="bulk-form" class="card mb-4 flex flex-wrap items-end gap-3 p-4" data-bulk data-confirm="Apply this to the selected accounts?" data-confirm-action="Apply">
    <?= Csrf::field() ?>
    <p class="w-full text-sm text-muted sm:w-auto sm:self-center"><span class="font-medium text-fg" data-bulk-count>0</span> selected</p>
    <?= Ui::select('op', 'With the selected', ['' => 'Choose an action…', 'package' => 'Change package', 'suspend' => 'Suspend', 'unsuspend' => 'Unsuspend', 'feature' => 'Switch a feature'], '', ['class' => 'select-sm', 'attrs' => ['data-bulk-op' => true]]) ?>
    <div data-bulk-for="package">
        <?php
        $po = [];
        foreach ($packages as $p) {
            $po[(string) $p['id']] = $p['name'];
        }
        echo Ui::select('package_id', 'Package', $po, null, ['class' => 'select-sm']);
        ?>
    </div>
    <div class="flex flex-wrap items-end gap-3" data-bulk-for="feature">
        <?php
        $fo = [];
        foreach (Features::LIST as $k => [$label]) {
            $fo[$k] = $label;
        }
        echo Ui::select('feature', 'Feature', $fo, null, ['class' => 'select-sm']);
        echo Ui::select('feature_value', 'Set to', ['1' => 'On', '0' => 'Off', '' => 'As the package'], '1', ['class' => 'select-sm']);
        ?>
    </div>
    <button type="submit" class="btn btn-primary btn-sm" data-bulk-apply>Apply</button>
</form>
<?php endif; ?>

<section class="panel" aria-labelledby="accounts-title">
    <h2 id="accounts-title" class="sr-only">Accounts</h2>
    <form method="get" action="/whm/accounts" class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-4" role="search" aria-label="Find accounts">
        <?php foreach (['sort', 'dir', 'status'] as $keep): if ($filters[$keep] !== ''): ?>
        <input type="hidden" name="<?= $keep ?>" value="<?= e((string) $filters[$keep]) ?>">
        <?php endif; endforeach; ?>
        <div class="relative min-w-[14rem] flex-1">
            <label for="acc-q" class="sr-only">Search accounts</label>
            <?= icon('search', 'pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle') ?>
            <input type="search" id="acc-q" name="q" value="<?= e($filters['q']) ?>" class="input input-sm h-9 pl-9" placeholder="Name, email or domain" autocomplete="off">
        </div>
        <?php if ($admin): ?>
        <div>
            <label for="acc-role" class="sr-only">Type</label>
            <select id="acc-role" name="role" class="select select-sm h-9 w-auto" data-autosubmit>
                <?php foreach (['' => 'Every type', 'user' => 'Hosting accounts', 'reseller' => 'Resellers', 'admin' => 'Administrators'] as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= $filters['role'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($packages): ?>
        <div>
            <label for="acc-pkg" class="sr-only">Package</label>
            <select id="acc-pkg" name="package" class="select select-sm h-9 w-auto" data-autosubmit>
                <option value="">Every package</option>
                <?php foreach ($packages as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= $filters['package'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($admin && $resellers): ?>
        <div>
            <label for="acc-res" class="sr-only">Reseller</label>
            <select id="acc-res" name="reseller" class="select select-sm h-9 w-auto" data-autosubmit>
                <option value="">Any reseller</option>
                <option value="none" <?= $filters['reseller'] === 'none' ? 'selected' : '' ?>>No reseller</option>
                <?php foreach ($resellers as $r): ?>
                <option value="<?= (int) $r['id'] ?>" <?= $filters['reseller'] === (string) $r['id'] ? 'selected' : '' ?>><?= e($r['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-secondary btn-sm h-9 js-hide">Search</button>
    </form>
    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
        <nav class="segmented" aria-label="Status">
            <?php foreach (['' => ['All', 'total'], 'active' => ['Active', 'active'], 'suspended' => ['Suspended', 'suspended'], 'over' => ['Over a limit', 'over_limits']] as $v => [$l, $k]): ?>
            <a href="<?= e($link(['status' => $v])) ?>"<?= $filters['status'] === $v ? ' aria-current="true"' : '' ?>><?= e($l) ?> <span class="tabular-nums text-subtle"><?= (int) ($counts[$k] ?? 0) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($filtered): ?>
        <a href="/whm/accounts" class="text-[13px] text-muted hover:text-fg"><?= icon('close', 'mr-1 inline h-3.5 w-3.5 align-[-2px]') ?>Clear filters</a>
        <?php endif; ?>
    </div>
    <div class="table-wrap border-t border-line">
        <table class="table" id="accounts-table">
            <thead>
                <tr>
                    <?php if ($selectable): ?><th scope="col" class="w-10"><input type="checkbox" data-bulk-all aria-label="Select every account on this page"></th><?php endif; ?>
                    <?= $th('name', 'Account') ?>
                    <th scope="col" class="hidden lg:table-cell">Package</th>
                    <?= $th('disk', 'Disk', 'hidden sm:table-cell') ?>
                    <?= $th('traffic', 'Requests, 14 days', 'hidden md:table-cell') ?>
                    <?php if ($admin): ?><?= $th('domains', 'Domains', 'hidden text-right xl:table-cell') ?><?php endif; ?>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $a): $id = (int) $a['id']; $self = $id === (int) $me['id']; $hosting = $a['role'] === 'user';
                    $limitMb = (int) ($a['disk_quota_mb'] ?? 0);
                    $pct = $a['disk_pct'] ?? null;
                    $pct = $pct === null ? null : (float) $pct * 100; ?>
                <tr>
                    <?php if ($selectable): ?>
                    <td><?php if (!$self): ?><input type="checkbox" name="ids[]" value="<?= $id ?>" form="bulk-form" data-bulk-item aria-label="Select <?= e($a['username']) ?>"><?php endif; ?></td>
                    <?php endif; ?>
                    <td class="max-w-[18rem]">
                        <div class="flex items-center gap-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-surface-3 text-xs font-semibold uppercase text-muted" aria-hidden="true"><?= e(substr((string) $a['username'], 0, 2)) ?></span>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <?php if ($self): ?>
                                    <span class="truncate font-medium text-fg"><?= e($a['username']) ?></span> <?= Ui::badge('You', 'neutral') ?>
                                    <?php else: ?>
                                    <a href="/whm/accounts/<?= $id ?>" class="truncate font-medium text-fg hover:text-accent-text"><?= e($a['username']) ?></a>
                                    <?php endif; ?>
                                    <?= $roleBadge((string) $a['role']) ?>
                                </div>
                                <p class="truncate text-[12.5px] text-muted"><?= e((string) (($a['full_name'] ?? '') ?: $a['email'])) ?><?= $admin && !empty($a['reseller_username']) ? ', via ' . e($a['reseller_username']) : '' ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="hidden text-muted lg:table-cell"><?= e($a['package_name'] ?? '—') ?></td>
                    <td class="hidden min-w-[9rem] sm:table-cell">
                        <?php if ($hosting): ?>
                        <div class="mb-1 flex items-baseline justify-between gap-2 text-[12.5px]">
                            <span class="text-fg"><?= e(fmt_bytes((int) ($a['used_bytes'] ?? 0))) ?></span>
                            <span class="text-subtle"><?= $pct !== null ? round($pct) . '%' : ($limitMb < 0 ? 'unlimited' : '') ?></span>
                        </div>
                        <?= $pct !== null ? Ui::progress(min(100, $pct), 'Disk used by ' . $a['username'], Ui::usageTone($pct, !empty($a['over_disk']))) : '<div class="progress" aria-hidden="true"></div>' ?>
                        <?php else: ?>
                        <span class="text-subtle">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="hidden md:table-cell">
                        <?php if ($hosting && isset($sparks[$id])): ?>
                        <div class="flex items-center gap-3">
                            <div class="w-24"><?= Chart::sparkline($sparks[$id], 'Requests per day to ' . $a['username'] . '\'s sites, last 14 days') ?></div>
                            <span class="text-[12.5px] tabular-nums text-muted"><?= e(Chart::compact((float) $a['traffic_14d'])) ?></span>
                        </div>
                        <?php elseif ($hosting): ?>
                        <span class="text-[12.5px] text-subtle">No visits</span>
                        <?php else: ?>
                        <span class="text-subtle">—</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($admin): ?><td class="hidden text-right xl:table-cell"><?= $hosting ? (int) $a['domain_count'] : '<span class="text-subtle">—</span>' ?></td><?php endif; ?>
                    <td>
                        <?= Ui::status((string) $a['status']) ?>
                        <?php if (!empty($a['over_disk']) || !empty($a['over_bandwidth'])): ?>
                        <div class="mt-1"><?= Ui::badge(!empty($a['over_disk']) ? 'Over disk quota' : 'Bandwidth used up', 'danger') ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$self): ?>
                        <div class="flex items-center justify-end gap-1">
                            <?php if ($hosting && $a['status'] === 'active'): ?>
                            <?= Ui::actionForm("/whm/accounts/$id/login-as", 'login', 'Log in as ' . $a['username']) ?>
                            <?php endif; ?>
                            <a href="/whm/accounts/<?= $id ?>" class="btn btn-secondary btn-sm">Manage</a>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$accounts && !$filtered): ?>
                <?= Ui::emptyRow($cols + 1, 'No accounts yet', 'Create a hosting account for a customer, or a reseller who creates their own.', 'users', '<a href="/whm/accounts/create" class="btn btn-primary">' . icon('user-plus') . 'Create account</a>') ?>
                <?php elseif (!$accounts): ?>
                <?= Ui::emptyRow($cols + 1, 'No account matches', 'Try fewer words, or clear the filters.', 'search', '<a href="/whm/accounts" class="btn btn-secondary">Clear filters</a>') ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > 0): ?>
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3 text-[13px] text-muted">
        <p><span class="tabular-nums text-fg"><?= $first ?>–<?= $first + count($accounts) - 1 ?></span> of <span class="tabular-nums text-fg"><?= $total ?></span></p>
        <?php if ($pages > 1): ?>
        <nav class="flex items-center gap-1" aria-label="Pages">
            <?php if ($filters['page'] > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($link(['page' => $filters['page'] - 1, 'sort' => $filters['sort'], 'dir' => $filters['dir']])) ?>" rel="prev"><?= icon('chevron-left') ?>Previous</a><?php endif; ?>
            <?php $prevP = 0; foreach (array_unique([1, max(1, $filters['page'] - 1), $filters['page'], min($pages, $filters['page'] + 1), $pages]) as $p): ?>
            <?php if ($p > $prevP + 1): ?><span class="px-1 text-subtle" aria-hidden="true">…</span><?php endif; $prevP = $p; ?>
            <a class="btn btn-sm <?= $p === $filters['page'] ? 'btn-secondary' : 'btn-ghost' ?> tabular-nums" href="<?= e($link(['page' => $p, 'sort' => $filters['sort'], 'dir' => $filters['dir']])) ?>"<?= $p === $filters['page'] ? ' aria-current="page"' : '' ?> aria-label="Page <?= $p ?>"><?= $p ?></a>
            <?php endforeach; ?>
            <?php if ($filters['page'] < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e($link(['page' => $filters['page'] + 1, 'sort' => $filters['sort'], 'dir' => $filters['dir']])) ?>" rel="next">Next<?= icon('chevron-right') ?></a><?php endif; ?>
        </nav>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
