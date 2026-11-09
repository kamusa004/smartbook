<?php
/**
 * Router for PHP's built-in development server.
 *   php -S 127.0.0.1:8080 router.php
 *
 * Maps /api/* to the PHP backend and everything else to /frontend,
 * which is exactly what the Apache .htaccess in the project root does in
 * production. Never use this in production.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api')) {
    require __DIR__ . '/public/api/index.php';
    return true;
}

$root = __DIR__ . '/frontend';
if ($path === '/' ) {
    $path = '/index.html';
}

$file = realpath($root . $path);
if ($file && str_starts_with($file, realpath($root)) && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimes = [
        'html' => 'text/html; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8', 'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'webp' => 'image/webp', 'webmanifest' => 'application/manifest+json',
    ];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Service-Worker-Allowed: /');
    readfile($file);
    return true;
}

http_response_code(404);
echo 'Not found';
return true;
