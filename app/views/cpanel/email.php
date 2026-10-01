<?php
/** @var list<array<string,mixed>> $accounts */
/** @var list<array<string,mixed>> $domains */
/** @var list<array<string,mixed>> $forwarders */
/** @var array<int,array<string,mixed>> $autoresponders keyed by email_account_id */
/** @var string $mailHost */
/** @var array<string,int> $webmail mail.<domain> hosts with webmail */
$input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500';
$label = 'block text-xs font-medium text-slate-600 mb-1';
$btn = 'rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium px-4 py-2 transition-colors';
$card = 'bg-white rounded-xl border border-slate-200 shadow-sm';
$targetsByDomain = [];
foreach ($accounts as $a) {
    $targetsByDomain[(int) $a['domain_id']][$a['local_part']] = 'mailbox';
}
foreach ($forwarders as $f) {
    $targetsByDomain[(int) $f['domain_id']][$f['local_part']] ??= 'forwarder';
}
?>
<?php include __DIR__ . '/../partials/quota_bars.php'; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4">
    <div class="lg:col-span-2 space-y-4">
        <div class="<?= $card ?> overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-700">Mailboxes</h2>
            </div>
            <ul class="divide-y divide-slate-100 text-sm">
                <?php foreach ($accounts as $a): ?>
                <?php $addr = $a['local_part'] . '@' . $a['domain_name']; $ar = $autoresponders[(int) $a['id']] ?? null; ?>
                <li class="px-5 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-slate-700"><?= e($addr) ?></span>
                        <?php if ($ar): ?><span class="inline-flex text-xs font-medium rounded-full px-2 py-0.5 bg-amber-50 text-amber-700">Autoresponder on</span><?php endif; ?>
                        <span class="ml-auto flex items-center gap-1">
                            <?php if (isset($webmail['mail.' . $a['domain_name']])): ?>
                            <a href="https://mail.<?= e($a['domain_name']) ?>/" target="_blank" rel="noopener" class="text-xs font-medium text-sky-700 hover:text-sky-600 px-2 py-1">Webmail</a>
                            <?php endif; ?>
                            <form method="post" action="/cpanel/email/<?= (int) $a['id'] ?>/delete" data-confirm="Delete mailbox <?= e($addr) ?> and all its mail?">
                                <?= Csrf::field() ?>
                                <button class="p-2 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50" title="Delete"><?= icon('trash', 'h-4 w-4') ?></button>
                            </form>
                        </span>
                    </div>
                    <div class="mt-1 flex flex-wrap gap-4 text-xs">
                        <details class="group">
                            <summary class="cursor-pointer text-slate-500 hover:text-slate-700">Change password</summary>
                            <form method="post" action="/cpanel/email/<?= (int) $a['id'] ?>/password" class="mt-2 flex gap-2">
                                <?= Csrf::field() ?>
                                <input type="password" name="password" required minlength="8" placeholder="New password" class="<?= $input ?>">
                                <button class="<?= $btn ?>">Save</button>
                            </form>
                        </details>
                        <details class="group w-full sm:w-auto">
                            <summary class="cursor-pointer text-slate-500 hover:text-slate-700">Autoresponder</summary>
                            <form method="post" action="/cpanel/email/<?= (int) $a['id'] ?>/autoresponder" class="mt-2 grid gap-2 sm:grid-cols-2 max-w-xl">
                                <?= Csrf::field() ?>
                                <div class="sm:col-span-2">
                                    <label class="<?= $label ?>">Subject</label>
                                    <input name="subject" required maxlength="200" value="<?= e($ar['subject'] ?? 'Out of office') ?>" class="<?= $input ?>">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="<?= $label ?>">Message</label>
                                    <textarea name="body" required rows="4" maxlength="10000" class="<?= $input ?>"><?= e($ar['body'] ?? '') ?></textarea>
                                </div>
                                <div>
                                    <label class="<?= $label ?>">From (optional)</label>
                                    <input type="date" name="starts_on" value="<?= e($ar['starts_on'] ?? '') ?>" class="<?= $input ?>">
                                </div>
                                <div>
                                    <label class="<?= $label ?>">Until (optional)</label>
                                    <input type="date" name="ends_on" value="<?= e($ar['ends_on'] ?? '') ?>" class="<?= $input ?>">
                                </div>
                                <div>
                                    <label class="<?= $label ?>">Reply to the same sender every</label>
                                    <select name="interval_days" class="<?= $input ?> bg-white">
                                        <?php foreach ([1, 3, 7, 14, 30] as $n): ?>
                                        <option value="<?= $n ?>" <?= (int) ($ar['interval_days'] ?? 1) === $n ? 'selected' : '' ?>><?= $n ?> day<?= $n === 1 ? '' : 's' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="flex items-end gap-2">
                                    <button class="<?= $btn ?>"><?= $ar ? 'Update' : 'Turn on' ?></button>
                                </div>
                            </form>
                            <?php if ($ar): ?>
                            <form method="post" action="/cpanel/email/<?= (int) $a['id'] ?>/autoresponder/delete" class="mt-2">
                                <?= Csrf::field() ?>
                                <button class="text-xs font-medium text-red-700 hover:text-red-600">Turn off the autoresponder</button>
                            </form>
                            <?php endif; ?>
                        </details>
                    </div>
                </li>
                <?php endforeach; ?>
                <?php if (!$accounts): ?>
                <li class="px-5 py-10 text-center text-slate-400">No mailboxes yet.</li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="<?= $card ?> overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100">
                <h2 class="text-sm font-semibold text-slate-700">Forwarders</h2>
                <p class="text-xs text-slate-400 mt-0.5">Pass mail for an address on to other addresses. A mailbox that forwards keeps its own copy.</p>
            </div>
            <ul class="divide-y divide-slate-100 text-sm">
                <?php foreach ($forwarders as $f): ?>
                <li class="px-5 py-3 flex flex-wrap items-start gap-2">
                    <span class="font-medium text-slate-700"><?= e($f['local_part'] . '@' . $f['domain_name']) ?></span>
                    <span class="text-slate-400">&rarr;</span>
                    <span class="flex flex-wrap gap-1.5">
                        <?php foreach (explode(',', (string) $f['destinations']) as $dest): ?>
                        <form method="post" action="/cpanel/email-forwarders/<?= (int) $f['id'] ?>/delete" class="inline-flex items-center gap-1 rounded-full bg-slate-100 pl-2.5 pr-1 py-0.5 text-xs text-slate-700">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="destination" value="<?= e($dest) ?>">
                            <?= e($dest) ?>
                            <button class="rounded-full p-0.5 text-slate-400 hover:text-red-600" title="Stop forwarding to <?= e($dest) ?>"><?= icon('close', 'h-3 w-3') ?></button>
                        </form>
                        <?php endforeach; ?>
                    </span>
                    <form method="post" action="/cpanel/email-forwarders/<?= (int) $f['id'] ?>/delete" class="ml-auto" data-confirm="Delete the forwarder for <?= e($f['local_part'] . '@' . $f['domain_name']) ?>?">
                        <?= Csrf::field() ?>
                        <button class="p-1.5 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50" title="Delete forwarder"><?= icon('trash', 'h-4 w-4') ?></button>
                    </form>
                </li>
                <?php endforeach; ?>
                <?php if (!$forwarders): ?>
                <li class="px-5 py-6 text-center text-slate-400">No forwarders.</li>
                <?php endif; ?>
            </ul>
            <?php if ($domains): ?>
            <form method="post" action="/cpanel/email-forwarders" class="border-t border-slate-100 px-5 py-4 grid gap-2 sm:grid-cols-[1fr_1fr_auto] items-end">
                <?= Csrf::field() ?>
                <div>
                    <label class="<?= $label ?>">Address</label>
                    <div class="flex items-stretch rounded-lg border border-slate-300 overflow-hidden focus-within:ring-2 focus-within:ring-sky-500">
                        <input type="text" name="local_part" required placeholder="sales" class="w-1/2 px-3 py-2 text-sm focus:outline-none">
                        <span class="px-2 flex items-center text-slate-400 bg-slate-50 border-x border-slate-200">@</span>
                        <select name="domain_id" class="w-1/2 px-2 py-2 text-sm focus:outline-none bg-white">
                            <?php foreach ($domains as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"><?= e($d['domain_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="<?= $label ?>">Forward to (comma-separated)</label>
                    <input type="text" name="destinations" required placeholder="you@gmail.com" class="<?= $input ?>">
                </div>
                <button class="<?= $btn ?>">Add</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if ($domains): ?>
        <div class="<?= $card ?> overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100">
                <h2 class="text-sm font-semibold text-slate-700">Default address</h2>
                <p class="text-xs text-slate-400 mt-0.5">Mail sent to an address that doesn't exist. Rejecting it is safest - a catch-all mainly collects spam.</p>
            </div>
            <ul class="divide-y divide-slate-100 text-sm">
                <?php foreach ($domains as $d): ?>
                <?php $targets = $targetsByDomain[(int) $d['id']] ?? []; ksort($targets); $current = $d['catch_all'] ? strstr((string) $d['catch_all'], '@', true) : ''; ?>
                <li class="px-5 py-3">
                    <form method="post" action="/cpanel/email-default-address" class="flex flex-wrap items-center gap-3">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="domain_id" value="<?= (int) $d['id'] ?>">
                        <span class="font-medium text-slate-700 min-w-40"><?= e($d['domain_name']) ?></span>
                        <select name="target" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-2 py-1.5 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-sky-500">
                            <option value="">Reject (bounce to the sender)</option>
                            <?php foreach ($targets as $local => $kind): ?>
                            <option value="<?= e($local) ?>" <?= $current === $local ? 'selected' : '' ?>>Deliver to <?= e($local . '@' . $d['domain_name']) ?><?= $kind === 'forwarder' ? ' (forwarder)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button class="<?= $btn ?>">Save</button></noscript>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <div class="space-y-4">
        <div class="<?= $card ?> p-5">
            <h2 class="text-sm font-semibold text-slate-700 mb-3">Create a mailbox</h2>
            <?php if (!$domains): ?>
            <p class="text-sm text-slate-400">Add a domain first.</p>
            <?php else: ?>
            <form method="post" action="/cpanel/email" class="space-y-3">
                <?= Csrf::field() ?>
                <div class="flex items-stretch rounded-lg border border-slate-300 overflow-hidden focus-within:ring-2 focus-within:ring-sky-500">
                    <input type="text" name="local_part" required placeholder="you" class="w-1/2 px-3 py-2.5 text-sm focus:outline-none">
                    <span class="px-2 flex items-center text-slate-400 bg-slate-50 border-x border-slate-200">@</span>
                    <select name="domain_id" class="w-1/2 px-2 py-2.5 text-sm focus:outline-none bg-white">
                        <?php foreach ($domains as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"><?= e($d['domain_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="<?= $label ?>">Password</label>
                    <input type="password" name="password" required minlength="8" class="<?= $input ?>">
                </div>
                <button type="submit" class="w-full <?= $btn ?> py-2.5">Create mailbox</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="<?= $card ?> p-5" id="mail-client-settings">
            <h2 class="text-sm font-semibold text-slate-700">Mail client settings</h2>
            <p class="text-xs text-slate-400 mt-0.5 mb-3">Most apps (Outlook, Apple Mail, Thunderbird, phones) set themselves up from just the address and password. To enter it by hand:</p>
            <dl class="text-sm space-y-3">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Username</dt>
                    <dd class="text-slate-700">The full email address, e.g. <span class="font-mono">you@<?= e($domains[0]['domain_name'] ?? 'example.com') ?></span></dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Incoming - IMAP (recommended)</dt>
                    <dd class="text-slate-700"><span class="font-mono"><?= e($mailHost) ?></span> &middot; port <span class="font-mono">993</span> &middot; SSL/TLS</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Incoming - POP3</dt>
                    <dd class="text-slate-700"><span class="font-mono"><?= e($mailHost) ?></span> &middot; port <span class="font-mono">995</span> &middot; SSL/TLS</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Outgoing - SMTP</dt>
                    <dd class="text-slate-700"><span class="font-mono"><?= e($mailHost) ?></span> &middot; port <span class="font-mono">465</span> &middot; SSL/TLS</dd>
                    <dd class="text-slate-500 text-xs">or port <span class="font-mono">587</span> with STARTTLS. Authentication: normal password, same username.</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Webmail</dt>
                    <dd class="text-slate-700"><span class="font-mono">https://mail.&lt;your domain&gt;/</span></dd>
                </div>
            </dl>
        </div>
    </div>
</div>
