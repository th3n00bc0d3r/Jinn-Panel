<?php
return [
    'layout' => 'auth', 'role' => 'guest', 'path' => '/login',
    'data' => ['error' => null],
    'variants' => [
        'failed' => ['error' => 'Invalid username/email or password.'],
        'throttled' => ['error' => 'Too many failed sign-ins. Try again in 12 minutes.'],
        'password-changed' => ['data' => [], 'flash' => [['success', 'Your password is changed. Sign in with it now.']]],
    ],
];
