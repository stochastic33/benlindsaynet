<?php
/**
 * Product Manager Simulator API.
 * The game engine, AI library and settings live in the private "pmsim" folder
 * next to public_html (not inside it), so visitors can never read them.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function pmsim_respond(array $body): void
{
    $status = (int)($body['status'] ?? 200);
    unset($body['status']);
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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

$privateDir = pmsim_locate();
if ($privateDir === null) {
    pmsim_respond(['ok' => false, 'status' => 500, 'message' => 'The simulator is not installed yet.']);
}
require $privateDir . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    pmsim_respond(['ok' => false, 'status' => 405, 'message' => 'Use POST.']);
}

// Only the site itself may call this API
$config = pmsim_config();
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = $config['allowed_origins'] ?? [];
if ($origin !== '' && !in_array($origin, $allowed, true)) {
    pmsim_respond(['ok' => false, 'status' => 403, 'message' => 'Not allowed.']);
}
if (($_SERVER['HTTP_X_PMSIM'] ?? '') !== '1') {
    pmsim_respond(['ok' => false, 'status' => 403, 'message' => 'Not allowed.']);
}

$raw = file_get_contents('php://input', false, null, 0, 4096) ?: '';
$req = json_decode($raw, true);
if (!is_array($req)) {
    pmsim_respond(['ok' => false, 'status' => 400, 'message' => 'Bad request.']);
}

session_name('pmsim');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/api/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$game = pmsim_game();
$ipKey = pmsim_ip_key();

switch ($req['action'] ?? '') {
    case 'start':
        session_regenerate_id(true);
        $out = $game->start((string)($req['product'] ?? ''), $ipKey);
        break;
    case 'turn':
        $out = $game->turn((string)($req['text'] ?? ''), $ipKey);
        break;
    default:
        $out = ['ok' => false, 'status' => 400, 'message' => 'Unknown action.'];
}
$out['mock'] = $game->isMock();
pmsim_respond($out);
