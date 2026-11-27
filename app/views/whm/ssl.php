<?php
/** @var list<array<string,mixed>> $rows */
/** @var array{ok:int, expiring:int, problem:int, self_signed:int} $counts */
/** @var string $show '' or 'attention' */
View::slot('desc', e('Every hosted domain\'s certificates: who issued them, when they expire and whether the domain\'s DNS points here. Caddy renews Let\'s Encrypt certificates on its own about 30 days before they expire; AutoSSL also runs every day.'));
View::slot('actions', '<form method="post" action="/whm/ssl/autossl">' . Csrf::field()
    . '<button type="submit" class="btn btn-primary">' . icon('refresh') . 'Run AutoSSL now</button></form>');

$now = time();
$attention = $counts['problem'] + $counts['expiring'] + $counts['self_signed'];
$modeOptions = ['auto' => 'Automatic', 'letsencrypt' => "Let's Encrypt", 'self_signed' => 'Self-signed only'];
$stateBadge = static fn (string $state): string => match ($state) {
    'ok' => Ui::badge('Valid', 'success', true),
    'expiring' => Ui::badge('Expiring soon', 'warning', true),
    'problem' => Ui::badge('No valid certificate', 'danger', true),
    'pinned' => Ui::badge('Self-signed only', 'neutral', true),
    default => Ui::badge('Self-signed', 'warning', true),
};
$nameBadge = static function (array $n) use ($now): string {
    $days = $n['cert'] !== null ? (int) floor(($n['cert']['valid_to'] - $now) / 86400) : null;
    return match ($n['state']) {
        'valid' => Ui::badge($n['name'], 'success'),
        'expiring' => Ui::badge($n['name'] . ' · ' . max(0, (int) $days) . ' d left', 'warning'),
        'expired' => Ui::badge($n['name'] . ' · expired', 'danger'),
        'missing' => Ui::badge($n['name'] . ' · no certificate', 'danger'),
        default => Ui::badge($n['name'], 'neutral'),
    };
};
?>
<div class="stack">
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <?= Ui::stat('Valid', $counts['ok'], 'check-circle', "Let's Encrypt, more than " . SslService::EXPIRING_DAYS . ' days left') ?>
        <?= Ui::stat('Expiring soon', $counts['expiring'], 'clock', 'Fewer than ' . SslService::EXPIRING_DAYS . ' days left', $counts['expiring'] ? '/whm/ssl?show=attention' : '') ?>
        <?= Ui::stat('No valid certificate', $counts['problem'], 'x-circle', 'Missing or expired', $counts['problem'] ? '/whm/ssl?show=attention' : '') ?>
        <?= Ui::stat('Self-signed', $counts['self_signed'], 'shield', 'Browsers show a warning') ?>
    </div>

    <?php if ($counts['problem'] > 0): ?>
    <?= Ui::alert("<p class=\"font-medium\">" . e(Ui::plural($counts['problem'], 'domain')) . " without a valid certificate.</p><p class=\"mt-0.5\">Usually a name whose DNS doesn't point here (" . e(Config::SERVER_IP) . "), so Let's Encrypt can't check it. Fix the DNS and run AutoSSL, or switch the domain to self-signed.</p>", 'danger', true) ?>
    <?php endif; ?>

    <section class="card min-w-0" aria-labelledby="ssl-title">
        <div class="card-header">
            <div class="min-w-0">
                <h2 id="ssl-title" class="card-title"><?= e(Ui::plural(count($rows), 'domain')) ?></h2>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <?= Ui::tabs([['/whm/ssl', 'All'], ['/whm/ssl?show=attention', 'Needs attention (' . $attention . ')']], $show === 'attention' ? '/whm/ssl?show=attention' : '/whm/ssl', 'Show') ?>
                <?php if (count($rows) > 8): ?>
                <input type="search" class="input input-sm w-full sm:w-56" placeholder="Filter domains…" aria-label="Filter domains" data-filter="#ssl-table">
                <?php endif; ?>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table" id="ssl-table">
                <thead>
                    <tr>
                        <th scope="col">Domain</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="hidden md:table-cell">Expires</th>
                        <th scope="col" class="hidden lg:table-cell">DNS</th>
                        <th scope="col" class="text-right">SSL mode</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): $id = (int) $r['id'];
                        $requestedMode = $r['ssl_mode'] === 'letsencrypt' ? 'letsencrypt' : ((int) $r['ssl_pinned'] === 1 ? 'self_signed' : 'auto');
                        $days = $r['expires'] !== null ? (int) floor(($r['expires'] - $now) / 86400) : null; ?>
                    <tr id="ssl-<?= $id ?>" class="scroll-mt-20 align-top">
                        <td class="min-w-[14rem] max-w-[26rem]">
                            <p class="truncate font-medium text-fg"><?= e($r['domain_name']) ?></p>
                            <p class="mt-0.5 inline-flex items-center gap-1.5 text-xs text-muted"><?= icon('user', 'h-3.5 w-3.5 text-subtle') ?><a href="/whm/accounts/<?= (int) $r['user_id'] ?>" class="hover:text-accent-text"><?= e($r['username']) ?></a></p>
                            <div class="mt-2 flex flex-wrap gap-1.5"><?php foreach ($r['names'] as $n) { echo $nameBadge($n); } ?></div>
                            <?php foreach ($r['names'] as $n): if ($n['points_here'] === false): ?>
                            <p class="mt-1.5 text-xs text-danger-text"><?= e($n['name']) ?> resolves to <?= e($n['addresses'] ? implode(', ', $n['addresses']) : 'nothing') ?>, not this server.</p>
                            <?php endif; endforeach; ?>
                        </td>
                        <td class="whitespace-nowrap"><?= $stateBadge($r['state']) ?></td>
                        <td class="hidden whitespace-nowrap text-sm md:table-cell">
                            <?php if ($r['expires'] !== null): ?>
                            <span class="tabular-nums text-fg"><?= e(gmdate('j M Y', $r['expires'])) ?></span>
                            <span class="block text-xs <?= $days < SslService::EXPIRING_DAYS ? 'text-danger-text' : 'text-muted' ?>"><?= $days >= 0 ? e(Ui::plural($days, 'day')) . ' left' : 'expired' ?> · <?= e($r['names'][0]['cert']['issuer'] ?? '') ?></span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="hidden whitespace-nowrap text-sm lg:table-cell">
                            <?= $r['points_here'] ? Ui::badge('Points here', 'success') : Ui::badge($r['addresses'] ? implode(', ', $r['addresses']) : 'No A record', 'warning') ?>
                        </td>
                        <td class="text-right">
                            <form method="post" action="/whm/ssl/<?= $id ?>" class="inline-flex items-center gap-2">
                                <?= Csrf::field() ?>
                                <label class="sr-only" for="ssl-mode-<?= $id ?>">SSL mode for <?= e($r['domain_name']) ?></label>
                                <select name="ssl_mode" id="ssl-mode-<?= $id ?>" class="select select-sm w-40">
                                    <?php foreach ($modeOptions as $v => $label): ?>
                                    <option value="<?= e($v) ?>"<?= $v === $requestedMode ? ' selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-secondary btn-sm"><?= in_array($r['state'], ['problem', 'expiring'], true) ? 'Retry' : 'Save' ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                    <?= Ui::emptyRow(5, $show === 'attention' ? 'Nothing needs attention' : 'No domains yet', $show === 'attention' ? 'Every domain has a valid certificate.' : 'Domains appear here once accounts add them.', 'shield') ?>
                    <?php elseif (count($rows) > 8): ?>
                    <tr data-filter-none data-filter-skip hidden><td colspan="5" class="py-8 text-center text-sm text-muted">No domain matches that filter.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" aria-labelledby="ssl-help-title">
        <?= Ui::cardHeader('How SSL works here', '', '', 'ssl-help-title') ?>
        <div class="card-body text-sm text-muted">
            <ul class="list-disc space-y-1.5 pl-5">
                <li><strong class="text-fg">Automatic</strong>: Let's Encrypt once the domain's DNS points here, self-signed until then. The daily AutoSSL pass switches it over by itself.</li>
                <li><strong class="text-fg">Let's Encrypt</strong>: always ask Let's Encrypt. Without DNS pointing here it can't issue, and the site has no working certificate.</li>
                <li><strong class="text-fg">Self-signed only</strong>: never switch to Let's Encrypt (browsers warn). For test sites or domains that point elsewhere on purpose.</li>
                <li><strong class="text-fg">Run AutoSSL now</strong> does the daily pass immediately and asks Caddy to issue or renew every certificate that is missing, expired or within <?= SslService::EXPIRING_DAYS ?> days of expiry.</li>
            </ul>
        </div>
    </section>
</div>
