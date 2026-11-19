<?php
/**
 * Private admin page for the Product Manager Simulator's video library.
 * The page itself lives in the private "pmsim" folder next to public_html.
 * It stays switched off until an admin password is set in pmsim/config.php.
 */
$private = dirname(__DIR__, 2) . '/pmsim/admin.php';
if (!is_file($private)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}
require $private;
