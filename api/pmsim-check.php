<?php
/**
 * Installation check for the Product Manager Simulator.
 * Shows only yes/no facts about the setup; it never shows the API key.
 * You can delete this file once everything works.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$private = dirname(__DIR__, 2) . '/pmsim';
$checks = [
    'php_version'          => PHP_VERSION,
    'php_ok'               => version_compare(PHP_VERSION, '8.1.0', '>='),
    'curl_extension'       => extension_loaded('curl'),
    'json_extension'       => extension_loaded('json'),
    'mbstring_extension'   => extension_loaded('mbstring'),
    'private_folder_found' => is_dir($private),
    'library_found'        => is_file($private . '/vendor/autoload.php'),
    'config_found'         => is_file($private . '/config.php'),
];
$dataDir = $private . '/data';
if (is_dir($private)) {
    if (!is_dir($dataDir)) @mkdir($dataDir, 0750, true);
    $checks['data_folder_writable'] = is_dir($dataDir) && is_writable($dataDir);
}
if ($checks['config_found']) {
    $cfg = require $private . '/config.php';
    $checks['api_key_set'] = is_array($cfg) && trim((string)($cfg['anthropic_api_key'] ?? '')) !== '';
    $checks['model'] = is_array($cfg) ? ($cfg['model'] ?? null) : null;
    $checks['daily_budget_usd'] = is_array($cfg) ? ($cfg['daily_budget_usd'] ?? null) : null;
}
if ($checks['library_found'] && $checks['php_ok']) {
    try {
        require $private . '/bootstrap.php';
        $checks['engine_loads'] = class_exists('PmSim\\Game');
        $checks['mode'] = pmsim_game()->isMock() ? 'mock' : 'live';
        $u = pmsim_game()->todayUsage();
        $checks['today_calls'] = $u['calls'] ?? 0;
        $checks['today_cost_usd'] = round((float)($u['cost'] ?? 0), 4);
        $checks['knowledge_videos'] = count(pmsim_knowledge()->videos());
        $checks['knowledge_cards'] = count(pmsim_knowledge()->cards());
        $checks['admin_page_enabled'] = strlen((string)(pmsim_config()['admin_password'] ?? '')) >= 10;
    } catch (Throwable $e) {
        $checks['engine_loads'] = false;
        $checks['engine_error'] = get_class($e);
    }
}
echo json_encode($checks, JSON_PRETTY_PRINT);
