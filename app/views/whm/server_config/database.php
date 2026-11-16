<?php
/** @var array<string,string> $current values in the panel's MariaDB drop-in (/etc/my.cnf.d/99-hostpanel-tuning.cnf) */
/** @var string|null $lastLog last line of the last database settings job: "[time] OK" or "[time] FAILED: ..." */
View::slot('desc', e('MariaDB server settings. Saving restarts MariaDB: every site and service that uses it is cut off for a few seconds. ')
    . '<a href="/whm/server-config/tuning" class="link">Server Tweaks</a>' . e(' can set them from the server\'s hardware.'));

$lastAt = null;
$lastMsg = '';
if ($lastLog !== null && preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $lastLog, $m)) {
    $lastAt = strtotime($m[1]) ?: null;
    $lastMsg = (string) preg_replace('/^FAILED:\s*/', '', trim($m[2]));
}
$failed = $lastLog !== null && str_contains($lastLog, 'FAILED');
// A value the panel hasn't written yet shows a suggestion, not what MariaDB uses.
$unset = fn(string $k): string => $current && !isset($current[$k]) ? ' Not set yet: this is a suggestion.' : '';
?>
<div class="stack max-w-3xl">
    <?php if ($failed): ?>
    <?= Ui::alert('<p class="font-medium">The last change to MariaDB didn\'t apply.</p><p class="mt-1 break-words font-mono text-xs">' . e($lastMsg !== '' ? $lastMsg : $lastLog) . '</p><p class="mt-1">Check the values below and save again.</p>', 'danger', true) ?>
    <?php endif; ?>

    <form method="post" action="/whm/server-config/database" class="card"
          data-confirm="MariaDB restarts to apply the new settings: sites and services that use a database are cut off for a few seconds." data-confirm-title="Save and restart MariaDB?" data-confirm-action="Save and restart" data-confirm-danger>
        <?= Csrf::field() ?>
        <?= Ui::cardHeader('Memory and connections', 'Server-wide: shared by every database on this server.') ?>
        <div class="card-body space-y-5">
            <?php if (!$current): ?>
            <?= Ui::alert('The panel hasn\'t set these yet, so MariaDB runs on its own configuration. The fields show suggested starting values.', 'info') ?>
            <?php endif; ?>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <?= Ui::field('innodb_buffer_pool_size', 'InnoDB buffer pool (MB)', [
                    'value' => (string) ($current['innodb_buffer_pool_size'] ?? '256M'),
                    'hint' => 'How much table data and indexes stay in memory - the biggest single lever for database speed.' . $unset('innodb_buffer_pool_size'),
                    'class' => 'font-mono',
                    'attrs' => ['pattern' => '\s*[0-9]+\s*[Mm]?\s*', 'title' => 'A number of megabytes, like 1024M', 'autocomplete' => 'off'],
                ]) ?>
                <?= Ui::field('max_connections', 'Max connections', [
                    'type' => 'number',
                    'value' => (string) ($current['max_connections'] ?? '100'),
                    'hint' => 'Open connections at once, from all sites together.' . $unset('max_connections'),
                    'attrs' => ['min' => 1],
                ]) ?>
                <?= Ui::field('thread_cache_size', 'Thread cache', [
                    'type' => 'number',
                    'value' => (string) ($current['thread_cache_size'] ?? '8'),
                    'hint' => 'Threads kept for reuse, so new connections start faster.' . $unset('thread_cache_size'),
                    'attrs' => ['min' => 0],
                ]) ?>
                <?= Ui::field('table_open_cache', 'Table open cache', [
                    'type' => 'number',
                    'value' => (string) ($current['table_open_cache'] ?? '400'),
                    'hint' => 'Tables kept open. Raise it when there are many databases.' . $unset('table_open_cache'),
                    'attrs' => ['min' => 1],
                ]) ?>
            </div>
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
            <button type="submit" class="btn btn-primary"><?= icon('save') ?>Save and restart MariaDB</button>
        </div>
    </form>
</div>
