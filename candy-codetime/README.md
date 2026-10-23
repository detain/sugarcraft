<img src=".assets/icon.png" alt="candy-codetime" width="160" align="right">

# CandyCodetime

> Formerly published as `sugarcraft/sugar-tick` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Tick\` → `SugarCraft\CodeTime\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-codetime)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-codetime)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-codetime?label=packagist)](https://packagist.org/packages/sugarcraft/candy-codetime)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/dashboard.gif)

candy-codetime — a privacy-first coding-time tracker for PHP 8.3+. Storage is
local by default: one append-only JSONL file per day, with an optional
embedded-SQLite backend (`--backend=sqlite`) for milestone tracking and indexed
queries. Nothing leaves your machine — no cloud, no daemon, and no database
*server* (SQLite is an in-process library reading a file you own). The
dashboard is a SugarCraft TUI that reads whichever backend you selected.

## Install

```bash
composer global require sugarcraft/candy-codetime
# the binary lands in the composer global bin dir (~/.composer/vendor/bin):
~/.composer/vendor/bin/candy-codetime push myproj php src/Main.php 120
```

Or from a clone: `composer install && php bin/candy-codetime`.

## Commands

| Command | Arguments | What it does |
|---------|-----------|--------------|
| `dashboard` | — | Open the TUI (reads the selected backend). Default when no command is given. |
| `push` | `<project> <language> <file> [duration]` | Record one heartbeat. `duration` is whole seconds (default 60); a non-numeric value is rejected, not silently read as 0. |
| `milestone` | `<add\|list> [name] [description]` | Manage release markers. Milestones live only in SQLite, so this command forces the sqlite backend regardless of `--backend`. |
| `export` | `<csv\|json\|ics> [days]` | Dump heartbeats for the last N days (default 7, clamped to [1, 3660]). |
| `gaps` | `[days]` | List untracked stretches longer than 300 seconds within the last N days (default 7). |
| `backup` | — | Archive data files older than 30 days into `<dir>-backups/`. Copies only — it never deletes your data. |
| `help`, `--help`, `-h` | — | Print usage. |
| `--version`, `-v` | — | Print the version. |

Options (accepted before or after the command):

| Option / env | Effect |
|--------------|--------|
| `--backend=file\|sqlite` | Storage for `push`/`export`/`gaps`/`dashboard` (default: `file`). The sqlite backend needs the `sqlite3` PHP extension; without it the CLI exits with a clear message instead of crashing. |
| `CANDY_CODETIME_DIR=<path>` | Data directory (default `$XDG_DATA_HOME/candy-codetime`, falling back to `~/.local/share/candy-codetime` — note that an unset `HOME` resolves to `./.local/share/…`). |

Legacy `SUGARTICK_*` environment variables are still read (with a deprecation
notice) and the old default data directory is migrated once on first launch.

## Ignoring files

A `.sugartrackignore` in the current directory, or in the tracked file's
directory, holds glob patterns (one per line); a `push` whose file matches is
reported as skipped and never stored.

## Data layout

```
$CANDY_CODETIME_DIR/
  2026-10-23.jsonl      one appendable line per heartbeat
  candy-codetime.db     created only by --backend=sqlite
<dir>-backups/          created by `backup`
```

### Privacy

The data directory and every file in it are owner-private: directories are
`0700`, day files and the database are `0600`, created under a narrowed umask
so they are never world-readable for an instant — and a directory or file left
loose by an older version is tightened on the next write (if that fails, the
write aborts rather than storing your paths in the open). Heartbeats carry
absolute file paths and project names; treat the directory accordingly.
`NO_COLOR=1` renders the dashboard without any colour tints.

## Keys (dashboard)

| Key            | Action                       |
|----------------|------------------------------|
| `←`            | Shift the 7-day window back  |
| `→`            | Shift forward (to today)     |
| `r`            | Reload from disk             |
| `q` / `Esc`    | Quit                         |
| `Ctrl+C`       | Quit                         |

The two ranking panes render side by side on terminals ≥56 columns and stack
vertically below that; the timeline sparkline follows the terminal width.

## Architecture

| File | Role |
|------|------|
| `Heartbeat` | Value object — one activity sample (project, language, file, duration, tags). |
| `Store` | JSONL-backed reader/appender; implements `HeartbeatReader`; enforces the private modes above. |
| `Storage\SqliteBackend` | Optional indexed storage; implements `HeartbeatReader`; also owns milestones. |
| `Storage\HeartbeatReader` | The read contract (`loadRange`/`invalidate`) both storages satisfy — what lets every subcommand honour `--backend` uniformly. |
| `Stats` | Pure fold — totals per project / language + per-day timeline buckets. |
| `Dashboard` | SugarCraft Model — window shifts (`←/→`), reload (`r`), quit; reads through a `HeartbeatReader`. |
| `Renderer` | View — header + ranking panes + Sparkline timeline (SugarCharts); NO_COLOR aware. |
| `Report\GapsReport` | Pure gap detection over sorted heartbeats (threshold 300 s). |
| `Backup\AutoBackup` | Copy-only archiver; throws loudly on any failed copy. |
| `Ignore\SugarTrackIgnore` | `.sugartrackignore` matcher used by `push`. |
| `Milestone` | Value object for sqlite markers. |
| `Export\CsvExporter` / `JsonExporter` / `IcalExporter` | `export` formats behind `ExporterInterface` (the tabular pair shares `TabularExporterInterface`). |
| `Support\Version` | Single-source version constant. |
| `Lang` | i18n facade (16 locales under `lang/`). |

The Store is intentionally append-only at the file level, so editor plugins can
`>>` echo a JSON line directly without coordinating with the dashboard — the
trade-off is honest: raw `>>` redirection does not take the advisory lock
`push` uses, so a hand-echoed line could theoretically interleave; plugin
integrations should `flock` or just call `push`. The dashboard re-reads on
launch and on `r`; it has no clock subscription, so an open panel never
refreshes by itself.

## Shared foundations

candy-codetime uses [sugar-async](https://github.com/detain/sugarcraft/tree/master/sugar-async)
for cooperative cancellation: `Store::append()` polls an optional
`CancellationToken` immediately before writing (PHP cannot interrupt a blocking
write mid-flight). The CLI never arms a token; the seam exists for embedding
hosts that want abortable writers.

## Test

```bash
composer install
vendor/bin/phpunit
```

## Credits & inspiration

Design antecedent: [Rtarun3606k/TakaTime](https://github.com/Rtarun3606k/TakaTime); SugarCraft is developed as a native PHP project.
