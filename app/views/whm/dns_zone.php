<?php
/** @var array<string,mixed> $zone */
/** @var list<array<string,mixed>> $records */
/** @var list<array<string,mixed>> $managed */
/** @var string|null $preview */
/** @var list<string> $types */
/** @var string|null $lastLog */
$input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500';
$label = 'block text-xs font-medium text-slate-500 mb-1';
$zid = (int) $zone['id'];
$canDelete = (int) $zone['is_server_zone'] !== 1 && empty($zone['domain_id']);
$lastFailed = $lastLog !== null && str_contains($lastLog, 'FAILED');
?>
<div class="space-y-6">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <a href="/whm/dns" class="text-sm text-slate-500 hover:text-indigo-600">← All zones</a>
            <h2 class="text-lg font-semibold text-slate-800 mt-1"><?= e($zone['zone_name']) ?></h2>
            <p class="text-xs text-slate-500 mt-0.5">
                <?php if ((int) $zone['is_server_zone'] === 1): ?>This server's own zone · <?php endif; ?>
                <?php if (!empty($zone['domain_id'])): ?>Hosted domain of <?= e($zone['owner_username'] ?? '?') ?> · <?php endif; ?>
                Serial <span class="font-mono"><?= (int) $zone['serial'] ?></span>
            </p>
        </div>
        <div class="flex items-center gap-2">
            <form method="post" action="/whm/dns/<?= $zid ?>/republish">
                <?= Csrf::field() ?>
                <button class="text-sm font-medium text-sky-700 hover:text-sky-800 bg-sky-50 hover:bg-sky-100 rounded-lg px-3 py-2 transition-colors">Re-publish</button>
            </form>
            <?php if ($canDelete): ?>
            <form method="post" action="/whm/dns/<?= $zid ?>/delete" data-confirm="Delete the zone <?= e($zone['zone_name']) ?> and all its records? Knot stops answering for it.">
                <?= Csrf::field() ?>
                <button class="text-sm font-medium text-red-700 hover:text-red-800 bg-red-50 hover:bg-red-100 rounded-lg px-3 py-2 transition-colors">Delete zone</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($lastLog !== null): ?>
    <div class="rounded-lg border px-4 py-2.5 text-xs font-mono <?= $lastFailed ? 'border-red-200 bg-red-50 text-red-700' : 'border-slate-200 bg-slate-50 text-slate-500' ?>">
        Last publish: <?= e($lastLog) ?>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
        <h3 class="font-semibold text-slate-800">Add record</h3>
        <form method="post" action="/whm/dns/<?= $zid ?>/records" class="mt-4 grid grid-cols-2 md:grid-cols-12 gap-3 items-end">
            <?= Csrf::field() ?>
            <div class="md:col-span-3">
                <label class="<?= $label ?>">Name</label>
                <input name="name" placeholder="@, www, panel.server" class="<?= $input ?>">
            </div>
            <div class="md:col-span-2">
                <label class="<?= $label ?>">Type</label>
                <select name="type" class="<?= $input ?>">
                    <?php foreach ($types as $t): ?>
                    <option value="<?= e($t) ?>"><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-1">
                <label class="<?= $label ?>">Priority</label>
                <input name="priority" placeholder="MX/SRV" class="<?= $input ?>">
            </div>
            <div class="col-span-2 md:col-span-4">
                <label class="<?= $label ?>">Value</label>
                <input name="content" placeholder="IP, hostname or text" class="<?= $input ?>" required>
            </div>
            <div class="md:col-span-1">
                <label class="<?= $label ?>">TTL</label>
                <input name="ttl" value="3600" class="<?= $input ?>">
            </div>
            <div class="md:col-span-1">
                <button class="w-full rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-3 py-2 transition-colors">Add</button>
            </div>
        </form>
        <p class="text-xs text-slate-400 mt-3">
            Names are relative to <?= e($zone['zone_name']) ?> (use @ for the zone itself). Hostname values without a dot, like "mail",
            are taken as inside this zone. SRV value: "weight port target". CAA value: 0 issue "letsencrypt.org".
        </p>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Records</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-2.5 font-medium">Name</th>
                        <th class="px-5 py-2.5 font-medium">Type</th>
                        <th class="px-5 py-2.5 font-medium">Value</th>
                        <th class="px-5 py-2.5 font-medium">TTL</th>
                        <th class="px-5 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($managed as $r): ?>
                    <tr class="bg-slate-50/60">
                        <td class="px-5 py-2.5 font-mono text-slate-700"><?= e($r['name']) ?></td>
                        <td class="px-5 py-2.5"><span class="inline-flex rounded bg-slate-200 text-slate-700 text-xs font-semibold px-1.5 py-0.5"><?= e($r['type']) ?></span></td>
                        <td class="px-5 py-2.5 font-mono text-slate-700 break-all"><?= e($r['content']) ?></td>
                        <td class="px-5 py-2.5 text-slate-500"><?= (int) $r['ttl'] ?></td>
                        <td class="px-5 py-2.5 text-right text-xs text-slate-400 whitespace-nowrap">From nameserver settings</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php foreach ($records as $r): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-2.5 font-mono text-slate-800"><?= e($r['name']) ?></td>
                        <td class="px-5 py-2.5"><span class="inline-flex rounded bg-indigo-50 text-indigo-700 text-xs font-semibold px-1.5 py-0.5"><?= e($r['type']) ?></span></td>
                        <td class="px-5 py-2.5 font-mono text-slate-800 break-all">
                            <?php if ($r['priority'] !== null): ?><span class="text-slate-400"><?= (int) $r['priority'] ?></span> <?php endif; ?><?= e($r['content']) ?>
                        </td>
                        <td class="px-5 py-2.5 text-slate-500"><?= (int) $r['ttl'] ?></td>
                        <td class="px-5 py-2.5 text-right">
                            <form method="post" action="/whm/dns/<?= $zid ?>/records/<?= (int) $r['id'] ?>/delete" data-confirm="Delete this <?= e($r['type']) ?> record for <?= e($r['name']) ?>?">
                                <?= Csrf::field() ?>
                                <button class="p-1.5 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50"><?= icon('trash', 'h-4 w-4') ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$records): ?>
                    <tr><td colspan="5" class="px-5 py-6 text-center text-slate-400">No records yet - add one above.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($preview !== null): ?>
    <details class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <summary class="px-5 py-4 cursor-pointer font-semibold text-slate-800">Zone file preview</summary>
        <pre class="text-xs font-mono text-slate-600 bg-slate-50 p-4 overflow-x-auto border-t border-slate-100"><?= e($preview) ?></pre>
    </details>
    <?php endif; ?>
</div>
