<?php
declare(strict_types=1);

/**
 * Raw endpoint used by the plugin pages (loaded with &nopage=1 so FPP skips its page chrome).
 *
 *   ?action=results&series=f1[&format=html|json][&force=1]
 *   ?action=config                       -> current settings as JSON
 *   POST ?action=saveConfig  (JSON body) -> validates and saves settings
 */
require_once __DIR__ . '/lib/bootstrap.php';

$dir    = racing_data_dir();
$action = (string) ($_GET['action'] ?? 'results');

function racing_json(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if ($action === 'config') {
    racing_json(['config' => Racing::loadConfig($dir), 'available' => array_map(fn($s) => $s['name'], Racing::SERIES)]);
}

if ($action === 'saveConfig') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        racing_json(['error' => 'POST required'], 405);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        racing_json(['error' => 'Invalid JSON'], 400);
    }
    try {
        racing_json(['config' => Racing::saveConfig($dir, $body)]);
    } catch (Throwable $e) {
        racing_json(['error' => $e->getMessage()], 500);
    }
}

$series = (string) ($_GET['series'] ?? '');
if (!isset(Racing::SERIES[$series])) {   // whitelist: the id is also used to build a cache file name
    racing_json(['error' => 'Unknown series'], 404);
}

$cfg  = Racing::loadConfig($dir);
$data = Racing::get($series, $dir, $cfg['refreshSeconds'], !empty($_GET['force']));

if (($_GET['format'] ?? 'html') === 'json') {
    racing_json($data);
}
header('Content-Type: text/html; charset=utf-8');
echo Racing::renderHtml($data, $cfg['topN']);
