<?php
require_once __DIR__ . '/../_sample.php';
return [
    'layout' => 'whm',
    'role' => 'admin',
    'path' => '/whm/packages',
    'data' => ['title' => 'Packages', 'packages' => sample_packages()],
    'variants' => ['empty' => ['packages' => []]],
];
