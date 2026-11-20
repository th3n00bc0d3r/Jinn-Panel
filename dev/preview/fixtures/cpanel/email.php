<?php
require_once __DIR__ . '/../_sample_mail.php';
$noMail = ['accounts' => [], 'forwarders' => [], 'autoresponders' => [], 'webmail' => []];
return [
    'layout' => 'cpanel',
    'role' => 'user',
    'path' => '/cpanel/email',
    'flash' => [['success', 'Mailbox "jane@acme.test" created.']],
    'data' => [
        'title' => 'Email Accounts',
        'accounts' => sample_mailboxes(),
        'domains' => sample_mail_domains(),
        'forwarders' => sample_forwarders(),
        'autoresponders' => sample_autoresponders(),
        'mailHosts' => ['acme.test' => 'mail.acme.test', 'shop.acme.test' => 'mail.shop.acme.test', 'acme-old.test' => 'server.example.com'],
        'webmail' => ['mail.acme.test' => true],
        'usage' => sample_usage(),
        'pkg' => sample_pkg(),
    ],
    'variants' => [
        'empty' => $noMail + ['mailHosts' => ['acme.test' => 'server.example.com', 'shop.acme.test' => 'server.example.com'], 'usage' => ['domains' => 3, 'databases' => 2, 'email_accounts' => 0, 'ftp_accounts' => 1]],
        'no-domains' => $noMail + ['domains' => [], 'mailHosts' => [], 'usage' => ['domains' => 0, 'databases' => 0, 'email_accounts' => 0, 'ftp_accounts' => 0]],
        'many' => ['accounts' => sample_mailboxes(9), 'usage' => ['domains' => 3, 'databases' => 2, 'email_accounts' => 13, 'ftp_accounts' => 1]],
        'full' => ['usage' => ['domains' => 3, 'databases' => 2, 'email_accounts' => 25, 'ftp_accounts' => 1]],
    ],
];
