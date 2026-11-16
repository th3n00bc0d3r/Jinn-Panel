<?php
/** @var list<array<string,mixed>> $domains */
/** @var array<string,mixed>|null $current */
/** @var array<string,mixed>|null $zone */
/** @var list<array<string,mixed>> $records each with 'locked' */
/** @var list<array<string,mixed>> $nsRecords */
/** @var list<string> $types */
/** @var string|null $preview */
/** @var bool $canEdit the package includes the DNS editor */
$canEdit ??= true;
$did = $current ? (int) $current['id'] : 0;
$zn = $zone ? (string) $zone['zone_name'] : (string) ($current['domain_name'] ?? '');
View::slot('desc', e('The DNS records of your domains, answered by this server\'s nameservers. Changes go live within seconds; the rest of the internet sees them within each record\'s TTL.'));
if ($zone && $canEdit) {
    View::slot('actions', '<a href="#add-record" class="btn btn-primary">' . icon('plus') . 'Add record</a>');
    View::script('dns');
}

// NS goes last: here it's only for handing a subdomain to other nameservers.
$typeOptions = [];
foreach ($types as $t) {
    if ($t !== 'NS') {
        $typeOptions[$t] = $t;
    }
}
if (in_array('NS', $types, true)) {
    $typeOptions['NS'] = 'NS (delegate a subdomain)';
}
$ttlText = fn(int $s): string => match (true) {
    $s >= 86400 && $s % 86400 === 0 => intdiv($s, 86400) . ' d',
    $s >= 3600 && $s % 3600 === 0 => intdiv($s, 3600) . ' h',
    $s >= 60 && $s % 60 === 0 => intdiv($s, 60) . ' min',
    default => $s . ' s',
};
$fqdn = fn(string $name): string => $name === '@' ? $zn : $name . '.' . $zn;
// Long values (DKIM keys, verification tokens) are cut off with a copy button.
$valueHtml = function (array $r): string {
    $content = (string) $r['content'];
    $prio = ($r['priority'] ?? null) !== null && $r['priority'] !== '' ? '<span class="text-subtle">' . (int) $r['priority'] . '</span> ' : '';
    if (mb_strlen($content) <= 60) {
        return '<span class="mono break-all">' . $prio . e($content) . '</span>';
    }
    return '<span class="flex min-w-0 items-center gap-1"><span class="mono min-w-0 truncate" title="' . e($content) . '">' . $prio . e($content) . '</span>'
        . Ui::copy($content, 'Copy value') . '</span>';
};
// Why a record can't be edited, under its value.
$lockNote = fn(array $r): string => $r['_lock']
    ? '<p class="mt-1 flex items-center gap-1 text-xs text-subtle" title="' . e((string) $r['_why']) . '">' . icon('lock', 'h-3 w-3 shrink-0') . e((string) $r['_lock']) . '</p>'
    : '';
$noAuto = ['autocapitalize' => 'off', 'autocomplete' => 'off', 'spellcheck' => 'false'];
$valueHint = 'A hostname without a dot, like "mail", means mail.' . $zn . '. MX and SRV also need a priority.';

// One list for the table: the nameserver records first, then the zone's own.
$rows = [];
$kinds = [];
foreach ($nsRecords as $r) {
    $rows[] = $r + ['_id' => null, '_lock' => 'Nameserver', '_why' => 'Points the domain at this server\'s nameservers. Set by your hosting provider.'];
    $kinds['ns'] = true;
}
foreach ($records as $r) {
    if (!empty($r['managed'])) {
        [$lock, $why] = [DnsService::managedLabel((string) $r['managed']), 'Kept up to date by the panel. Add your own record with this name to replace it.'];
        $kinds['auto'] = true;
    } elseif (!empty($r['locked'])) {
        [$lock, $why] = ['Server record', 'Keeps this server reachable (its hostname, panel or nameservers), so only the administrator can change it.'];
        $kinds['server'] = true;
    } else {
        [$lock, $why] = [null, null];
    }
    $rows[] = $r + ['_id' => (int) $r['id'], '_lock' => $lock, '_why' => $why];
}
$nameservers = [];
foreach ($nsRecords as $r) {
    if ($r['type'] === 'NS' && $r['name'] === '@') {
        $nameservers[] = rtrim((string) $r['content'], '.');
    }
}
?>
<?php if (!$domains): ?>
<div class="card"><?= Ui::empty('No domains yet', 'A DNS zone is created with each domain you add. Add a domain first, then manage its records here.', 'globe', '<a href="/cpanel/domains#add-domain" class="btn btn-primary">' . icon('plus') . 'Add a domain</a>') ?></div>
<?php else: ?>
<div class="stack">
    <section class="card" aria-label="Zone">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-3 px-5 py-4">
            <?php if (count($domains) > 1): ?>
            <form method="get" action="/cpanel/dns" class="flex min-w-0 max-w-full items-center gap-2.5">
                <label for="dns-domain" class="shrink-0 text-sm font-medium text-fg">Domain</label>
                <select id="dns-domain" name="domain" class="select min-w-0 sm:w-72" data-autosubmit>
                    <?php foreach ($domains as $d): ?>
                    <option value="<?= (int) $d['id'] ?>"<?= (int) $d['id'] === $did ? ' selected' : '' ?>><?= e($d['domain_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn btn-secondary">Show</button></noscript>
            </form>
            <?php else: ?>
            <p class="min-w-0 text-sm"><span class="font-medium text-muted">Domain</span> <span class="ml-1.5 font-semibold text-fg"><?= e($zn) ?></span></p>
            <?php endif; ?>
            <?php if (!$zone): ?>
            <?= Ui::badge('No zone', 'warning', true) ?>
            <?php elseif ((int) $current['dns_provisioned']): ?>
            <span title="This server's nameservers answer for <?= e($zn) ?>"><?= Ui::badge('Zone active', 'success', true) ?></span>
            <?php else: ?>
            <?= Ui::badge('Not provisioned', 'warning', true) ?>
            <?php endif; ?>
            <?php if ($zone): ?>
            <form method="post" action="/cpanel/dns/<?= $did ?>/reprovision" class="sm:ml-auto">
                <?= Csrf::field() ?>
                <?php if ((int) $current['dns_provisioned']): ?>
                <button type="submit" class="btn btn-secondary btn-sm" title="Publish the zone again, e.g. after a problem. Your records are kept."><?= icon('refresh') ?>Re-publish</button>
                <?php else: ?>
                <button type="submit" class="btn btn-primary btn-sm"><?= icon('bolt') ?>Provision now</button>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
        <?php if ($zone && $nameservers): ?>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-b-xl border-t border-line bg-surface-2 px-5 py-3 text-sm">
            <span class="text-muted">Nameservers to set at the domain's registrar</span>
            <?php foreach ($nameservers as $ns): ?>
            <span class="text-fg"><?= Ui::copyable($ns) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <?php if (!$zone): ?>
    <div class="card"><?= Ui::empty(
        'No DNS zone for ' . $zn . ' yet',
        'Provisioning creates the zone on this server with records pointing the domain and www here. Records you add later are kept whenever it is published again.',
        'globe',
        '<form method="post" action="/cpanel/dns/' . $did . '/reprovision">' . Csrf::field() . '<button type="submit" class="btn btn-primary">' . icon('bolt') . 'Provision now</button></form>'
    ) ?></div>
    <?php else: ?>
    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <section class="card min-w-0 xl:col-span-2" aria-labelledby="records-title">
            <div class="card-header">
                <div class="min-w-0">
                    <h2 id="records-title" class="card-title">Records</h2>
                    <p class="card-desc"><?= e(Ui::plural(count($rows), 'record')) ?> in <span class="font-mono"><?= e($zn) ?></span></p>
                </div>
                <?php if (count($rows) > 8): ?>
                <input type="search" class="input input-sm w-full sm:w-56" placeholder="Filter records…" aria-label="Filter records" data-filter="#dns-records">
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table class="table" id="dns-records">
                    <thead>
                        <tr>
                            <th scope="col" class="hidden sm:table-cell">Type</th>
                            <th scope="col"><span class="sm:hidden">Record</span><span class="hidden sm:inline">Name</span></th>
                            <th scope="col" class="hidden sm:table-cell">Value</th>
                            <th scope="col" class="hidden md:table-cell">TTL</th>
                            <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): $rid = $r['_id']; $lock = $r['_lock']; $tone = $lock ? 'text-muted' : 'text-fg'; ?>
                        <tr>
                            <td class="hidden w-px whitespace-nowrap sm:table-cell"><?= Ui::badge((string) $r['type'], $lock ? 'neutral' : 'accent') ?></td>
                            <td class="w-full max-w-0 sm:w-auto sm:max-w-none">
                                <span class="mr-1.5 sm:hidden"><?= Ui::badge((string) $r['type'], $lock ? 'neutral' : 'accent') ?></span><span class="mono break-all sm:whitespace-nowrap sm:break-normal <?= $tone ?>" title="<?= e($fqdn((string) $r['name'])) ?>"><?= e($r['name']) ?></span>
                                <div class="mt-1 text-muted sm:hidden"><?= $valueHtml($r) ?><?= $lockNote($r) ?></div>
                            </td>
                            <td class="hidden w-full max-w-0 sm:table-cell <?= $tone ?>"><?= $valueHtml($r) ?><?= $lockNote($r) ?></td>
                            <td class="hidden whitespace-nowrap text-xs text-muted md:table-cell" title="<?= (int) $r['ttl'] ?> seconds"><?= e($ttlText((int) $r['ttl'])) ?></td>
                            <td class="whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1">
                                    <?php if ($lock || !$canEdit): ?>
                                    <span class="p-2 text-subtle" title="<?= e($lock ? $r['_why'] : 'Editing DNS records isn\'t in your package') ?>"><?= icon('lock', 'h-4 w-4') ?></span>
                                    <?php else: ?>
                                    <?= Ui::iconButton('edit', 'Edit ' . $r['type'] . ' record ' . $r['name'], ['type' => 'button', 'attrs' => ['data-dns-edit' => 'dns-edit-' . $rid, 'aria-expanded' => 'false', 'aria-controls' => 'dns-edit-' . $rid]]) ?>
                                    <?= Ui::deleteForm(
                                        "/cpanel/dns/$did/records/$rid/delete",
                                        'Delete the ' . $r['type'] . ' record for ' . $fqdn((string) $r['name']) . ' (' . mb_strimwidth((string) $r['content'], 0, 60, '…') . ')? It stops resolving once its TTL runs out.',
                                        'Delete record'
                                    ) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php if (!$lock && $canEdit): ?>
                        <tr id="dns-edit-<?= $rid ?>" class="bg-surface-2" hidden data-filter-skip data-dns-edit-row>
                            <td colspan="5">
                                <form method="post" action="/cpanel/dns/<?= $did ?>/records/<?= $rid ?>" class="grid grid-cols-2 items-start gap-3 py-1 lg:grid-cols-12" data-dns-form>
                                    <?= Csrf::field() ?>
                                    <?= Ui::select('type', 'Type', $typeOptions, (string) $r['type'], ['wrap' => 'lg:col-span-2', 'attrs' => ['data-dns-type' => true]]) ?>
                                    <?= Ui::field('name', 'Name', ['value' => (string) $r['name'], 'mono' => true, 'wrap' => 'lg:col-span-3', 'attrs' => $noAuto]) ?>
                                    <?= Ui::field('priority', 'Priority', ['type' => 'number', 'value' => (string) ($r['priority'] ?? ''), 'wrap' => 'lg:col-span-1', 'attrs' => ['min' => '0', 'max' => '65535', 'data-dns-priority' => true]]) ?>
                                    <?= Ui::field('content', 'Value', ['value' => (string) $r['content'], 'required' => true, 'mono' => true, 'hint' => $valueHint, 'wrap' => 'col-span-2 lg:col-span-4', 'attrs' => $noAuto + ['data-dns-value' => true]]) ?>
                                    <?= Ui::field('ttl', 'TTL (seconds)', ['type' => 'number', 'value' => (string) (int) $r['ttl'], 'wrap' => 'lg:col-span-2', 'attrs' => ['min' => '60', 'max' => '604800']]) ?>
                                    <div class="col-span-2 flex flex-wrap items-center gap-2 lg:col-span-12">
                                        <button type="submit" class="btn btn-primary btn-sm"><?= icon('save') ?>Save record</button>
                                        <button type="button" class="btn btn-ghost btn-sm" data-dns-cancel>Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (!$records): ?>
                        <?= Ui::emptyRow(5, 'No records of your own yet', 'Add an A record for @ to point ' . $zn . ' at a server, or an MX record for its mail.', 'list', $canEdit ? '<a href="#add-record" class="btn btn-secondary btn-sm">' . icon('plus') . 'Add record</a>' : '') ?>
                        <?php elseif (count($rows) > 8): ?>
                        <tr data-filter-none data-filter-skip hidden><td colspan="5" class="py-8 text-center text-sm text-muted">No record matches that filter.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($kinds): ?>
            <div class="card-footer justify-start">
                <p class="flex items-start gap-2 text-xs text-muted">
                    <?= icon('lock', 'mt-0.5 h-3.5 w-3.5 shrink-0') ?>
                    <span>
                        Locked records are kept up to date for you.
                        <?php if (isset($kinds['ns'])): ?>The nameserver (NS) records come from your hosting provider.<?php endif; ?>
                        <?php if (isset($kinds['auto'])): ?>Automatic ones follow your mail, IPv6 and subdomain setup: add your own record with the same name to replace one.<?php endif; ?>
                        <?php if (isset($kinds['server'])): ?>Server records keep this server reachable, so only the administrator can change them.<?php endif; ?>
                    </span>
                </p>
            </div>
            <?php endif; ?>
        </section>

        <?php if (!$canEdit): ?>
        <section class="card h-fit">
            <?= Ui::empty('DNS records are read-only', 'Editing DNS isn\'t in your package. Your hosting provider can change records for you, or add the DNS editor to your package.', 'lock') ?>
        </section>
        <?php else: ?>
        <section class="card h-fit scroll-mt-20" id="add-record" aria-labelledby="add-record-title">
            <div class="card-header">
                <div class="min-w-0">
                    <h2 id="add-record-title" class="card-title">Add a record</h2>
                    <p class="card-desc">Names are relative to <span class="font-mono"><?= e($zn) ?></span>.</p>
                </div>
            </div>
            <form method="post" action="/cpanel/dns/<?= $did ?>/records" class="card-body space-y-4" data-dns-form>
                <?= Csrf::field() ?>
                <div class="grid grid-cols-2 gap-3">
                    <?= Ui::select('type', 'Type', $typeOptions, null, ['attrs' => ['data-dns-type' => true]]) ?>
                    <?= Ui::field('ttl', 'TTL (seconds)', ['type' => 'number', 'value' => Ui::old('ttl') ?? '3600', 'attrs' => ['min' => '60', 'max' => '604800']]) ?>
                </div>
                <?= Ui::field('name', 'Name', ['placeholder' => '@, www, blog', 'mono' => true, 'hint' => '@ is ' . $zn . ' itself, www is www.' . $zn . ', * matches any name.', 'attrs' => $noAuto]) ?>
                <?= Ui::field('priority', 'Priority', ['type' => 'number', 'placeholder' => '10', 'hint' => 'MX and SRV only. Lower is tried first.', 'attrs' => ['min' => '0', 'max' => '65535', 'data-dns-priority' => true]]) ?>
                <?= Ui::field('content', 'Value', ['required' => true, 'mono' => true, 'placeholder' => 'IP address, hostname or text', 'hint' => $valueHint, 'attrs' => $noAuto + ['data-dns-value' => true]]) ?>
                <button type="submit" class="btn btn-primary btn-block"><?= icon('plus') ?>Add record</button>
            </form>
        </section>
        <?php endif; ?>
    </div>

    <?php if ($preview !== null): ?>
    <details class="disclosure card">
        <summary class="px-5 py-4">
            <span class="min-w-0">
                <span class="block">Zone file</span>
                <span class="mt-0.5 block text-sm font-normal text-muted">Exactly what this server publishes for <?= e($zn) ?>, including the locked records.</span>
            </span>
        </summary>
        <div class="border-t border-line p-5">
            <div class="mb-2 flex justify-end"><button type="button" class="btn btn-secondary btn-sm" data-copy="<?= e($preview) ?>"><?= icon('copy') ?>Copy zone file</button></div>
            <pre class="log whitespace-pre text-fg"><?= e($preview) ?></pre>
        </div>
    </details>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php if ($zone): ?>

<script type="application/json" id="dns-type-help"><?= json_encode([
    'A' => ['placeholder' => '203.0.113.10', 'hint' => 'An IPv4 address, e.g. of a web server.', 'priority' => false],
    'AAAA' => ['placeholder' => '2001:db8::1', 'hint' => 'An IPv6 address.', 'priority' => false],
    'CNAME' => ['placeholder' => 'shop.example.net', 'hint' => 'The hostname this name is an alias of. Without a dot, like "shop", it means shop.' . $zn . '. Not allowed on @, and the name can\'t have other records.', 'priority' => false],
    'MX' => ['placeholder' => 'mail.' . $zn, 'hint' => 'The mail server for this name. Without a dot, like "mail", it means mail.' . $zn . '.', 'priority' => true],
    'TXT' => ['placeholder' => 'v=spf1 mx ~all', 'hint' => 'Any text: SPF, a DKIM key, a verification token. Quotes are optional.', 'priority' => false],
    'NS' => ['placeholder' => 'ns1.example.net', 'hint' => 'Hands the subdomain in Name to another nameserver. The zone\'s own NS records are set by your hosting provider.', 'priority' => false],
    'SRV' => ['placeholder' => '5 5060 sip.' . $zn, 'hint' => 'Weight, port and target, separated by spaces. Name is like _sip._tcp.', 'priority' => true],
    'CAA' => ['placeholder' => '0 issue "letsencrypt.org"', 'hint' => 'Which certificate authority may issue certificates for this name: flags, tag and value.', 'priority' => false],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<?php endif; ?>
