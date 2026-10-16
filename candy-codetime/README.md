<img src=".assets/icon.png" alt="candy-codetime" width="160" align="right">

# CandyCodetime

> Formerly published as `sugarcraft/sugar-tick` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Tick\` → `SugarCraft\CodeTime\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-codetime)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-codetime)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-codetime?label=packagist)](https://packagist.org/packages/sugarcraft/candy-codetime)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/dashboard.gif)
![push demo](.vhs/push.gif)

candy-codetime — a privacy-first coding-time tracker for PHP 8.3+. Storage is JSONL on local disk (no cloud, no database server), the dashboard is a SugarCharts-driven TUI that reads it.

## Run it

```bash
# write a heartbeat from your editor (one-shot CLI):
candy-codetime push <project> <language> <file> [duration]

# open the dashboard:
candy-codetime
```

By default heartbeats land in `$XDG_DATA_HOME/candy-codetime` (or
`~/.local/share/candy-codetime`). Override with `CANDY_CODETIME_DIR=...`.

## Keys (dashboard)

| Key            | Action                       |
|----------------|------------------------------|
| `←`            | Shift the 7-day window back  |
| `→`            | Shift forward (to today)     |
| `r`            | Reload from disk             |
| `q` / `Esc`    | Quit                         |

## Architecture

| File          | Role                                                                          |
|---------------|-------------------------------------------------------------------------------|
| `Heartbeat`   | Value object — one activity sample (project, language, file, duration).       |
| `Store`       | JSONL-backed reader / appender. One file per day under the data dir.          |
| `Stats`       | Pure folder — totals per project / language + per-day timeline buckets.       |
| `Dashboard`   | SugarCraft Model — renders the report, ←/→ shifts the window, r reloads.       |
| `Renderer`    | View — header + ranking panes side-by-side + Sparkline timeline (SugarCharts).|

The Store is intentionally append-only at the file level, so editor plug-ins can `>>` echo a JSON line directly without coordinating with the dashboard. The dashboard reads at `r` / on launch / when the day shifts — never holds a file lock.

## Shared foundations

candy-codetime uses [candy-async](https://github.com/detain/sugarcraft/tree/master/candy-async) for cancellation support in async write operations.

## Test

```bash
composer install
vendor/bin/phpunit
```

## Credits & inspiration

Design antecedent: [Rtarun3606k/TakaTime](https://github.com/Rtarun3606k/TakaTime); SugarCraft is developed as a native PHP project.
