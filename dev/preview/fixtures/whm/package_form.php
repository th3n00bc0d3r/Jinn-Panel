<?php
require_once __DIR__ . '/../_sample.php';
return [
    'layout' => 'whm', 'role' => 'admin', 'path' => '/whm/packages/create',
    'data' => ['title' => 'Create Package', 'pkg' => null, 'accounts' => 0],
    'variants' => ['edit' => ['title' => 'Edit Business', 'pkg' => sample_pkg(), 'accounts' => 6], 'edit-unlimited' => ['title' => 'Edit Unlimited', 'pkg' => sample_packages()[3], 'accounts' => 1], 'edit-starter' => ['title' => 'Edit Starter', 'pkg' => sample_packages()[1], 'accounts' => 0]],
];
