<?php
/** @var array<string,string> $current values read from /etc/php-zts/php.ini and conf.d/opcache.ini */
/** @var string|null $lastLog last line of the last PHP settings job: "[time] OK" or "[time] FAILED: ..." */
View::slot('desc', e('Server-wide settings of the default PHP (' . PHP_VERSION . '). Saving restarts the web server, so every site pauses for a second or two. ')
    . '<a href="/whm/server-config/tuning" class="link">Server Tweaks</a>' . e(' can set them from the server\'s hardware.'));

$lastAt = null;
$lastMsg = '';
if ($lastLog !== null && preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $lastLog, $m)) {
    $lastAt = strtotime($m[1]) ?: null;
    $lastMsg = (string) preg_replace('/^FAILED:\s*/', '', trim($m[2]));
}
$failed = $lastLog !== null && str_contains($lastLog, 'FAILED');

// Megabyte values: the server keeps the digits and adds "M" ("1G" would become 1M).
$mb = ['pattern' => '\s*[0-9]+\s*[Mm]?\s*', 'title' => 'A number of megabytes, like 256M', 'autocomplete' => 'off'];

$reval = ($current['opcache.validate_timestamps'] ?? '1') === '0' ? 'never' : (string) ($current['opcache.revalidate_freq'] ?? '2');
$revalOptions = ['0' => 'On every request (development)', '2' => 'Every 2 seconds (default)', '60' => 'Every minute', '300' => 'Every 5 minutes', 'never' => 'Never - only after "Clear cache" or a restart (fastest)'];
if (!isset($revalOptions[$reval])) {
    // Set by hand to something else: offer it, so saving doesn't change it.
    $revalOptions = [$reval => 'Every ' . Ui::plural((int) $reval, 'second') . ' (current)'] + $revalOptions;
}
?>
<div class="stack max-w-3xl">
    <?php if ($failed): ?>
    <?= Ui::alert('<p class="font-medium">The last change to PHP didn\'t apply.</p><p class="mt-1 break-words font-mono text-xs">' . e($lastMsg !== '' ? $lastMsg : $lastLog) . '</p><p class="mt-1">Check the values below and save again.</p>', 'danger', true) ?>
    <?php endif; ?>

    <form method="post" action="/whm/server-config/php" class="card"
          data-confirm="The web server restarts to apply the new settings: every site pauses for a second or two." data-confirm-title="Save and restart PHP?" data-confirm-action="Save and restart" data-confirm-danger>
        <?= Csrf::field() ?>

        <?= Ui::cardHeader('Request limits', 'What one PHP request may use.') ?>
        <div class="card-body grid grid-cols-1 gap-5 sm:grid-cols-2">
            <?= Ui::field('memory_limit', 'Memory limit (MB)', [
                'value' => (string) ($current['memory_limit'] ?? '128M'),
                'hint' => 'Per request. Default 128M. Sites can change it in cPanel › Domains › PHP settings.',
                'class' => 'font-mono',
                'attrs' => $mb,
            ]) ?>
            <?= Ui::field('max_execution_time', 'Max execution time (seconds)', [
                'type' => 'number',
                'value' => (string) ($current['max_execution_time'] ?? '30'),
                'hint' => '0 = no limit. Default 30. Sites can change it too.',
                'attrs' => ['min' => 0],
            ]) ?>
            <?= Ui::field('upload_max_filesize', 'Max upload size (MB)', [
                'value' => (string) ($current['upload_max_filesize'] ?? '2M'),
                'hint' => 'Largest single uploaded file. Default 2M. The same for every site.',
                'class' => 'font-mono',
                'attrs' => $mb,
            ]) ?>
            <?= Ui::field('post_max_size', 'Max POST size (MB)', [
                'value' => (string) ($current['post_max_size'] ?? '8M'),
                'hint' => 'A whole form submission, uploads included: keep it at least the upload size. Default 8M.',
                'class' => 'font-mono',
                'attrs' => $mb,
            ]) ?>
        </div>

        <div class="card-header border-t">
            <div class="min-w-0">
                <h2 class="card-title">OPcache</h2>
                <p class="card-desc">Keeps compiled PHP in memory, so scripts aren't compiled again on every request.</p>
            </div>
        </div>
        <div class="card-body space-y-5">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <?= Ui::field('opcache_memory', 'Memory (MB)', [
                    'type' => 'number',
                    'value' => (string) ($current['opcache.memory_consumption'] ?? '128'),
                    'hint' => 'Shared by all sites. Default 128.',
                    'attrs' => ['min' => 8],
                ]) ?>
                <?= Ui::field('opcache_max_files', 'Max cached files', [
                    'type' => 'number',
                    'value' => (string) ($current['opcache.max_accelerated_files'] ?? '10000'),
                    'hint' => 'Raise it for many sites or big apps. Default 10000.',
                    'attrs' => ['min' => 200],
                ]) ?>
            </div>
            <?= Ui::select('opcache_revalidate', 'Check for changed PHP files', $revalOptions, $reval, [
                'hint' => 'How soon uploaded PHP changes take effect. Customers can clear their site\'s cache in cPanel › Cache.',
            ]) ?>
            <?= Ui::checkbox('jit_enabled', 'Enable JIT', ($current['opcache.jit'] ?? 'off') !== 'off', [
                'hint' => 'Tracing mode with a 64 MB buffer. Mostly helps CPU-heavy code; typical sites see little difference.',
            ]) ?>
        </div>

        <div class="card-footer justify-between">
            <p class="flex flex-wrap items-center gap-2 text-xs text-muted">
                <?php if ($lastLog === null): ?>
                No changes applied from the panel yet.
                <?php else: ?>
                <?= $failed ? Ui::status('failed', 'Last change failed') : Ui::status('ok', 'Applied') ?>
                <?php if ($lastAt !== null): ?><span title="<?= e(date('Y-m-d H:i', $lastAt)) ?>"><?= e(fmt_ago($lastAt)) ?></span><?php endif; ?>
                <?php endif; ?>
            </p>
            <button type="submit" class="btn btn-primary"><?= icon('save') ?>Save and restart PHP</button>
        </div>
    </form>
</div>
