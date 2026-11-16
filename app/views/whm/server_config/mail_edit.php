<?php
/**
 * Generic form for any Stalwart singleton settings object, rendered
 * straight from its schema. Simple types get a real input; anything
 * structural (object/objectList/set/map) falls back to a JSON textarea -
 * still fully editable, just "advanced mode" for the less common fields.
 *
 * @var string $object e.g. x:SystemSettings
 * @var array<string, array<string,mixed>> $fields schema properties: name => {type:{type, enumName?, format?, nullable?}, description?, update?}
 * @var array<string,mixed> $values current values
 * @var array<string, list<array{name:string,label:string}>> $enums
 */

// "x:MtaInboundSession" -> "MTA inbound session", "dnsIpv4" -> "DNS IPv4".
$human = function (string $name): string {
    $acronyms = ['mta' => 'MTA', 'dns' => 'DNS', 'dnsbl' => 'DNSBL', 'http' => 'HTTP', 'jmap' => 'JMAP', 'imap' => 'IMAP', 'dmarc' => 'DMARC', 'dkim' => 'DKIM', 'tls' => 'TLS', 'dav' => 'DAV', 'api' => 'API', 'smtp' => 'SMTP',
        'ip' => 'IP', 'ipv4' => 'IPv4', 'ipv6' => 'IPv6', 'mx' => 'MX', 'url' => 'URL', 'id' => 'ID', 'ttl' => 'TTL', 'tcp' => 'TCP', 'spf' => 'SPF', 'arc' => 'ARC', 'lmtp' => 'LMTP', 'sql' => 'SQL', 'acme' => 'ACME', 'oidc' => 'OIDC'];
    $words = preg_split('/(?<=[a-z0-9])(?=[A-Z])|[_.]/', preg_replace('/^x:/', '', $name)) ?: [$name];
    $out = [];
    foreach ($words as $i => $w) {
        $l = strtolower($w);
        $out[] = $acronyms[$l] ?? ($i === 0 ? ucfirst($l) : $l);
    }
    return str_replace('Web DAV', 'WebDAV', implode(' ', $out));
};

$group = '';
foreach (StalwartAdminService::SETTINGS_GROUPS as $g => $objs) {
    if (in_array($object, $objs, true)) {
        $group = (string) $g;
        break;
    }
}

View::breadcrumbs([['Mail Settings', '/whm/server-config/mail'], [$object]]);
View::script('mail-settings');

// One control per field the panel may change. A value typed into a form
// that failed to save (Flash::error keeps it) wins over the stored one.
$simple = [];
$advanced = [];
foreach ($fields as $name => $def) {
    if (($def['update'] ?? '') === 'serverSet') {
        continue;
    }
    $name = (string) $name;
    $type = $def['type']['type'] ?? 'string';
    $current = $values[$name] ?? null;
    $old = Ui::old($name);
    $label = $human($name);
    $desc = trim((string) ($def['description'] ?? ''));

    if ($type === 'boolean') {
        $simple[] = Ui::select($name, $label, ['1' => 'Enabled', '0' => 'Disabled'], $old ?? ($current === true ? '1' : ($current === false ? '0' : null)), ['hint' => $desc]);
    } elseif ($type === 'enum' && isset($enums[$def['type']['enumName'] ?? ''])) {
        // "Not set" (sent empty = left untouched) so an unset field isn't saved as the first choice.
        $options = $current === null ? ['' => 'Not set'] : [];
        foreach ($enums[$def['type']['enumName']] as $opt) {
            $options[(string) $opt['name']] = (string) $opt['label'];
        }
        $simple[] = Ui::select($name, $label, $options, $old ?? ($current === null ? '' : (string) $current), ['hint' => $desc]);
    } elseif ($type === 'number') {
        $hint = $desc;
        if (($def['type']['format'] ?? '') === 'size') {
            $hint = trim($hint . ' Size in bytes' . (is_numeric($current) ? ' (now ' . fmt_bytes((int) $current) . ').' : '.'));
        }
        $simple[] = Ui::field($name, $label, [
            'type' => 'number',
            'value' => $old ?? (string) ($current ?? ''),
            'placeholder' => $current === null ? 'Not set' : null,
            'hint' => $hint,
            'class' => 'tabular-nums sm:max-w-xs',
            'attrs' => ['step' => 'any'],
        ]);
    } elseif ($type === 'string' || $type === 'enum') {
        $simple[] = Ui::field($name, $label, [
            'value' => $old ?? (string) ($current ?? ''),
            'placeholder' => $current === null ? 'Not set' : null,
            'hint' => $desc,
            'mono' => true,
            'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false'],
        ]);
    } else {
        $json = $old ?? ($current !== null ? (string) json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
        $kind = match ($type) {
            'set' => 'a list of values',
            'objectList' => 'a list of objects',
            'map' => 'key/value pairs',
            'object' => 'an object',
            default => $type,
        };
        $advanced[] = Ui::textarea($name, $label, $json, [
            'rows' => max(3, min(14, substr_count($json, "\n") + 2)),
            'mono' => true,
            'placeholder' => 'Advanced field - raw JSON',
            'hint' => trim($desc . ' JSON: ' . $kind . '.'),
            'attrs' => ['spellcheck' => 'false', 'autocapitalize' => 'off', 'data-json' => true],
        ]);
    }
}
View::slot('desc', e($human($object) . ($group !== '' ? ', in Stalwart\'s ' . $group . ' settings' : '') . '.'
    . ($simple || $advanced ? ' This form is built from the mail server\'s live schema; saving applies immediately, no restart needed.' : '')));
?>
<?php if (!$simple && !$advanced): ?>
<div class="card max-w-3xl"><?= Ui::empty('Nothing to change here', 'Stalwart sets every field of ' . $object . ' itself, so none can be edited from the panel.', 'settings', '<a href="/whm/server-config/mail" class="btn btn-secondary">' . icon('arrow-left') . 'All mail settings</a>') ?></div>
<?php else: ?>
<form method="post" action="/whm/server-config/mail/<?= e($object) ?>" class="stack max-w-3xl" data-json-fields>

    <?php if ($simple): ?>
    <section class="card">
        <?= Ui::cardHeader($human($object), 'Leaving a field empty keeps its current value, or clears it when the setting is optional.') ?>
        <div class="card-body space-y-5">
            <?= implode("\n", $simple) ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($advanced): ?>
    <section class="card">
        <?= Ui::cardHeader('Advanced', 'Lists and nested settings, edited as JSON. Leave one as it is to keep it.') ?>
        <div class="card-body space-y-5">
            <?= implode("\n", $advanced) ?>
        </div>
    </section>
    <?php endif; ?>

    <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="btn btn-primary"><?= icon('save') ?>Save settings</button>
        <a href="/whm/server-config/mail" class="btn btn-ghost">Cancel</a>
        <span class="text-xs text-muted">Applies immediately, no restart.</span>
    </div>
    <?= Csrf::field() ?>
</form>
<?php endif; ?>
