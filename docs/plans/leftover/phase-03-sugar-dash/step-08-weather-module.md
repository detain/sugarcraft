# Step 03.08 — sugar-dash Weather built-in module

**Source:** `leftover_updates_later.md` Dash-06
**Branch:** `ai/dash-weather-module`

## Deliverable

Last missing built-in module from the original dash.md plan. HTTP
fetch from `wttr.in` (or open-meteo); cache to disk; fall back on
network failure.

## Files

**Create:**
- `sugar-dash/src/Modules/Weather/WeatherModule.php` — implements
  `Module` per the contract from step 02.04. State includes:
  - `?WeatherSnapshot $current` (temp, condition, location, ts).
  - `\DateTimeImmutable $lastFetch`.
- `sugar-dash/src/Modules/Weather/WeatherSnapshot.php` — readonly DTO.
- `sugar-dash/src/Modules/Weather/HttpClient.php` — abstract HTTP
  fetch (so tests can mock).
- `sugar-dash/src/Modules/Weather/WttrInClient.php` — concrete impl
  hitting `wttr.in/<location>?format=j1`.

**Modify:**
- `sugar-dash/examples/dashboard-live.php` — replace the stub Weather
  with the real WeatherModule. If `WEATHER_LOCATION` env var is set,
  use it; otherwise default to "auto" (wttr.in detects from IP).

**Cache:**
- `~/.cache/sugar-dash/weather.json` — atomic save via tmp+rename
  (per Homedash pattern). TTL 30 min.

## Tests

- `sugar-dash/tests/Modules/Weather/WeatherModuleTest.php`:
  - Mocks `HttpClient`; asserts cache hit/miss behaviour.
  - Asserts network failure falls back to cached snapshot.
  - Asserts stale cache (> TTL) triggers re-fetch.
- `sugar-dash/tests/Modules/Weather/WttrInClientTest.php`:
  - Optional `markTestSkipped` if no network; assert response parses.

## Acceptance

- `cd sugar-dash && vendor/bin/phpunit --filter Weather` green.
- `WEATHER_LOCATION=Seattle php sugar-dash/examples/dashboard-live.php`
  shows the right city's weather panel.

## Notes

- Use `\SugarCraft\Pty\Posix\PosixProcess` if you go the curl-shell
  route. Better: use PHP's `file_get_contents` with stream context
  for the HTTP fetch (simpler, no extra dep).
- 30-minute interval matches Homedash's pattern; longer interval is
  fine for weather data.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
