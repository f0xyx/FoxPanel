<?php
// PHP Development Server Router for WebPanel Pro
// This ensures that static files (css, js, images) are served directly 
// and logic files are parsed appropriately.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files as-is
if (preg_match('/\.(?:png|jpg|jpeg|gif|css|js|webp|svg|woff|woff2|ttf)$/', $path)) {
    return false;
}

// Ensure default root hits the new entry point
if ($path === '/' || $path === '') {
    include __DIR__ . '/cpanel.php';
    return;
}

// Serve any existing file (e.g. cpanel.php, panel.php, etc.)
if (file_exists(__DIR__ . $path)) {
    return false; 
}

// If all else fails, output 404
http_response_code(404);
echo "404 Not Found";
