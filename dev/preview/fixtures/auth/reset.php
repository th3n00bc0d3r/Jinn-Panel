<?php
return [
    'layout' => 'auth', 'role' => 'guest', 'path' => '/login/reset',
    'data' => ['title' => 'Choose a new password', 'token' => str_repeat('ab', 32), 'row' => ['username' => 'acme', 'purpose' => 'reset'], 'error' => null],
    'variants' => [
        'expired' => ['token' => null, 'row' => null],
        'welcome' => ['row' => ['username' => 'acme', 'purpose' => 'welcome']],
        'weak' => ['error' => 'That password is too easy to guess - pick a less common one.'],
        'mismatch' => ['error' => 'The two passwords do not match.'],
    ],
];
