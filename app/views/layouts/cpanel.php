<?php
$nav = [
    ['label' => null, 'items' => [
        ['href' => '/cpanel', 'label' => 'Dashboard', 'icon' => 'grid'],
    ]],
    ['label' => 'Websites', 'items' => [
        ['href' => '/cpanel/domains', 'label' => 'Domains', 'icon' => 'globe'],
        ['href' => '/cpanel/files', 'label' => 'File Manager', 'icon' => 'folder'],
        ['href' => '/cpanel/dns', 'label' => 'DNS Zones', 'icon' => 'server'],
        ['href' => '/cpanel/cache', 'label' => 'Cache', 'icon' => 'bolt'],
        ['href' => '/cpanel/cron', 'label' => 'Cron Jobs', 'icon' => 'clock'],
    ]],
    ['label' => 'Email & data', 'items' => [
        ['href' => '/cpanel/email', 'label' => 'Email Accounts', 'icon' => 'mail'],
        ['href' => '/cpanel/databases', 'label' => 'MySQL Databases', 'icon' => 'database'],
        ['href' => '/cpanel/ftp', 'label' => 'FTP / SFTP', 'icon' => 'folder-up'],
        ['href' => '/cpanel/backups', 'label' => 'Backups', 'icon' => 'archive'],
    ]],
];
// Tools the account's package doesn't include aren't listed (Features).
$can = Features::current();
$gated = ['/cpanel/files' => 'file_manager', '/cpanel/cron' => 'cron', '/cpanel/backups' => 'backups'];
foreach ($nav as &$group) {
    $group['items'] = array_values(array_filter($group['items'], fn($i) => !isset($gated[$i['href']]) || $can[$gated[$i['href']]]));
}
unset($group);
$quickActions = [
    ['href' => '/cpanel/domains#add-domain', 'label' => 'Add a domain', 'icon' => 'plus'],
    ['href' => '/cpanel/email#add-mailbox', 'label' => 'Create a mailbox', 'icon' => 'mail'],
    ['href' => '/cpanel/databases#add-database', 'label' => 'Create a database', 'icon' => 'database'],
];
$roleLabel = 'Hosting account';
$securityHref = '/cpanel/security';
include __DIR__ . '/../partials/shell.php';
