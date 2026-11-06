#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Command-line front end. Works with or without FPP.
 *
 *   php bin/racing.php                       print every configured series
 *   php bin/racing.php --series=f1,indycar   print specific series
 *   php bin/racing.php --format=json         machine-readable output
 *   php bin/racing.php --refresh             fetch and cache only (for cron), print nothing on success
 *   php bin/racing.php --fixtures=DIR        read sample JSON from DIR instead of the network
 *   php bin/racing.php --list                list known series ids
 */
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$opt = getopt('', ['series::', 'format::', 'top::', 'refresh', 'fixtures::', 'list', 'help']);

if (isset($opt['help'])) {
    fwrite(STDOUT, <<<TXT
Usage: php bin/racing.php [options]
  --series=f1,indycar   series ids to show (default: your saved settings)
  --format=json         JSON instead of text
  --top=N               rows per table
  --refresh             fetch and cache only (for cron)
  --fixtures=DIR        read sample JSON from DIR instead of the network
  --list                list known series ids

TXT);
    exit(0);
}
if (isset($opt['list'])) {
    foreach (Racing::SERIES as $id => $def) {
        fwrite(STDOUT, str_pad($id, 16) . $def['name'] . "\n");
    }
    exit(0);
}

if (!empty($opt['fixtures'])) {
    $fixDir = rtrim((string) $opt['fixtures'], '/');
    Racing::$http = function (string $url) use ($fixDir): ?string {
        $name = Racing::fixtureFor($url);
        $path = $name ? "$fixDir/$name" : '';
        return is_file($path) ? (string) file_get_contents($path) : null;
    };
}

$dir = racing_data_dir();
$cfg = Racing::loadConfig($dir);
$ids = isset($opt['series']) && $opt['series'] !== false
    ? array_filter(array_map('trim', explode(',', (string) $opt['series'])), 'strlen')
    : $cfg['series'];
$top = isset($opt['top']) ? max(1, (int) $opt['top']) : $cfg['topN'];

$unknown = array_diff($ids, array_keys(Racing::SERIES));
if ($unknown) {
    fwrite(STDERR, 'Unknown series: ' . implode(', ', $unknown) . " (try --list)\n");
    exit(2);
}

$failed = 0;
$all    = [];
foreach ($ids as $id) {
    $data = Racing::get($id, $dir, $cfg['refreshSeconds'], isset($opt['refresh']) || !empty($opt['fixtures']));
    if (!empty($data['error'])) {
        $failed++;
        fwrite(STDERR, "$id: {$data['error']}\n");
    }
    $all[$id] = $data;
}

if (!isset($opt['refresh'])) {
    if (($opt['format'] ?? 'text') === 'json') {
        fwrite(STDOUT, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    } else {
        fwrite(STDOUT, implode("\n", array_map(fn($d) => Racing::renderText($d, $top), $all)));
    }
}
exit($failed ? 1 : 0);
