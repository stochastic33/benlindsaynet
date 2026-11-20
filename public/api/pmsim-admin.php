<?php
/**
 * Private admin page for the Product Manager Simulator's video library.
 * The page itself lives in the private "pmsim" folder next to public_html.
 * It stays switched off until an admin password is set in pmsim/config.php.
 */
/** Find the private "pmsim" folder: next to public_html, or directly in the home folder. */
function pmsim_locate(): ?string
{
    $home = getenv('HOME') ?: '';
    if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $home = (string)(posix_getpwuid(posix_geteuid())['dir'] ?? '');
    }
    $candidates = [dirname(__DIR__, 2) . '/pmsim', dirname(__DIR__, 3) . '/pmsim', dirname(__DIR__, 4) . '/pmsim'];
    if ($home !== '') array_unshift($candidates, rtrim($home, '/') . '/pmsim');
    foreach ($candidates as $dir) {
        if (is_file($dir . '/bootstrap.php')) return $dir;
    }
    return null;
}

$dir = pmsim_locate();
$private = $dir !== null ? $dir . '/admin.php' : '';
if ($private === '' || !is_file($private)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}
require $private;
