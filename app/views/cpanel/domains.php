<?php
/** @var list<array<string,mixed>> $domains each with ['ssl' => {state,label,detail}] */
/** @var list<string> $phpVersions */
$sslTone = fn(string $st) => $st === 'ok' ? 'success' : ($st === 'no_dns' ? 'danger' : 'warning');
View::slot('desc', 'Websites on this account. Open one to change its PHP version, SSL, document root, aliases and routing rules.');
View::slot('actions', '<a href="#add-domain" class="btn btn-primary">' . icon('plus') . 'Add domain</a>');
?>
<div class="stack">
    <?php include __DIR__ . '/../partials/quota_bars.php'; ?>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <section class="card xl:col-span-2" aria-labelledby="domains-title">
            <div class="card-header">
                <h2 id="domains-title" class="card-title"><?= e(Ui::plural(count($domains), 'domain')) ?></h2>
                <?php if (count($domains) > 5): ?>
                <input type="search" class="input input-sm w-56" placeholder="Filter domains…" aria-label="Filter domains" data-filter="#domains-table">
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table class="table" id="domains-table">
                    <thead>
                        <tr>
                            <th scope="col">Domain</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="hidden sm:table-cell">PHP</th>
                            <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($domains as $d): $id = (int) $d['id']; $st = (string) $d['ssl']['state']; ?>
                        <tr>
                            <td class="max-w-[22rem]">
                                <div class="flex items-center gap-1.5">
                                    <a href="/cpanel/domains/<?= $id ?>" class="truncate font-medium text-fg hover:text-accent-text"><?= e($d['domain_name']) ?></a>
                                    <a href="https://<?= e($d['domain_name']) ?>/" target="_blank" rel="noopener" class="text-subtle hover:text-accent-text" title="Open the site in a new tab" aria-label="Open <?= e($d['domain_name']) ?> in a new tab"><?= icon('external', 'h-3.5 w-3.5') ?></a>
                                </div>
                                <p class="mt-0.5 truncate font-mono text-xs text-muted"><?= e($d['docroot']) ?></p>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    <span title="<?= e($d['ssl']['detail']) ?>"><?= Ui::badge((string) $d['ssl']['label'], $sslTone($st), true) ?></span>
                                    <?= (int) $d['dns_provisioned'] ? '' : Ui::badge('DNS pending', 'warning', true) ?>
                                    <?= !empty($d['routes_review']) ? Ui::badge('Routes need review', 'warning', true) : '' ?>
                                </div>
                            </td>
                            <td class="hidden whitespace-nowrap text-muted sm:table-cell"><?= e($d['php_version'] === 'default' ? 'Default' : 'PHP ' . $d['php_version']) ?></td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    <a href="/cpanel/domains/<?= $id ?>" class="btn btn-secondary btn-sm">Manage</a>
                                    <?= Features::has('file_manager') ? Ui::iconLink('/cpanel/files?domain_id=' . $id, 'folder', 'Files of ' . $d['domain_name']) : '' ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$domains): ?>
                        <?= Ui::emptyRow(4, 'No domains yet', 'Add your first domain to create its website, DNS zone and certificate.', 'globe') ?>
                        <?php else: ?>
                        <tr data-filter-none data-filter-skip hidden><td colspan="4" class="py-8 text-center text-sm text-muted">No domain matches that filter.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card h-fit scroll-mt-20" id="add-domain" aria-labelledby="add-domain-title">
            <?= Ui::cardHeader('Add a domain', 'Creates the website, a DNS zone pointed at this server, and a certificate.', id: 'add-domain-title') ?>
            <form method="post" action="/cpanel/domains" class="card-body space-y-4">
                <?= Csrf::field() ?>
                <?= Ui::field('domain_name', 'Domain name', ['required' => true, 'placeholder' => 'example.com', 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false', 'inputmode' => 'url']]) ?>
                <?php
                $phpOptions = ['default' => 'Default (PHP 8.5)'];
                foreach (Features::has('php_versions') ? $phpVersions : [] as $v) {
                    $phpOptions[$v] = 'PHP ' . $v;
                }
                echo count($phpOptions) > 1 ? Ui::select('php_version', 'PHP version', $phpOptions, 'default') : '<input type="hidden" name="php_version" value="default" hidden>';
                echo Ui::select('ssl_mode', 'Certificate', [
                    'auto' => 'Automatic (recommended)',
                    'letsencrypt' => 'Let\'s Encrypt now',
                    'self_signed' => 'Self-signed only',
                ], 'auto', ['hint' => 'Automatic uses a self-signed certificate until the domain points here, then switches to Let\'s Encrypt by itself.']);
                ?>
                <button type="submit" class="btn btn-primary btn-block"><?= icon('plus') ?>Add domain</button>
            </form>
        </section>
    </div>
</div>
