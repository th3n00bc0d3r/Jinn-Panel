<?php
return [
    'layout' => 'cpanel', 'role' => 'user', 'path' => '/cpanel/security',
    'data' => ['title' => 'Login Security', 'me' => ['id' => 10, 'username' => 'acme', 'role' => 'user', 'totp_secret_enc' => null], 'setup' => null, 'base' => '/cpanel/security', 'codes' => null, 'codesLeft' => 0],
    'variants' => [
        'setting-up' => ['setup' => ['secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'uri' => 'otpauth://totp/JinnPanel:acme@server.example.com?secret=JBSWY3DPEHPK3PXP&issuer=JinnPanel', 'qr' => '<svg viewBox="0 0 21 21" xmlns="http://www.w3.org/2000/svg"><rect width="7" height="7" fill="#000"/><rect x="14" width="7" height="7" fill="#000"/><rect y="14" width="7" height="7" fill="#000"/><rect x="9" y="9" width="3" height="3" fill="#000"/></svg>']],
        'on-new-codes' => ['me' => ['id' => 10, 'username' => 'acme', 'role' => 'user', 'totp_secret_enc' => 'x'], 'codesLeft' => 10, 'codes' => ['k7mq-2xpd-9fhw', 'a3ns-t8rk-qe2m', 'pv6c-h4wz-n7ba', 'r2dj-y9fs-k5ut', 'mx8e-q3vn-w6hc', 'b4tz-j7pk-s2rd', 'g9wm-c5ha-e8qx', 'u6fy-n2rb-t3kp', 'z5ke-x8md-a4wv', 'h3qs-v7jc-p9ne']],
        'on-few-left' => ['me' => ['id' => 10, 'username' => 'acme', 'role' => 'user', 'totp_secret_enc' => 'x'], 'codesLeft' => 2],
    ],
];
