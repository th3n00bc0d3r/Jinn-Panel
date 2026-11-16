<?php
require_once __DIR__ . '/../_sample.php';
return [
    'layout' => 'cpanel', 'role' => 'user', 'path' => '/cpanel/files',
    'data' => ['title' => 'File Manager', 'domains' => sample_domains(), 'domain' => sample_domains()[0], 'domainId' => 1, 'currentRel' => 'public/app', 'docrootRel' => 'public', 'entries' => sample_files()],
    'variants' => [
        'empty-folder' => ['entries' => [], 'currentRel' => 'public/uploads'],
        'no-domains' => ['domains' => [], 'domain' => null, 'domainId' => 0, 'entries' => []],
    ],
];
