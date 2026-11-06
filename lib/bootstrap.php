<?php
declare(strict_types=1);

require_once __DIR__ . '/Racing.php';

/**
 * Where config and cache live.
 *  - Inside FPP: <FPP config directory>/fpp-racing-results/
 *  - Anywhere else (CLI, tests): $FPP_RACING_DIR, or ./data next to the plugin.
 */
function racing_data_dir(): string
{
    global $settings;
    $env = getenv('FPP_RACING_DIR');
    if ($env !== false && $env !== '') {
        $dir = $env;
    } elseif (is_array($settings ?? null) && !empty($settings['configDirectory'])) {
        $dir = rtrim((string) $settings['configDirectory'], '/') . '/fpp-racing-results';
    } else {
        $dir = dirname(__DIR__) . '/data';
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}
