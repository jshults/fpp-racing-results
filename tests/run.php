<?php
declare(strict_types=1);

/** Offline tests. Run: php tests/run.php */
require_once dirname(__DIR__) . '/lib/Racing.php';

$fixDir = __DIR__ . '/fixtures';
$tmp    = sys_get_temp_dir() . '/racing-tests-' . getmypid();
mkdir($tmp, 0775, true);

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
}

$calls = [];
$online = true;
Racing::$http = function (string $url) use ($fixDir, &$calls, &$online): ?string {
    $calls[] = $url;
    if (!$online) {
        return null;
    }
    $name = Racing::fixtureFor($url);
    $path = $name ? "$fixDir/$name" : '';
    return is_file($path) ? (string) file_get_contents($path) : null;
};

echo "Formula 1 (Jolpica)\n";
$f1 = Racing::fetch('f1');
check('event name/round', $f1['event']['name'] === 'Sample Grand Prix' && $f1['event']['round'] === 7);
check('event is final', $f1['event']['state'] === 'post');
check('venue joined', $f1['event']['venue'] === 'Sample Circuit, Sampletown, Testland', $f1['event']['venue']);
check('4 result rows', count($f1['results']) === 4);
check('winner shows race time', $f1['results'][0]['detail'] === '1:32:10.123' && $f1['results'][0]['driver'] === 'Alex Example');
check('lapped car shows status', $f1['results'][2]['detail'] === '+1 Lap');
check('retired car shows reason', $f1['results'][3]['detail'] === 'Engine');
check('points trimmed', $f1['results'][0]['points'] === '25' && $f1['standings']['drivers'][1]['points'] === '112.5');
check('driver standings', count($f1['standings']['drivers']) === 2 && $f1['standings']['drivers'][0]['team'] === 'Sample Racing');
check('constructor standings', $f1['standings']['teams'][0]['name'] === 'Sample Racing');
check('next race', ($f1['next']['name'] ?? '') === 'Another Sample Grand Prix');

echo "ESPN (IndyCar)\n";
$ic = Racing::fetch('indycar');
check('picks last finished event, not the upcoming one', $ic['event']['name'] === 'Sample 500' && $ic['event']['state'] === 'post');
check('uses the Race session, sorted by order', array_column($ic['results'], 'driver') === ['Jordan First', 'Riley Second', 'Morgan Third'], json_encode(array_column($ic['results'], 'driver')));
check('winner flagged', $ic['results'][0]['detail'] === 'Winner' && $ic['results'][1]['detail'] === '');
check('manufacturer as team, blank when absent', $ic['results'][0]['team'] === 'Honda' && $ic['results'][2]['team'] === '');
check('venue from circuit', $ic['event']['venue'] === 'Sample Speedway');
check('next event reported', ($ic['next']['name'] ?? '') === 'Season Opener Grand Prix');

echo "ESPN (NASCAR, live)\n";
$nc = Racing::fetch('nascar-cup');
check('live event chosen', $nc['event']['state'] === 'in' && count($nc['results']) === 2);

echo "HTML rendering\n";
$html = Racing::renderHtml($nc);
check('LIVE badge shown', str_contains($html, 'LIVE'));
check('driver names are escaped', !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'));
$html = Racing::renderHtml($f1, 2);
check('top-N limits result rows', substr_count($html, 'Alex Example') >= 1 && !str_contains($html, 'Casey Placeholder'));
check('standings and next shown', str_contains($html, 'championship') && str_contains($html, 'Another Sample Grand Prix'));

echo "Text rendering\n";
$txt = Racing::renderText($f1);
check('text has race and winner', str_contains($txt, 'Sample Grand Prix') && str_contains($txt, 'Alex Example'));

echo "Cache\n";
$calls = [];
$a = Racing::get('f1', $tmp, 900);
$n1 = count($calls);
$b = Racing::get('f1', $tmp, 900);
check('first call fetches', $n1 === 4, "calls=$n1");
check('second call is served from cache', count($calls) === $n1);
Racing::get('f1', $tmp, 900, true);
check('force refetches', count($calls) === $n1 * 2);

// Age the cache, then take the source offline: expect stale data plus an error, not a crash.
$file = "$tmp/racing-cache-f1.json";
$c = json_decode((string) file_get_contents($file), true);
$c['fetchedAt'] = time() - 5000;
file_put_contents($file, json_encode($c));
$online = false;
$s = Racing::get('f1', $tmp, 900);
check('offline + old cache -> stale data with error', $s['stale'] === true && $s['error'] !== null && count($s['results']) === 4);
$e = Racing::get('indycar', $tmp, 900);
check('offline + no cache -> empty with error', $e['error'] !== null && $e['results'] === [] && $e['event'] === null);
$online = true;

// A live session shortens the refresh window to 30s even when the setting is 15 minutes.
Racing::get('nascar-cup', $tmp, 900, true);
$file = "$tmp/racing-cache-nascar-cup.json";
$c = json_decode((string) file_get_contents($file), true);
$c['fetchedAt'] = time() - 60;
file_put_contents($file, json_encode($c));
$calls = [];
Racing::get('nascar-cup', $tmp, 900);
check('live session refetches after 60s', count($calls) === 1);

echo "Config\n";
$cfg = Racing::cleanConfig(['series' => ['f1', 'nope', '../../etc/passwd', 'f1'], 'refreshSeconds' => 1, 'topN' => 999]);
check('unknown/duplicate series dropped', $cfg['series'] === ['f1'], json_encode($cfg['series']));
check('refresh and topN clamped', $cfg['refreshSeconds'] === Racing::MIN_REFRESH && $cfg['topN'] === 30);
check('defaults when empty', Racing::cleanConfig([])['series'] === Racing::DEFAULT_CONFIG['series']);
$saved = Racing::saveConfig($tmp, ['series' => ['indycar'], 'refreshSeconds' => 120, 'topN' => 5]);
check('save then load round-trips', Racing::loadConfig($tmp) === $saved && $saved['series'] === ['indycar']);
check('unknown series id rejected by fetch', (function () { try { Racing::fetch('zzz'); return false; } catch (InvalidArgumentException) { return true; } })());

// cleanup
array_map('unlink', glob("$tmp/*") ?: []);
rmdir($tmp);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
