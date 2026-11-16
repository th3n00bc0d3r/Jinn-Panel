<?php
/** @var list<array<string,mixed>> $accounts with domain_name */
/** @var list<array<string,mixed>> $domains */
/** @var list<array<string,mixed>> $forwarders with domain_name */
/** @var array<int,array<string,mixed>> $autoresponders keyed by email_account_id */
/** @var string $mailHost */
/** @var array<string,bool> $webmail mail.<domain> hosts that serve webmail */
View::slot('desc', e('Mailboxes, forwarders and the default address of your domains.'));
View::slot('actions', '<a href="#mail-client-settings" class="btn btn-secondary">' . icon('settings') . 'Mail app settings</a>'
    . ($domains ? '<a href="#add-mailbox" class="btn btn-primary">' . icon('plus') . 'Create mailbox</a>' : ''));
View::script('email');

$today = date('Y-m-d');
$day = fn(?string $d) => $d ? date('j M', (int) strtotime($d)) : '';
$domainOptions = array_column($domains, 'domain_name', 'id');

// Which addresses also forward, and each domain's default (catch-all) address.
$forwards = [];
foreach ($forwarders as $f) {
    $forwards[strtolower($f['local_part'] . '@' . $f['domain_name'])] = true;
}
$catchAll = [];
foreach ($domains as $d) {
    if (!empty($d['catch_all'])) {
        $catchAll[strtolower((string) $d['catch_all'])] = true;
    }
}
// Choices for the default address: the domain's mailboxes, then its forwarders.
$targetsByDomain = [];
foreach ($accounts as $a) {
    $targetsByDomain[(int) $a['domain_id']][$a['local_part']] = 'mailbox';
}
foreach ($forwarders as $f) {
    $targetsByDomain[(int) $f['domain_id']][$f['local_part']] ??= 'forwarder';
}

// A failed form comes back with what was typed; only the forwarder form sends "destinations".
$fromForwarder = Ui::old('destinations') !== null;

/** "name @ domain" as one control: the label belongs to the name, the domain select is named on its own. */
$addressField = function (string $label, string $placeholder, ?string $value) use ($domainOptions): string {
    $id = Ui::id('addr');
    $selected = Ui::old('domain_id');
    $opts = '';
    foreach ($domainOptions as $did => $name) {
        $opts .= '<option value="' . (int) $did . '"' . ((string) $did === $selected ? ' selected' : '') . '>' . e((string) $name) . '</option>';
    }
    return '<div class="field"><label for="' . e($id) . '" class="label">' . e($label) . '</label>'
        . '<div class="flex items-stretch">'
        . '<input type="text" name="local_part" id="' . e($id) . '" required placeholder="' . e($placeholder) . '" value="' . e($value) . '"'
        . ' class="input min-w-0 flex-1 rounded-r-none" autocomplete="off" autocapitalize="off" spellcheck="false">'
        . '<span class="input-addon rounded-none border-x-0 px-2.5" aria-hidden="true">@</span>'
        . '<select name="domain_id" class="select min-w-0 flex-1 rounded-l-none" aria-label="Domain">' . $opts . '</select>'
        . '</div></div>';
};

/** Badges for what a mailbox does besides receiving mail. */
$extras = function (string $addr, ?array $ar) use ($today, $day, $forwards, $catchAll): string {
    $out = '';
    if ($ar) {
        if (!empty($ar['starts_on']) && $ar['starts_on'] > $today) {
            $out .= Ui::badge('Auto-reply from ' . $day($ar['starts_on']), 'neutral', true);
        } elseif (!empty($ar['ends_on']) && $ar['ends_on'] < $today) {
            $out .= Ui::badge('Auto-reply ended', 'neutral', true);
        } else {
            $out .= Ui::badge('Auto-reply on' . (!empty($ar['ends_on']) ? ' until ' . $day($ar['ends_on']) : ''), 'info', true);
        }
    }
    if (isset($forwards[strtolower($addr)])) {
        $out .= Ui::badge('Forwarding', 'accent');
    }
    if (isset($catchAll[strtolower($addr)])) {
        $out .= Ui::badge('Default address', 'neutral');
    }
    return $out;
};

$compact = true;
$mailFull = $pkg && !Quota::unlimited($pkg['max_email_accounts']) && (int) $usage['email_accounts'] >= (int) $pkg['max_email_accounts'];
?>
<div class="stack">
    <?php include __DIR__ . '/../partials/quota_bars.php'; ?>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <div class="stack xl:col-span-2">
            <section class="card" aria-labelledby="mailboxes-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="mailboxes-title" class="card-title"><?= e(Ui::plural(count($accounts), 'mailbox', 'mailboxes')) ?></h2>
                        <p class="card-desc">Sign in to webmail or a mail app with the full address and its password.</p>
                    </div>
                    <?php if (count($accounts) > 5): ?>
                    <input type="search" class="input input-sm w-full sm:w-56" placeholder="Filter mailboxes…" aria-label="Filter mailboxes" data-filter="#mailboxes-table">
                    <?php endif; ?>
                </div>
                <div class="table-wrap">
                    <table class="table" id="mailboxes-table">
                        <thead>
                            <tr>
                                <th scope="col">Address</th>
                                <th scope="col" class="hidden md:table-cell">Created</th>
                                <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $a):
                                $id = (int) $a['id'];
                                $addr = $a['local_part'] . '@' . $a['domain_name'];
                                $ar = $autoresponders[$id] ?? null;
                                $badges = $extras($addr, $ar); ?>
                            <tr>
                                <td class="max-w-[10rem] sm:max-w-[24rem]">
                                    <div class="flex items-center gap-1">
                                        <span class="truncate font-medium text-fg"><?= e($addr) ?></span>
                                        <?= Ui::copy($addr, 'Copy ' . $addr) ?>
                                    </div>
                                    <?php if ($badges !== ''): ?><div class="mt-1 flex flex-wrap gap-1.5"><?= $badges ?></div><?php endif; ?>
                                </td>
                                <td class="hidden whitespace-nowrap text-muted md:table-cell"><?= e(fmt_ago($a['created_at'] ?? null)) ?></td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <?php if (isset($webmail['mail.' . $a['domain_name']])): ?>
                                        <span class="hidden sm:inline-flex"><?= Ui::iconLink('https://mail.' . $a['domain_name'] . '/', 'external', 'Open webmail for ' . $addr, true) ?></span>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-secondary btn-sm" data-dialog-open="mailbox-<?= $id ?>" aria-haspopup="dialog" aria-label="Manage <?= e($addr) ?>">Manage</button>
                                        <?= Ui::deleteForm("/cpanel/email/$id/delete", 'Delete the mailbox ' . $addr . ' and all its mail? This can\'t be undone.', 'Delete mailbox') ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$accounts): ?>
                            <?= Ui::emptyRow(3, 'No mailboxes yet', $domains ? 'Create one to send and receive mail at your own domain, e.g. info@' . $domains[0]['domain_name'] . '.' : 'Add a domain first, then create mailboxes on it.', 'mail') ?>
                            <?php else: ?>
                            <tr data-filter-none data-filter-skip hidden><td colspan="3" class="py-8 text-center text-sm text-muted">No mailbox matches that filter.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php foreach ($accounts as $a):
                    $id = (int) $a['id'];
                    $addr = $a['local_part'] . '@' . $a['domain_name'];
                    $ar = $autoresponders[$id] ?? null;
                    $hasWebmail = isset($webmail['mail.' . $a['domain_name']]); ?>
                <dialog id="mailbox-<?= $id ?>" class="dialog max-w-lg" aria-labelledby="mailbox-<?= $id ?>-title">
                    <div class="flex items-start justify-between gap-3 border-b border-line px-6 py-4">
                        <div class="min-w-0">
                            <h2 id="mailbox-<?= $id ?>-title" class="dialog-title truncate"><?= e($addr) ?></h2>
                            <p class="mt-0.5 text-sm text-muted">
                                Password and automatic replies<?php if ($hasWebmail): ?> · <a href="https://mail.<?= e($a['domain_name']) ?>/" target="_blank" rel="noopener" class="link">Open webmail</a><?php endif; ?>
                            </p>
                        </div>
                        <?= Ui::iconButton('close', 'Close', ['type' => 'button', 'attrs' => ['data-dialog-close' => true]]) ?>
                    </div>

                    <form method="post" action="/cpanel/email/<?= $id ?>/password" class="space-y-3 border-b border-line px-6 py-5">
                        <?= Csrf::field() ?>
                        <h3 class="text-sm font-semibold text-fg">Change password</h3>
                        <?= Ui::field('password', 'New password', [
                            'type' => 'password', 'generate' => true, 'required' => true,
                            'hint' => 'Mail apps signed in with the old password ask for the new one.',
                            'attrs' => ['minlength' => Passwords::MIN_LENGTH, 'autocomplete' => 'new-password', 'data-dialog-focus' => true],
                        ]) ?>
                        <button type="submit" class="btn btn-secondary">Change password</button>
                    </form>

                    <form method="post" action="/cpanel/email/<?= $id ?>/autoresponder" class="space-y-4 px-6 py-5">
                        <?= Csrf::field() ?>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-semibold text-fg">Automatic reply</h3>
                                <?= $ar ? Ui::badge('On', 'info', true) : Ui::badge('Off', 'neutral') ?>
                            </div>
                            <p class="mt-1 text-xs text-muted">Answers incoming mail while you're away, e.g. an out-of-office note. Leave the dates empty to reply until you turn it off.</p>
                        </div>
                        <?= Ui::field('subject', 'Subject', ['value' => (string) ($ar['subject'] ?? 'Out of office'), 'required' => true, 'attrs' => ['maxlength' => 200]]) ?>
                        <?= Ui::textarea('body', 'Message', (string) ($ar['body'] ?? ''), ['required' => true, 'rows' => 5, 'attrs' => ['maxlength' => 10000]]) ?>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <?= Ui::field('starts_on', 'From', ['type' => 'date', 'optional' => true, 'value' => (string) ($ar['starts_on'] ?? '')]) ?>
                            <?= Ui::field('ends_on', 'Until', ['type' => 'date', 'optional' => true, 'value' => (string) ($ar['ends_on'] ?? '')]) ?>
                        </div>
                        <?= Ui::select('interval_days', 'Reply to the same sender at most once every', [1 => '1 day', 3 => '3 days', 7 => '7 days', 14 => '14 days', 30 => '30 days'], (string) (int) ($ar['interval_days'] ?? 1)) ?>
                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <button type="submit" class="btn btn-primary"><?= $ar ? 'Save reply' : 'Turn on' ?></button>
                            <?php if ($ar): ?>
                            <button type="submit" form="mailbox-<?= $id ?>-ar-off" class="btn btn-danger-ghost">Turn off</button>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if ($ar): ?>
                    <form method="post" action="/cpanel/email/<?= $id ?>/autoresponder/delete" id="mailbox-<?= $id ?>-ar-off"><?= Csrf::field() ?></form>
                    <?php endif; ?>
                </dialog>
                <?php endforeach; ?>
            </section>

            <section class="card" aria-labelledby="forwarders-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="forwarders-title" class="card-title">Forwarders</h2>
                        <p class="card-desc">Pass mail for an address on to other addresses. A mailbox that forwards keeps its own copy.</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">Address and where it goes</th>
                                <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forwarders as $f):
                                $fid = (int) $f['id'];
                                $from = $f['local_part'] . '@' . $f['domain_name'];
                                $dests = array_values(array_filter(array_map('trim', explode(',', (string) $f['destinations'])), 'strlen')); ?>
                            <tr>
                                <td>
                                    <p class="break-all font-medium text-fg"><?= e($from) ?></p>
                                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                        <span class="text-subtle" aria-hidden="true"><?= icon('chevron-right', 'h-3.5 w-3.5') ?></span>
                                        <span class="sr-only">Forwards to</span>
                                        <?php foreach ($dests as $dest): ?>
                                        <?php if (count($dests) > 1): ?>
                                        <form method="post" action="/cpanel/email-forwarders/<?= $fid ?>/delete" class="chip gap-1 pr-0.5" data-confirm="Stop forwarding <?= e($from) ?> to <?= e($dest) ?>?" data-confirm-action="Stop forwarding">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="destination" value="<?= e($dest) ?>">
                                            <span class="break-all"><?= e($dest) ?></span>
                                            <button type="submit" class="rounded p-0.5 text-subtle hover:bg-surface-2 hover:text-danger-text" title="Stop forwarding to <?= e($dest) ?>" aria-label="Stop forwarding to <?= e($dest) ?>"><?= icon('close', 'h-3 w-3') ?></button>
                                        </form>
                                        <?php else: ?>
                                        <span class="chip break-all"><?= e($dest) ?></span>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="w-px">
                                    <div class="flex justify-end">
                                        <?= Ui::deleteForm("/cpanel/email-forwarders/$fid/delete", 'Delete the forwarder for ' . $from . '? Mail to it is no longer passed on.', 'Delete forwarder') ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$forwarders): ?>
                            <?= Ui::emptyRow(2, 'No forwarders', 'Forward an address to another inbox, e.g. sales@ to your own mailbox.', 'transfer') ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($domains): ?>
                <form method="post" action="/cpanel/email-forwarders" class="grid gap-4 rounded-b-xl border-t border-line bg-surface-2 px-5 py-4 sm:grid-cols-2">
                    <?= Csrf::field() ?>
                    <?= $addressField('Forward mail for', 'sales', $fromForwarder ? Ui::old('local_part') : '') ?>
                    <?= Ui::field('destinations', 'To', ['required' => true, 'placeholder' => 'you@example.net', 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
                    <div class="flex flex-col gap-3 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-muted">Several addresses: separate them with commas. Adding to an existing forwarder adds to its list.</p>
                        <button type="submit" class="btn btn-secondary shrink-0"><?= icon('plus') ?>Add forwarder</button>
                    </div>
                </form>
                <?php endif; ?>
            </section>

            <?php if ($domains): ?>
            <section class="card" aria-labelledby="catchall-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="catchall-title" class="card-title">Default address</h2>
                        <p class="card-desc">What happens to mail for an address that doesn't exist. Rejecting it is safest: a catch-all mainly collects spam.</p>
                    </div>
                </div>
                <ul class="divide-y divide-line">
                    <?php foreach ($domains as $d):
                        $targets = $targetsByDomain[(int) $d['id']] ?? [];
                        ksort($targets);
                        $current = $d['catch_all'] ? (string) strstr((string) $d['catch_all'], '@', true) : '';
                        $sid = Ui::id('catchall');
                        $options = ['' => 'Reject it (bounce to the sender)'];
                        foreach ($targets as $local => $kind) {
                            $options[$local] = 'Deliver to ' . $local . '@' . $d['domain_name'] . ($kind === 'forwarder' ? ' (forwarder)' : '');
                        } ?>
                    <li class="px-5 py-3">
                        <form method="post" action="/cpanel/email-default-address" class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="domain_id" value="<?= (int) $d['id'] ?>">
                            <label for="<?= e($sid) ?>" class="min-w-0 truncate text-sm font-medium text-fg sm:w-48 sm:shrink-0"><span class="sr-only">Default address for </span><?= e($d['domain_name']) ?></label>
                            <div class="flex min-w-0 flex-1 items-center gap-2">
                                <select name="target" id="<?= e($sid) ?>" class="select min-w-0 flex-1 sm:max-w-sm">
                                    <?php foreach ($options as $v => $label): ?>
                                    <option value="<?= e((string) $v) ?>"<?= $current === (string) $v ? ' selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-secondary shrink-0">Save</button>
                            </div>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
        </div>

        <div class="stack">
            <section class="card scroll-mt-20" id="add-mailbox" aria-labelledby="add-mailbox-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="add-mailbox-title" class="card-title">Create a mailbox</h2>
                        <p class="card-desc">It works in webmail and in any mail app.</p>
                    </div>
                </div>
                <?php if (!$domains): ?>
                <?= Ui::empty('Add a domain first', 'Mailboxes are created on one of your domains.', 'globe', '<a href="/cpanel/domains#add-domain" class="btn btn-secondary btn-sm">' . icon('plus') . 'Add a domain</a>') ?>
                <?php else: ?>
                <form method="post" action="/cpanel/email" class="card-body space-y-4">
                    <?= Csrf::field() ?>
                    <?php if ($mailFull): ?>
                    <?= Ui::alert('All ' . (int) $pkg['max_email_accounts'] . ' mailboxes of your package are in use. Delete one, or ask your hosting provider for more.', 'warning') ?>
                    <?php endif; ?>
                    <?= $addressField('Email address', 'you', $fromForwarder ? '' : Ui::old('local_part')) ?>
                    <?= Ui::field('password', 'Password', [
                        'type' => 'password', 'generate' => true, 'required' => true,
                        'hint' => 'At least ' . Passwords::MIN_LENGTH . ' characters.',
                        'attrs' => ['minlength' => Passwords::MIN_LENGTH, 'autocomplete' => 'new-password'],
                    ]) ?>
                    <button type="submit" class="btn btn-primary btn-block"><?= icon('plus') ?>Create mailbox</button>
                </form>
                <?php endif; ?>
            </section>

            <section class="card scroll-mt-20" id="mail-client-settings" aria-labelledby="mail-client-title">
                <div class="card-header">
                    <div class="min-w-0">
                        <h2 id="mail-client-title" class="card-title">Mail app settings</h2>
                        <p class="card-desc">Outlook, Apple Mail, Thunderbird and phones usually set themselves up from the address and password. To enter them by hand:</p>
                    </div>
                </div>
                <div class="card-body space-y-5 text-sm">
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-xs font-medium text-muted">Server, incoming and outgoing</dt>
                            <dd class="mt-1 text-fg"><?= Ui::copyable($mailHost) ?></dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-muted">Username</dt>
                            <dd class="mt-1 text-fg">The full email address, e.g. <code>you@<?= e($domains[0]['domain_name'] ?? 'example.com') ?></code></dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-muted">Password</dt>
                            <dd class="mt-1 text-fg">The mailbox's password, for incoming and outgoing mail</dd>
                        </div>
                    </dl>
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Ports</caption>
                        <thead>
                            <tr class="text-xs text-muted">
                                <th scope="col" class="pb-1.5 font-medium">Protocol</th>
                                <th scope="col" class="pb-1.5 font-medium">Port</th>
                                <th scope="col" class="pb-1.5 font-medium">Security</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line border-t border-line">
                            <tr><td class="py-2 pr-3 text-fg">IMAP <span class="text-xs text-muted">incoming, recommended</span></td><td class="py-2 pr-3 font-mono">993</td><td class="py-2 text-muted">SSL/TLS</td></tr>
                            <tr><td class="py-2 pr-3 text-fg">POP3 <span class="text-xs text-muted">incoming</span></td><td class="py-2 pr-3 font-mono">995</td><td class="py-2 text-muted">SSL/TLS</td></tr>
                            <tr><td class="py-2 pr-3 text-fg">SMTP <span class="text-xs text-muted">outgoing</span></td><td class="py-2 pr-3 font-mono">465</td><td class="py-2 text-muted">SSL/TLS</td></tr>
                            <tr><td class="py-2 pr-3 text-fg">SMTP <span class="text-xs text-muted">outgoing</span></td><td class="py-2 pr-3 font-mono">587</td><td class="py-2 text-muted">STARTTLS</td></tr>
                        </tbody>
                    </table>
                    <div>
                        <p class="text-xs font-medium text-muted">Webmail</p>
                        <?php if ($webmail): ?>
                        <ul class="mt-1 space-y-1">
                            <?php foreach (array_keys($webmail) as $host): ?>
                            <li><a href="https://<?= e($host) ?>/" target="_blank" rel="noopener" class="link inline-flex items-center gap-1 font-mono text-[0.8125rem]">https://<?= e($host) ?>/<?= icon('external', 'h-3.5 w-3.5') ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <p class="mt-1 text-fg"><code>https://mail.&lt;your domain&gt;/</code></p>
                        <p class="hint">Opens once that name points to this server.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
