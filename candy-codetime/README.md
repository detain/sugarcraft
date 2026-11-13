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


![candy-codetime dashboard TUI: seven-day window with project and language rankings and a daily timeline](.vhs/dashboard.gif)

candy-codetime — a privacy-first coding-time tracker for PHP 8.3+. Storage is
local by default: one append-only JSONL file per day, with an optional
embedded-SQLite backend (`--backend=sqlite`) for milestone tracking and
time-indexed range queries. Nothing leaves your machine — no cloud, no daemon,
and no database
*server* (SQLite is an in-process library reading a file you own). The
dashboard is a SugarCraft TUI that reads whichever backend you selected.

## Install

```bash
composer global require sugarcraft/candy-codetime
# the global bin dir varies by composer version and platform; ask composer:
BIN="$(composer global config bin-dir --absolute)"
"$BIN/candy-codetime" push myproj php src/Main.php 120
```

Or from a clone: `composer install && php bin/candy-codetime`.

## Quick start

```bash
candy-codetime push myproj php src/Main.php 120   # record one heartbeat
candy-codetime                                    # open the dashboard
candy-codetime export csv 30                      # headless dump of 30 days
```

`php examples/dashboard.php` seeds a throwaway data directory with a pretend
week and opens the dashboard, so you can see the TUI before recording
anything real. Like the `dashboard` command, it refuses (exit 1) when
standard input or output is not a terminal, so piping it into a capture
cannot dump an alt-screen into the log.

## Automating collection

**No editor plugins ship with candy-codetime** — the tracker is passive by
design, so capturing your day is something you wire up once, and this section
is the whole how-to. Three doors exist, pick one:

1. **The editor hook** (recommended): `examples/plugin-heartbeat.example.sh`
   is a worked ~35-line POSIX sh script. Call it from a save hook with the
   saved path and one heartbeat lands per save. It prefers the CLI's
   `push --` stdin door (ignore patterns, clamps and the advisory lock all
   apply), and falls back to a raw append under `flock` when no binary is on
   `PATH`.
2. **A direct `push`** typed (or scripted) by you: `candy-codetime push
   <project> <language> <file> [duration]`, backdated with `--time=<unix>`.
3. **A raw `printf … >> YYYY-MM-DD.jsonl`** from cron — the exact line shape
   is in *Data layout* below, but see the two caveats there before choosing
   this door: raw lines are unstamped unless you write `time` into them, and
   they land in the **file backend only** (a `--backend=sqlite` dashboard
   never sees them).

A cron recipe for the raw door, five-minute cadence (it still needs `time`
inline — see the contract in *Data layout*):

```cron
*/5 * * * * umask 077; D=${CANDY_CODETIME_DIR:-$HOME/.local/share/candy-codetime}; mkdir -p "$D" && printf '{"time": %s, "project": "myproj", "language": "php", "file": "/home/me/myproj/src/Main.php", "duration": 300, "tags": []}\n' "$(date +\%s)" >> "$D/$(date +\%F).jsonl"
```

(`%` is escaped for cron; the day file is named with the machine's local
calendar date, matching the store layout.)

## Commands

| Command | Arguments | What it does |
|---------|-----------|--------------|
| `dashboard` | `[days]` | Open the TUI (reads the selected backend), showing a `[days]`-day window ending today (default 7). Inside the TUI, `+`/`-` step the window through 7, 14, 30, 90 days. Without an interactive terminal it refuses with a pointer to `export` instead of hanging. |
| `push` | `<project> <language> <file> [duration] [tags]` \| `--` | Record heartbeats. `duration` is whole seconds (default 60); a non-numeric value is rejected, not silently read as 0; durations above one year are clamped to 31,536,000 s. `--time=<unix>` files a single beat at a past (or future) instant instead of `now` — a malformed value is refused, never coerced. `push --` reads a batch: one JSON heartbeat per line on stdin, each with its own `time`; a malformed line is named with its line number on STDERR and the run exits 1 after the well-formed rows still land. `tags` is a comma-list (`work,review`) stored with the beat and carried by every export format; blank members are dropped. Both forms pass the same ignore door and the same clamps. |
| `milestone` | `add [name] [description]` \| `list` | Manage release markers. `name` and `description` are read for `add` only. Milestones live only in SQLite, so this command uses the sqlite backend regardless of `--backend` (a `milestone add` into a file-only data dir says so on stderr before it creates the database); the sqlite dashboard marks the milestone days with `*` under the weekday row. |
| `export` | `<csv\|json\|ics> [days]` | Dump heartbeats for the last N days (default 7, clamped to [1, 3660]; a non-numeric count is refused with a one-line error and exit 1). Output goes to STDOUT — redirect it to a file to save it. |
| `gaps` | `[days]` | List untracked stretches of at least 300 seconds within the last N days (default 7, days clamped to [1, 3660]; a non-numeric count is refused with a one-line error and exit 1). |
| `backup` | — | Archive day files (`*.jsonl`) older than 30 days into `<dir>-backups/`. Copies only — it never deletes your data, and archives are never pruned either. Archives are named after the source directory and the file's logical day, so a backfilled day *refreshes* its existing archive instead of minting a second diverging copy, and two same-basename roots can never overwrite one another's archives. Overlapping runs refuse on a lock file rather than interleaving into one destination. A byte copy of a live `.db` would be a torn snapshot, so plain `backup` refuses the SQLite database (the reminder you see when a `.db` is present points at `backup --sqlite`, which runs a crash-safe `VACUUM INTO` snapshot into `<dir>-backups/`). Nothing trims the live database: it grows until you remove it yourself. |
| `help`, `--help`, `-h` | — | Print usage. |
| `--version`, `-v` | — | Print the version. |

Any command word not listed above prints a one-line error followed by the full
usage on stderr and exits 1.

Options (accepted before or after the command):

| Option / env | Effect |
|--------------|--------|
| `--backend=file\|sqlite` | Storage for `push`/`export`/`gaps`/`dashboard`. Both forms work: `--backend=sqlite` and `--backend sqlite`. The sqlite backend needs the `sqlite3` PHP extension; without it the CLI exits with a clear message instead of crashing. With neither the flag nor `CANDY_CODETIME_BACKEND` set, the CLI auto-selects `sqlite` when the database holds records and the JSONL store does not — the store is re-decided per invocation, never persisted. |
| `CANDY_CODETIME_BACKEND=<name>` | Default backend (`file` or `sqlite`) for every invocation, so an sqlite user need not repeat the flag. An explicit `--backend` on the command line outranks it; a junk value is refused like a junk flag. The dashboard header badges the store it actually reads (`[file]`/`[sqlite]`), and when the *other* store also holds records the CLI prints a one-line pointer on STDERR. |
| `--sqlite` (with `backup`) | Archive the SQLite database itself: `VACUUM INTO` writes a consistent standalone snapshot named `candy-codetime-<timestamp>.db` under `<dir>-backups/`, owner-private like every other archived payload. The live database is untouched — this is archive-only, no prune, no in-place vacuum. |
| `CANDY_CODETIME_DIR=<path>` | Data directory (default `$XDG_DATA_HOME/candy-codetime`, falling back to `~/.local/share/candy-codetime` — note that an unset `HOME` resolves to `./.local/share/…`). |

A storage failure ends any command with a one-line message on stderr and exit
status 1 — never a bare PHP fatal.

Compatibility with the tool's earlier names — the banner at the top of this
file covers the package and namespace rename, and the same tool simply kept
its old file and environment names:

- Legacy `SUGARTICK_*` environment variables are still imported on every
  launch, each with a deprecation notice on stderr.
- The old default data directory is migrated once on first launch — not when
  `CANDY_CODETIME_DIR` is set, because then you have chosen the destination.

CLI messages and dashboard chrome follow `LC_ALL`/`LC_MESSAGES`/`LANG`
across the 16 shipped locales. Date and weekday names (`Jun 7`, `Sat`) render
in English in every locale: the locale catalogue carries the PHP format
characters, not month tables.

## Ignoring files

A `.sugartrackignore` in the current directory — or, failing that, in the
tracked file's own directory (the first found wins) — holds glob patterns one
per line; a `push` whose file matches is reported as skipped and never
stored. (The dotfile keeps the pre-rename name — same tool, same patterns.)
Patterns match in three ways:

- a bare pattern (`secret.log`) matches by file name at any depth;
- a pattern naming the whole tracked path matches it exactly;
- a path pattern (`vendor/*`) matches below any `vendor` directory, whether
  the tracked path is relative or absolute.

An ignore file larger than 1 MiB is refused outright:
`push` reports it with a one-line error and exits 1 rather than parsing a
deliberately hostile file. An ignore file that exists but cannot be read is refused the
same way — tracking with the opt-out in an unknown state is exactly the
failure the file was written to prevent, so `push` never proceeds as if the
file were empty.

Trust boundary: ignore files usually live *inside* tracked repositories, so
their patterns are untrusted input to the tracking tool. A wildcard-only
pattern (a bare `*`) matches every file name and silently zeroes that
project's entire recorded time — and so does anything *behaviourally* equal
to it (`?*` matches every real, non-empty path while failing a pure-shape
test), so such patterns are caught by probe-matching, not just by shape.
When the loaded file carries such a pattern,
the skip is no longer a quiet stdout line: `push` reports it on **stderr**
with the ignore file's path, its pattern count, and the affected project —
then still exits 0, because the opt-out is honored, not refused. To undo a
file supplied by the repository, delete or narrow the file the notice names;
to override a directory-level ignore file instead, run `push` from a working
directory whose own `.sugartrackignore` (checked first) carries only the
patterns you chose.

## Data layout

```
$CANDY_CODETIME_DIR/
  2026-10-23.jsonl      One appendable line per heartbeat.
  candy-codetime.db     Created by --backend=sqlite, and by `milestone` (which always uses SQLite).
  candy-codetime.db-wal WAL sidecar: recent commits live here until checkpointed.
  candy-codetime.db-shm WAL index header (shared memory).

<dir>-backups/          Created by `backup` (day files) and `backup --sqlite` (database snapshots).
```

The SQLite backend tunes the database on every open:

- `journal_mode=WAL` — dashboard reads never block behind a committing
  appender, nor does the reverse hold: the stated model is editor plugin +
  CLI + cron appending to one file.
- `busy_timeout` (5 seconds) — concurrent writers wait out each other's
  locks instead of failing with "database is locked".
- `synchronous=NORMAL` — WAL's companion setting: a power cut can cost the
  last transaction of heartbeat telemetry — one sample, never the file.
- `cache_size=-64000` — a 64,000 KiB (62.5 MiB) page cache that keeps the
  `idx_heartbeats_time` range scans in memory. The schema is declared in SQLite's `user_version` pragma (version
`1`): a file stamped with a version this build does not know is refused on
open rather than silently no-op'ed against a layout it cannot read, and
pre-versioning databases are stamped once after their tables are verified.
The `-wal`/`-shm` sidecars are swept to `0600` with the database
itself (Privacy below) — one more reason a byte copy cannot cover SQLite;
`backup --sqlite` snapshots the database crash-safely with `VACUUM INTO`. Nothing is ever pruned: neither the
live `.db` nor the archives under `<dir>-backups/` are trimmed — retention is
yours to manage (delete day files, or archive-and-remove by hand).

Each line of a day file is one JSON object:
`{"time": <unix seconds>, "project": <string>, "language": <string>, "file":
<path as supplied>, "duration": <seconds>, "tags": <array>}`. The CLI never
sets tags; a plugin-written tag is honored on read and export. Readers should
ignore unknown keys. Timestamps are part of the payload, not the envelope: a
line fed through the `push --` stdin door **without** `time` gets stamped at
the moment it is read *in*, so a batch pushed after the fact files every beat
under batch-time. A raw line placed in a day file with no `time` is a corrupt
record: readers drop it (counted on the day-file warning lane) rather than
invent a live beat at read time. If you are
backfilling, carry the real instant on each line — or feed the batch through
`push --time=<unix>` (one beat) / `push --` (stdin JSON-lines), which run the
same ignore door, clamps and locking as an interactive push.

Day files are keyed by the machine's **local** calendar date, so history
written while crossing time zones stays filed under the date it was tracked.
Nothing is ever deleted: beats accumulate until you remove them by hand or
archive them with `backup` (which copies and never trims).

### Fixing a bad entry

There is no edit command, and that is deliberate — the store is plain files.
With the file backend, fix or drop the offending JSON line in the day file,
then press `r` in the dashboard to reload it (or `q` and reopen). With the
sqlite backend, open the database with `sqlite3` and fix the row in
`heartbeats`.

### Privacy

The data directory and every file in it are owner-private, per surface:

- **Directories** are `0700`, created under a narrowed umask, so they never
  exist world-readable.
- **Day files, the database, and its `-wal`/`-shm` sidecars** are `0600`.
  Regular files are created-then-tightened — mode `0600` is settled before
  any payload is written, and until then the file is empty and shielded by
  the `0700` directory. The WAL sidecars are the exception to that timing:
  SQLite creates them mid-transaction, so on a fresh database the first
  beats can land on a `-wal` microseconds before the constructor's sweep
  tightens it — there, the `0700` directory is the protection window. The
  sweep tightens every sidecar visible at open and re-asserts on every
  subsequent open.
- **Legacy and plugin-made files** cannot stay loose: the contract is a
  launch invariant, not a write-path favor. A directory or file left
  world-readable — by an older version, a plugin direct-appending with `>>`
  (which runs none of this code), or the pre-rename migration moving a
  legacy tree — is swept tight on the next CLI run, any command, reads
  included; if that fails, the run aborts rather than printing over data
  that is still out in the open. Until that next run, a raw-echoed file
  sits at the shell's umask, so plugin and cron writers should run under
  `umask 077`.
- **Stored names are swept before they paint**, per surface. The dashboard
  runs every name through a one-line sweep: escape sequences, C0 and C1
  control classes, and DEL are dropped, TAB/CR/LF go with them (a name is
  one row of the frame), and zero-width/bidi "Trojan Source" characters are
  made visible as `<U+XXXX>` markers instead of painted. CLI echoes use the
  byte-oriented sweep of the same family — escapes and control classes
  dropped, line breaks preserved so multi-line output stays readable. A
  doctored store cannot inject escape sequences into either surface.
- **The `backup` archive** under `<dir>-backups/` is held to the same
  contract: it is created `0700` and every copied day file is tightened to
  `0600`, because an archive of those files is the same path map one
  directory over.

Heartbeats carry file paths exactly as supplied (editor plugins typically
send absolute ones) and project names; treat the directory accordingly.

Colour is driven by the detected `ColorProfile` for the terminal: a profile
below 256-colour (which `NO_COLOR=1`, `CLICOLOR=0` and `TERM=dumb` all land
on) renders the dashboard without any tints. Detection runs once at startup
(and without a detected profile the fallback honours a set, non-empty
`NO_COLOR`, treating `0` and `false` as not suppressing); a resize relayouts
the frame under the stored profile.

## Keys (dashboard)

| Key            | Action                       |
|----------------|------------------------------|
| `←`            | Move the window back one day |
| `→`            | Move it forward one day      |
| `+`            | Widen the window (7/14/30/90 days) |
| `-`            | Narrow the window back down  |
| `r`            | Reload from disk             |
| `q` / `Esc`    | Quit                         |
| `Ctrl+C`       | Quit                         |

The two ranking panes render side by side from 62 columns up (two 30-cell
frames plus a gap) and stack vertically below that. Every dashboard line is
clipped to the terminal width and never wraps: the timeline frame fills the
width, while the ranking panes keep their budgeted 62-cell band, and days
that cannot be drawn are counted
("+N earlier") rather than silently dropped.

## Accessibility

The dashboard is a deliberately visual artifact; the supported assistive
path is the plain-text commands: `export csv|json` and `gaps` write their
whole answer to stdout as ordinary lines, so a screen reader gets the same
data as the terminal does. The TUI adds nothing that those commands lack.

Font requirement: the frame draws rounded box glyphs, the timeline draws
block-character bars, and the help row uses arrow glyphs. There is no ASCII
fallback, so on a terminal whose font lacks those characters they degrade to
replacement symbols — labels and numbers stay legible, borders get ugly.

Colour is decorative only. The palette ships dark-terminal hexes, applied
solely when the terminal declares 256-colour or TrueColor capability (and
never under `NO_COLOR`); the information survives without it — bars are
height-encoded (minutes, not hue), every coloured value carries its word
label (`total:`, spelled-out project and language names), and the milestone
mark is `*` text rather than a colour change.

The interface is static by design: the dashboard subscribes to nothing — no
timers, no animation, no moving cells. Each screen is a snapshot that
redraws only when a key changes it, which is also the reduced-motion answer:
nothing ever moves on its own.

## Architecture

| File | Role |
|------|------|
| `Heartbeat` | Value object — one activity sample (project, language, file, duration, tags). |
| `Store` | JSONL-backed reader/appender; implements `HeartbeatReader`; enforces the private modes above. |
| `Storage\SqliteBackend` | Optional storage whose only index is on the heartbeat time column (`idx_heartbeats_time`) for range queries; implements `HeartbeatReader`; also owns milestones. |
| `Storage\HeartbeatReader` | The read contract (`loadRange`/`invalidate`) both storages satisfy — what lets every subcommand honour `--backend` uniformly. |
| `Stats` | Pure fold — totals per project / language + per-day timeline buckets. |
| `Dashboard` | SugarCraft Model — window shifts one day per press (`←/→`), reload (`r`), quit; reads through a `HeartbeatReader`. |
| `Renderer` | View — header + ranking panes + Sparkline timeline (SugarCharts); colour-ladder aware (honours the detected `ColorProfile`). |
| `Report\GapsReport` | Pure gap detection over sorted heartbeats (threshold 300 s). |
| `Backup\AutoBackup` | Copy-only archiver; temp-file-plus-rename copies under a run lock; throws loudly on any failure. |
| `Ignore\SugarTrackIgnore` | `.sugartrackignore` matcher used by `push`. |
| `Milestone` | Value object for sqlite markers. |
| `Export\CsvExporter` / `JsonExporter` / `IcalExporter` | `export` formats behind `ExporterInterface` (the tabular pair shares `TabularExporterInterface`). |
| `Support\Version` | Single-source version constant. |
| `Lang` | i18n facade (16 locales under `lang/`). |

The Store is intentionally append-only at the file level, so editor hooks can
`>>` echo a JSON line directly without coordinating with the dashboard (no
plugins ship — see *Automating collection* for the worked example) — the
trade-off is honest: raw `>>` redirection does not take the advisory lock
`push` uses, so a hand-echoed line could theoretically interleave, and it runs
none of the privacy-mode doors — the next CLI launch sweeps such files to
`0600`, but write under `umask 077` meanwhile; plugin integrations should
`flock` or just call `push`. The dashboard re-reads on
launch and on `r`; it has no clock subscription, so an open panel never
refreshes by itself. Reads degrade loudly too: a day file that exists but
cannot be opened is named in a one-line stderr warning and counted as an
empty day, never skipped in silence.

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
