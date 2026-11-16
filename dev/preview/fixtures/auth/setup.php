<?php
return [
    'layout' => 'auth', 'role' => 'guest', 'path' => '/setup',
    'data' => ['server' => ['services' => ['MariaDB' => true, 'FrankenPHP' => true, 'Stalwart' => true, 'SFTPGo' => true, 'Knot DNS' => true], 'hostname' => 'server.example.com', 'ip' => '203.0.113.10'], 'errors' => [], 'old' => []],
    'variants' => ['problems' => ['server' => ['services' => ['MariaDB' => true, 'FrankenPHP' => true, 'Stalwart' => false, 'SFTPGo' => true, 'Knot DNS' => false], 'hostname' => 'server.example.com', 'ip' => '203.0.113.10'], 'errors' => ['The setup token is not right.', 'The passwords do not match.'], 'old' => ['username' => 'admin', 'email' => 'admin@example.com', 'full_name' => '']]],
];
