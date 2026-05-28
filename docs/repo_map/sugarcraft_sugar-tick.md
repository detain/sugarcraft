# sugarcraft/sugar-tick

## Metadata
- **URL:** https://github.com/sugarcraft/sugar-tick (monorepo) / upstream: https://github.com/Rtarun3606k/TakaTime
- **Language:** PHP 8.3+
- **Stars:** N/A (SugarCraft monorepo lib)
- **License:** MIT
- **Description:** Privacy-first coding-time tracker. JSONL on local disk (no cloud, no MongoDB), SugarCharts-driven TUI dashboard.

## Feature List

### Data Model
- **Heartbeat value object** (`src/Heartbeat.php`) — `time`, `project`, `language`, `file`, `duration`, `tags[]`
- **Milestone support** (`src/Milestone.php`) — named time-points (e.g., "shipped v1.0")
- **Pure aggregator Stats** (`src/Stats.php`) — `perProject()`, `perLanguage()`, `timeline()`, `totalSeconds()`

### JSONL Persistence (Primary Store)
- **Store class** (`src/Store.php`) — append-only JSONL, one file per day under `$XDG_DATA_HOME/sugar-tick/`
- `append(Heartbeat)` — `file_put_contents($file, $line, FILE_APPEND)` with auto-mkdir
- `loadDay(DateTimeImmutable)` — reads and parses one `.jsonl` file
- `loadRange($from, $to)` — iterates day-by-day, `array_merge`s results
- Tolerates corrupt lines via `json_decode` + `is_array` guard (skips invalid rows silently)
- **Intentional design**: editor plugins can `>>` echo a JSON line directly without coordination

### SQLite Backend (Optional Queryable Store)
- **SqliteBackend** (`src/Storage/SqliteBackend.php`) — alternative backend with indexed queries
- Schema: `heartbeats(id, time, project, language, file, duration, tags)` + `milestones(id, name, time, description)`
- `query($fromTime, $toTime)` — time-range queries with prepared statements
- Separate `insert(Heartbeat)` / `insertMilestone()` methods

### TUI Dashboard
- **Dashboard Model** (`src/Dashboard.php`) — implements `SugarCraft\Core\Model` (init/update/view)
- Key bindings: `←/→` shift 7-day window, `r` reload from disk, `q/Esc/Ctrl+C` quit
- `shift()` clamps to today (does not exceed current day)
- `reload()` re-reads store, recomputes Stats
- Pure read-only model — no file locks held

### Rendering
- **Renderer** (`src/Renderer.php`) — pure static view function returning ANSI string
- Three sections: header (`SugarTick [date range] total: Xh Ym`), project+language rankings side-by-side, daily sparkline timeline
- Rankings show top 6 projects/languages with duration, truncated to 14 chars
- **Sparkline integration** (`SugarCraft\Charts\Sparkline\Sparkline`) — minutes-per-day as ANSI bar chart
- Uses `candy-sprinkles` for styling: `Border::rounded()`, `Color::hex()`, `Style::new()->bold()->foreground()`

### Export
- **CsvExporter** (`src/Export/CsvExporter.php`) — RFC 4180 CSV with `time,project,language,file,duration,tags` headers
- **JsonExporter** (`src/Export/JsonExporter.php`) — JSON array of heartbeat objects
- **IcalExporter** (`src/Export/IcalExporter.php`) — RFC 5545 iCalendar VEVENT per heartbeat
  - `PRODID:-//SugarCraft//sugar-tick//EN`
  - `UID: md5($hb->file . $hb->time)@sugar-tick`
  - Maps tags → `CATEGORIES:` field
  - CRLF line endings per RFC 5545

### Privacy / Ignore
- **SugarTrackIgnore** (`src/Ignore/SugarTrackIgnore.php`) — `.sugartrackignore` glob pattern matching
- Supports basename globs (`*.log`), path prefixes (`vendor/*`), absolute paths (`/full/path`)
- Comment lines (`#`) and empty lines skipped

### Backup
- **AutoBackup** (`src/Backup/AutoBackup.php`) — rotating backup manager
- `rotate($keepDays = 30)` — copies old `.jsonl` files to backup dir with dated suffixes
- Dedup: skips if destination already exists
- `listBackups()` — glob over backup directory

### Gaps Detection
- **GapsReport** (`src/Report/GapsReport.php`) — detects untracked time between heartbeats
- Sorts heartbeats by time, computes gap = `$curr->time - ($prev->time + $prev->duration)`
- Configurable `minGapSeconds` (default 300 = 5 minutes)
- `totalUntrackedSeconds()` — sum of all gaps

### i18n
- **Lang facade** (`src/Lang.php`) — wraps `SugarCraft\Core\I18n\T` with `'tick'` namespace
- 16 locales: `en`, `fr`, `de`, `es`, `pt`, `pt-br`, `zh-cn`, `zh-tw`, `ja`, `ru`, `it`, `ko`, `pl`, `nl`, `tr`, `cs`, `ar`
- `Lang::t('cli.tick_pushed', [...])` pattern in bin script

### CLI Entry Point
- **bin/sugar-tick** — single binary entry point
- `push <project> <language> <file> [duration]` — appends heartbeat, prints confirmation
- `dashboard` (default) — runs `Program::new(Dashboard::start($store))->run()` with alt screen

---

## Key Architecture Decisions

### JSONL Append-Only Design
The store intentionally uses **append-only file writes**. This enables:
1. Editor plugins can `echo '{"time":...}>>~/.local/share/sugar-tick/2026-05-27.jsonl` without any lock coordination
2. Dashboard never blocks or holds locks — it reads at launch and on explicit `r` key
3. Old-school cron jobs can ship files to cold storage without locking concerns
4. The append-only guarantee means concurrent writes from multiple sources are safe at the OS level

### Timeline Bucketing
`Stats::timeline()` creates one bucket per day in the range, then assigns heartbeats to buckets via timezone-aware `DateTimeImmutable::setTimezone()`. This ensures the same physical day grouping regardless of timezone configuration.

### Immutable Model Pattern
`Dashboard` is a **read-only model**. All mutations (`reload()`, `shift()`) return **new instances**, following the immutable fluent pattern. This makes testing deterministic and the model safe to hold in the program loop without defensive copying.

### Storage Backend Separation
JSONL (`Store`) and SQLite (`SqliteBackend`) are **separate classes** sharing the same `Heartbeat` value object. This allows users to choose between:
- **JSONL**: portable, editor-plugins-can-append, no query capability
- **SQLite**: queryable, indexable, supports richer SQL aggregation

---

## Notable Implementation Details

### Heartbeat Coercion
`Heartbeat::fromArray()` aggressively coerces all fields: `(int)`, `(string)`, `array_filter(is_string)` for tags. Handles missing fields with sensible defaults (`time: time()`, `project/language: 'unknown'`, `duration: 60`).

### Stats Timeline Algorithm
```php
// Creates day-key map and fills buckets
$dayKeys = array_map(fn($d) => $d->format('Y-m-d'), $this->days);
$tz = $this->days[0]->getTimezone() ?? new \DateTimeZone(date_default_timezone_get());
foreach ($this->beats as $b) {
    $stamp = (new \DateTimeImmutable('@' . $b->time))->setTimezone($tz);
    $key = $stamp->format('Y-m-d');
    // O(n*m) lookup — acceptable for 7-day windows
}
```

### Gaps Detection
```php
// Detects gap between end of prev heartbeat and start of curr
$gap = $curr->time - ($prev->time + $prev->duration);
if ($gap >= $this->minGapSeconds) {
    $result[] = ['start' => $prev->time + $prev->duration, 'end' => $curr->time, 'gapSeconds' => $gap];
}
```

### Renderer Color Scheme
- Title: `#ff5f87` (pink/magenta) bold
- Date range: `#a78bfa` (lavender/purple)
- Total time: `#6ee7b7` (mint green) bold
- Project names: `#fde68a` (amber/gold)
- Language names: `#7dd3fc` (sky blue)
- Help text: `#7d6e98` (muted purple)

---

## JSONL Persistence Design

### File Naming Convention
```
~/.local/share/sugar-tick/
  2026-05-27.jsonl
  2026-05-26.jsonl
  2026-05-25.jsonl
  ...
```

Each file contains one JSON object per line, one heartbeat per line:
```json
{"time":1704067200,"project":"my-project","language":"go","file":"/path/to/file.go","duration":60}
```

### Append Semantics
```php
// In Store::append()
$line = json_encode($hb->toArray(), JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($file, $line, FILE_APPEND);
// No ftruncate/rewind — purely additive
```

### Graceful Corruption Handling
```php
// In Store::loadDay()
foreach (explode("\n", (string) file_get_contents($file)) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $decoded = json_decode($line, true);
    if (is_array($decoded)) {  // Only accepts arrays, not null/false from invalid JSON
        $rows[] = Heartbeat::fromArray($decoded);
    }
    // Invalid lines silently skipped — file remains readable
}
```

---

## Privacy-First Approach

### No Cloud Dependency
- TakaTime (upstream) requires MongoDB — self-hosted or Atlas cloud
- sugar-tick uses **local files only** — `$XDG_DATA_HOME/sugar-tick/` or `~/.local/share/sugar-tick/`
- No network calls, no telemetry, no third-party services

### Data Portability
- JSONL is **human-readable plaintext** — can be inspected with `cat`, `grep`, `jq`
- No database lock-in — files can be copied, backed up, or processed with any tool
- Can be version-controlled if desired (though large repos may have many files)

### Privacy Controls
- `.sugartrackignore` — glob-based file/project exclusion (like `.gitignore` for time tracking)
- **No editor/plugin requirement** — the JSONL format is self-documenting and tool-agnostic
- Data never leaves the local machine unless user explicitly copies files

### Encryption Path (Future)
The research doc (`docs/research/libraries/sugar-tick-research.md`) recommends libsodium encryption for future optional at-rest encryption, similar to `trackWork`'s AES-256-GCM approach.

---

## Comparison with Upstream (Rtarun3606k/TakaTime)

| Aspect | TakaTime (Upstream) | sugar-tick (Port) |
|--------|---------------------|------------------|
| **Language** | Go | PHP 8.3+ |
| **Storage** | MongoDB (self-hosted or Atlas) | JSONL on local disk + optional SQLite |
| **Editor Plugins** | Yes (Neovim plugin sends to `taka-upload` daemon) | JSONL append protocol (any tool can `>>`) |
| **Dashboard** | Bubble Tea TUI | SugarCraft TUI (Bubble Tea port) |
| **Reports** | `taka-report` (GitHub README stats) | Built-in dashboard + CSV/JSON/iCal export |
| **Themes** | 7 built-in (dark/light/dracula/nord/gruvbox/monokai/cyberpunk) | Configurable via `candy-sprinkles` Theme |
| **Privacy** | Self-hosted MongoDB (data leaves machine) | Local-only JSONL (no network) |
| **GitHub Actions** | Yes (`taka-report` integration) | Future (see roadmap) |
| **Tags** | Yes (`+TAG` syntax) | Yes (`tags[]` array on Heartbeat) |
| **Milestones** | Unknown | Yes (`Milestone` value object) |

---

## Comparison with Other Time Trackers

### vs. Timewarrior (GothenburgBitFactory/timewarrior)
| Aspect | Timewarrior | sugar-tick |
|--------|-------------|------------|
| **Storage** | Monthly `.data` files (append-only) | Daily `.jsonl` files (append-only) |
| **Model** | Tag-based intervals (start/end) | Heartbeat samples (time + duration) |
| **Queries** | `timew summary` aggregate by tag | `Stats::perProject()` / `perLanguage()` |
| **Gaps** | `timew gaps` command | `GapsReport` class |
| **Export** | JSON | CSV, JSON, iCal |
| **i18n** | No | 16 locales |

### vs. tmpo (DylanDevelops/tmpo)
| Aspect | tmpo | sugar-tick |
|--------|------|------------|
| **Storage** | SQLite | JSONL (primary) + SQLite (optional) |
| **Projects** | Git detection + `.tmporc` | Manual project naming |
| **Milestones** | Yes (sprints, releases) | Yes (named time-points) |
| **Export** | CSV, JSON | CSV, JSON, iCal |
| **Privacy** | Local SQLite | Local JSONL |

### vs. tock (kriuchkov/tock)
| Aspect | tock | sugar-tick |
|--------|------|------------|
| **Storage** | File / SQLite / TimeWarrior (multiple backends) | JSONL (primary, single backend) |
| **Reports** | `report`, `analyze`, calendar view | Dashboard + export |
| **Calendar** | Interactive TUI calendar | Future (see roadmap) |
| **Privacy** | All backends local | Local JSONL |

---

## Strengths

1. **Zero Infrastructure Dependency** — No MongoDB, no SQLite required. JSONL files are the only dependency.
2. **Editor-Agnostic** — Any tool that can write to a file can push heartbeats. No daemon or plugin required.
3. **Human-Readable Storage** — `cat 2026-05-27.jsonl | jq` to inspect a day's activity.
4. **Immutable Model** — Dashboard is a pure value object; mutations return new instances.
5. **Comprehensive Export** — CSV, JSON, and iCal cover all common integration needs.
6. **Gaps Detection** — Find untracked time periods (like Timewarrior's `gaps` command).
7. **Milestone Tracking** — Mark significant coding achievements.
8. **XDG Compliant** — Respects `$XDG_DATA_HOME` with `~/.local/share` fallback.
9. **Full i18n** — 16 locales following `LOCALES.md` recommended set.
10. **Immutable Heartbeat Value Object** — `withTags()` returns new instance; `fromArray()` / `toArray()` for round-trip.

---

## Weaknesses

1. **No Concurrent Write Safety** — Multiple editors writing to same `.jsonl` file simultaneously could cause interleaved lines. OS-level `FILE_APPEND` is atomic but partial JSON lines from concurrent writes could corrupt the file.
2. **No Query Capability (JSONL)** — Finding all heartbeats for a specific project requires loading all files and scanning. SQLite backend mitigates this but adds complexity.
3. **O(n*m) Timeline Lookup** — `Stats::timeline()` uses nested loop (n heartbeats, m days). Acceptable for 7-day windows but would degrade for longer ranges.
4. **No Automatic Backup** — `AutoBackup` exists but is not integrated into the main CLI. User must run manually or via cron.
5. **No Interactive Calendar View** — Dashboard shows a sparkline timeline but lacks tock's interactive calendar navigation.
6. **No Retroactive Editing** — Cannot adjust past heartbeats. `GapsReport` detects gaps but does not fill them.
7. **Limited Project Hierarchy** — Flat project name space; no parent/child project relationships.

---

## SugarCraft Ecosystem Integration

### Dependencies
```json
{
  "require": {
    "php": ">=8.3",
    "sugarcraft/candy-core": "dev-master",
    "sugarcraft/candy-sprinkles": "dev-master",
    "sugarcraft/sugar-charts": "dev-master"
  }
}
```

### Integration Points
| sugar-tick Component | Uses | Notes |
|--------------------|------|-------|
| `Dashboard` | `SugarCraft\Core\Model` | TEA pattern (init/update/view) |
| `Dashboard::view()` | `Renderer` | Pure static function |
| `Renderer::timeline()` | `SugarCraft\Charts\Sparkline\Sparkline` | ANSI sparkline chart |
| `Renderer` styling | `SugarCraft\Sprinkles\{Border,Color,Layout,Position,Style}` | Fluent style builders |
| `Theme` | `SugarCraft\Sprinkles\Theme` | Dark/light theme wrapper |
| `Lang` | `SugarCraft\Core\I18n\T` | i18n namespace `'tick'` |

---

## Testing Coverage

### Test Files (14 total)
| Test | Coverage |
|------|----------|
| `HeartbeatTest` | 157 lines — construction, fromArray/toArray, tags, coercion |
| `StoreTest` | 90 lines — append/load round-trip, missing day, range merge, corrupt line skip |
| `StatsTest` | 79 lines — perProject sorted desc, perLanguage sum, timeline bucketing, formatHours |
| `DashboardTest` | 314 lines — key bindings (q/Escape/Ctrl+C/r/←/→), reload, shift clamp-to-today, view snapshot |
| `GapsReportTest` | 97 lines — empty/single/contiguous/gap detection, custom threshold, unsorted |
| `MilestoneTest` | 64 lines — construction, fromArray/toArray, round-trip |
| `ThemeTest` | (read from file) |
| `RendererTest` | (read from file) |
| `SugarTrackIgnoreTest` | 89 lines — comment/empty-line skip, basename/path glob matching, multiple patterns |
| `AutoBackupTest` | 121 lines — rotate old files, skip recent, deduplication, listBackups |
| `Export/IcalExporterTest` | 103 lines — single/multiple/empty export, custom PRODID, UID uniqueness, no-categories-when-empty |
| `Export/CsvExporterTest` | (read from file) |
| `Export/JsonExporterTest` | (read from file) |
| `Storage/SqliteBackendTest` | 107 lines — insert/query, range boundary, multiple ordered by time, milestones |

### Test Pattern
- **Unit tests**: `Heartbeat`, `Stats`, `Milestone`, `GapsReport`, `SugarTrackIgnore`, `AutoBackup`
- **Snapshot tests**: `Dashboard::view()` asserts on expected strings (no raw SGR bytes in snapshot)
- **Behaviour tests**: `Dashboard::update()` drives with scripted `KeyMsg` objects, asserts `[Model, ?Cmd]` tuples
- **Coercion tests**: `Heartbeat::fromArray()` handles missing fields, type coercion, non-string tags filter

---

## Research & Roadmap Context

The comprehensive research document at `docs/research/libraries/sugar-tick-research.md` surveys:
- Go: TakaTime, tmpo, tock, timetrace, hours
- Rust: rtw, oclock, rtimelogger, trackWork
- Python: Timewarrior, bugwarrior

Key recommendations already implemented:
- [x] Tags field in Heartbeat (v1)
- [x] CSV/JSON export (v1)
- [x] .sugartrackignore (v1)
- [x] Gaps detection (v1)
- [x] Milestone tracking (v1)
- [x] iCal export (v1)
- [x] Auto-backup (v1)
- [x] SQLite backend (v1)

Future roadmap items from research:
- [ ] Calendar view (interactive)
- [ ] Encryption at rest (libsodium)
- [ ] Retroactive entry editing
- [ ] GitHub Actions integration (profile stats)
- [ ] Issue tracker integration (bugwarrior model)

---

## SugarCraft Mapping

### Direct Mappings (sugar-tick → SugarCraft ecosystem)

| sugar-tick | SugarCraft | Notes |
|---|---|---|
| TUI Dashboard | `candy-core` | `Model` interface, `Program` runner |
| Sparkline chart | `sugar-charts` | `Sparkline` component |
| Styling | `candy-sprinkles` | `Border`, `Color`, `Style`, `Layout` |
| Theming | `candy-sprinkles` | `Theme::dark()` / `Theme::light()` |
| i18n | `candy-core` | `Core\I18n\T` with per-namespace facade |

### Not Mapped (external ecosystem)

| Tool | Domain | Notes |
|------|--------|-------|
| TakaTime (upstream) | Go + MongoDB | Different language + infrastructure |
| Timewarrior | C++ + monthly `.data` files | Tag-based model, different storage |
| tmpo | Go + SQLite | Similar but Go-centric |
| tock | Go + multi-backend | Over-engineered for sugar-tick scope |

---

## Analysis

Sugar-tick represents a pragmatic privacy-first approach to coding time tracking. By choosing JSONL over MongoDB (upstream's choice), it eliminates the most significant barrier to adoption: infrastructure dependency. Any tool that can write to a file can push a heartbeat, making the system truly editor-agnostic and portable.

The architecture demonstrates several sound patterns: the **append-only file design** enables coordination-free writes from multiple sources, the **immutable model** ensures testability and predictability, and the **pure aggregator Stats class** separates computation from I/O. The integration with `sugar-charts` via `Sparkline` shows how the SugarCraft ecosystem components compose cleanly.

The research document reveals the project was planned against a thorough survey of the time-tracking ecosystem, and many "high priority" recommendations (tags, CSV/JSON export, gaps detection, `.sugartrackignore`, milestones) are already implemented in v1. The remaining items (calendar view, encryption, retroactive editing) are appropriately marked as lower priority due to higher effort.

The primary limitation is the **O(n*m) timeline lookup** in `Stats::timeline()` and the lack of **concurrent write safety** at the application level (though `FILE_APPEND` is atomic at the OS level, interleaved partial writes from truly concurrent sources could corrupt JSON lines). The SQLite backend addresses the query capability gap but adds a second storage path to maintain.

Overall, sugar-tick achieves its goal of a privacy-first, zero-dependency, SugarCraft-native coding time tracker that can serve as the foundation for more sophisticated productivity analysis tools built on the same data model.
