<?php
/**
 * Small helpers shared by the migration views. Kept under views/ (not
 * src/) on purpose: tailwind.config.js only scans views/**, so every class
 * name used here has to be written out literally in this directory.
 */
if (!function_exists('migration_status_badge')) {
    function migration_status_badge(string $status): string
    {
        $map = [
            'draft' => ['Not started', 'bg-slate-100 text-slate-600', 'bg-slate-400'],
            'queued' => ['Queued', 'bg-sky-50 text-sky-700', 'bg-sky-500'],
            'pending' => ['Waiting', 'bg-slate-100 text-slate-600', 'bg-slate-400'],
            'running' => ['Running', 'bg-indigo-50 text-indigo-700', 'bg-indigo-500 animate-pulse'],
            'backing_up' => ['Backing up', 'bg-indigo-50 text-indigo-700', 'bg-indigo-500 animate-pulse'],
            'transferring' => ['Transferring', 'bg-indigo-50 text-indigo-700', 'bg-indigo-500 animate-pulse'],
            'restoring' => ['Restoring', 'bg-violet-50 text-violet-700', 'bg-violet-500 animate-pulse'],
            'completed' => ['Completed', 'bg-emerald-50 text-emerald-700', 'bg-emerald-500'],
            'completed_with_errors' => ['Completed with notes', 'bg-amber-50 text-amber-700', 'bg-amber-500'],
            'failed' => ['Failed', 'bg-red-50 text-red-700', 'bg-red-500'],
            'cancelled' => ['Cancelled', 'bg-slate-100 text-slate-600', 'bg-slate-400'],
            'stalled' => ['Stalled', 'bg-red-50 text-red-700', 'bg-red-500'],
        ];
        [$label, $cls, $dot] = $map[$status] ?? [$status, 'bg-slate-100 text-slate-600', 'bg-slate-400'];
        return '<span data-badge class="inline-flex items-center gap-1.5 text-xs font-medium rounded-full px-2 py-0.5 ' . $cls . '">'
            . '<span class="h-1.5 w-1.5 rounded-full ' . $dot . '"></span>' . e($label) . '</span>';
    }

    function migration_result_class(string $status): string
    {
        return match ($status) {
            'ok' => 'text-emerald-700',
            'partial' => 'text-amber-700',
            'skipped' => 'text-slate-500',
            default => 'text-red-700',
        };
    }

    function migration_source_label(string $type): string
    {
        return ['cpanel' => 'cPanel account', 'reseller' => 'WHM reseller', 'root' => 'WHM root'][$type] ?? $type;
    }
}
