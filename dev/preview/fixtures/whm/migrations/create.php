<?php
require_once __DIR__ . '/../../_sample_ops.php';
$old = ['type' => 'cpanel', 'hostRaw' => '', 'portRaw' => '', 'user' => '', 'authType' => 'token', 'verifyTls' => true, 'mode' => 'auto'];
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/migrations/create',
    'data' => [
        'title' => 'New migration',
        'errors' => [],
        'old' => $old,
        'canRoot' => true,
        'serverIp' => Config::SERVER_IP,
    ],
    'variants' => [
        // What the controller re-renders after a failed connect.
        'errors' => ['errors' => ['Could not connect: cURL error 28: Connection timed out after 15001 milliseconds (is port 2087 open to this server?)'],
            'old' => ['type' => 'reseller', 'hostRaw' => 'server.oldhost.com', 'portRaw' => '', 'user' => 'oldresell', 'authType' => 'token', 'verifyTls' => false, 'mode' => 'auto']],
        'reseller' => ['role' => 'reseller', 'data' => ['canRoot' => false]],
        // Preview only: the view lists the import folder itself; this fills it in.
        'files-found' => ['backupFiles' => [
            'acme2' => '/var/lib/jinnpanel/migrations/import/cpmove-acme2.tar.gz',
            'florist' => '/var/lib/jinnpanel/migrations/import/backup-11.1.2026_02-10-33_florist.tar.gz',
        ]],
    ],
];
