<?php
return [
    'layout' => 'auth', 'role' => 'guest', 'path' => '/login/2fa',
    'data' => ['error' => null, 'recovery' => false],
    'variants' => [
        'wrong-code' => ['error' => 'That code is not right (or was already used). Enter the current code from your authenticator app.'],
        'recovery' => ['recovery' => true, 'error' => 'That recovery code is not right, or was already used.'],
    ],
];
