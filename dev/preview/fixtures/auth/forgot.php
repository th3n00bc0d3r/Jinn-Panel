<?php
return ['layout' => 'auth', 'role' => 'guest', 'path' => '/login/forgot', 'data' => ['title' => 'Reset your password', 'sent' => false, 'error' => null], 'variants' => ['sent' => ['sent' => true], 'throttled' => ['error' => 'Too many attempts. Try again in 12 minutes.']]];
