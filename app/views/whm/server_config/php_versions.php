<?php
/** @var list<array{id:int, version:string, port:int, status:string, created_at:string}> $installed extra versions (php_versions rows) */
/** @var list<string> $available supported versions not installed yet */
View::slot('desc', e('Extra PHP versions for sites. Each one is its own PHP-FPM service with its own binaries and common extensions, running every account as its own Linux user. Pick a domain\'s version in cPanel › Domains.'));
if ($available) {
    View::slot('actions', '<a href="#install-version" class="btn btn-primary">' . icon('plus') . 'Install a version</a>');
}

$working = [];
foreach ($installed as $v) {
    if ($v['status'] === 'installing') {
        $working[] = 'Installing PHP ' . $v['version'] . ' - downloading and starting it, usually a minute or two.';
    } elseif ($v['status'] === 'removing') {
        $working[] = 'Removing PHP ' . $v['version'] . '.';
    }
}
$status = fn(string $s): string => match ($s) {
    'active' => Ui::status('active'),
    'installing' => Ui::status('working', 'Installing…'),
    'removing' => Ui::status('working', 'Removing…'),
    'failed' => Ui::status('failed', 'Install failed'),
    default => Ui::status($s),
};
?>
<div class="stack">
    <?php if ($working): ?>
    <?= Ui::busy(implode(' ', $working)) ?>
    <?php endif; ?>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <section class="card xl:col-span-2" aria-labelledby="versions-title">
            <div class="card-header">
                <h2 id="versions-title" class="card-title"><?= e(Ui::plural(count($installed) + 1, 'version')) ?> installed</h2>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Version</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="hidden sm:table-cell">Service</th>
                            <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-fg">PHP 8.5</span><?= Ui::badge('Default', 'accent') ?>
                                </div>
                                <p class="mt-0.5 hidden text-xs text-muted sm:block">The main install; it can't be removed.</p>
                            </td>
                            <td><?= Ui::status('active') ?></td>
                            <td class="hidden whitespace-nowrap font-mono text-xs text-muted sm:table-cell">jinnpanel-php-fpm@default</td>
                            <td class="text-right"><a href="/whm/server-config/php-extensions" class="btn btn-secondary btn-sm"><?= icon('layers') ?>Extensions</a></td>
                        </tr>
                        <?php foreach ($installed as $v): $ver = (string) $v['version']; ?>
                        <tr>
                            <td>
                                <span class="font-medium text-fg">PHP <?= e($ver) ?></span>
                                <?php if ($v['status'] === 'failed'): ?>
                                <p class="mt-0.5 text-xs text-danger-text">Remove it, then install it again.</p>
                                <?php endif; ?>
                            </td>
                            <td><?= $status((string) $v['status']) ?></td>
                            <td class="hidden whitespace-nowrap font-mono text-xs text-muted sm:table-cell">jinnpanel-php-fpm@<?= e(str_replace('.', '', $ver)) ?></td>
                            <td>
                                <div class="flex justify-end">
                                    <?php if ($v['status'] !== 'removing'): ?>
                                    <?= Ui::deleteForm(
                                        '/whm/server-config/php-versions/' . $ver . '/remove',
                                        'Remove PHP ' . $ver . '? Its PHP-FPM service is stopped and its files are deleted. Domains still on it must be moved to another version first.',
                                        'Remove PHP ' . $ver
                                    ) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card h-fit scroll-mt-20" id="install-version" aria-labelledby="install-title">
            <div class="card-header">
                <div class="min-w-0">
                    <h2 id="install-title" class="card-title">Install a version</h2>
                    <p class="card-desc">Downloads the official static-php build of PHP-FPM with its common extensions and starts it.</p>
                </div>
            </div>
            <?php if ($available): ?>
            <form method="post" action="/whm/server-config/php-versions" class="card-body space-y-4">
                <?php
                $options = [];
                foreach ($available as $v) {
                    $options[$v] = 'PHP ' . $v;
                }
                echo Ui::select('version', 'Version', $options, null, ['hint' => 'Usually ready within a minute or two; this page shows when it\'s done.']);
                ?>
                <button type="submit" class="btn btn-primary btn-block"><?= icon('download') ?>Install</button>
                <?= Csrf::field() ?>
            </form>
            <?php else: ?>
            <?= Ui::empty('Every version is installed', 'PHP ' . implode(', ', PhpVersionService::SUPPORTED) . ' are all on this server.', 'check') ?>
            <?php endif; ?>
        </section>
    </div>
</div>
