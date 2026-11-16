<?php
/** @var list<array<string,mixed>> $zones */
/** @var array{ns1_host:string, ns1_ip:string, ns2_host:string, ns2_ip:string} $ns */
/** @var string|null $serverZone */
$hasServerZone = false;
foreach ($zones as $z) {
    if ((int) $z['is_server_zone'] === 1) {
        $hasServerZone = true;
    }
}
View::slot('desc', e('Every zone this server\'s nameservers answer for: its own, the hosted domains\' and standalone ones.'));
View::slot('actions', '<a href="#add-zone" class="btn btn-primary">' . icon('plus') . 'Add zone</a>');
?>
<div class="stack">
    <?php if ($serverZone !== null && !$hasServerZone): ?>
    <div class="alert alert-warning" role="status">
        <?= icon('alert') ?>
        <div class="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="font-medium">No zone for <?= e($serverZone) ?> yet.</p>
                <p class="mt-0.5">Without it the panel (panel.<?= e(Config::SERVER_HOSTNAME) ?>) and the nameservers themselves don't resolve publicly.</p>
            </div>
            <form method="post" action="/whm/dns/server-zone">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-primary btn-sm"><?= icon('plus') ?>Create server zone</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <section class="card min-w-0 xl:col-span-2" aria-labelledby="zones-title">
            <div class="card-header">
                <h2 id="zones-title" class="card-title"><?= e(Ui::plural(count($zones), 'zone')) ?></h2>
                <?php if (count($zones) > 8): ?>
                <input type="search" class="input input-sm w-full sm:w-56" placeholder="Filter zones…" aria-label="Filter zones" data-filter="#zones-table">
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table class="table" id="zones-table">
                    <thead>
                        <tr>
                            <th scope="col">Zone</th>
                            <th scope="col" class="hidden sm:table-cell">Belongs to</th>
                            <th scope="col" class="hidden text-right md:table-cell">Records</th>
                            <th scope="col" class="hidden lg:table-cell">Serial</th>
                            <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($zones as $z): $zid = (int) $z['id'];
                            $owner = (int) $z['is_server_zone'] === 1 ? Ui::badge('This server', 'accent')
                                : (!empty($z['domain_id']) ? '<span class="inline-flex items-center gap-1.5 text-muted">' . icon('user', 'h-3.5 w-3.5 text-subtle') . e((string) ($z['owner_username'] ?? '?')) . '</span>'
                                : Ui::badge('Standalone', 'neutral')); ?>
                        <tr>
                            <td class="max-w-[22rem]">
                                <a href="/whm/dns/<?= $zid ?>" class="block truncate font-medium text-fg hover:text-accent-text"><?= e($z['zone_name']) ?></a>
                                <div class="mt-1 text-xs sm:hidden"><?= $owner ?> <span class="text-muted">· <?= e(Ui::plural((int) $z['record_count'], 'record')) ?></span></div>
                            </td>
                            <td class="hidden whitespace-nowrap sm:table-cell"><?= $owner ?></td>
                            <td class="hidden text-right tabular-nums text-muted md:table-cell"><?= (int) $z['record_count'] ?></td>
                            <td class="hidden font-mono text-xs text-muted lg:table-cell"><?= (int) $z['serial'] ?></td>
                            <td class="text-right"><a href="/whm/dns/<?= $zid ?>" class="btn btn-secondary btn-sm" aria-label="Manage <?= e($z['zone_name']) ?>">Manage</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$zones): ?>
                        <?= Ui::emptyRow(5, 'No zones yet', 'A zone is created with each hosted domain. Add a standalone zone for a domain whose DNS should be served here.', 'globe') ?>
                        <?php elseif (count($zones) > 8): ?>
                        <tr data-filter-none data-filter-skip hidden><td colspan="5" class="py-8 text-center text-sm text-muted">No zone matches that filter.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="stack">
            <section class="card scroll-mt-20" id="add-zone" aria-labelledby="add-zone-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="add-zone-title" class="card-title">Add a zone</h2>
                        <p class="card-desc">For a domain that isn't hosted on an account here, but whose DNS should be.</p>
                    </div>
                </div>
                <form method="post" action="/whm/dns/zones" class="card-body space-y-4">
                    <?= Csrf::field() ?>
                    <?= Ui::field('zone_name', 'Domain', ['required' => true, 'placeholder' => 'example.com', 'mono' => true, 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false', 'inputmode' => 'url']]) ?>
                    <?= Ui::checkbox('point_to_server', '@ and www point to this server', true, ['hint' => 'Adds A records for the domain and www with ' . Config::SERVER_IP . '.']) ?>
                    <button type="submit" class="btn btn-primary btn-block"><?= icon('plus') ?>Add zone</button>
                </form>
            </section>

            <section class="card scroll-mt-20" id="nameservers" aria-labelledby="ns-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="ns-title" class="card-title">Nameservers</h2>
                        <p class="card-desc">The SOA and NS records of every zone use these. Register them as glue records ("personal nameservers") at the registrar of <?= e($serverZone ?? 'your domain') ?>, then point hosted domains at the two names.</p>
                    </div>
                </div>
                <form method="post" action="/whm/dns/nameservers">
                    <div class="card-body space-y-4">
                        <?= Csrf::field() ?>
                        <?php foreach (['ns1', 'ns2'] as $n): $N = strtoupper($n); ?>
                        <fieldset class="grid grid-cols-1 gap-3 sm:grid-cols-5 xl:grid-cols-1">
                            <legend class="sr-only"><?= $N ?></legend>
                            <?= Ui::field($n . '_host', $N . ' hostname', ['value' => $ns[$n . '_host'], 'required' => true, 'mono' => true, 'wrap' => 'sm:col-span-3 xl:col-span-1', 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
                            <?= Ui::field($n . '_ip', $N . ' IPv4 address', ['value' => $ns[$n . '_ip'], 'required' => true, 'mono' => true, 'wrap' => 'sm:col-span-2 xl:col-span-1', 'attrs' => ['inputmode' => 'decimal', 'spellcheck' => 'false']]) ?>
                        </fieldset>
                        <?php endforeach; ?>
                    </div>
                    <div class="card-footer justify-between">
                        <p class="text-xs text-muted">Saving re-publishes every zone.</p>
                        <button type="submit" class="btn btn-primary"><?= icon('save') ?>Save nameservers</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</div>
