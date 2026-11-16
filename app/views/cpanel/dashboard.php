<?php
/** @var array<string,mixed> $me */
/** @var array<string,mixed>|null $pkg */
/** @var array<string,int> $usage Quota::usage() */
/** @var array<string,mixed> $measured Quota::measured() */
/** @var array<string,array> $projection disk / bw => StatsService::*Projection() */
/** @var string $range */
/** @var array<string,mixed>|null $summary StatsService::summary() for the account, null without statistics */
/** @var array<int, array{days:list<float>, visitors:float, requests:float}> $sparks per domain id */
/** @var list<array<string,mixed>> $domains every domain, busiest first, with ['ssl'] */
$name = trim((string) ($me['full_name'] ?? '')) ?: (string) $me['username'];
$sites = count($domains);
View::slot('desc', e('Welcome back, ' . $name . '. ' . ($sites ? Ui::plural($sites, 'site') . ($pkg ? ' on the ' . $pkg['name'] . ' plan' : '') . '.' : 'Add a domain to get your first site.')));
View::slot('actions', '<a href="/cpanel/domains#add-domain" class="btn btn-primary">' . icon('plus') . 'Add domain</a>');
$sslTone = fn(string $st) => $st === 'ok' ? 'success' : ($st === 'no_dns' ? 'danger' : 'warning');
$rangeName = StatsService::RANGES[$range];
$change = $summary ? StatsService::change((float) $summary['sum']['visitors'], (float) ($summary['prev']['visitors'] ?? 0), $rangeName) : null;
$errPct = $summary && $summary['sum']['requests'] > 0 ? $summary['sum']['s5xx'] / $summary['sum']['requests'] * 100 : null;
$diskPct = $measured['limit'] > 0 ? $measured['used'] / $measured['limit'] * 100 : 0;
$bwPct = $measured['bw_limit'] > 0 ? $measured['bw_used'] / $measured['bw_limit'] * 100 : 0;
$toneOf = fn(?array $p) => ($p['days'] ?? null) === 0 ? 'text-danger-text' : (($p['days'] ?? 999) <= 30 ? 'text-warning-text' : 'text-subtle');
?>
<div class="stack">
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <?php if ($summary === null): ?>
        <section class="panel px-5 py-6 text-[13px] text-muted xl:col-span-2">Visitor numbers aren't available yet.</section>
        <?php else: ?>
        <section class="panel xl:col-span-2" aria-labelledby="visitors-title" data-chart-region="visitors" data-live="visitors">
            <div class="flex flex-wrap items-start justify-between gap-x-8 gap-y-3 px-5 pt-5">
                <div class="min-w-0">
                    <h2 id="visitors-title" class="text-[13px] font-medium text-muted">Visitors to your sites, last <?= e($rangeName) ?></h2>
                    <p class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <span class="figure"><?= e(Chart::format((float) $summary['sum']['visitors'], 'count')) ?></span>
                        <?php if ($change): ?><span class="text-[13px] text-muted"><?= $change[0] === 'same' ? '' : icon('arrow-up', 'mr-0.5 inline h-3.5 w-3.5 align-[-2px]' . ($change[0] === 'down' ? ' rotate-180' : '')) ?><?= e($change[1]) ?></span><?php endif; ?>
                    </p>
                </div>
                <?= Chart::ranges(StatsService::RANGES, $range, 'Time range') ?>
            </div>
            <dl class="mt-4 flex flex-wrap gap-x-8 gap-y-3 px-5 text-[13px]">
                <div><dt class="text-muted">Requests</dt><dd class="figure-sm mt-1.5"><?= e(Chart::compact((float) $summary['sum']['requests'])) ?></dd></div>
                <div><dt class="text-muted">Data sent</dt><dd class="figure-sm mt-1.5"><?= e(Chart::bytes((float) $summary['sum']['bytes'])) ?></dd></div>
                <div><dt class="text-muted">Server errors</dt><dd class="figure-sm mt-1.5"><?= $errPct === null ? '—' : e($errPct < 0.1 && $errPct > 0 ? '<0.1%' : rtrim(rtrim(number_format($errPct, 1), '0'), '.') . '%') ?></dd></div>
            </dl>
            <div class="px-5 pb-4 pt-6">
                <?= Chart::line([['name' => 'Visitors', 'values' => $summary['series']['visitors']]], $summary['labels'], 'Visitors per ' . $summary['per'] . ', last ' . $rangeName, [
                    'area' => true, 'height' => 220, 'fmt' => 'count', 'table' => ['hour' => 'Hour', '4 hours' => 'From', 'day' => 'Day'][$summary['per']],
                ]) ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="panel" aria-labelledby="plan-title" data-live="plan">
            <?= Ui::cardHeader('Your plan', $pkg ? $pkg['name'] . ($measured['updated'] ? ', measured ' . fmt_ago((string) $measured['updated']) : '') : 'No package yet', id: 'plan-title') ?>
            <?php if (!$pkg): ?>
            <p class="card-body text-[13px] text-muted">No package is assigned to this account yet, so nothing can be created. Ask your hosting provider to assign one.</p>
            <?php else: ?>
            <div class="space-y-6 p-5">
                <div class="flex items-center gap-4">
                    <?= $measured['limit'] > 0
                        ? Chart::ring($diskPct, 'Disk used', ['size' => 72, 'tone' => Ui::usageTone($diskPct, $measured['over']) === 'danger' ? 'danger' : (Ui::usageTone($diskPct) === 'warning' ? 'warning' : 'accent')])
                        : '<span class="flex h-[72px] w-[72px] shrink-0 items-center justify-center rounded-full bg-surface-3 text-[12px] text-muted">No limit</span>' ?>
                    <div class="min-w-0 text-[13px]">
                        <p class="font-medium text-fg">Disk: <?= e(fmt_bytes((int) $measured['used'])) ?><?= $measured['limit'] > 0 ? ' of ' . e(fmt_bytes((int) $measured['limit'])) : '' ?></p>
                        <p class="text-muted">Files <?= e(fmt_bytes((int) $measured['files'])) ?>, databases <?= e(fmt_bytes((int) $measured['databases'])) ?>, mail <?= e(fmt_bytes((int) $measured['mail'])) ?></p>
                        <?php if (isset($projection['disk'])): ?>
                        <p class="mt-1 flex items-center gap-1.5 <?= $toneOf($projection['disk']) ?>"><?= icon('clock', 'h-3.5 w-3.5 shrink-0') ?><?= e($measured['over'] ? 'Full: uploads and new mailboxes, databases and domains are refused until you free space.' : $projection['disk']['note']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="mb-1.5 flex items-baseline justify-between gap-3 text-[13px]"><span class="text-muted">Bandwidth this month</span><span class="font-medium tabular-nums text-fg"><?= e(fmt_bytes((int) $measured['bw_used'])) ?><?= $measured['bw_limit'] > 0 ? ' of ' . e(fmt_bytes((int) $measured['bw_limit'])) : '' ?></span></div>
                    <?= $measured['bw_limit'] > 0 ? Ui::progress(min(100, $bwPct), 'Bandwidth this month', Ui::usageTone($bwPct, $measured['bw_over'])) : '<div class="progress" aria-hidden="true"></div>' ?>
                    <?php if ($measured['bw_over']): ?>
                    <p class="mt-1.5 text-[12px] text-danger-text">Used up: your sites are paused until <?= e(date('j M', strtotime('first day of next month'))) ?>.</p>
                    <?php elseif (isset($projection['bw'])): ?>
                    <p class="mt-1.5 text-[12px] <?= ($projection['bw']['days'] ?? null) !== null ? 'text-warning-text' : 'text-subtle' ?>"><?= e($projection['bw']['note']) ?><?= ($projection['bw']['days'] ?? null) !== null ? '; your sites would pause until ' . e(date('j M', strtotime('first day of next month'))) . '.' : '' ?></p>
                    <?php endif; ?>
                </div>
                <ul class="grid grid-cols-2 gap-x-6 gap-y-4 border-t border-line pt-5">
                    <?php foreach ([['Domains', 'domains', 'max_domains'], ['Databases', 'databases', 'max_databases'], ['Mailboxes', 'email_accounts', 'max_email_accounts'], ['SFTP logins', 'ftp_accounts', 'max_ftp_accounts']] as [$l, $k, $max]):
                        $lim = (int) $pkg[$max];
                        $p = Quota::unlimited($lim) ? 0 : ($lim > 0 ? $usage[$k] / $lim * 100 : 100); ?>
                    <li>
                        <div class="mb-1.5 flex items-baseline justify-between text-[13px]"><span class="text-muted"><?= e($l) ?></span><span class="tabular-nums text-fg"><?= (int) $usage[$k] ?> / <?= e(Quota::fmtCount($lim)) ?></span></div>
                        <?= Ui::progress(min(100, $p), $l, $p >= 100 ? 'warning' : 'accent') ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="panel" aria-labelledby="sites-title">
        <?= Ui::cardHeader('Your sites', $sparks ? 'Visitors per day over the last 14 days' : '', '<a href="/cpanel/domains" class="btn btn-ghost btn-sm">All domains</a>', id: 'sites-title') ?>
        <?php if (!$domains): ?>
        <?= Ui::empty('No websites yet', 'Add a domain to get a website, a DNS zone and a certificate. Point the domain at this server and it goes live.', 'globe', '<a href="/cpanel/domains#add-domain" class="btn btn-primary">' . icon('plus') . 'Add your first domain</a>') ?>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Site</th>
                        <?php if ($sparks): ?>
                        <th scope="col" class="hidden w-44 sm:table-cell">Last 14 days</th>
                        <th scope="col" class="hidden text-right md:table-cell">Visitors</th>
                        <th scope="col" class="hidden text-right lg:table-cell">Requests</th>
                        <?php endif; ?>
                        <th scope="col" class="hidden sm:table-cell">Certificate</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($domains as $d): $did = (int) $d['id']; $sp = $sparks[$did] ?? null; ?>
                    <tr>
                        <td class="max-w-[18rem]">
                            <a href="/cpanel/domains/<?= $did ?>" class="block truncate font-medium text-fg hover:text-accent-text"><?= e($d['domain_name']) ?></a>
                            <p class="truncate text-[12px] text-subtle"><?= e($d['php_version'] === 'default' ? 'PHP default' : 'PHP ' . $d['php_version']) ?> · panel at <span class="font-mono"><?= e($d['domain_name']) ?>/jpanel</span></p>
                        </td>
                        <?php if ($sparks): ?>
                        <td class="hidden sm:table-cell"><div class="w-36"><?= $sp && array_sum($sp['days']) > 0 ? Chart::sparkline($sp['days'], 'Visitors per day to ' . $d['domain_name'] . ', last 14 days', ['area' => true]) : '<span class="text-[12.5px] text-subtle">No visits yet</span>' ?></div></td>
                        <td class="hidden text-right md:table-cell"><?= e(Chart::format((float) ($sp['visitors'] ?? 0), 'count')) ?></td>
                        <td class="hidden text-right text-muted lg:table-cell"><?= e(Chart::compact((float) ($sp['requests'] ?? 0))) ?></td>
                        <?php endif; ?>
                        <td class="hidden sm:table-cell"><span title="<?= e($d['ssl']['detail']) ?>"><?= Ui::badge((string) $d['ssl']['label'], $sslTone((string) $d['ssl']['state']), true) ?></span></td>
                        <td>
                            <div class="flex items-center justify-end gap-1">
                                <a href="/cpanel/domains/<?= $did ?>/stats" class="btn btn-secondary btn-sm"><?= icon('chart') ?>Stats</a>
                                <a href="https://<?= e($d['domain_name']) ?>/" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" aria-label="Visit <?= e($d['domain_name']) ?> (opens in a new tab)"><?= icon('external') ?></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="panel" aria-labelledby="help-title">
        <?= Ui::cardHeader('Connection details', 'For SFTP apps, mail apps and DNS at your registrar', id: 'help-title') ?>
        <dl class="grid grid-cols-1 gap-x-8 gap-y-4 p-5 text-[13px] sm:grid-cols-2 xl:grid-cols-4">
            <div><dt class="text-muted">Server</dt><dd class="mt-1"><?= Ui::copyable(Config::SERVER_HOSTNAME) ?></dd></div>
            <div><dt class="text-muted">IP address</dt><dd class="mt-1"><?= Ui::copyable(Config::SERVER_IP) ?></dd></div>
            <div><dt class="text-muted">SFTP</dt><dd class="mt-1"><code class="mono"><?= e(Config::SERVER_HOSTNAME) ?></code>, port <code class="mono"><?= (int) Config::SFTP_PUBLIC_PORT ?></code></dd></div>
            <div><dt class="text-muted">Your username</dt><dd class="mt-1"><code class="mono"><?= e($me['username']) ?></code></dd></div>
        </dl>
    </section>
</div>
