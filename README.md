# fpp-racing-results

A [Falcon Player (FPP)](https://github.com/FalconChristmas/fpp) plugin that gathers the latest **Formula 1**, **IndyCar** and **NASCAR** results and shows them in the FPP web UI. Modeled on [derwin12/fpp-gameday](https://github.com/derwin12/fpp-gameday), which does the same job for team sports using ESPN's scoreboard API.

Unlike fpp-gameday it is plain PHP: no compiling, no `fppd` restart, and the same code also runs as a command-line tool without FPP.

## What it shows

| Series | Source | Shows |
|---|---|---|
| Formula 1 | [Jolpica F1](https://github.com/jolpica/jolpica-f1) (Ergast-compatible, no key) | Latest race classification, driver and constructor standings, next race |
| IndyCar | ESPN scoreboard (`racing/irl`) | Live / latest / next event and finishing order |
| NASCAR Cup, O'Reilly, Truck | ESPN scoreboard (`racing/nascar-*`) | Live / latest / next event and finishing order |

ESPN series do not include championship standings yet.

## Install on FPP

Copy this folder to `/home/fpp/media/plugins/fpp-racing-results/`. Two new entries appear in FPP's menus: **Racing Setup** (pick series, refresh interval, rows) and **Racing Results**.

## Command line

```
php bin/racing.php                      # every series you enabled in Setup
php bin/racing.php --series=f1,indycar --top=5
php bin/racing.php --format=json
php bin/racing.php --list
php bin/racing.php --refresh            # fetch + cache only; good for cron
php bin/racing.php --fixtures=tests/fixtures   # offline demo with sample data
```

To gather results in the background instead of only when someone opens the page:

```
*/15 * * * * php /home/fpp/media/plugins/fpp-racing-results/bin/racing.php --refresh
```

## How it works

- `lib/Racing.php` fetches each series, reduces it to one normalised shape, caches it as JSON, and renders it (HTML for the web UI, text for the CLI).
- `results.php` is the small endpoint the pages call. `status.php` polls it every 30 s; the **server-side cache** decides how often the upstream APIs are really hit (your refresh setting, floored at 20 s, and 30 s while a session is live).
- If a source is down, the last cached data is shown with a warning instead of an empty page.
- Config and cache live in `<FPP config dir>/fpp-racing-results/` (or `./data`, or `$FPP_RACING_DIR`, outside FPP).

Jolpica asks clients to stay under 4 requests/second and 500/hour. The F1 refresh makes 4 requests, so even a 20 s refresh during a live weekend stays well inside the limit.

## Adding a series

Add one line to `Racing::SERIES` in `lib/Racing.php`: an id, a display name, and for ESPN the league path (`racing/<slug>`). It then appears in Setup automatically.

## Tests

```
php tests/run.php
```

Runs offline against the sample JSON in `tests/fixtures/` (fictional drivers, not real results).

## Known limitations

- The ESPN and Jolpica response handling was written from their documented formats and checked against hand-written fixtures. It has **not** been run against the live APIs, so the first real run may need small field-name fixes in `fetchEspn` / `fetchJolpica`.
- `menu.inc` and the `plugin.php?...&nopage=1` page URLs follow FPP's conventions from memory and have not been tried on an FPP install.
- ESPN only returns its current scoreboard window, so between race weekends the "latest" event may be missing.
- No live F1 timing. [OpenF1](https://openf1.org/) would be the source for that.
