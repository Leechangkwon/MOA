<?php
declare(strict_types=1);
// Test server equivalent of nginx's /tbox/assets/ location. No operational files.
if (PHP_SAPI !== 'cli-server') { exit(2); }
$public = dirname(__DIR__, 2) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
error_log('[export-test] ' . (is_string($path) ? $path : 'invalid-path'));
if (is_string($path) && str_starts_with($path, '/tbox/assets/')) {
    $relative = substr($path, strlen('/tbox/assets/'));
    $base = realpath($public . '/assets');
    $file = realpath($public . '/assets/' . $relative);
    if ($base !== false && $file !== false && str_starts_with($file, $base . '/') && is_file($file)) {
        $types = ['js' => 'text/javascript', 'mjs' => 'text/javascript', 'css' => 'text/css',
            'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
            'webmanifest' => 'application/manifest+json', 'map' => 'application/json', 'txt' => 'text/plain'];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (isset($types[$ext])) {
            header('Content-Type: ' . $types[$ext]);
            readfile($file);
            return;
        }
    }
    http_response_code(404);
    return;
}
require $public . '/index.php';
