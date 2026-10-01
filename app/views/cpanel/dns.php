<?php
/** @var list<array<string,mixed>> $domains */
/** @var array<string,mixed>|null $current */
/** @var array<string,mixed>|null $zone */
/** @var list<array<string,mixed>> $records each with 'locked' */
/** @var list<array<string,mixed>> $nsRecords */
/** @var list<string> $types */
/** @var string|null $preview */
$input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500';
$label = 'block text-xs font-medium text-slate-500 mb-1';
$card = 'bg-white rounded-xl border border-slate-200 shadow-sm';
$did = $current ? (int) $current['id'] : 0;
?>
<?php if (!$domains): ?>
<p class="text-slate-400 text-sm">Add a domain first to manage its DNS zone.</p>
<?php else: ?>
<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
        <form method="get" action="/cpanel/dns">
            <select name="domain" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-sky-500">
                <?php foreach ($domains as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $did ? 'selected' : '' ?>><?= e($d['domain_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="text-sm text-sky-700">Show</button></noscript>
        </form>
        <span class="text-xs <?= $current['dns_provisioned'] ? 'text-emerald-600' : 'text-amber-600' ?>"><?= $current['dns_provisioned'] ? 'Zone active on this server\'s DNS' : 'Not provisioned' ?></span>
        <form method="post" action="/cpanel/dns/<?= $did ?>/reprovision" class="ml-auto">
            <?= Csrf::field() ?>
            <button class="text-sm font-medium text-sky-700 hover:text-sky-800 bg-sky-50 hover:bg-sky-100 rounded-lg px-3 py-1.5 transition-colors"><?= $current['dns_provisioned'] ? 'Re-publish' : 'Provision now' ?></button>
        </form>
    </div>

    <?php if ($zone): ?>
    <div class="<?= $card ?> p-5">
        <h3 class="text-sm font-semibold text-slate-700">Add a record</h3>
        <form method="post" action="/cpanel/dns/<?= $did ?>/records" class="mt-3 grid grid-cols-2 md:grid-cols-12 gap-3 items-end">
            <?= Csrf::field() ?>
            <div class="md:col-span-3"><label class="<?= $label ?>">Name</label><input name="name" placeholder="@, www, blog" class="<?= $input ?>"></div>
            <div class="md:col-span-2"><label class="<?= $label ?>">Type</label>
                <select name="type" class="<?= $input ?> bg-white">
                    <?php foreach ($types as $t): if ($t === 'NS') continue; ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
                    <option value="NS">NS (delegate a subdomain)</option>
                </select>
            </div>
            <div class="md:col-span-1"><label class="<?= $label ?>">Priority</label><input name="priority" placeholder="MX/SRV" class="<?= $input ?>"></div>
            <div class="col-span-2 md:col-span-4"><label class="<?= $label ?>">Value</label><input name="content" required placeholder="IP address, hostname or text" class="<?= $input ?>"></div>
            <div class="md:col-span-1"><label class="<?= $label ?>">TTL</label><input name="ttl" value="3600" class="<?= $input ?>"></div>
            <div class="md:col-span-1"><button class="w-full rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium px-3 py-2 transition-colors">Add</button></div>
        </form>
        <p class="text-xs text-slate-400 mt-3">Names are relative to <?= e($zone['zone_name']) ?> (@ = the domain itself). A hostname value without a dot, like "mail", means mail.<?= e($zone['zone_name']) ?>. SRV value: "weight port target". CAA: 0 issue "letsencrypt.org".</p>
    </div>

    <div class="<?= $card ?> overflow-hidden">
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
                    <?php foreach ($nsRecords as $r): ?>
                    <tr class="bg-slate-50/60">
                        <td class="px-5 py-2.5 font-mono text-slate-600"><?= e($r['name']) ?></td>
                        <td class="px-5 py-2.5"><span class="inline-flex rounded bg-slate-200 text-slate-700 text-xs font-semibold px-1.5 py-0.5"><?= e($r['type']) ?></span></td>
                        <td class="px-5 py-2.5 font-mono text-slate-600 break-all"><?= e($r['content']) ?></td>
                        <td class="px-5 py-2.5 text-slate-500"><?= (int) $r['ttl'] ?></td>
                        <td class="px-5 py-2.5 text-right text-xs text-slate-400 whitespace-nowrap">This server's nameservers</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php foreach ($records as $r): ?>
                    <?php $rid = (int) $r['id']; ?>
                    <tr class="<?= $r['locked'] ? 'bg-slate-50/60' : 'hover:bg-slate-50' ?>">
                        <td class="px-5 py-2.5 font-mono text-slate-800"><?= e($r['name']) ?></td>
                        <td class="px-5 py-2.5"><span class="inline-flex rounded <?= $r['locked'] ? 'bg-slate-200 text-slate-700' : 'bg-sky-50 text-sky-700' ?> text-xs font-semibold px-1.5 py-0.5"><?= e($r['type']) ?></span></td>
                        <td class="px-5 py-2.5 font-mono text-slate-800 break-all">
                            <?php if ($r['priority'] !== null): ?><span class="text-slate-400"><?= (int) $r['priority'] ?></span> <?php endif; ?><?= e($r['content']) ?>
                            <?php if (!$r['locked']): ?>
                            <details class="mt-1 font-sans">
                                <summary class="cursor-pointer text-xs text-slate-500 hover:text-slate-700">Edit</summary>
                                <form method="post" action="/cpanel/dns/<?= $did ?>/records/<?= $rid ?>" class="mt-2 grid grid-cols-2 md:grid-cols-6 gap-2 items-end">
                                    <?= Csrf::field() ?>
                                    <div><label class="<?= $label ?>">Name</label><input name="name" value="<?= e($r['name']) ?>" class="<?= $input ?>"></div>
                                    <div><label class="<?= $label ?>">Type</label>
                                        <select name="type" class="<?= $input ?> bg-white"><?php foreach ($types as $t): ?><option value="<?= e($t) ?>" <?= $t === $r['type'] ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
                                    </div>
                                    <div><label class="<?= $label ?>">Priority</label><input name="priority" value="<?= e((string) ($r['priority'] ?? '')) ?>" class="<?= $input ?>"></div>
                                    <div class="col-span-2"><label class="<?= $label ?>">Value</label><input name="content" required value="<?= e($r['content']) ?>" class="<?= $input ?>"></div>
                                    <div class="flex gap-2"><div><label class="<?= $label ?>">TTL</label><input name="ttl" value="<?= (int) $r['ttl'] ?>" class="<?= $input ?>"></div><button class="self-end rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium px-3 py-2">Save</button></div>
                                </form>
                            </details>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-2.5 text-slate-500 align-top"><?= (int) $r['ttl'] ?></td>
                        <td class="px-5 py-2.5 text-right align-top">
                            <?php if (!empty($r['managed'])): ?>
                            <span class="text-xs text-slate-400 whitespace-nowrap" title="Kept up to date by the panel. Add your own record with this name to replace it."><?= e(DnsService::managedLabel((string) $r['managed'])) ?></span>
                            <?php elseif ($r['locked']): ?>
                            <span class="text-xs text-slate-400 whitespace-nowrap">Server record</span>
                            <?php else: ?>
                            <form method="post" action="/cpanel/dns/<?= $did ?>/records/<?= $rid ?>/delete" data-confirm="Delete this <?= e($r['type']) ?> record for <?= e($r['name']) ?>?">
                                <?= Csrf::field() ?>
                                <button class="p-1.5 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50" title="Delete"><?= icon('trash', 'h-4 w-4') ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$records): ?>
                    <tr><td colspan="5" class="px-5 py-6 text-center text-slate-400">No records yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($preview !== null): ?>
    <details class="<?= $card ?>">
        <summary class="px-5 py-3 cursor-pointer text-sm font-semibold text-slate-700">Zone file</summary>
        <pre class="text-xs font-mono text-slate-600 bg-slate-50 p-4 overflow-x-auto border-t border-slate-100"><?= e($preview) ?></pre>
    </details>
    <?php endif; ?>
    <?php else: ?>
    <p class="text-sm text-slate-400">This domain has no DNS zone on this server yet - use "Provision now".</p>
    <?php endif; ?>
</div>
<?php endif; ?>
