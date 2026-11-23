<?php
$nav = [
    ['label' => null, 'items' => [
        ['href' => '/whm', 'label' => 'Dashboard', 'icon' => 'grid'],
        ['href' => '/whm/analytics', 'label' => 'Analytics', 'icon' => 'chart'],
    ]],
    ['label' => 'Accounts', 'items' => [
        ['href' => '/whm/accounts', 'label' => 'Accounts', 'icon' => 'users'],
        ['href' => '/whm/packages', 'label' => 'Packages', 'icon' => 'box'],
        ['href' => '/whm/migrations', 'label' => 'cPanel Migration', 'icon' => 'transfer'],
        ['href' => '/whm/activity', 'label' => 'Activity Log', 'icon' => 'activity'],
        ['href' => '/whm/api-tokens', 'label' => 'API Tokens', 'icon' => 'key'],
    ]],
];
if (Auth::isAdmin()) {
    $nav[] = ['label' => 'Server', 'items' => [
        ['href' => '/whm/alerts', 'label' => 'Alerts', 'icon' => 'bell'],
        ['href' => '/whm/backups', 'label' => 'Backups', 'icon' => 'archive'],
        ['href' => '/whm/dns', 'label' => 'DNS Zones', 'icon' => 'globe'],
        ['href' => '/whm/notifications', 'label' => 'Notifications', 'icon' => 'mail'],
    ]];
    $nav[] = ['label' => 'Configuration', 'items' => [
        ['href' => '/whm/server-config/mail', 'label' => 'Mail Settings', 'icon' => 'mail'],
        ['href' => '/whm/server-config/sftp', 'label' => 'SFTP Settings', 'icon' => 'folder-up'],
        ['href' => '/whm/server-config/php', 'label' => 'PHP Settings', 'icon' => 'settings'],
        ['href' => '/whm/server-config/php-extensions', 'label' => 'PHP Extensions', 'icon' => 'layers'],
        ['href' => '/whm/server-config/php-versions', 'label' => 'PHP Versions', 'icon' => 'code'],
        ['href' => '/whm/server-config/database', 'label' => 'Database Settings', 'icon' => 'database'],
        ['href' => '/whm/server-config/tuning', 'label' => 'Server Tweaks', 'icon' => 'sliders'],
    ]];
}
$quickActions = [
    ['href' => '/whm/accounts/create', 'label' => 'Create account', 'icon' => 'user-plus'],
    ['href' => '/whm/packages/create', 'label' => 'Create package', 'icon' => 'plus'],
    ['href' => '/whm/migrations/create', 'label' => 'Start a cPanel migration', 'icon' => 'transfer'],
];
$roleLabel = Auth::isAdmin() ? 'Administrator' : 'Reseller';
// The alerts bell (admins): the count the worker remembers every minute (AlertService::remember).
$bell = '';
if (Auth::isAdmin()) {
    $alertSum = AlertService::summary();
    $alertN = (int) ($alertSum['count'] ?? 0);
    $alertLabel = $alertN ? Ui::plural($alertN, 'alert') . ' to look at' : 'Alerts: all clear';
    $bell = '<span data-live="bell"><a href="/whm/alerts" class="btn btn-icon relative" aria-label="' . e($alertLabel) . '" title="' . e($alertLabel) . '">' . icon('bell', 'h-5 w-5')
        . ($alertN ? '<span class="absolute right-0.5 top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-semibold leading-none tabular-nums '
            . (($alertSum['level'] ?? '') === 'danger' ? 'bg-danger text-white' : 'bg-warning-soft text-warning-text ring-1 ring-warning/40') . '" aria-hidden="true">' . ($alertN > 9 ? '9+' : $alertN) . '</span>' : '')
        . '</a></span>';
}
$securityHref = '/whm/security';
include __DIR__ . '/../partials/shell.php';
