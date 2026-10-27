<?php

declare(strict_types=1);

// Router script for "php veyro serve" (PHP's built-in development server).
// Existing files inside the document root (public/) are served directly;
// every other request goes to public/index.php. Not for production use.

$root = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = rawurldecode(is_string($uriPath) ? $uriPath : '/');

if ($root !== false && $path !== '/') {
    $file = realpath($root . $path);

    // Only real files inside public/, and never PHP files other than index.php.
    if ($file !== false && is_file($file) && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && !str_ends_with(strtolower($file), '.php')) {
        return false;
    }
}

// The kernel detects the base path from SCRIPT_NAME; the app is served at the root.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . DIRECTORY_SEPARATOR . 'index.php';

require $root . DIRECTORY_SEPARATOR . 'index.php';
