<?php
/** @var array<string,mixed> $zone */
/** @var list<array<string,mixed>> $records */
/** @var list<array<string,mixed>> $managed */
/** @var string|null $preview */
/** @var list<string> $types */
/** @var string|null $lastLog */
$zid = (int) $zone['id'];
$zn = (string) $zone['zone_name'];
$isServer = (int) $zone['is_server_zone'] === 1;
$canDelete = !$isServer && empty($zone['domain_id']);

// "[2026-11-13T09:10:00+00:00] OK" / "[...] FAILED: reason" (SystemWorkerService::lastLog)
$logAt = null;
$logMsg = $lastLog;
if ($lastLog !== null && preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $lastLog, $m)) {
    [$logAt, $logMsg] = [$m[1], $m[2]];
}
$lastFailed = $lastLog !== null && str_contains($lastLog, 'FAILED');

View::breadcrumbs([['DNS Zones', '/whm/dns'], [$zn]]);
$kind = $isServer ? 'This server\'s own zone (hostname, panel, nameservers)'
    : (!empty($zone['domain_id']) ? 'Zone of a hosted domain, account ' . ($zone['owner_username'] ?? '?') : 'Standalone zone, not tied to a hosting account');
View::slot('desc', e($kind . '. Serial ' . (int) $zone['serial'] . '.'));
View::slot('actions',
    '<form method="post" action="/whm/dns/' . $zid . '/republish">' . Csrf::field()
    . '<button type="submit" class="btn btn-secondary" title="Render the zone file again and reload it in Knot">' . icon('refresh') . 'Re-publish</button></form>'
    . ($canDelete
        ? '<form method="post" action="/whm/dns/' . $zid . '/delete" data-confirm="' . e('Delete the zone ' . $zn . ' and all its records? This server stops answering for it.') . '" data-confirm-action="Delete zone" data-confirm-danger>'
            . Csrf::field() . '<button type="submit" class="btn btn-danger-ghost">' . icon('trash') . 'Delete zone</button></form>'
        : '')
    . '<a href="#add-record" class="btn btn-primary">' . icon('plus') . 'Add record</a>');
View::script('dns');

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
$typeOptions = [];
foreach ($types as $t) {
    $typeOptions[$t] = $t === 'NS' ? 'NS (delegate a subdomain)' : $t;
}

$rows = [];
$kinds = [];
foreach ($managed as $r) {
    $rows[] = $r + ['_id' => null, '_lock' => 'Nameserver settings', '_why' => 'Generated from the nameserver settings on WHM > DNS Zones. Change them there.'];
    $kinds['ns'] = true;
}
foreach ($records as $r) {
    if (!empty($r['managed'])) {
        $rows[] = $r + ['_id' => (int) $r['id'], '_lock' => DnsService::managedLabel((string) $r['managed']), '_why' => 'Kept in sync by the panel. Add your own record with this name to replace it.'];
        $kinds['auto'] = true;
    } else {
        $rows[] = $r + ['_id' => (int) $r['id'], '_lock' => null, '_why' => null];
    }
}
?>
<div class="stack">
    <?php if ($lastLog !== null && $lastFailed): ?>
    <?= Ui::alert('<p class="font-medium">The last publish failed' . ($logAt ? ' (' . e(fmt_ago($logAt)) . ')' : '') . '.</p><p class="mt-1 break-words font-mono text-xs">' . e((string) preg_replace('/^FAILED:\s*/', '', (string) $logMsg)) . '</p><p class="mt-1">Knot keeps answering with the zone it had before. Fix the record it complains about, then re-publish.</p>', 'danger', true) ?>
    <?php endif; ?>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <section class="card min-w-0 xl:col-span-2" aria-labelledby="records-title">
            <div class="card-header">
                <div class="min-w-0">
                    <h2 id="records-title" class="card-title">Records</h2>
                    <p class="card-desc">
                        <?= e(Ui::plural(count($rows), 'record')) ?>
                        <?php if ($lastLog !== null && !$lastFailed): ?>
                        · <span title="<?= e($lastLog) ?>">published <?= e($logAt ? fmt_ago($logAt) : (string) $logMsg) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <?php if (count($rows) > 8): ?>
                <input type="search" class="input input-sm w-full sm:w-56" placeholder="Filter records…" aria-label="Filter records" data-filter="#zone-records">
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table class="table" id="zone-records">
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
                                    <?php if ($lock): ?>
                                    <span class="p-2 text-subtle" title="<?= e($r['_why']) ?>"><?= icon('lock', 'h-4 w-4') ?></span>
                                    <?php else: ?>
                                    <?= Ui::deleteForm(
                                        "/whm/dns/$zid/records/$rid/delete",
                                        'Delete the ' . $r['type'] . ' record for ' . $fqdn((string) $r['name']) . ' (' . mb_strimwidth((string) $r['content'], 0, 60, '…') . ')? It stops resolving once its TTL runs out.',
                                        'Delete record'
                                    ) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$records): ?>
                        <?= Ui::emptyRow(5, 'No records yet', 'Only the NS records from the nameserver settings are published. Add an A record for @ to point ' . $zn . ' at a server.', 'list', '<a href="#add-record" class="btn btn-secondary btn-sm">' . icon('plus') . 'Add record</a>') ?>
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
                        <?php if (isset($kinds['ns'])): ?>NS records<?= in_array('A', array_column($managed, 'type'), true) ? ' and the nameservers\' own A records' : '' ?> come from the <a href="/whm/dns#nameservers" class="link">nameserver settings</a>.<?php endif; ?>
                        <?php if (isset($kinds['auto'])): ?>Automatic records follow the mail, IPv6 and subdomain setup: add your own record with the same name to replace one.<?php endif; ?>
                    </span>
                </p>
            </div>
            <?php endif; ?>
        </section>

        <section class="card h-fit scroll-mt-20" id="add-record" aria-labelledby="add-record-title">
            <div class="card-header">
                <div class="min-w-0">
                    <h2 id="add-record-title" class="card-title">Add a record</h2>
                    <p class="card-desc">Names are relative to <span class="font-mono"><?= e($zn) ?></span>. Published within seconds.</p>
                </div>
            </div>
            <form method="post" action="/whm/dns/<?= $zid ?>/records" class="card-body space-y-4" data-dns-form>
                <?= Csrf::field() ?>
                <div class="grid grid-cols-2 gap-3">
                    <?= Ui::select('type', 'Type', $typeOptions, null, ['attrs' => ['data-dns-type' => true]]) ?>
                    <?= Ui::field('ttl', 'TTL (seconds)', ['type' => 'number', 'value' => Ui::old('ttl') ?? '3600', 'attrs' => ['min' => '60', 'max' => '604800']]) ?>
                </div>
                <?= Ui::field('name', 'Name', ['placeholder' => '@, www, panel.server', 'mono' => true, 'hint' => '@ is ' . $zn . ' itself, www is www.' . $zn . ', * matches any name.', 'attrs' => $noAuto]) ?>
                <?= Ui::field('priority', 'Priority', ['type' => 'number', 'placeholder' => '10', 'hint' => 'MX and SRV only. Lower is tried first.', 'attrs' => ['min' => '0', 'max' => '65535', 'data-dns-priority' => true]]) ?>
                <?= Ui::field('content', 'Value', ['required' => true, 'mono' => true, 'placeholder' => 'IP, hostname or text', 'hint' => 'A hostname without a dot, like "mail", means mail.' . $zn . '. SRV: "weight port target". CAA: 0 issue "letsencrypt.org".', 'attrs' => $noAuto + ['data-dns-value' => true]]) ?>
                <button type="submit" class="btn btn-primary btn-block"><?= icon('plus') ?>Add record</button>
            </form>
        </section>
    </div>

    <?php if ($preview !== null): ?>
    <details class="disclosure card">
        <summary class="px-5 py-4">
            <span class="min-w-0">
                <span class="block">Zone file</span>
                <span class="mt-0.5 block text-sm font-normal text-muted">What Knot serves for <?= e($zn) ?>: the SOA, the generated NS records and every record above.</span>
            </span>
        </summary>
        <div class="border-t border-line p-5">
            <div class="mb-2 flex justify-end"><button type="button" class="btn btn-secondary btn-sm" data-copy="<?= e($preview) ?>"><?= icon('copy') ?>Copy zone file</button></div>
            <pre class="log whitespace-pre text-fg"><?= e($preview) ?></pre>
        </div>
    </details>
    <?php endif; ?>
</div>

<script type="application/json" id="dns-type-help"><?= json_encode([
    'A' => ['placeholder' => '203.0.113.10', 'hint' => 'An IPv4 address.', 'priority' => false],
    'AAAA' => ['placeholder' => '2001:db8::1', 'hint' => 'An IPv6 address.', 'priority' => false],
    'CNAME' => ['placeholder' => 'target.example.net', 'hint' => 'The hostname this name is an alias of. Without a dot, like "shop", it means shop.' . $zn . '. Not allowed on @, and the name can\'t have other records.', 'priority' => false],
    'MX' => ['placeholder' => 'mail.' . $zn, 'hint' => 'The mail server for this name. Without a dot, like "mail", it means mail.' . $zn . '.', 'priority' => true],
    'TXT' => ['placeholder' => 'v=spf1 mx ~all', 'hint' => 'Any text: SPF, a DKIM key, a verification token. Quotes are optional.', 'priority' => false],
    'NS' => ['placeholder' => 'ns1.example.net', 'hint' => 'Delegates the subdomain in Name to other nameservers. The zone\'s own NS records come from the nameserver settings.', 'priority' => false],
    'SRV' => ['placeholder' => '5 5060 sip.' . $zn, 'hint' => 'Weight, port and target, separated by spaces. Name is like _sip._tcp.', 'priority' => true],
    'CAA' => ['placeholder' => '0 issue "letsencrypt.org"', 'hint' => 'Which certificate authority may issue certificates for this name: flags, tag and value.', 'priority' => false],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
