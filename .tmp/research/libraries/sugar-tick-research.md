# sugar-tick Time Tracking Tools Research

**Date:** 2026-05-13
**Project:** sugar-tick (SugarCraft TakaTime port)
**Upstream:** Rtarun3606k/TakaTime
**Context:** PHP 8.3+ monorepo, privacy-first coding time tracker

---

## Executive Summary

Sugar-tick currently stores heartbeats as JSONL (one file per day under `~/.local/share/sugar-tick/`), tracks project/language/file/duration, and renders a Bubble Tea TUI dashboard. This research surveys Go, Rust, and Python time trackers to identify concrete improvements for data storage, project organization, reports, and privacy features.

**Key findings:**
- TakaTime (upstream) uses MongoDB — a significant infrastructure dependency sugar-tick avoids
- Most modern CLI trackers (tmpo, oclock, hours) use SQLite for richer queries
- Timewarrior's tag-based model is more flexible than project-only hierarchies
- Privacy features vary widely: from local-only (rtw, tmpo) to cloud-dependent (TakaTime, WakaTime)

---

## 1. Go Time Tracking Tools

### 1.1 TakaTime (Upstream) — `Rtarun3606k/TakaTime`

**Stars:** 57 | **Language:** Go | **License:** MIT

**Data Storage:**
- MongoDB (self-hosted or Atlas free tier)
- Connection string stored in `~/.local/share/nvim/taka_data.json`
- Split binaries: `taka-upload`, `taka-dashboard`, `taka-report`
- Editor plugins send heartbeats to `taka-upload` daemon → MongoDB

**Project Organization:**
- Automatic project/language/file detection from editor context
- Privacy controls: `.takaignore` and `.takatrack` files
- Tags support (via `+TAG` syntax in dashboard)

**Reports/Summaries:**
- `taka-report` binary generates GitHub README stats
- Interactive TUI dashboard (Bubble Tea) via `taka-dashboard`
- Themes: dark, light, dracula, nord, gruvbox, monokai, cyberpunk
- GitHub Actions integration for automated profile updates

**Privacy Features:**
- Self-hosted MongoDB — no third-party cloud
- Data never leaves user's infrastructure
- Interactive setup via `:TakaInit` — connection string not hardcoded

**Key Insight for sugar-tick:** TakaTime's MongoDB dependency is a dealbreaker for many users. Sugar-tick's JSONL approach is more portable but less queryable.

---

### 1.2 tmpo — `DylanDevelops/tmpo`

**Stars:** N/A (newer) | **Language:** Go | **License:** MIT

**Data Storage:**
- SQLite (local, `~/.local/share/tmpo/tmpo.db`)
- Automatic project detection from Git repos or `.tmporc` files

**Project Organization:**
- Milestone tracking (sprints, releases, project phases)
- Global projects vs per-directory projects
- Automatic project name detection

**Reports/Summaries:**
- `tmpo stats` — statistics with filtering
- Export to CSV/JSON
- Hourly rate tracking configurable

**Privacy Features:**
- 100% local storage — no network calls
- XDG base directory compliant (`$XDG_DATA_HOME/tmpo`)

**Key Insight for sugar-tick:** SQLite would enable date-range queries without loading all files. Milestone concept could extend sugar-tick's project model.

---

### 1.3 tock — `kriuchkov/tock`

**Stars:** N/A | **Language:** Go | **License:** GPL-3.0

**Data Storage:**
- **Multiple backends:** flat files, TodoTXT, TimeWarrior, SQLite
- XDG config compliant (`~/.config/tock/tock.yaml`)
- Activity log format designed for plaintext longevity

**Project Organization:**
- Project + description + notes + tags
- Calendar view for browsing entries
- Interactive TUI via Bubble Tea

**Reports/Summaries:**
- `tock report` — time tracking reports
- `tock analyze` — productivity pattern analysis
- `tock calendar` — interactive calendar view
- iCal export for calendar integration
- CSV/JSON export

**Privacy Features:**
- All storage backends are local
- No network connectivity by design

**Key Insight for sugar-tick:** Tock's multi-backend approach is over-engineered for sugar-tick, but the calendar view and productivity analysis would be high-value additions.

---

### 1.4 timetrace — `dominikbraun/timetrace`

**Stars:** 788 | **Language:** Go | **License:** Apache-2.0

**Data Storage:**
- JSON files by default (configurable)
- Reports stored as JSON

**Project Organization:**
- Projects as top-level entities (key + name)
- Records tagged with projects
- Tags via `+TAG` syntax

**Reports/Summaries:**
- `timetrace report` (beta)
- CSV/JSON export
- Filtering by project, date range

**Privacy Features:**
- Fully local — no cloud
- XDG base directory compliant

**Key Insight for sugar-tick:** Simpler than TakaTime, closer to sugar-tick's model. Could serve as reference for report generation patterns.

---

### 1.5 hours — `dhth/hours`

**Stars:** N/A | **Language:** Go | **License:** MIT

**Data Storage:**
- SQLite (internal)
- TUI-first design

**Project Organization:**
- Tasks with start/stop recording
- Task logs (manual entries)
- Deactivate/activate tasks

**Reports/Summaries:**
- `hours report` — time per task per day
- `hours stats` — aggregate statistics
- `hours log` — detailed task logs
- Theme support (6 built-in themes)
- Custom theme creation via JSON

**Privacy Features:**
- Fully local, no network
- Themes stored as JSON files

**Key Insight for sugar-tick:** Hours' TUI is the most polished of the Go trackers. The theme system is elegant and could inspire sugar-tick's dashboard customization.

---

## 2. Rust Time Tracking Tools

### 2.1 rtw — `PicoJr/rtw`

**Stars:** 44 | **Language:** Rust | **License:** Apache-2.0

**Data Storage:**
- JSON files: `~/.rtw.json` (ongoing), `~/.rtwh.json` (finished)
- No file locking — concurrent writes unsafe
- XDG config compliant

**Project Organization:**
- Simple activity tracking with start/stop
- Config file for customization

**Reports/Summaries:**
- `rtw summary` — day's activity
- `rtw day` — timeline view
- `rtw week` / `rtw month` — periodic views

**Privacy Features:**
- 100% local JSON files
- No network, no telemetry

**Key Insight for sugar-tick:** rtw's JSON dual-file approach (active vs finished) is interesting. However, JSON files lack the query capability of SQLite.

---

### 2.2 oclock — `dghilardi/oclock`

**Stars:** N/A | **Language:** Rust | **License:** MIT

**Data Storage:**
- SQLite via event-sourcing design
- Daemon-based (server/client architecture)
- Database: `~/.oclock/oclock.db`

**Project Organization:**
- Tasks created and switched between
- Retroactive task switching (correct past entries)
- PUB/SUB socket for real-time state updates

**Reports/Summaries:**
- `oclock client timesheet` — CSV daily totals
- JSON API for all commands

**Privacy Features:**
- Fully local SQLite
- No network by default

**Key Insight for sugar-tick:** The event-sourcing model would enable perfect retroactive corrections and rich history queries. The daemon architecture adds complexity but enables real-time dashboard updates without polling.

---

### 2.3 rtimelogger — `umpire274/rTimelogger`

**Stars:** N/A | **Language:** Rust | **License:** N/A

**Data Storage:**
- SQLite
- Event-based (IN/OUT pairs)
- Internal audit log
- Safe migrations with automatic backups

**Project Organization:**
- Day positions: Office, Remote, Client, Holiday, National holiday, Sick, Mixed
- Multiple locations per day
- Configurable lunch rules

**Reports/Summaries:**
- Expected exit time calculation
- Daily surplus/deficit tracking
- JSON/CSV/XLSX/PDF export
- Import from JSON/CSV

**Privacy Features:**
- Fully local
- Automatic database backups

**Key Insight for sugar-tick:** The surplus/deficit calculation and expected-exit features are work-tracking focused, not coding-time focused. However, the automatic backup system is worth adopting.

---

### 2.4 trackWork — `nicecurry/timetrack`

**Stars:** N/A (crates.io) | **Language:** Rust | **License:** MIT

**Data Storage:**
- SQLite (`~/.timetrack.db`)
- Encrypted secrets via AES-256-GCM + Argon2id

**Project Organization:**
- Issue keys with Jira sync
- Timeline visualization (vertical bar chart)
- Task management

**Reports/Summaries:**
- Weekly statistics by issue/description
- Jira issue name auto-sync
- Clipboard copy for durations

**Privacy Features:**
- API tokens encrypted at rest
- Local SQLite only
- Passphrase-based encryption

**Key Insight for sugar-tick:** The encrypted secrets pattern is relevant if sugar-tick ever adds API integrations (e.g., syncing with GitHub). The timeline visualization is a polished UX reference.

---

## 3. Python Time Tracking Tools

### 3.1 Timewarrior — `GothenburgBitFactory/timewarrior`

**Stars:** 1,572 | **Language:** C++/Python | **License:** MIT

**Data Storage:**
- Monthly data files: `${XDG_DATA_HOME}/timewarrior/data/YYYY-MM.data`
- Tags database: `tags.data`
- Undo transactions: `undo.data`
- Configuration: `${XDG_CONFIG_HOME}/timewarrior/timewarrior.cfg`
- XDG Base Directory Specification compliant (since v1.5.0)

**Project Organization:**
- **Tag-based** (not hierarchical projects)
- Intervals with start/end times
- Annotations on intervals
- Filters: `timew summary`, `timew week`, `timew month`
- Extensions directory for custom scripts

**Reports/Summaries:**
- `timew summary` — aggregate by tag
- `timew report` — custom reports
- `timew export` — JSON export
- `timew gaps` — find untracked time
- Modifiable intervals (move, shorten, lengthen, split, join)
- Holiday/exclusion support

**Privacy Features:**
- 100% local plaintext files
- No network, no telemetry
- XDG compliant

**Key Insight for sugar-tick:** Timewarrior's tag-based model is more flexible than project-only. The interval modification commands (especially `gaps` to find untracked time) are valuable. Sugar-tick's heartbeat model could benefit from tag support.

---

### 3.2 bugwarrior — `GothenburgBitFactory/bugwarrior`

**Stars:** N/A | **Language:** Python | **License:** BSD

**Data Storage:**
- Reads from Taskwarrior database
- Pulls from external issue trackers
- Configuration: `${XDG_CONFIG_HOME}/bugwarrior/bugwarriorrc` or `.toml`

**Project Organization:**
- Imports issues from: GitHub, Gitlab, Jira, Trello, Bitbucket, Pagure, and 20+ others
- Unified as Taskwarrior tasks with UDAs

**Reports/Summaries:**
- Works with Timewarrior via hooks
- `bugwarrior pull` — sync issues
- `bugwarrior uda` — export UDA definitions

**Privacy Features:**
- Local-only by default
- Issue tracker credentials stored locally
- Keyring support for credential management

**Key Insight for sugar-tick:** Bugwarrior doesn't track time itself — it's a bridge tool. However, if sugar-tick added issue tracker integration (GitHub issues → coding time), bugwarrior's service model is the reference architecture.

---

## 4. Comparative Analysis

### 4.1 Data Storage Comparison

| Tool | Storage | Queryable | Portable | Concurrent-Safe |
|------|---------|-----------|----------|-----------------|
| sugar-tick | JSONL (one file/day) | No (full scan) | Yes | Append-only safe |
| TakaTime | MongoDB | Yes | No | Yes |
| tmpo | SQLite | Yes | Yes | Yes (SQLite) |
| tock | File/SQLite/TimeWarrior | Backend-dependent | Yes | Backend-dependent |
| timetrace | JSON files | No | Yes | No |
| hours | SQLite | Yes | Yes | Yes |
| rtw | JSON (dual file) | No | Yes | No |
| oclock | SQLite | Yes | Yes | Yes (daemon) |
| rtimelogger | SQLite | Yes | Yes | Yes |
| Timewarrior | Monthly `.data` files | Partial | Yes | Yes (append-only) |

**Recommendation for sugar-tick:** Consider SQLite as an optional storage backend alongside JSONL. This would enable:
- Date-range queries without full scans
- Aggregation queries (total by project, language, day)
- Indexed lookups for dashboard rendering

Implementation: Add `SugarCraft\Tick\SqliteStore` alongside `Store`, sharing the same `Heartbeat` model.

---

### 4.2 Project Organization Comparison

| Tool | Model | Hierarchical | Tags | Auto-Detect |
|------|-------|--------------|------|-------------|
| sugar-tick | Flat (project + language) | No | No | No |
| TakaTime | Project + language + file | No | Yes (+TAG) | Yes |
| tmpo | Project + milestones | Milestones | No | Git detection |
| tock | Project + task + tags | No | Yes | No |
| timetrace | Project (keyed) + records | No | Yes | No |
| hours | Tasks + logs | No | No | No |
| rtw | Flat activity | No | No | No |
| Timewarrior | Tags only | No | Yes | No |
| bugwarrior | Issues → Tasks | No | Yes | GitHub API |

**Recommendations for sugar-tick:**
1. **Add tags** — Timewarrior's tag model is proven and flexible. Add `tags: list<string>` to `Heartbeat`.
2. **Add milestones** — tmpo's milestone concept (sprints, releases) could map to user-defined coding goals.
3. **Add project aliases** — Allow `project: "my-project"` → display name "My Project" mapping.

---

### 4.3 Reports/Summaries Comparison

| Tool | Built-in Reports | Export | Interactive | Special |
|------|-----------------|--------|-------------|---------|
| sugar-tick | Per-project/language totals, timeline | None | TUI dashboard | Sparkline chart |
| TakaTime | GitHub README, dashboard | Unknown | Yes | Profile stats |
| tmpo | Stats, weekly | CSV, JSON | No | Hourly rates |
| tock | Report, analyze | CSV, JSON, iCal | Calendar view | Productivity analysis |
| timetrace | Report (beta) | CSV, JSON | No | — |
| hours | Report, stats, log | None shown | TUI | Theme system |
| rtimelogger | Surplus/deficit | CSV, JSON, XLSX, PDF | No | Expected exit time |
| Timewarrior | Summary, week, month | JSON | No | Gaps detection |
| bugwarrior | — (sync only) | — | No | Issue aggregation |

**Recommendations for sugar-tick:**
1. **Add CSV/JSON export** — Trivial to implement, high user value. `sugar-tick export --format csv --from 2026-01-01 --to 2026-01-31`
2. **Add "gaps" detection** — Find periods with no heartbeats (like Timewarrior)
3. **Add iCal export** — Enables calendar integration (like tock)
4. **Add productivity analysis** — Compare coding time patterns across weeks

---

### 4.4 Privacy Features Comparison

| Tool | Local Storage | No Telemetry | Encryption | Self-Hosted |
|------|--------------|--------------|------------|-------------|
| sugar-tick | JSONL | Yes | No | Yes |
| TakaTime | MongoDB | Yes | No | Requires MongoDB |
| tmpo | SQLite | Yes | No | Yes |
| tock | File/SQLite | Yes | No | Yes |
| timetrace | JSON | Yes | No | Yes |
| hours | SQLite | Yes | No | Yes |
| rtw | JSON | Yes | No | Yes |
| oclock | SQLite | Yes | No | Yes |
| rtimelogger | SQLite | Yes | No | Yes |
| Timewarrior | Monthly files | Yes | No | Yes |
| WakaTime | Cloud | No | No | No |

**Recommendations for sugar-tick:**
1. **Document privacy guarantees** — Sugar-tick's JSONL is the most portable and verifiable privacy model. Make this prominent in README.
2. **Add optional encryption** — Use sodium (libsodium-php) to encrypt heartbeats at rest with a user passphrase, similar to trackWork's AES-256-GCM approach.
3. **Add .sugartrackignore** — Like `.gitignore` for privacy-sensitive projects/files.

---

## 5. Prioritized Recommendations for sugar-tick

### High Priority (Low Effort, High Impact)

| # | Improvement | Effort | Source Inspiration |
|---|-------------|--------|-------------------|
| 1 | Add CSV export | 1-2h | Timewarrior, tock, tmpo |
| 2 | Add JSON export | 1h | Same as above |
| 3 | Add tags to Heartbeat | 2-3h | Timewarrior, TakaTime |
| 4 | Add `.sugartrackignore` support | 3-4h | TakaTime's `.takaignore` |
| 5 | Add gaps detection (untracked periods) | 2-3h | Timewarrior's `gaps` command |

### Medium Priority (Moderate Effort, High Impact)

| # | Improvement | Effort | Source Inspiration |
|---|-------------|--------|-------------------|
| 6 | SQLite storage backend (optional) | 8-12h | tmpo, hours, oclock |
| 7 | Milestones / coding goals | 6-8h | tmpo's milestones |
| 8 | Add iCal export | 4-6h | tock, rtimelogger |
| 9 | Automatic database backups | 3-4h | rtimelogger's approach |
| 10 | Theme system for dashboard | 4-6h | hours' JSON themes |

### Lower Priority (Higher Effort, Significant Features)

| # | Improvement | Effort | Source Inspiration |
|---|-------------|--------|-------------------|
| 11 | Interactive calendar view | 12-16h | tock's calendar, trackWork's timeline |
| 12 | Encrypted storage at rest | 8-10h | trackWork's AES-256-GCM |
| 13 | Retroactive entry editing | 6-8h | oclock's retroactive switch |
| 14 | GitHub Actions integration (profile stats) | 8-10h | TakaTime's `taka-report` |
| 15 | Issue tracker integration | 20-30h | bugwarrior's service model |

---

## 6. Implementation Plan

### Phase 1: Export & Tags (Weeks 1-2)
```
1.1 Add tags field to Heartbeat value object
1.2 Implement TagFilter in Stats class
1.3 Add csv_export.php command
1.4 Add json_export.php command
1.5 Update Dashboard to display tags
1.6 Add .sugartrackignore parsing in Store
```

### Phase 2: Storage Backend (Weeks 3-4)
```
2.1 Design SQLite schema (heartbeats table with indexes)
2.2 Implement SqliteStore alongside JsonlStore
2.3 Add SUGARTICK_BACKEND env var for store selection
2.4 Add auto-backup on write for SQLite
```

### Phase 3: Reports & Analysis (Weeks 5-6)
```
3.1 Implement gaps detection algorithm
3.2 Add iCal generation
3.3 Add weekly productivity comparison
3.4 Implement milestone tracking
```

### Phase 4: Polish (Weeks 7-8)
```
4.1 Theme system for dashboard
4.2 Automatic daily summary (cron-friendly)
4.3 Vim/Neovim plugin integration
4.4 VS Code extension skeleton
```

---

## 7. Appendix: Data Format Reference

### Timewarrior Interval Format (`.data` files)
```json
{"start":"20260113T090000","end":"20260113T120000","tags":["project","coding"]}
{"start":"20260113T140000","end":"20260113T173000","tags":["project","meeting"]}
```

### TakaTime Heartbeat (MongoDB)
```json
{
  "time": 1704067200,
  "project": "my-project",
  "language": "go",
  "file": "/path/to/file.go",
  "duration": 60,
  "editor": "neovim"
}
```

### Sugar-tick Heartbeat (Current JSONL)
```json
{"time":1704067200,"project":"my-project","language":"go","file":"/path/to/file.go","duration":60}
```

### Proposed Extended Heartbeat (with tags)
```json
{
  "time": 1704067200,
  "project": "my-project",
  "language": "go",
  "file": "/path/to/file.go",
  "duration": 60,
  "tags": ["feature-x", "review"]
}
```

---

## 8. Citations

- TakaTime: https://github.com/Rtarun3606k/TakaTime (stars: 57, MIT)
- Timewarrior: https://github.com/GothenburgBitFactory/timewarrior (stars: 1,572, MIT)
- tmpo: https://github.com/DylanDevelops/tmpo (Go, MIT)
- tock: https://github.com/kriuchkov/tock (Go, GPL-3.0)
- timetrace: https://github.com/dominikbraun/timetrace (stars: 788, Apache-2.0)
- hours: https://github.com/dhth/hours (Go, MIT)
- rtw: https://github.com/PicoJr/rtw (stars: 44, Apache-2.0)
- oclock: https://github.com/dghilardi/oclock (Rust, MIT)
- rtimelogger: https://github.com/umpire274/rTimelogger (Rust)
- trackWork: https://crates.io/crates/trackWork (Rust, MIT)
- bugwarrior: https://github.com/GothenburgBitFactory/bugwarrior (Python, BSD)
- XDG Base Directory: https://specifications.freedesktop.org/basedir-spec/basedir-spec-latest.html
