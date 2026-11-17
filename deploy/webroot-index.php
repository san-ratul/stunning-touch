<?php

/**
 * Front controller for hosts where the document root is the project root
 * (shared hosting without a configurable vhost).
 *
 * - While .env does not exist, every visitor is sent to the installer.
 * - After installation this delegates everything to public/index.php; the
 *   root .htaccess routes requests through public/ and blocks dotfiles.
 */
if (! file_exists(__DIR__.'/.env')) {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    // Serve the installer directly when its own URL is requested (also
    // covers routers without mod_rewrite, e.g. PHP's built-in server).
    if ($path !== null && str_ends_with($path, 'install.php')) {
        require __DIR__.'/install.php';
        exit;
    }

    header('Location: install.php');
    exit;
}

require __DIR__.'/public/index.php';
