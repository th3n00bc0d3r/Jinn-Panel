<?php
$navItems = [
    ['href' => '/cpanel', 'label' => 'Dashboard', 'icon' => 'grid'],
    ['href' => '/cpanel/domains', 'label' => 'Domains', 'icon' => 'globe'],
    ['href' => '/cpanel/databases', 'label' => 'MySQL Databases', 'icon' => 'database'],
    ['href' => '/cpanel/email', 'label' => 'Email Accounts', 'icon' => 'mail'],
    ['href' => '/cpanel/ftp', 'label' => 'FTP / SFTP', 'icon' => 'folder-up'],
    ['href' => '/cpanel/dns', 'label' => 'DNS Zones', 'icon' => 'server'],
    ['href' => '/cpanel/files', 'label' => 'File Manager', 'icon' => 'folder'],
    ['href' => '/cpanel/cron', 'label' => 'Cron Jobs', 'icon' => 'clock'],
];
$badge = 'cPanel';
$accent = 'sky';
include __DIR__ . '/../partials/shell.php';
