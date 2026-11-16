<?php
/** @var array<string,mixed> $common the "common" section of /etc/sftpgo/sftpgo.json */
/** @var string|null $lastLog last line of the last SFTPGo settings job: "[time] OK" or "[time] FAILED: ..." */
View::slot('desc', e('Connection limits for SFTPGo, the server behind every SFTP account. ')
    . '<a href="/whm/server-config/tuning" class="link">Server Tweaks</a>' . e(' can set them from the server\'s hardware.'));

$lastAt = null;
$lastMsg = '';
if ($lastLog !== null && preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $lastLog, $m)) {
    $lastAt = strtotime($m[1]) ?: null;
    $lastMsg = (string) preg_replace('/^FAILED:\s*/', '', trim($m[2]));
}
$failed = $lastLog !== null && str_contains($lastLog, 'FAILED');
?>
<div class="stack max-w-3xl">
    <?php if ($failed): ?>
    <?= Ui::alert('<p class="font-medium">The last change to SFTPGo didn\'t apply.</p><p class="mt-1 break-words font-mono text-xs">' . e($lastMsg !== '' ? $lastMsg : $lastLog) . '</p><p class="mt-1">Check the values below and save again.</p>', 'danger', true) ?>
    <?php endif; ?>

    <form method="post" action="/whm/server-config/sftp" class="card"
          data-confirm="SFTPGo restarts to apply the new limits, which disconnects every open SFTP session." data-confirm-title="Save and restart SFTPGo?" data-confirm-action="Save and restart" data-confirm-danger>
        <?= Csrf::field() ?>
        <?= Ui::cardHeader('Connection limits', 'Apply to every SFTP account on this server.') ?>
        <div class="card-body grid grid-cols-1 gap-5 sm:grid-cols-2">
            <?= Ui::field('max_total_connections', 'Max connections in total', [
                'type' => 'number',
                'value' => (string) ($common['max_total_connections'] ?? 0),
                'hint' => 'Across all SFTP accounts at once. 0 = unlimited (the default).',
                'attrs' => ['min' => 0],
            ]) ?>
            <?= Ui::field('max_per_host_connections', 'Max connections per IP address', [
                'type' => 'number',
                'value' => (string) ($common['max_per_host_connections'] ?? 20),
                'hint' => 'From one client address at once. Default 20.',
                'attrs' => ['min' => 0],
            ]) ?>
            <?= Ui::field('idle_timeout', 'Idle timeout (minutes)', [
                'type' => 'number',
                'value' => (string) ($common['idle_timeout'] ?? 15),
                'hint' => 'Sessions with no activity for this long are closed. 0 = never. Default 15.',
                'attrs' => ['min' => 0],
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
            <button type="submit" class="btn btn-primary"><?= icon('save') ?>Save and restart SFTPGo</button>
        </div>
    </form>
</div>
