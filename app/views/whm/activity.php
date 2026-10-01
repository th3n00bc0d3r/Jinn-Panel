<?php
/** @var list<array<string,mixed>> $rows */
/** @var string $q */
/** @var int|null $next */
$card = 'bg-white rounded-xl border border-slate-200 shadow-sm';
$tone = function (string $action): string {
    if (preg_match('/(failed|blocked|denied)/', $action)) {
        return 'bg-red-50 text-red-700';
    }
    if (preg_match('/(delete|remove|suspend|disable|reset)/', $action)) {
        return 'bg-amber-50 text-amber-700';
    }
    return 'bg-slate-100 text-slate-700';
};
?>
<div class="<?= $card ?> overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-center gap-3">
        <div>
            <h2 class="text-sm font-semibold text-slate-700">Activity log</h2>
            <p class="text-xs text-slate-400 mt-0.5">Sign-ins, and every change made through the panel - by whom and from which address.</p>
        </div>
        <form method="get" action="/whm/activity" class="ml-auto flex gap-2">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Action, user, detail or IP" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <button class="rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-3 py-1.5">Search</button>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="text-left px-5 py-2">When</th><th class="text-left px-3 py-2">Who</th><th class="text-left px-3 py-2">Action</th><th class="text-left px-3 py-2">Detail</th><th class="text-left px-3 py-2">Account</th><th class="text-left px-3 py-2">From</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="px-5 py-2 whitespace-nowrap text-slate-500"><?= e((string) $r['created_at']) ?></td>
                    <td class="px-3 py-2 whitespace-nowrap"><?= e($r['actor_name'] ?? ($r['actor_id'] ? '#' . $r['actor_id'] : '-')) ?></td>
                    <td class="px-3 py-2 whitespace-nowrap"><span class="text-xs font-medium rounded-full px-2 py-0.5 <?= $tone((string) $r['action']) ?>"><?= e((string) $r['action']) ?></span></td>
                    <td class="px-3 py-2 text-slate-600 break-all"><?= e((string) ($r['detail'] ?? '')) ?></td>
                    <td class="px-3 py-2 whitespace-nowrap"><?= e($r['target_name'] ?? '') ?></td>
                    <td class="px-3 py-2 whitespace-nowrap font-mono text-xs text-slate-500"><?= e((string) ($r['ip'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="6" class="px-5 py-8 text-center text-slate-400">Nothing recorded<?= $q !== '' ? ' that matches' : ' yet' ?>.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($next !== null): ?>
    <div class="px-5 py-3 border-t border-slate-100 text-right">
        <a href="/whm/activity?<?= e(http_build_query(['q' => $q, 'before' => $next])) ?>" class="text-sm font-medium text-indigo-700 hover:text-indigo-600">Older entries &rarr;</a>
    </div>
    <?php endif; ?>
</div>
