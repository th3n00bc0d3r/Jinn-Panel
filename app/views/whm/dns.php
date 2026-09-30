<?php
/** @var list<array<string,mixed>> $zones */
/** @var array{ns1_host:string, ns1_ip:string, ns2_host:string, ns2_ip:string} $ns */
/** @var string|null $serverZone */
$input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500';
$label = 'block text-xs font-medium text-slate-500 mb-1';
$hasServerZone = false;
foreach ($zones as $z) {
    if ((int) $z['is_server_zone'] === 1) {
        $hasServerZone = true;
    }
}
?>
<div class="space-y-6">

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold text-slate-800">Nameservers</h2>
                <p class="text-sm text-slate-500 mt-1">
                    Used for the SOA and NS records of every zone on this server. Set the same names and IPs as
                    glue records ("personal nameservers") at the registrar of <?= e($serverZone ?? 'your domain') ?>,
                    and point hosted domains at these two names.
                </p>
            </div>
        </div>
        <form method="post" action="/whm/dns/nameservers" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            <?= Csrf::field() ?>
            <?php foreach (['ns1', 'ns2'] as $n): ?>
            <div class="grid grid-cols-5 gap-2">
                <div class="col-span-3">
                    <label class="<?= $label ?>"><?= strtoupper($n) ?> hostname</label>
                    <input name="<?= $n ?>_host" value="<?= e($ns[$n . '_host']) ?>" class="<?= $input ?>" required>
                </div>
                <div class="col-span-2">
                    <label class="<?= $label ?>"><?= strtoupper($n) ?> IPv4</label>
                    <input name="<?= $n ?>_ip" value="<?= e($ns[$n . '_ip']) ?>" class="<?= $input ?>" required>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="md:col-span-2 flex justify-end">
                <button class="rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 transition-colors">Save nameservers</button>
            </div>
        </form>
    </div>

    <?php if ($serverZone !== null && !$hasServerZone): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-5 flex items-start justify-between gap-4">
        <div class="text-sm text-amber-800">
            <p class="font-medium">No zone for <?= e($serverZone) ?> yet.</p>
            <p class="mt-1">Without it the panel (panel.<?= e(Config::SERVER_HOSTNAME) ?>) and the nameservers themselves don't resolve publicly.</p>
        </div>
        <form method="post" action="/whm/dns/server-zone">
            <?= Csrf::field() ?>
            <button class="whitespace-nowrap rounded-lg bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium px-4 py-2 transition-colors">Create server zone</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-5 py-4 border-b border-slate-100">
            <h2 class="font-semibold text-slate-800">Zones <span class="text-slate-400 font-normal">(<?= count($zones) ?>)</span></h2>
            <form method="post" action="/whm/dns/zones" class="flex flex-wrap items-center gap-2">
                <?= Csrf::field() ?>
                <input name="zone_name" placeholder="example.com" class="<?= $input ?> md:w-56" required>
                <label class="inline-flex items-center gap-1.5 text-xs text-slate-600">
                    <input type="checkbox" name="point_to_server" value="1" checked class="rounded border-slate-300"> @ and www → this server
                </label>
                <button class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-3 py-2 transition-colors">
                    <?= icon('plus', 'h-4 w-4') ?> Add zone
                </button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-2.5 font-medium">Zone</th>
                        <th class="px-5 py-2.5 font-medium">Belongs to</th>
                        <th class="px-5 py-2.5 font-medium">Records</th>
                        <th class="px-5 py-2.5 font-medium">Serial</th>
                        <th class="px-5 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($zones as $z): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3 font-medium text-slate-800">
                            <a href="/whm/dns/<?= (int) $z['id'] ?>" class="hover:text-indigo-600"><?= e($z['zone_name']) ?></a>
                        </td>
                        <td class="px-5 py-3 text-slate-600">
                            <?php if ((int) $z['is_server_zone'] === 1): ?>
                                <span class="inline-flex rounded-full bg-indigo-50 text-indigo-700 text-xs font-medium px-2 py-0.5">Server</span>
                            <?php endif; ?>
                            <?php if (!empty($z['domain_id'])): ?>
                                <span class="text-slate-600">Account: <?= e($z['owner_username'] ?? '?') ?></span>
                            <?php elseif ((int) $z['is_server_zone'] !== 1): ?>
                                <span class="text-slate-400">Standalone</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3 text-slate-600"><?= (int) $z['record_count'] ?></td>
                        <td class="px-5 py-3 font-mono text-xs text-slate-500"><?= (int) $z['serial'] ?></td>
                        <td class="px-5 py-3 text-right">
                            <a href="/whm/dns/<?= (int) $z['id'] ?>" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">Manage records</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$zones): ?>
                    <tr><td colspan="5" class="px-5 py-6 text-center text-slate-400">No zones yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
