<?php
declare(strict_types=1);

/**
 * Racing results: fetch, normalise, cache and render.
 *
 * Every series is reduced to one normalised shape so the web UI and CLI never
 * care where the data came from:
 *
 *   [
 *     'id', 'name', 'source', 'fetchedAt' (epoch), 'stale' (bool), 'error' (?string),
 *     'event'     => ['name','round','date','venue','state' => pre|in|post] | null,
 *     'results'   => [['pos','driver','team','detail','points','grid'], ...],
 *     'standings' => ['drivers' => [...], 'teams' => [...]],   // F1 only for now
 *     'next'      => ['name','date','venue'] | null,
 *   ]
 *
 * To add a series, add one entry to SERIES. Providers: 'jolpica' (F1) and
 * 'espn' (any ESPN racing league slug).
 */
final class Racing
{
    public const USER_AGENT   = 'fpp-racing-results/0.1';
    public const MIN_REFRESH  = 20;   // seconds, floor for the refresh setting
    public const LIVE_REFRESH = 30;   // seconds, used while a session is live

    private const JOLPICA = 'https://api.jolpi.ca/ergast/f1';
    private const ESPN    = 'https://site.api.espn.com/apis/site/v2/sports';

    public const SERIES = [
        'f1'             => ['name' => 'Formula 1',                       'provider' => 'jolpica', 'source' => 'Jolpica F1'],
        'indycar'        => ['name' => 'IndyCar',                         'provider' => 'espn', 'path' => 'racing/irl',              'source' => 'ESPN'],
        'nascar-cup'     => ['name' => 'NASCAR Cup Series',               'provider' => 'espn', 'path' => 'racing/nascar-premier',   'source' => 'ESPN'],
        'nascar-oreilly' => ['name' => "NASCAR O'Reilly Auto Parts Series", 'provider' => 'espn', 'path' => 'racing/nascar-secondary', 'source' => 'ESPN'],
        'nascar-truck'   => ['name' => 'NASCAR Craftsman Truck Series',   'provider' => 'espn', 'path' => 'racing/nascar-truck',     'source' => 'ESPN'],
    ];

    /** @var callable|null fn(string $url): ?string. Swapped out by tests and --fixtures mode. */
    public static $http = null;

    // ------------------------------------------------------------------ HTTP

    public static function httpGet(string $url): ?string
    {
        if (self::$http !== null) {
            return (self::$http)($url);
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING       => '',            // accept gzip, like the ESPN API sends
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ($body !== false && $code >= 200 && $code < 300) ? (string) $body : null;
        }
        $ctx  = stream_context_create(['http' => [
            'timeout' => 10,
            'header'  => 'User-Agent: ' . self::USER_AGENT . "\r\nAccept: application/json\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }

    private static function jsonGet(string $url): ?array
    {
        $body = self::httpGet($url);
        if ($body === null) {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    // ----------------------------------------------------------------- fetch

    /** Fetch fresh data for one series. Throws RuntimeException if the source is unreachable. */
    public static function fetch(string $id): array
    {
        $def = self::SERIES[$id] ?? throw new InvalidArgumentException("Unknown series: $id");
        $out = $def['provider'] === 'jolpica' ? self::fetchJolpica() : self::fetchEspn($def['path']);
        return [
            'id'        => $id,
            'name'      => $def['name'],
            'source'    => $def['source'],
            'fetchedAt' => time(),
            'stale'     => false,
            'error'     => null,
        ] + $out;
    }

    private static function fetchJolpica(): array
    {
        $res = self::jsonGet(self::JOLPICA . '/current/last/results.json');
        if ($res === null) {
            throw new RuntimeException('Could not reach the Jolpica F1 API');
        }
        $race  = $res['MRData']['RaceTable']['Races'][0] ?? null;
        $event = null;
        $rows  = [];
        if (is_array($race)) {
            $event = [
                'name'  => (string) ($race['raceName'] ?? 'Grand Prix'),
                'round' => (int) ($race['round'] ?? 0),
                'date'  => self::isoDate($race['date'] ?? null, $race['time'] ?? null),
                'venue' => self::venue($race['Circuit'] ?? []),
                'state' => 'post',
            ];
            foreach (($race['Results'] ?? []) as $r) {
                $d      = $r['Driver'] ?? [];
                $rows[] = [
                    'pos'    => (int) ($r['position'] ?? 0),
                    'driver' => trim(($d['givenName'] ?? '') . ' ' . ($d['familyName'] ?? '')),
                    'team'   => (string) ($r['Constructor']['name'] ?? ''),
                    'detail' => (string) ($r['Time']['time'] ?? ($r['status'] ?? '')),
                    'points' => self::fmtNum($r['points'] ?? 0),
                    'grid'   => (int) ($r['grid'] ?? 0),
                ];
            }
        }

        // Standings and the next race are nice-to-have: a failure here must not hide the results.
        $drivers = [];
        $ds = self::jsonGet(self::JOLPICA . '/current/driverstandings.json');
        foreach (($ds['MRData']['StandingsTable']['StandingsLists'][0]['DriverStandings'] ?? []) as $s) {
            $d         = $s['Driver'] ?? [];
            $drivers[] = [
                'pos'    => (int) ($s['position'] ?? 0),
                'name'   => trim(($d['givenName'] ?? '') . ' ' . ($d['familyName'] ?? '')),
                'team'   => (string) ($s['Constructors'][0]['name'] ?? ''),
                'points' => self::fmtNum($s['points'] ?? 0),
                'wins'   => (int) ($s['wins'] ?? 0),
            ];
        }
        $teams = [];
        $cs = self::jsonGet(self::JOLPICA . '/current/constructorstandings.json');
        foreach (($cs['MRData']['StandingsTable']['StandingsLists'][0]['ConstructorStandings'] ?? []) as $s) {
            $teams[] = [
                'pos'    => (int) ($s['position'] ?? 0),
                'name'   => (string) ($s['Constructor']['name'] ?? ''),
                'points' => self::fmtNum($s['points'] ?? 0),
                'wins'   => (int) ($s['wins'] ?? 0),
            ];
        }
        $next = null;
        $nx   = self::jsonGet(self::JOLPICA . '/current/next.json');
        $nr   = $nx['MRData']['RaceTable']['Races'][0] ?? null;
        if (is_array($nr) && (int) ($nr['round'] ?? 0) !== ($event['round'] ?? -1)) {
            $next = [
                'name'  => (string) ($nr['raceName'] ?? ''),
                'date'  => self::isoDate($nr['date'] ?? null, $nr['time'] ?? null),
                'venue' => self::venue($nr['Circuit'] ?? []),
            ];
        }

        return [
            'event'     => $event,
            'results'   => $rows,
            'standings' => ['drivers' => $drivers, 'teams' => $teams],
            'next'      => $next,
        ];
    }

    private static function fetchEspn(string $path): array
    {
        $sb = self::jsonGet(self::ESPN . '/' . $path . '/scoreboard');
        if ($sb === null) {
            throw new RuntimeException('Could not reach the ESPN scoreboard API');
        }

        // Pick the event to show: a live one, else the most recent finished one, else the next.
        $live = $last = $upcoming = null;
        foreach (($sb['events'] ?? []) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $state = (string) ($e['status']['type']['state'] ?? 'pre');
            $date  = (string) ($e['date'] ?? '');
            if ($state === 'in') {
                $live ??= $e;
            } elseif ($state === 'post') {
                if ($last === null || strcmp($date, (string) ($last['date'] ?? '')) > 0) {
                    $last = $e;
                }
            } elseif ($upcoming === null || strcmp($date, (string) ($upcoming['date'] ?? '')) < 0) {
                $upcoming = $e;
            }
        }
        $chosen = $live ?? $last ?? $upcoming;

        $event = null;
        $rows  = [];
        if ($chosen !== null) {
            $comp  = self::pickCompetition($chosen);
            $event = [
                'name'  => (string) ($chosen['name'] ?? $chosen['shortName'] ?? 'Race'),
                'round' => 0,
                'date'  => isset($chosen['date']) ? (string) $chosen['date'] : null,
                'venue' => self::espnVenue($chosen, $comp),
                'state' => (string) ($chosen['status']['type']['state'] ?? 'pre'),
            ];
            $competitors = array_values(array_filter($comp['competitors'] ?? [], 'is_array'));
            usort($competitors, fn($a, $b) => (int) ($a['order'] ?? PHP_INT_MAX) <=> (int) ($b['order'] ?? PHP_INT_MAX));
            foreach ($competitors as $i => $c) {
                $ath    = $c['athlete'] ?? [];
                $name   = $ath['displayName'] ?? $ath['fullName'] ?? $ath['shortName']
                    ?? ($c['team']['displayName'] ?? ('Car ' . ($c['number'] ?? '?')));
                $rows[] = [
                    'pos'    => (int) ($c['order'] ?? ($i + 1)),
                    'driver' => (string) $name,
                    'team'   => (string) ($c['vehicle']['manufacturer'] ?? $c['team']['displayName'] ?? ''),
                    'detail' => !empty($c['winner']) ? 'Winner' : '',
                    'points' => '',
                    'grid'   => 0,
                ];
            }
        }

        $next = null;
        if ($upcoming !== null && $upcoming !== $chosen) {
            $next = [
                'name'  => (string) ($upcoming['name'] ?? $upcoming['shortName'] ?? ''),
                'date'  => isset($upcoming['date']) ? (string) $upcoming['date'] : null,
                'venue' => self::espnVenue($upcoming, self::pickCompetition($upcoming)),
            ];
        }

        return [
            'event'     => $event,
            'results'   => $rows,
            'standings' => ['drivers' => [], 'teams' => []],
            'next'      => $next,
        ];
    }

    /** Prefer a session that is live right now, then the Race session, then the last session listed. */
    private static function pickCompetition(array $event): array
    {
        $comps = array_values(array_filter($event['competitions'] ?? [], 'is_array'));
        foreach ($comps as $c) {
            if (($c['status']['type']['state'] ?? '') === 'in') {
                return $c;
            }
        }
        foreach ($comps as $c) {
            if (strcasecmp((string) ($c['type']['abbreviation'] ?? ''), 'Race') === 0) {
                return $c;
            }
        }
        return $comps ? end($comps) : [];
    }

    private static function espnVenue(array $event, array $comp): string
    {
        return (string) ($event['circuit']['fullName'] ?? $comp['venue']['fullName'] ?? $event['venue']['displayName'] ?? '');
    }

    // --------------------------------------------------------------- helpers

    private static function venue(array $circuit): string
    {
        $loc   = $circuit['Location'] ?? [];
        $parts = array_filter([$circuit['circuitName'] ?? '', $loc['locality'] ?? '', $loc['country'] ?? ''], 'strlen');
        return implode(', ', $parts);
    }

    private static function isoDate(?string $date, ?string $time): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        return $time ? "{$date}T{$time}" : $date;
    }

    private static function fmtNum(mixed $v): string
    {
        if ($v === '' || $v === null) {
            return '';
        }
        $s = number_format((float) $v, 1, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    // ----------------------------------------------------------------- cache

    /**
     * Cached read. Serves the cache while it is fresh (or the source is down), refetches otherwise.
     * Never throws: a failure comes back as 'error' (and 'stale' when old data is still shown).
     */
    public static function get(string $id, string $dir, int $ttl, bool $force = false): array
    {
        $file   = rtrim($dir, '/') . "/racing-cache-$id.json";
        $cached = null;
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            $cached  = is_array($decoded) ? $decoded : null;
        }

        $ttl = max(self::MIN_REFRESH, $ttl);
        if (($cached['event']['state'] ?? '') === 'in') {
            $ttl = min($ttl, self::LIVE_REFRESH);
        }
        if ($cached !== null && !$force && (time() - (int) ($cached['fetchedAt'] ?? 0)) < $ttl) {
            return $cached;
        }

        try {
            $fresh = self::fetch($id);
            self::writeAtomic($file, json_encode($fresh, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $fresh;
        } catch (Throwable $e) {
            if ($cached !== null) {
                return ['stale' => true, 'error' => $e->getMessage()] + $cached;
            }
            $def = self::SERIES[$id] ?? ['name' => $id, 'source' => ''];
            return [
                'id' => $id, 'name' => $def['name'], 'source' => $def['source'],
                'fetchedAt' => 0, 'stale' => false, 'error' => $e->getMessage(),
                'event' => null, 'results' => [], 'standings' => ['drivers' => [], 'teams' => []], 'next' => null,
            ];
        }
    }

    private static function writeAtomic(string $file, string $contents): void
    {
        $tmp = $file . '.tmp' . getmypid();
        if (file_put_contents($tmp, $contents) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Could not write cache file $file");
        }
    }

    // ---------------------------------------------------------------- config

    public const DEFAULT_CONFIG = [
        'series'         => ['f1', 'indycar', 'nascar-cup'],
        'refreshSeconds' => 900,
        'topN'           => 10,
    ];

    public static function loadConfig(string $dir): array
    {
        $file = rtrim($dir, '/') . '/config.json';
        $cfg  = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return self::cleanConfig(is_array($cfg) ? $cfg : []);
    }

    /** Validate untrusted input (the settings form) against the known series and sane bounds. */
    public static function cleanConfig(array $in): array
    {
        $series = array_values(array_unique(array_filter(
            (array) ($in['series'] ?? self::DEFAULT_CONFIG['series']),
            fn($s) => is_string($s) && isset(self::SERIES[$s])
        )));
        return [
            'series'         => $series,
            'refreshSeconds' => max(self::MIN_REFRESH, min(3600, (int) ($in['refreshSeconds'] ?? self::DEFAULT_CONFIG['refreshSeconds']))),
            'topN'           => max(3, min(30, (int) ($in['topN'] ?? self::DEFAULT_CONFIG['topN']))),
        ];
    }

    public static function saveConfig(string $dir, array $in): array
    {
        $cfg = self::cleanConfig($in);
        self::writeAtomic(rtrim($dir, '/') . '/config.json', json_encode($cfg, JSON_PRETTY_PRINT));
        return $cfg;
    }

    // ---------------------------------------------------------------- render

    private static function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function when(?string $iso): string
    {
        $t = $iso ? strtotime($iso) : false;
        return $t === false ? '' : date('D M j, Y', $t);
    }

    /** HTML fragment for one series (a card). Everything dynamic is escaped. */
    public static function renderHtml(array $d, int $top = 10): string
    {
        $ev    = $d['event'] ?? null;
        $state = $ev['state'] ?? '';
        $badge = match ($state) {
            'in'    => ['LIVE', '#c0392b'],
            'post'  => ['FINAL', '#555'],
            'pre'   => ['UPCOMING', '#2c6fbb'],
            default => null,
        };

        $h  = '<div class="card mb-3 racing-card"><div class="card-body">';
        $h .= '<h5 class="card-title mb-1">' . self::e($d['name'] ?? '');
        if ($badge) {
            $h .= ' <span class="badge" style="background:' . $badge[1] . ';color:#fff;font-size:.65em;vertical-align:middle">' . $badge[0] . '</span>';
        }
        $h .= '</h5>';

        if ($ev) {
            $sub = array_filter([$ev['name'], $ev['venue'], self::when($ev['date'])], 'strlen');
            $h  .= '<div class="text-muted mb-2">' . self::e(implode(' · ', $sub)) . '</div>';
        } elseif (empty($d['error'])) {
            $h .= '<div class="text-muted mb-2">No race data available yet.</div>';
        }

        if (!empty($d['error'])) {
            $h .= '<div class="alert alert-warning py-1 px-2 mb-2">'
                . self::e($d['error']) . (!empty($d['stale']) ? ' — showing the last data we have.' : '') . '</div>';
        }

        $rows = array_slice($d['results'] ?? [], 0, $top);
        if ($rows) {
            $hasPts = (bool) array_filter(array_column($rows, 'points'), 'strlen');
            $h .= '<table class="table table-sm table-striped mb-2"><thead><tr><th>Pos</th><th>Driver</th><th>Team</th><th>Time / Status</th>'
                . ($hasPts ? '<th class="text-end">Pts</th>' : '') . '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $h .= '<tr><td>' . self::e($r['pos']) . '</td><td>' . self::e($r['driver']) . '</td><td>' . self::e($r['team'])
                    . '</td><td>' . self::e($r['detail']) . '</td>'
                    . ($hasPts ? '<td class="text-end">' . self::e($r['points']) . '</td>' : '') . '</tr>';
            }
            $h .= '</tbody></table>';
        }

        $st = $d['standings'] ?? [];
        if (!empty($st['drivers']) || !empty($st['teams'])) {
            $h .= '<div class="row">';
            foreach ([['Drivers\' championship', 'drivers'], ['Constructors\' championship', 'teams']] as [$title, $key]) {
                if (empty($st[$key])) {
                    continue;
                }
                $h .= '<div class="col-md-6"><strong>' . self::e($title) . '</strong>'
                    . '<table class="table table-sm mb-2"><tbody>';
                foreach (array_slice($st[$key], 0, $top) as $s) {
                    $h .= '<tr><td>' . self::e($s['pos']) . '</td><td>' . self::e($s['name']) . '</td><td class="text-end">'
                        . self::e($s['points']) . ' pts</td></tr>';
                }
                $h .= '</tbody></table></div>';
            }
            $h .= '</div>';
        }

        if (!empty($d['next']['name'])) {
            $n  = $d['next'];
            $h .= '<div class="small text-muted">Next: ' . self::e(implode(' · ', array_filter([$n['name'], $n['venue'], self::when($n['date'])], 'strlen'))) . '</div>';
        }
        if (!empty($d['fetchedAt'])) {
            $h .= '<div class="small text-muted">' . self::e($d['source'] ?? '') . ' · updated ' . self::e(date('g:i:s a', (int) $d['fetchedAt'])) . '</div>';
        }
        return $h . '</div></div>';
    }

    private static function pad(string $s, int $w): string
    {
        $s = mb_strimwidth($s, 0, $w, '…');
        return $s . str_repeat(' ', max(0, $w - mb_strlen($s)));
    }

    /** Plain-text rendering for the CLI. */
    public static function renderText(array $d, int $top = 10): string
    {
        $ev  = $d['event'] ?? null;
        $out = strtoupper((string) ($d['name'] ?? '')) . "\n";
        if ($ev) {
            $state = ['in' => 'LIVE', 'post' => 'FINAL', 'pre' => 'UPCOMING'][$ev['state']] ?? '';
            $out  .= implode(' · ', array_filter([$ev['name'], $ev['venue'], self::when($ev['date']), $state], 'strlen')) . "\n";
        }
        if (!empty($d['error'])) {
            $out .= '! ' . $d['error'] . (!empty($d['stale']) ? ' (showing last data)' : '') . "\n";
        }
        foreach (array_slice($d['results'] ?? [], 0, $top) as $r) {
            $out .= '  ' . str_pad((string) $r['pos'], 3) . self::pad($r['driver'], 24) . self::pad($r['team'], 22)
                . self::pad($r['detail'], 14) . ($r['points'] !== '' ? $r['points'] . ' pts' : '') . "\n";
        }
        $st = $d['standings'] ?? [];
        foreach ([['Drivers', 'drivers'], ['Constructors', 'teams']] as [$title, $key]) {
            if (!empty($st[$key])) {
                $out .= "\n  $title' standings\n";
                foreach (array_slice($st[$key], 0, $top) as $s) {
                    $out .= '  ' . str_pad((string) $s['pos'], 3) . self::pad($s['name'], 28) . $s['points'] . " pts\n";
                }
            }
        }
        if (!empty($d['next']['name'])) {
            $out .= "\nNext: " . implode(' · ', array_filter([$d['next']['name'], $d['next']['venue'], self::when($d['next']['date'])], 'strlen')) . "\n";
        }
        return $out;
    }

    /** Map an API URL to a fixture file name, for --fixtures (offline) mode and tests. */
    public static function fixtureFor(string $url): ?string
    {
        if (str_contains($url, 'api.jolpi.ca')) {
            return match (true) {
                str_contains($url, 'constructorstandings') => 'jolpica_constructorstandings.json',
                str_contains($url, 'driverstandings')      => 'jolpica_driverstandings.json',
                str_contains($url, '/next.json')           => 'jolpica_next.json',
                str_contains($url, '/last/results')        => 'jolpica_results.json',
                default                                    => null,
            };
        }
        if (preg_match('#/sports/racing/([a-z0-9-]+)/scoreboard#', $url, $m)) {
            return 'espn_' . $m[1] . '.json';
        }
        return null;
    }
}
