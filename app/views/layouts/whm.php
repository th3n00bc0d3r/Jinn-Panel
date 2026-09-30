<?php
$navItems = [
    ['href' => '/whm', 'label' => 'Dashboard', 'icon' => 'grid'],
    ['href' => '/whm/accounts', 'label' => 'Accounts', 'icon' => 'users'],
    ['href' => '/whm/packages', 'label' => 'Packages', 'icon' => 'box'],
    ['href' => '/whm/migrations', 'label' => 'cPanel Migration', 'icon' => 'transfer'],
];
if (Auth::isAdmin()) {
    $navItems[] = ['section' => 'Server Config'];
    $navItems[] = ['href' => '/whm/server-config/mail', 'label' => 'Mail Settings', 'icon' => 'mail'];
    $navItems[] = ['href' => '/whm/server-config/sftp', 'label' => 'SFTP Settings', 'icon' => 'folder-up'];
    $navItems[] = ['href' => '/whm/server-config/php', 'label' => 'PHP Settings', 'icon' => 'settings'];
    $navItems[] = ['href' => '/whm/server-config/php-versions', 'label' => 'PHP Versions', 'icon' => 'box'];
    $navItems[] = ['href' => '/whm/server-config/database', 'label' => 'Database Settings', 'icon' => 'database'];
    $navItems[] = ['href' => '/whm/server-config/tuning', 'label' => 'Server Tweaks', 'icon' => 'sliders'];
}
$badge = Auth::isAdmin() ? 'WHM · Admin' : 'WHM · Reseller';
$accent = 'indigo';
include __DIR__ . '/../partials/shell.php';
