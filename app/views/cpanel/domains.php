<?php include __DIR__ . '/../partials/quota_bars.php'; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4">
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                <tr>
                    <th class="text-left font-medium px-5 py-3">Domain</th>
                    <th class="text-left font-medium px-5 py-3">DNS</th>
                    <th class="text-left font-medium px-5 py-3">PHP version</th>
                    <th class="text-left font-medium px-5 py-3">SSL</th>
                    <th class="text-right font-medium px-5 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($domains as $d): ?>
                <tr class="hover:bg-slate-50/70">
                    <td class="px-5 py-3">
                        <a href="https://<?= e($d['domain_name']) ?>" target="_blank" class="font-medium text-sky-700 hover:underline"><?= e($d['domain_name']) ?></a>
                        <p class="text-xs text-slate-400 font-mono mt-0.5"><?= e($d['docroot']) ?></p>
                    </td>
                    <td class="px-5 py-3">
                        <span class="text-xs font-medium <?= $d['dns_provisioned'] ? 'text-emerald-700' : 'text-amber-700' ?>"><?= $d['dns_provisioned'] ? 'Provisioned' : 'Pending' ?></span>
                    </td>
                    <td class="px-5 py-3">
                        <form method="post" action="/cpanel/domains/<?= (int) $d['id'] ?>/settings">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="ssl_mode" value="<?= e($d['ssl_mode']) ?>">
                            <select name="php_version" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-2 py-1.5 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-sky-500">
                                <option value="default" <?= $d['php_version'] === 'default' ? 'selected' : '' ?>>Default (8.5)</option>
                                <?php foreach ($phpVersions as $v): ?>
                                <option value="<?= e($v) ?>" <?= $d['php_version'] === $v ? 'selected' : '' ?>>PHP <?= e($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td class="px-5 py-3">
                        <form method="post" action="/cpanel/domains/<?= (int) $d['id'] ?>/settings">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="php_version" value="<?= e($d['php_version']) ?>">
                            <select name="ssl_mode" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-2 py-1.5 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-sky-500">
                                <option value="self_signed" <?= $d['ssl_mode'] === 'self_signed' ? 'selected' : '' ?>>Self-signed</option>
                                <option value="letsencrypt" <?= $d['ssl_mode'] === 'letsencrypt' ? 'selected' : '' ?>>AutoSSL (Let's Encrypt)</option>
                            </select>
                        </form>
                        <?php $st = $d['ssl']['state']; ?>
                        <p class="mt-1 text-xs font-medium <?= $st === 'ok' ? 'text-emerald-700' : ($st === 'no_dns' ? 'text-red-700' : 'text-amber-700') ?>" title="<?= e($d['ssl']['detail']) ?>">
                            <?= e($d['ssl']['label']) ?>
                        </p>
                        <p class="text-xs text-slate-400 max-w-xs"><?= e($d['ssl']['detail']) ?></p>
                    </td>
                    <td class="px-5 py-3 text-right">
                        <form method="post" action="/cpanel/domains/<?= (int) $d['id'] ?>/delete" data-confirm="Remove <?= e($d['domain_name']) ?>? Files on disk are kept.">
                            <?= Csrf::field() ?>
                            <button class="p-2 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50"><?= icon('trash', 'h-4 w-4') ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$domains): ?>
                <tr><td colspan="5" class="px-5 py-10 text-center text-slate-400">No domains yet - add your first one.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 h-fit">
        <h2 class="text-sm font-semibold text-slate-700 mb-3">Add a domain</h2>
        <form method="post" action="/cpanel/domains" class="space-y-3">
            <?= Csrf::field() ?>
            <input type="text" name="domain_name" required placeholder="example.com"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500">

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">PHP version</label>
                <select name="php_version" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-sky-500">
                    <option value="default">Default (8.5)</option>
                    <?php foreach ($phpVersions as $v): ?>
                    <option value="<?= e($v) ?>">PHP <?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">SSL</label>
                <select name="ssl_mode" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-sky-500">
                    <option value="auto" selected>Automatic - Let's Encrypt once the domain points here</option>
                    <option value="letsencrypt">Let's Encrypt now (the domain must already point here)</option>
                    <option value="self_signed">Self-signed only (browsers warn)</option>
                </select>
            </div>

            <button type="submit" class="w-full rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium py-2.5 transition-colors">Add domain</button>
            <p class="text-xs text-slate-400">Creates a live web vhost and a DNS zone pointed at this server.</p>
        </form>
    </div>
</div>
