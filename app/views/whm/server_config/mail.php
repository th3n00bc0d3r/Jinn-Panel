<?php
/** @var array<string, list<string>> $groups group name => Stalwart settings objects (StalwartAdminService::SETTINGS_GROUPS) */

// "x:MtaInboundSession" -> "MTA inbound session".
$human = function (string $name): string {
    $acronyms = ['mta' => 'MTA', 'dns' => 'DNS', 'dnsbl' => 'DNSBL', 'http' => 'HTTP', 'jmap' => 'JMAP', 'imap' => 'IMAP', 'dmarc' => 'DMARC', 'dkim' => 'DKIM', 'tls' => 'TLS', 'dav' => 'DAV', 'api' => 'API', 'smtp' => 'SMTP'];
    $words = preg_split('/(?<=[a-z0-9])(?=[A-Z])|[_.]/', preg_replace('/^x:/', '', $name)) ?: [$name];
    $out = [];
    foreach ($words as $i => $w) {
        $l = strtolower($w);
        $out[] = $acronyms[$l] ?? ($i === 0 ? ucfirst($l) : $l);
    }
    return str_replace('Web DAV', 'WebDAV', implode(' ', $out));
};
$about = [
    'Server' => ['server', 'Threads, caches, DNS lookups and background tasks.'],
    'Web & API' => ['globe', 'HTTP, JMAP and WebDAV access, and sharing between accounts.'],
    'Mail protocols' => ['mail', 'IMAP, message limits, and SMTP in and out.'],
    'Security & spam' => ['shield', 'Sign-in, abuse protection, spam filtering and sender checks.'],
    'Storage' => ['hard-drive', 'Where mail, attachments and the search index live, and how long data is kept.'],
    'Reporting' => ['chart', 'DMARC, DKIM and TLS reports.'],
];
View::slot('desc', e('Stalwart\'s own settings, read from and saved to the mail server directly. Changes take effect immediately - no restart.'));
?>
<div class="grid-cards">
    <?php foreach ($groups as $groupName => $objects): [$ico, $blurb] = $about[$groupName] ?? ['settings', '']; $gid = 'mail-group-' . md5((string) $groupName); ?>
    <section class="card overflow-hidden" aria-labelledby="<?= e($gid) ?>">
        <div class="flex items-start gap-3 border-b border-line px-5 py-4">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent-text"><?= icon($ico, 'h-4 w-4') ?></span>
            <div class="min-w-0">
                <h2 id="<?= e($gid) ?>" class="card-title"><?= e((string) $groupName) ?></h2>
                <?php if ($blurb !== ''): ?><p class="mt-0.5 text-xs text-muted"><?= e($blurb) ?></p><?php endif; ?>
            </div>
        </div>
        <ul class="divide-y divide-line">
            <?php foreach ($objects as $obj): ?>
            <li>
                <a href="/whm/server-config/mail/<?= e($obj) ?>" class="group flex items-center justify-between gap-3 px-5 py-2.5 text-sm transition-colors hover:bg-surface-2 focus-visible:-outline-offset-2">
                    <span class="min-w-0">
                        <span class="block truncate font-medium text-fg group-hover:text-accent-text"><?= e($human($obj)) ?></span>
                        <span class="block truncate font-mono text-xs text-subtle"><?= e($obj) ?></span>
                    </span>
                    <?= icon('chevron-right', 'h-4 w-4 shrink-0 text-subtle group-hover:text-accent-text') ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>
</div>
