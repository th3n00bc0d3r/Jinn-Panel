<div class="space-y-4">
    <?php foreach ($domains as $d): ?>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
            <div>
                <p class="font-medium text-slate-800"><?= e($d['domain_name']) ?></p>
                <p class="text-xs <?= $d['dns_provisioned'] ? 'text-emerald-600' : 'text-amber-600' ?>"><?= $d['dns_provisioned'] ? 'Zone active on Knot DNS' : 'Not provisioned' ?></p>
            </div>
            <form method="post" action="/cpanel/dns/<?= (int) $d['id'] ?>/reprovision">
                <?= Csrf::field() ?>
                <button class="text-sm font-medium text-sky-700 hover:text-sky-800 bg-sky-50 hover:bg-sky-100 rounded-lg px-3 py-1.5 transition-colors">
                    <?= $d['dns_provisioned'] ? 'Re-provision' : 'Provision now' ?>
                </button>
            </form>
        </div>
        <?php if ($d['zone_content']): ?>
        <pre class="text-xs font-mono text-slate-600 bg-slate-50 p-4 overflow-x-auto"><?= e($d['zone_content']) ?></pre>
        <?php else: ?>
        <p class="text-sm text-slate-400 px-5 py-4">No zone file yet.</p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (!$domains): ?>
    <p class="text-slate-400 text-sm">Add a domain first to manage its DNS zone.</p>
    <?php endif; ?>
</div>
