<?php
/**
 * Loads the SDK, settings and game engine. Included by public_html/api/pm-sim.php.
 */
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/game.php';

function pmsim_config(): array
{
    $file = __DIR__ . '/config.php';
    $config = is_file($file) ? require $file : require __DIR__ . '/config.sample.php';
    return is_array($config) ? $config : [];
}

function pmsim_game(): \PmSim\Game
{
    return new \PmSim\Game(pmsim_config(), __DIR__ . '/data');
}

/** A salted hash of the visitor's IP: used only for rate limits, never stored in plain form. */
function pmsim_ip_key(): string
{
    $saltFile = __DIR__ . '/data/salt';
    if (!is_file($saltFile)) {
        @mkdir(__DIR__ . '/data', 0750, true);
        @file_put_contents($saltFile, bin2hex(random_bytes(16)));
    }
    $salt = (string)@file_get_contents($saltFile);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr(hash('sha256', $salt . '|' . $ip), 0, 32);
}
