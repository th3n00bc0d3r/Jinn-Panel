<?php
/** @var array<string,mixed> $a the account */
/** @var array<string,mixed> $me */
/** @var array<string,mixed> $o AccountService::overview() */
/** @var bool $lastAdmin */
/** @var list<array<string,mixed>> $packages packages the viewer may give it */
/** @var array<string,bool> $pkgFeatures its package's features */
/** @var array<string,bool> $overrides its own feature switches */
/** @var array<string,mixed>|null $alloc reseller allocation (resellers) */
/** @var array<string,mixed>|null $history StatsService::accountHistory() (hosting accounts) */
/** @var array<string,array> $projection disk / bw => StatsService::*Projection() */
$history ??= null;
$projection ??= [];
$id = (int) $a['id'];
$user = $a['role'] === 'user';
$pkg = $o['package'];
$u = $o['usage'];
View::breadcrumbs([['Accounts', '/whm/accounts'], [(string) $a['username']]]);
$actions = '';
if ($user && $a['status'] === 'active') {
    $actions .= '<form method="post" action="/whm/accounts/' . $id . '/login-as">' . Csrf::field() . '<button class="btn btn-secondary">' . icon('login') . 'Log in as ' . e($a['username']) . '</button></form>';
}
$actions .= '<a href="/whm/accounts/' . $id . '/edit" class="btn btn-primary">' . icon('edit') . 'Edit</a>';
View::slot('actions', $actions);
View::slot('desc', e(match ($a['role']) {
    'admin' => 'Administrator: full control of the server.',
    'reseller' => 'Reseller: manages its own hosting accounts in WHM.',
    default => 'Hosting account' . ($o['parent'] ? ' of reseller ' . $o['parent'] : '') . '.',
}));
$roleLabel = ['admin' => 'Administrator', 'reseller' => 'Reseller', 'user' => 'Hosting account'][$a['role']] ?? $a['role'];
?>
<div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
    <div class="stack xl:col-span-2">
        <?php if ($user): ?>
        <section class="card" aria-labelledby="usage-title">
            <?= Ui::cardHeader('Usage', $u ? 'Measured ' . fmt_ago((string) $u['updated_at']) . ', every hour.' : 'Not measured yet - usage is counted every hour.', $pkg ? Ui::badge((string) $pkg['name'], 'accent') : Ui::badge('No package', 'warning'), id: 'usage-title') ?>
            <div class="card-body space-y-6">
                <?php if ($pkg): ?>
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <?php
                    $used = $u ? (int) $u['files_bytes'] + (int) $u['db_bytes'] + (int) $u['mail_bytes'] : 0;
                    $limit = Quota::bytes($pkg['disk_quota_mb']);
                    $pct = $limit > 0 ? $used / $limit * 100 : 0;
                    echo Ui::meter('Disk', fmt_bytes($used) . ' of ' . ($limit ? fmt_bytes($limit) : 'unlimited'), $pct, Ui::usageTone($pct, !empty($u['over_disk'])),
                        $u ? 'Files ' . fmt_bytes((int) $u['files_bytes']) . ' · databases ' . fmt_bytes((int) $u['db_bytes']) . ' · mail ' . fmt_bytes((int) $u['mail_bytes']) : '');
                    $bw = $u && $u['bw_month'] === date('Y-m') ? (int) $u['bw_bytes'] : 0;
                    $bwLimit = Quota::bytes($pkg['bandwidth_mb']);
                    $bwPct = $bwLimit > 0 ? $bw / $bwLimit * 100 : 0;
                    echo Ui::meter('Bandwidth this month', fmt_bytes($bw) . ' of ' . ($bwLimit ? fmt_bytes($bwLimit) : 'unlimited'), $bwPct, Ui::usageTone($bwPct, !empty($u['over_bandwidth'])), !empty($u['over_bandwidth']) ? 'Used up: its sites answer 509 until next month.' : '');
                    ?>
                </div>
                <div class="grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-4">
                    <?php foreach ([['Domains', 'domains', 'max_domains'], ['Databases', 'databases', 'max_databases'], ['Mailboxes', 'mailboxes', 'max_email_accounts'], ['SFTP logins', 'sftp', 'max_ftp_accounts']] as [$label, $k, $max]):
                        $lim = (int) $pkg[$max];
                        $p = Quota::unlimited($lim) ? 0 : ($lim > 0 ? $o['counts'][$k] / $lim * 100 : 100); ?>
                    <?= Ui::meter($label, $o['counts'][$k] . ' / ' . Quota::fmtCount($lim), $p, $p >= 100 ? 'warning' : 'accent') ?>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <?= Ui::alert('This account has no package, so it can\'t create anything. Edit it to assign one.', 'warning') ?>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($history !== null && $pkg): ?>
        <section class="card" aria-labelledby="history-title">
            <?= Ui::cardHeader('Last 30 days', $history['has'] ? 'Disk use each day, and what its sites sent each day.' : 'History starts with the next hourly measure.', id: 'history-title') ?>
            <?php if ($history['has']): ?>
            <div class="grid grid-cols-1 divide-y divide-line lg:grid-cols-2 lg:divide-x lg:divide-y-0">
                <div class="p-5">
                    <h3 class="text-[13px] font-medium text-fg">Disk</h3>
                    <p class="mt-0.5 text-[12.5px] <?= ($projection['disk']['days'] ?? null) === 0 ? 'text-danger-text' : (($projection['disk']['days'] ?? 999) <= 30 ? 'text-warning-text' : 'text-muted') ?>"><?= e($projection['disk']['note'] ?? 'No limit') ?></p>
                    <div class="mt-4">
                        <?php $diskLimit = Quota::bytes($pkg['disk_quota_mb']); ?>
                        <?= Chart::line([['name' => 'Disk used', 'values' => $history['disk']]], $history['labels'], 'Disk used per day, last 30 days', ['area' => true, 'fmt' => 'bytes', 'height' => 180, 'xEvery' => 7, 'table' => 'Day'] + ($diskLimit > 0 ? ['ref' => [$diskLimit, 'Limit ' . fmt_bytes($diskLimit)]] : [])) ?>
                    </div>
                </div>
                <div class="p-5">
                    <h3 class="text-[13px] font-medium text-fg">Data sent by its sites</h3>
                    <p class="mt-0.5 text-[12.5px] <?= ($projection['bw']['days'] ?? null) === 0 ? 'text-danger-text' : (($projection['bw']['days'] ?? null) !== null ? 'text-warning-text' : 'text-muted') ?>"><?= e($projection['bw']['note'] ?? 'No monthly limit') ?></p>
                    <div class="mt-4">
                        <?= Chart::columns([['name' => 'Sent']], array_map(fn($v) => [$v], $history['bw']), $history['labels'], 'Data sent by its sites per day, last 30 days', ['fmt' => 'bytes', 'height' => 180, 'xEvery' => 7, 'table' => 'Day']) ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="card scroll-mt-20" id="features" aria-labelledby="features-title">
            <?= Ui::cardHeader('Package and features', 'Limits come from the package. Each feature follows the package unless it is switched for this account.', '', id: 'features-title') ?>
            <form method="post" action="/whm/accounts/<?= $id ?>/package" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <?= Csrf::field() ?>
                <?php
                $pkgOptions = [];
                foreach ($packages as $p) {
                    $pkgOptions[(string) $p['id']] = $p['name'];
                }
                if ($pkg && !isset($pkgOptions[(string) $pkg['id']])) {
                    $pkgOptions[(string) $pkg['id']] = $pkg['name'];
                }
                if (!$pkg) {
                    $pkgOptions = ['' => 'Choose a package…'] + $pkgOptions;
                }
                echo Ui::select('package_id', 'Package', $pkgOptions, $pkg ? (string) $pkg['id'] : '', ['wrap' => 'min-w-[14rem] flex-1']);
                ?>
                <button class="btn btn-secondary"><?= icon('box') ?>Change package</button>
            </form>
            <form method="post" action="/whm/accounts/<?= $id ?>/features">
                <?= Csrf::field() ?>
                <div class="table-wrap">
                    <table class="table table-tight">
                        <thead><tr><th scope="col">Feature</th><th scope="col" class="hidden sm:table-cell">Package</th><th scope="col" class="w-40">This account</th></tr></thead>
                        <tbody>
                        <?php foreach (Features::LIST as $key => [$label, $what]):
                            $fromPkg = $pkgFeatures[$key];
                            $ov = array_key_exists($key, $overrides) ? ($overrides[$key] ? '1' : '0') : '';
                            $effective = $ov === '' ? $fromPkg : $ov === '1'; ?>
                        <tr>
                            <td>
                                <p class="font-medium text-fg"><?= e($label) ?> <?= $effective ? '' : Ui::badge('Off', 'neutral') ?></p>
                                <p class="hidden text-xs text-muted md:block"><?= e($what) ?></p>
                            </td>
                            <td class="hidden sm:table-cell"><?= $fromPkg ? Ui::badge('Included', 'success', true) : Ui::badge('Not included', 'neutral') ?></td>
                            <td>
                                <label for="ov-<?= e($key) ?>" class="sr-only"><?= e($label) ?> for this account</label>
                                <select id="ov-<?= e($key) ?>" name="override[<?= e($key) ?>]" class="select select-sm">
                                    <option value="" <?= $ov === '' ? 'selected' : '' ?>>Package (<?= $fromPkg ? 'on' : 'off' ?>)</option>
                                    <option value="1" <?= $ov === '1' ? 'selected' : '' ?>>On</option>
                                    <option value="0" <?= $ov === '0' ? 'selected' : '' ?>>Off</option>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer"><button class="btn btn-primary"><?= icon('save') ?>Save features</button></div>
            </form>
        </section>

        <section class="card" aria-labelledby="domains-title">
            <?= Ui::cardHeader('Domains', '', id: 'domains-title') ?>
            <?php if (!$o['domains']): ?>
            <?= Ui::empty('No domains yet', 'The customer adds domains in their own panel - or log in as them to do it.', 'globe') ?>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table table-tight">
                    <thead><tr><th scope="col">Domain</th><th scope="col">PHP</th><th scope="col" class="hidden sm:table-cell">Certificate</th><th scope="col" class="hidden md:table-cell">Added</th></tr></thead>
                    <tbody>
                    <?php foreach ($o['domains'] as $d): ?>
                    <tr>
                        <td><a href="https://<?= e($d['domain_name']) ?>/" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 font-medium text-fg hover:text-accent-text"><?= e($d['domain_name']) ?><?= icon('external', 'h-3.5 w-3.5 text-subtle') ?></a></td>
                        <td class="text-muted"><?= e($d['php_version'] === 'default' ? 'Default' : 'PHP ' . $d['php_version']) ?></td>
                        <td class="hidden text-muted sm:table-cell"><?= e(['letsencrypt' => 'Let\'s Encrypt', 'self_signed' => 'Self-signed'][$d['ssl_mode']] ?? $d['ssl_mode']) ?></td>
                        <td class="hidden text-muted md:table-cell"><?= e(fmt_ago((string) $d['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="card" aria-labelledby="act-title">
            <?= Ui::cardHeader('Recent activity', 'By this account, or done to it.', '<a href="/whm/activity?q=' . rawurlencode((string) $a['username']) . '" class="btn btn-ghost btn-sm">All' . icon('chevron-right') . '</a>', id: 'act-title') ?>
            <?php if (!$o['activity']): ?>
            <?= Ui::empty('Nothing recorded yet', '', 'activity') ?>
            <?php else: ?>
            <ul class="divide-y divide-line">
                <?php foreach ($o['activity'] as $r): ?>
                <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-5 py-2.5 text-sm">
                    <span class="w-24 shrink-0 text-muted" title="<?= e((string) $r['created_at']) ?>"><?= e(fmt_ago((string) $r['created_at'])) ?></span>
                    <span class="chip"><?= e($r['action']) ?></span>
                    <span class="min-w-0 flex-1 truncate text-muted"><?= e((string) $r['detail']) ?><?= $r['actor_name'] && $r['actor_name'] !== $a['username'] ? ' · by ' . e($r['actor_name']) : '' ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="stack">
        <section class="card" aria-labelledby="details-title">
            <?= Ui::cardHeader('Details', id: 'details-title') ?>
            <dl class="dl card-body">
                <div><dt>Status</dt><dd><?= Ui::status((string) $a['status']) ?></dd></div>
                <div><dt>Type</dt><dd><?= e($roleLabel) ?></dd></div>
                <div><dt>Email</dt><dd class="break-all"><?= e($a['email']) ?></dd></div>
                <?php if (!empty($a['full_name'])): ?><div><dt>Name</dt><dd><?= e($a['full_name']) ?></dd></div><?php endif; ?>
                <?php if ($o['parent']): ?><div><dt>Reseller</dt><dd><?= e($o['parent']) ?></dd></div><?php endif; ?>
                <?php if ($a['role'] === 'reseller'): ?><div><dt>Accounts</dt><dd><?= (int) $o['counts']['accounts'] ?><?= $pkg && (int) $pkg['max_accounts'] > 0 ? ' of ' . (int) $pkg['max_accounts'] : '' ?></dd></div><?php endif; ?>
                <div><dt>Two-factor</dt><dd><?= !empty($a['totp_secret_enc']) ? Ui::badge('On', 'success', true) : Ui::badge('Off', 'neutral') ?></dd></div>
                <?php if ($user): ?>
                <div><dt>Linux user</dt><dd><code>jp_<?= e($a['username']) ?></code></dd></div>
                <div><dt>PHP may run programs</dt><dd><?= (int) $a['php_exec'] ? Ui::badge('Yes (exec on)', 'warning') : Ui::badge('No', 'neutral') ?></dd></div>
                <?php endif; ?>
                <div><dt>Created</dt><dd><?= e(date('j M Y', (int) strtotime((string) $a['created_at']))) ?></dd></div>
            </dl>
        </section>

        <?php if ($a['role'] === 'reseller' && !empty($alloc)): ?>
        <?php include __DIR__ . '/../partials/reseller_allocation.php'; ?>
        <?php endif; ?>

        <?php if ($a['role'] === 'reseller'): ?>
        <form method="post" action="/whm/accounts/<?= $id ?>/package" class="card">
            <?= Csrf::field() ?>
            <?= Ui::cardHeader('Package', 'Sets how many hosting accounts this reseller may create.') ?>
            <div class="card-body">
                <?php
                $rOptions = ['' => 'No package'];
                foreach ($packages as $p) {
                    $rOptions[(string) $p['id']] = $p['name'] . ((int) ($p['max_accounts'] ?? 0) > 0 ? ' — ' . (int) $p['max_accounts'] . ' accounts' : '');
                }
                echo Ui::select('package_id', 'Package', $rOptions, (string) ($a['package_id'] ?? ''), ['label_hidden' => true]);
                ?>
            </div>
            <div class="card-footer"><button class="btn btn-secondary"><?= icon('save') ?>Save</button></div>
        </form>
        <?php endif; ?>

        <section class="card" aria-labelledby="manage-title">
            <?= Ui::cardHeader('Manage', id: 'manage-title') ?>
            <div class="divide-y divide-line">
                <div class="px-5 py-4">
                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0 text-sm">
                            <p class="font-medium text-fg"><?= $a['status'] === 'active' ? 'Suspend' : 'Unsuspend' ?></p>
                            <p class="text-muted"><?= $user ? 'Sites, mail logins, SFTP, MySQL and cron.' : 'Signs it out and blocks sign-in.' ?></p>
                        </div>
                        <?php if ($a['status'] !== 'active'): ?>
                        <form method="post" action="/whm/accounts/<?= $id ?>/unsuspend">
                            <?= Csrf::field() ?>
                            <button class="btn btn-secondary btn-sm"><?= icon('play') ?>Unsuspend</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($a['status'] === 'active'): ?>
                    <form method="post" action="/whm/accounts/<?= $id ?>/suspend" class="mt-3 flex items-end gap-2" data-confirm="Suspend <?= e($a['username']) ?>? <?= $user ? 'Its sites go offline (503), and its mail logins, SFTP, MySQL users and cron jobs stop until it\'s unsuspended.' : 'It is signed out and can\'t sign in until unsuspended.' ?>" data-confirm-action="Suspend" data-confirm-danger>
                        <?= Csrf::field() ?>
                        <?php if ($user): ?>
                        <?= Ui::field('reason', 'Reason', ['optional' => true, 'placeholder' => 'e.g. unpaid invoice #1042', 'class' => 'input-sm', 'wrap' => 'min-w-0 flex-1', 'attrs' => ['maxlength' => 500, 'title' => 'Included in the email to the customer, if suspension emails are on']]) ?>
                        <?php endif; ?>
                        <button class="btn btn-secondary btn-sm shrink-0" <?= $lastAdmin ? 'disabled title="The last active administrator can\'t be suspended"' : '' ?>><?= icon('pause') ?>Suspend</button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if (($me['role'] ?? '') === 'admin' && !empty($a['totp_secret_enc'])): ?>
                <div class="flex items-center justify-between gap-4 px-5 py-4">
                    <div class="min-w-0 text-sm">
                        <p class="font-medium text-fg">Reset two-factor</p>
                        <p class="text-muted">For a lost phone. Their recovery codes stop working too.</p>
                    </div>
                    <form method="post" action="/whm/accounts/<?= $id ?>/reset-2fa" data-confirm="Turn off two-factor sign-in for <?= e($a['username']) ?>? They sign in with only their password until they set it up again." data-confirm-action="Reset two-factor">
                        <?= Csrf::field() ?>
                        <button class="btn btn-secondary btn-sm"><?= icon('shield') ?>Reset</button>
                    </form>
                </div>
                <?php endif; ?>

                <?php if (($me['role'] ?? '') === 'admin' && $user): ?>
                <div class="flex items-center justify-between gap-4 px-5 py-4">
                    <div class="min-w-0 text-sm">
                        <p class="font-medium text-fg">PHP can run programs</p>
                        <p class="text-muted">exec(), proc_open() and friends. Only for software that needs it.</p>
                    </div>
                    <form method="post" action="/whm/accounts/<?= $id ?>/php-exec" data-confirm="<?= (int) $a['php_exec'] ? 'Stop ' . e($a['username']) . '\'s PHP from running programs?' : 'Let ' . e($a['username']) . '\'s PHP run programs (exec, proc_open...)? Only for software that needs it.' ?>" data-confirm-action="<?= (int) $a['php_exec'] ? 'Turn off' : 'Turn on' ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="allow" value="<?= (int) $a['php_exec'] ? '' : '1' ?>">
                        <button class="btn btn-secondary btn-sm"><?= (int) $a['php_exec'] ? 'Turn off' : 'Turn on' ?></button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="flex items-center justify-between gap-4 px-5 py-4">
                    <div class="min-w-0 text-sm">
                        <p class="font-medium text-danger-text">Delete account</p>
                        <p class="text-muted"><?= $user ? 'Domains, databases, mailboxes and SFTP logins go too.' : ($a['role'] === 'reseller' ? 'Its hosting accounts stay, owned by the administrators.' : 'Removes this administrator login.') ?></p>
                    </div>
                    <form method="post" action="/whm/accounts/<?= $id ?>/delete" data-confirm="Permanently delete <?= e($a['username']) ?><?= $user ? ' and all of its domains, databases, mailboxes and SFTP accounts? Its site files are moved to /var/lib/jinnpanel/removed/ (root only).' : '?' ?> This can't be undone." data-confirm-action="Delete <?= e($a['username']) ?>" data-confirm-danger>
                        <?= Csrf::field() ?>
                        <button class="btn btn-danger btn-sm" <?= $lastAdmin ? 'disabled title="The last active administrator can\'t be deleted"' : '' ?>><?= icon('trash') ?>Delete</button>
                    </form>
                </div>
            </div>
        </section>
    </div>
</div>
