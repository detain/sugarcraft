# Ecosystem Comparison Update Report: sugar-tick

**Package:** `sugarcraft/sugar-tick`
**Analysis Date:** 2026-05-28
**Source:** `docs/repo_map/sugarcraft_sugar-tick.md`, `docs/research/libraries/sugar-tick-research.md`, ecosystem intelligence

---

# Overview

Sugar-tick is a privacy-first coding-time tracker that ports the Go-based TakaTime to PHP, substituting MongoDB dependency with local JSONL files. It occupies a unique niche in SugarCraft as the only productivity/product analytics library and the sole application (vs. component library).

**Biggest opportunities:**
1. Interactive calendar view (high-demand feature from tock, trackWork)
2. Productivity analytics (comparative week-over-week patterns)
3. Event-sourcing model for retroactive edits (from oclock)
4. Encrypted storage at rest (from trackWork's AES-256-GCM approach)
5. GitHub Actions integration for profile stats (from TakaTime)

**Biggest missing capabilities:**
1. No retroactive heartbeat editing (can detect gaps but not fill them)
2. No interactive calendar navigation (only sparkline timeline)
3. No concurrent write safety at application level
4. No project hierarchy (flat namespace only)
5. O(n*m) timeline lookup degrades for long ranges

---

# Internal Capability Summary

## Current Architecture

```
sugar-tick/
├── src/
│   ├── Heartbeat.php         # Value object: time, project, language, file, duration, tags[]
│   ├── Milestone.php          # Named time-points (e.g., "shipped v1.0")
│   ├── Stats.php              # Pure aggregator: perProject(), perLanguage(), timeline(), totalSeconds()
│   ├── Store.php              # JSONL append-only, one file per day
│   ├── Dashboard.php          # TEA Model: init/update/view, key bindings
│   ├── Renderer.php           # Pure static view function returning ANSI string
│   ├── GapsReport.php          # Detects untracked time between heartbeats
│   ├── Ignore/SugarTrackIgnore.php  # .sugartrackignore glob pattern matching
│   ├── Backup/AutoBackup.php   # Rotating backup manager
│   ├── Export/
│   │   ├── CsvExporter.php     # RFC 4180 CSV
│   │   ├── JsonExporter.php   # JSON array
│   │   └── IcalExporter.php   # RFC 5545 iCalendar
│   ├── Storage/
│   │   └── SqliteBackend.php  # Optional SQLite with indexed queries
│   ├── Theme.php              # Dark/light theming via candy-sprinkles
│   └── Lang.php               # i18n facade (16 locales)
└── bin/sugar-tick             # CLI: push <project> <language> <file> [duration]
```

## Key Strengths

1. **Zero infrastructure** — JSONL files are the only storage dependency
2. **Editor-agnostic** — Any tool that can write to a file can push heartbeats
3. **Human-readable storage** — `cat 2026-05-27.jsonl | jq` works
4. **Immutable model** — Dashboard mutations return new instances
5. **Comprehensive export** — CSV, JSON, iCal
6. **Gaps detection** — Find untracked time periods
7. **Full i18n** — 16 locales

## Key Weaknesses

1. **No concurrent write safety** — Multiple editors writing simultaneously could corrupt JSONL
2. **No query capability (JSONL)** — Requires loading all files to scan
3. **O(n*m) timeline lookup** — Nested loop in Stats::timeline()
4. **No automatic backup integration** — AutoBackup exists but not in main CLI
5. **No interactive calendar** — Only sparkline timeline
6. **No retroactive editing** — GapsReport detects gaps but doesn't fill them
7. **Limited project hierarchy** — Flat namespace only

---

# Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|------|-----------|---------------------------|---------|
| `docs/repo_map/pr_charmbracelet_bubbletea.md` | Critical | TEA pattern (Dashboard), Cmd::tick() for animations, testing patterns | P0 |
| `docs/repo_map/pr_textualize_textual.md` | High | Reactive state, calendar view, productivity analytics, worker/timer patterns | P1 |
| `docs/research/libraries/sugar-tick-research.md` | Critical | Full ecosystem survey: tock, tmpo, Timewarrior, oclock, trackWork, hours | P0 |
| `docs/repo_map/pr_treilik_bubblelister.md` | Medium | List filtering, highlighting patterns | P2 |
| `docs/repo_map/pr_daltonsw_bubbleup.md` | Medium | Animation timing, 100ms tick intervals for LAB color lerp | P2 |
| `docs/repo_map/ECOSYSTEM_INTELLIGENCE_SUMMARY.md` | High | Cross-cutting patterns: layout, async, fuzzy filtering, plugin architecture | P1 |

---

# Feature Gap Analysis

## Critical

### 1. No Interactive Calendar View
- **Description:** Dashboard only shows sparkline timeline; no calendar navigation
- **Why it matters:** Users cannot browse historical data interactively; tock's calendar view is repeatedly requested feature
- **Source:** `docs/research/libraries/sugar-tick-research.md` — tock calendar, trackWork timeline
- **Implementation ideas:**
  - Add `CalendarView` model with month navigation
  - Use sugar-charts for daily bars in calendar grid
  - Integrate with sugar-stickers FlexBox for date grid layout
- **Complexity:** Medium — requires new view model + rendering
- **Impact:** High — competitive differentiator

### 2. No Retroactive Entry Editing
- **Description:** GapsReport detects gaps but cannot fill them; cannot adjust past heartbeats
- **Why it matters:** Users make mistakes (wrong project, forgot to track); oclock implements retroactive switching
- **Source:** `docs/research/libraries/sugar-tick-research.md` — oclock's retroactive task switching
- **Implementation ideas:**
  - Add `HeartbeatEditor` with `update()` / `delete()` methods
  - JSONL: append correction record, filter during load
  - SQLite: direct UPDATE/DELETE on indexed records
- **Complexity:** Medium — requires store API extension
- **Impact:** High — user experience killer for data correction

### 3. Concurrent Write Safety
- **Description:** Multiple editors writing to same .jsonl could cause interleaved lines
- **Why it matters:** OS-level FILE_APPEND is atomic but partial JSON lines from concurrent writes corrupt file
- **Source:** `docs/research/libraries/sugar-tick-research.md` — rtw "concurrent writes unsafe"
- **Implementation ideas:**
  - File-based locking via flock() before write
  - Or: Single-writer daemon (like TakaTime's taka-upload)
  - Or: Per-day lock files + timeout
- **Complexity:** Low — single file change
- **Impact:** Critical — data corruption risk

## High

### 4. O(n*m) Timeline Lookup Performance
- **Description:** Stats::timeline() uses nested loop (n heartbeats, m days)
- **Why it matters:** Degrades for long date ranges (>30 days)
- **Source:** `docs/repo_map/sugarcraft_sugar-tick.md` — "acceptable for 7-day windows"
- **Implementation ideas:**
  - Pre-sort heartbeats, use single-pass bucketing
  - Or: Use SQLite backend with indexed time column
  - Or: Cache day-key map for repeated queries
- **Complexity:** Low — algorithm optimization
- **Impact:** Medium — performance regression on large datasets

### 5. Project Hierarchy
- **Description:** Flat project namespace; no parent/child relationships
- **Why it matters:** Real projects have sub-projects (e.g., "myapp-api", "myapp-web")
- **Source:** `docs/research/libraries/sugar-tick-research.md` — tock project + task + tags
- **Implementation ideas:**
  - Add `parentProject` field to Heartbeat
  - Or: Project aliases mapping file
  - Display hierarchy in dashboard with indentation
- **Complexity:** Medium — data model + UI change
- **Impact:** Medium — nice-to-have for large codebases

### 6. Productivity Analytics
- **Description:** No week-over-week comparison, no patterns analysis
- **Why it matters:** Users want to see productivity trends; tock analyze command
- **Source:** `docs/research/libraries/sugar-tick-research.md` — tock analyze, rtimelogger surplus/deficit
- **Implementation ideas:**
  - Add `ProductivityReport` class: compare current week to previous
  - Calculate: total hours, per-project distribution change, streak days
  - Render as additional dashboard section or separate view
- **Complexity:** Medium — new class + rendering
- **Impact:** Medium — engagement driver

## Medium

### 7. Encrypted Storage at Rest
- **Description:** No encryption; sensitive project names visible in plaintext
- **Why it matters:** Corporate environments may require at-rest encryption; trackWork approach
- **Source:** `docs/research/libraries/sugar-tick-research.md` — trackWork AES-256-GCM + Argon2id
- **Implementation ideas:**
  - Use libsodium (ext-sodium) for encryption
  - Derive key from user passphrase via Argon2id
  - Encrypt individual Heartbeat fields or entire JSONL lines
- **Complexity:** Medium — requires crypto library
- **Impact:** Low — niche enterprise feature

### 8. GitHub Actions Integration
- **Description:** No automated profile stats generation
- **Why it matters:** TakaTime's taka-report generates GitHub README stats
- **Source:** `docs/research/libraries/sugar-tick-research.md` — TakaTime GitHub Actions
- **Implementation ideas:**
  - Add `GithubActionReport` class: generates Markdown summary
  - Output format matches GitHub README stats syntax
  - Document as separate cron job or CI step
- **Complexity:** Low — text generation only
- **Impact:** Medium — visibility/social proof

### 9. Issue Tracker Integration
- **Description:** No integration with GitHub issues, Jira, etc.
- **Why it matters:** Link coding time to specific issues; bugwarrior model
- **Source:** `docs/research/libraries/sugar-tick-research.md` — bugwarrior service model
- **Implementation ideas:**
  - Add `IssueTrackerBackend` interface
  - Implement GitHub issues fetching
  - Map issue keys → project names automatically
- **Complexity:** High — external API integration
- **Impact:** Low — niche use case

### 10. Automatic Backup Integration
- **Description:** AutoBackup exists but not integrated into main CLI
- **Why it matters:** Users must run manually or via cron
- **Source:** `docs/repo_map/sugarcraft_sugar-tick.md` — "AutoBackup exists but is not integrated"
- **Implementation ideas:**
  - Add `--backup` flag to push command
  - Or: Automatic backup after N heartbeats
  - Or: Daily backup cron suggestion in docs
- **Complexity:** Low — CLI integration
- **Impact:** Low — data safety

## Low

### 11. Tags Filtering in Dashboard
- **Description:** Tags stored but not displayed/filterable in dashboard
- **Why it matters:** Timewarrior's tag model is proven; users expect tag filtering
- **Source:** `docs/research/libraries/sugar-tick-research.md` — Timewarrior tags
- **Implementation ideas:**
  - Add tag filter input to dashboard
  - Show tags in heartbeat list view
  - Filter rankings by selected tag
- **Complexity:** Low — UI + filter logic
- **Impact:** Low — secondary feature

### 12. Hourly Rate Tracking
- **Description:** No billing/rate tracking like tmpo
- **Why it matters:** Freelancers want to track billable hours
- **Source:** `docs/research/libraries/sugar-tick-research.md` — tmpo hourly rates
- **Implementation ideas:**
  - Add `hourlyRate` field to project config
  - Calculate earnings in Stats
  - Export with earnings column
- **Complexity:** Low — data model extension
- **Impact:** Low — narrow use case

---

# Algorithm / Performance Opportunities

## Current Approach: O(n*m) Timeline Bucketing

```php
// Stats::timeline() current implementation
$dayKeys = array_map(fn($d) => $d->format('Y-m-d'), $this->days);
foreach ($this->beats as $b) {
    $stamp = (new \DateTimeImmutable('@' . $b->time))->setTimezone($tz);
    $key = $stamp->format('Y-m-d');
    // O(n*m) lookup — n heartbeats, m days
}
```

**External Approach:** Pre-sort + single-pass bucketing

```php
// hours (Go) uses sorted insertion + binary search
// oclock uses indexed SQLite queries
// Timewarrior uses monthly files with binary search within files
```

**Why external better:**
- Sorted data enables O(log n) lookup per heartbeat
- SQLite indexed queries are O(1) for range selections
- Pre-computed day index avoids repeated timezone calculations

**Tradeoffs:**
- Sorting adds O(n log n) upfront cost
- SQLite requires additional storage path
- Pre-computed index requires memory for large datasets

**Applicability to sugar-tick:**
- High — add `Stats::timelineOptimized()` using sorted beats
- Medium — SQLite backend already supports indexed queries

---

# Architecture Improvements

1. **Event-sourcing pattern** — Append heartbeat events, derive state; enables perfect retroactive edits
   - Source: `docs/research/libraries/sugar-tick-research.md` — oclock event-sourcing

2. **Storage backend interface** — Abstract `StoreInterface` to enable JSONL/SQLite/encrypted switching
   - Source: `docs/research/libraries/sugar-tick-research.md` — tock multi-backend

3. **Separate read/write models** — Dashboard is read-only; writes go through command handler
   - Source: `docs/repo_map/pr_charmbracelet_bubbletea.md` — tea.Cmd pattern

4. **Lazy initialization** — Don't compute stats until dashboard opens
   - Source: `docs/repo_map/ECOSYSTEM_INTELLIGENCE_SUMMARY.md` — "No Lazy Initialization" lesson

---

# API / Developer Experience Improvements

1. **Fluent Heartbeat building** — `Heartbeat::forProject($name)->withTags(['bug'])->withDuration(120)`
   - Source: `docs/repo_map/sugarcraft_sugar-tick.md` — "Immutable Heartbeat Value Object"

2. **Stats query builder** — `Stats::forRange($from, $to)->groupByProject()->top(10)`
   - Source: `docs/research/libraries/sugar-tick-research.md` — tock/tmpo query patterns

3. **Store abstraction** — `Store::open('jsonl')` vs `Store::open('sqlite')`
   - Source: `docs/research/libraries/sugar-tick-research.md` — tock multi-backend

4. **Dashboard as library** — Allow embedding Dashboard in larger apps
   - Source: `docs/repo_map/ECOSYSTEM_INTELLIGENCE_SUMMARY.md` — "Library-First Architecture"

---

# Documentation / Cookbook Opportunities

1. **Editor plugin templates** — Vim, Neovim, VS Code heartbeat push examples
   - Source: `docs/research/libraries/sugar-tick-research.md` — TakaTime neovim plugin

2. **Cron integration guide** — Automated daily summaries via cron
   - Source: `docs/repo_map/sugar-tick.md` — "old-school cron jobs can ship files to cold storage"

3. **Multi-machine sync** — Syncing JSONL files via rsync, Dropbox, etc.
   - Source: `docs/repo_map/sugar-tick.md` — "human-readable plaintext"

4. **Privacy hardening guide** — Optional encryption, .sugartrackignore best practices
   - Source: `docs/research/libraries/sugar-tick-research.md` — trackWork encryption approach

---

# UX / TUI Improvements

1. **Calendar navigation** — ←/→ shifts months, Enter selects day
   - Source: `docs/research/libraries/sugar-tick-research.md` — tock calendar view

2. **Interactive heartbeat editing** — Press 'e' on selected heartbeat to edit
   - Source: `docs/research/libraries/sugar-tick-research.md` — oclock retroactive switch

3. **Live dashboard updates** — Auto-reload when JSONL file changes (filesystem watcher)
   - Source: `docs/repo_map/pr_textualize_textual.md` — reactive state updates

4. **Fuzzy project filtering** — Type to filter project list like bubblelister
   - Source: `docs/repo_map/pr_treilik_bubblelister.md` — fuzzy filtering

5. **Better gaps visualization** — Highlight gaps directly in timeline view
   - Source: `docs/research/libraries/sugar-tick-research.md` — Timewarrior gaps

---

# Testing / Reliability Improvements

1. **Golden file snapshots for Dashboard** — Capture expected ANSI output
   - Source: `docs/repo_map/pr_charmbracelet_bubbletea.md` — "teatest" golden file testing

2. **Property-based testing for Heartbeat** — Fuzz random inputs to fromArray()
   - Source: `docs/repo_map/ECOSYSTEM_INTELLIGENCE_SUMMARY.md` — "No Test Coverage" lesson

3. **Concurrent write stress test** — Simulate multiple processes appending simultaneously
   - Source: `docs/research/libraries/sugar-tick-research.md` — rtw "concurrent writes unsafe"

4. **Performance regression suite** — Time Stats::timeline() on 10k+ heartbeats
   - Source: `docs/repo_map/pr_textualize_textual.md` — OptionList performance regression

---

# Ecosystem / Integration Opportunities

1. **sugar-charts integration** — Use BarChart for weekly summaries, heatmap for calendar
   - Source: `sugar-tick` already uses Sparkline — expand to full chart suite

2. **candy-core Tick subscription** — Subscribe to filesystem changes for live updates
   - Source: `docs/repo_map/pr_charmbracelet_bubbletea.md` — tick subscriptions

3. **candy-palette color detection** — Respect terminal color profile for dashboard
   - Source: `docs/repo_map/pr_charmbracelet_colorprofile.md` — OSC 2026/2027 queries

4. **Editor integrations** — Publish editor plugin SDK/guidelines
   - Source: `docs/research/libraries/sugar-tick-research.md` — TakaTime neovim plugin

5. **GitHub Actions workflow** — Publish `sugarcraft/action-sugar-tick` for automated stats
   - Source: `docs/research/libraries/sugar-tick-research.md` — TakaTime GitHub Actions

---

# Notable PRs / Issues / Discussions

### From Bubble Tea (charmbracelet/bubbletea)
- **Issue #1654:** Proposal: Testing Framework — confirms TUI testing is critical gap
  - *Lesson:* Build testing infrastructure early; VHS-based snapshot testing
- **Issue #1627:** Terminal Escape Sequence Leak — capability queries race with exit
  - *Lesson:* Handle capability responses synchronously or provide opt-out
- **Issue #831:** Textarea Slow When Pasting — memoize expensive operations
  - *Lesson:* Memoize width calculations in any text rendering

### From Textual (textualize/textual)
- **Issue #4959:** clear_panes memory leak — reference cycles in context vars
  - *Lesson:* Use weak references for parent/child references to prevent GC pressure
- **Issue #6381:** MarkdownViewer GC stutter — large widget trees cause gen2 pauses
  - *Lesson:* Avoid creating excessive child widgets; batch updates

### From Ecosystem Intelligence Summary
- **Pattern #4:** Shared Mutable Renderer State — race condition between input/render loops
  - *Lesson:* Protect shared state with mutex or immutable data structures
- **Pattern #5:** Timeout Without Cancellation — timers created but never cancelled
  - *Lesson:* Track all subscriptions; cancel in Program::destruct()

---

# Recommended Roadmap

## Immediate Wins (0-2 weeks)
1. **File locking on append** — Add flock() to Store::append() for concurrent safety
2. **Stats timeline optimization** — Pre-sort beats, single-pass bucketing
3. **Tags in dashboard** — Display and filter by tags in rankings
4. **AutoBackup CLI integration** — Add `--backup` flag to push command

## Medium-Term (2-8 weeks)
5. **Interactive calendar view** — Month navigation + day selection via sugar-stickers FlexBox
6. **Retroactive editing** — Edit/delete past heartbeats via SQLite backend
7. **Productivity analytics** — Week-over-week comparison in dashboard
8. **GitHub Actions workflow** — Publish action for automated README stats

## Major Upgrades (2-3 months)
9. **Event-sourcing model** — Append-only event log + derived state
10. **Encrypted storage** — libsodium encryption for sensitive projects
11. **Issue tracker integration** — GitHub issues → project mapping

## Experimental (3+ months)
12. **Web dashboard** — Render sugar-tick data in browser (textual-web pattern)
13. **Collaborative features** — Shared team dashboards via JSONL sync
14. **ML-based insights** — Predict productivity patterns

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|-------------|--------|------------|------|----------|
| File locking on append | Critical | Low | Low | **P0 — Immediate** |
| Stats timeline optimization | Medium | Low | Low | **P0 — Immediate** |
| Tags in dashboard | Medium | Low | Low | **P0 — Immediate** |
| AutoBackup CLI integration | Low | Low | Low | **P1 — Week 2** |
| Interactive calendar view | High | Medium | Medium | **P1 — Month 1** |
| Retroactive editing | High | Medium | Medium | **P1 — Month 1** |
| Productivity analytics | Medium | Medium | Low | **P1 — Month 1** |
| GitHub Actions workflow | Medium | Low | Low | **P2 — Month 2** |
| Event-sourcing model | High | High | High | **P2 — Month 2-3** |
| Encrypted storage | Low | Medium | Medium | **P3 — Future** |
| Issue tracker integration | Low | High | Medium | **P3 — Future** |

---

# Final Strategic Assessment

Sugar-tick represents a well-executed privacy-first time tracker that successfully differentiates from its Go upstream (TakaTime) by eliminating MongoDB dependency. Its JSONL append-only design enables true editor-agnosticism — any tool can push heartbeats without coordination. The implementation demonstrates solid software engineering: immutable value objects, pure aggregator functions, and clean separation between storage backends.

**Competitive positioning:** Sugar-tick occupies a defensible niche as the only PHP-native, privacy-first, TUI-based coding time tracker. The TakaTime upstream requires MongoDB; Timewarrior lacks a TUI; tmpo/hours are Go-only. This leaves a clear market segment: PHP developers who want local-only, editor-agnostic time tracking with a polished Bubble Tea-style interface.

**Critical risks:**
1. **Concurrent write corruption** — The most pressing reliability issue; must be addressed before production use
2. **Performance at scale** — O(n*m) timeline lookup will degrade as data grows
3. **Feature parity with Go tools** — Calendar view and retroactive editing are expected features in this category

**Key strategic recommendations:**
1. **Prioritize concurrent safety** — Add flock() immediately; consider single-writer daemon model
2. **Invest in calendar view** — This is the #1 most-requested feature and competitive differentiator
3. **Build editor plugin ecosystem** — Provide SDK/templates for Vim, Neovim, VS Code; editor integration is the growth lever
4. **Document privacy guarantees** — Make the "no network, no cloud, no telemetry" story prominent

**Verdict:** Sugar-tick is a solid v1 implementation with clear product-market fit. The immediate priorities are hardening (concurrent safety, performance) before adding features (calendar, editing). If editor plugin ecosystem develops, this could become the de facto time tracker for the PHP community.

---

*Report generated from analysis of: `docs/repo_map/sugarcraft_sugar-tick.md`, `docs/research/libraries/sugar-tick-research.md`, `docs/repo_map/ECOSYSTEM_INTELLIGENCE_SUMMARY.md`, `docs/repo_map/pr_charmbracelet_bubbletea.md`, `docs/repo_map/pr_textualize_textual.md`, `docs/repo_map/pr_treilik_bubblelister.md`, `docs/repo_map/pr_daltonsw_bubbleup.md`.*
