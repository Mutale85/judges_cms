<?php

// Router for PHP's built-in development server only:
//   php -S 127.0.0.1:8080 -t public bin/dev-router.php
// Never use the built-in server in production (it has no TLS).

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && is_file(__DIR__ . '/../public' . $path)) {
    return false;
}
require __DIR__ . '/../public/index.php';
