<?php
require_once __DIR__ . '/../_sample.php';
$php = "<?php\n// Front controller\ndeclare(strict_types=1);\n\nrequire __DIR__ . '/../vendor/autoload.php';\n\n\$app = require_once __DIR__ . '/../bootstrap/app.php';\n\n\$app->handleRequest(Illuminate\\Http\\Request::capture());\n";
return [
    'layout' => 'cpanel', 'role' => 'user', 'path' => '/cpanel/files/edit',
    'data' => ['title' => 'index.php', 'domain' => sample_domains()[0], 'dir' => 'public', 'name' => 'index.php', 'content' => $php, 'size' => strlen($php), 'modified' => time() - 600, 'mode' => '644', 'eol' => 'lf', 'error' => null],
    'variants' => [
        'new-file' => ['title' => 'New file', 'name' => '', 'content' => '', 'size' => 0, 'modified' => null, 'mode' => '644'],
        'conflict' => ['error' => 'index.php was changed by something else after you opened it. Copy your text, reopen the file and apply your change again.'],
    ],
];
