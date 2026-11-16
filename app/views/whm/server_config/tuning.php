<?php
/** @var array{cpus:int, mem_total:int, disk_total:int|float} $hardware */
/** @var string $preview the profile shown: balanced | performance | extreme */
/** @var array<string,array<string,mixed>> $computed TuningService::compute($preview) */
$profiles = [
    'balanced' => ['Balanced', 'Conservative resource use, safest for a server that also does other things.'],
    'performance' => ['Performance', 'Noticeably more headroom for traffic and concurrency.'],
    'extreme' => ['Extreme', 'As fast as this hardware responsibly allows, with a safety margin so nothing gets starved.'],
];
$label = $profiles[$preview][0] ?? ucfirst($preview);
View::slot('desc', e('Works out settings for MariaDB, PHP, Stalwart and SFTPGo from this server\'s hardware. Pick a profile, check what it would set, then apply it.'));

$services = [
    ['MariaDB', 'database', $computed['mysql'], true],
    ['PHP and OPcache', 'code', array_merge($computed['php'], $computed['opcache']), true],
    ['Stalwart mail', 'mail', $computed['stalwartSystem'] + ['cache sizes' => fmt_bytes((int) ($computed['stalwartCache']['accounts'] ?? 0)) . ' each'], false],
    ['SFTPGo', 'folder-up', $computed['sftpgo'], true],
];
?>
<div class="stack">
    <section class="card grid grid-cols-3 divide-x divide-line" aria-label="Detected hardware">
        <?php foreach ([['CPU cores', (string) (int) $hardware['cpus'], 'cpu'], ['Memory', fmt_bytes((int) $hardware['mem_total']), 'layers'], ['Disk', fmt_bytes((int) $hardware['disk_total']), 'hard-drive']] as [$hwLabel, $hwValue, $hwIcon]): ?>
        <div class="flex min-w-0 items-center gap-4 p-4 sm:p-5">
            <span class="stat-icon hidden sm:flex"><?= icon($hwIcon) ?></span>
            <div class="min-w-0">
                <p class="stat-label"><?= e($hwLabel) ?></p>
                <p class="stat-value tabular-nums"><?= e($hwValue) ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </section>

    <section class="card">
        <?= Ui::cardHeader('Profile', 'Each one is worked out from the hardware above and capped, so the services sharing this server all keep room.') ?>
        <nav class="card-body grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Tuning profiles">
            <?php foreach ($profiles as $key => [$name, $blurb]): $on = $preview === $key; ?>
            <a href="/whm/server-config/tuning?preview=<?= e($key) ?>"
               class="<?= $on ? 'block rounded-lg border border-accent bg-accent-soft p-4 ring-1 ring-accent transition-colors' : 'block rounded-lg border border-line bg-surface p-4 transition-colors hover:border-line-strong hover:bg-surface-2' ?>"<?= $on ? ' aria-current="true"' : '' ?>>
                <span class="flex items-center justify-between gap-2">
                    <span class="<?= $on ? 'text-sm font-semibold text-accent-text' : 'text-sm font-semibold text-fg' ?>"><?= e($name) ?></span>
                    <?= $on ? icon('check-circle', 'h-4 w-4 text-accent-text') : '' ?>
                </span>
                <span class="mt-1 block text-xs text-muted"><?= e($blurb) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <form method="post" action="/whm/server-config/tuning" class="card-footer justify-between"
              data-confirm="This overwrites the PHP, Database and SFTP settings and Stalwart's thread and cache settings. MariaDB, the web server and SFTPGo restart (sites pause for a few seconds); Stalwart applies it without a restart."
              data-confirm-title="Apply the <?= e($label) ?> profile?" data-confirm-action="Apply <?= e($label) ?>" data-confirm-danger>
            <?= Csrf::field() ?>
            <input type="hidden" name="profile" value="<?= e($preview) ?>">
            <p class="text-xs text-muted">Check the values below first: applying overwrites the PHP, Database and SFTP settings.</p>
            <button type="submit" class="btn btn-primary"><?= icon('sliders') ?>Apply <?= e($label) ?> profile</button>
        </form>
    </section>

    <section aria-labelledby="tuning-values-title">
        <h2 id="tuning-values-title" class="section-title mb-3">What <?= e($label) ?> sets</h2>
        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
            <?php foreach ($services as [$name, $ico, $rows, $restarts]): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title flex items-center gap-2"><?= icon($ico, 'h-4 w-4 text-muted') ?><?= e($name) ?></h3>
                    <?= $restarts ? Ui::badge('Restarts', 'warning') : Ui::badge('No restart', 'success') ?>
                </div>
                <dl class="divide-y divide-line">
                    <?php foreach ($rows as $k => $v): ?>
                    <div class="flex items-baseline justify-between gap-4 px-5 py-2">
                        <dt class="min-w-0 break-all font-mono text-xs text-muted"><?= e((string) $k) ?></dt>
                        <dd class="shrink-0 text-right font-mono text-xs font-medium tabular-nums text-fg"><?= e((string) $v) ?></dd>
                    </div>
                    <?php endforeach; ?>
                </dl>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
