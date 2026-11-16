<?php
/**
 * Small helpers shared by the migration views. Kept under views/ (not
 * src/) on purpose: tailwind.config.js scans views/**, so every class name
 * used here is written out literally in this directory.
 */
if (!function_exists('migration_status_badge')) {
    /** Badge for a migration or item status (also handed to migration-show.js). */
    function migration_status_badge(string $status): string
    {
        [$label, $tone] = [
            'draft' => ['Not started', 'neutral'],
            'queued' => ['Queued', 'neutral'],
            'pending' => ['Waiting', 'neutral'],
            'running' => ['Running', 'info'],
            'backing_up' => ['Backing up', 'info'],
            'transferring' => ['Transferring', 'info'],
            'restoring' => ['Restoring', 'info'],
            'completed' => ['Completed', 'success'],
            'completed_with_errors' => ['Completed with notes', 'warning'],
            'failed' => ['Failed', 'danger'],
            'cancelled' => ['Cancelled', 'neutral'],
            'stalled' => ['Stalled', 'danger'],
        ][$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'neutral'];
        return Ui::badge($label, $tone, true);
    }

    /** Text colour for a report row status (ok, partial, skipped, failed). */
    function migration_result_class(string $status): string
    {
        return match ($status) {
            'ok' => 'text-success-text',
            'partial' => 'text-warning-text',
            'skipped' => 'text-subtle',
            default => 'text-danger-text',
        };
    }

    /** Icon + screen-reader word for a report row status. */
    function migration_result_mark(string $status): string
    {
        [$ico, $word] = match ($status) {
            'ok' => ['check', 'OK'],
            'partial' => ['alert', 'Partly'],
            'skipped' => ['info', 'Skipped'],
            default => ['x-circle', 'Failed'],
        };
        return '<span class="' . migration_result_class($status) . '" title="' . e($word) . '">' . icon($ico, 'h-4 w-4') . '<span class="sr-only">' . e($word) . ': </span></span>';
    }

    function migration_source_label(string $type): string
    {
        return ['cpanel' => 'cPanel account', 'reseller' => 'WHM reseller', 'root' => 'WHM root'][$type] ?? $type;
    }

    /** "acme@old.example.com" or "Backup files" for a file import. */
    function migration_source_name(array $m): string
    {
        return $m['transfer_mode'] === 'file' ? 'Backup files' : $m['source_user'] . '@' . $m['source_host'];
    }

    /** "WHM reseller · pull transfer" / "From backup files on this server". */
    function migration_source_meta(array $m): string
    {
        if ($m['transfer_mode'] === 'file') {
            return 'Restored from archives on this server';
        }
        return migration_source_label((string) $m['source_type']) . ' · ' . ($m['transfer_mode'] === 'push' ? 'push' : 'pull') . ' transfer';
    }

    /** The wizard's step indicator: 1 Source · 2 Choose accounts · 3 Migrate. */
    function migration_steps(int $current): string
    {
        $steps = [1 => 'Source', 2 => 'Choose accounts', 3 => 'Migrate'];
        $html = '<nav aria-label="Migration steps" class="mb-6"><ol class="flex items-center gap-2 text-sm sm:gap-3">';
        foreach ($steps as $n => $label) {
            $done = $n < $current;
            $now = $n === $current;
            if ($n > 1) {
                $html .= '<li aria-hidden="true" class="h-px min-w-3 flex-1 sm:max-w-16 ' . ($done || $now ? 'bg-accent' : 'bg-line-strong') . '"></li>';
            }
            $circle = $done
                ? '<span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent text-accent-fg">' . icon('check', 'h-3.5 w-3.5') . '</span>'
                : '<span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold tabular-nums '
                    . ($now ? 'bg-accent text-accent-fg' : 'border border-line-strong bg-surface text-muted') . '">' . $n . '</span>';
            // Only the current step's name shows on a phone; the others stay readable for screen readers.
            $text = '<span class="whitespace-nowrap ' . ($now ? 'font-medium text-fg' : 'sr-only text-muted sm:not-sr-only') . '">' . e($label)
                . ($done ? '<span class="sr-only"> (done)</span>' : '') . '</span>';
            $html .= '<li class="flex items-center gap-2"' . ($now ? ' aria-current="step"' : '') . '>' . $circle . $text . '</li>';
        }
        return $html . '</ol></nav>';
    }
}
