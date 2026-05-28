# Overview

**candy-vt** is an in-memory virtual terminal emulator that parses ANSI byte streams into a cell grid with cursor, mode, SGR style, and hyperlink state. It is a PHP port of `charmbracelet/x/vt` and serves as the terminal emulator behind `candy-vcr`'s render pipeline. The library provides two entry points: a full VT500 emulator (mutable, feed-in-place) and a lightweight renderer (immutable, fluent, for the vcr path).

**Biggest opportunity areas:**
1. **Truecolor support in the lightweight renderer path** — `CsiHandlerImpl` lacks truecolor SGR (`38;2;R;G;B`)
2. **Mouse event emission** — Mode flags are tracked but no actual mouse events are emitted to consumers
3. **DCS/SOS/PM/APC dispatch** — Currently no-op, limiting protocol completeness
4. **Performance optimization** — Per-byte PHP iteration could benefit from batching strategies

**Biggest missing capabilities:**
1. **DECRTMM (margin mode queries)** — Mode request/response not implemented
2. **Soft terminal reset (DECSTR)** — `CSI !p` not implemented
3. **Character set selection (G0/G1/G2/G3)** — Not wired
4. **BiDi/RTL rendering** — Not implemented (not in upstream either)
5. **Window manipulation (OSC 224-229)** — Not implemented

---

# Internal Capability Summary

## Current Architecture

candy-vt implements a two-tier architecture:

### Full VT Emulator (`SugarCraft\Vt\Terminal\Terminal`)
- **Parser:** Paul Williams VT500 state machine, direct port from `charmbracelet/x/ansi/parser`
- **Handler:** `ScreenHandler` orchestrates Buffer/Cursor/Sgr/Mode mutations
- **Feed style:** Mutable, in-place — `feed(string $bytes): void`
- **State:** Full VT500 compliance including DECAWM, DECOM, synchronized output, bracketed paste, alt screen (3 variants), focus events
- **Grid:** `Buffer` (mutable `array<int, array<int, Cell>>`)

### Lightweight Renderer (`SugarCraft\Vt\Terminal` root namespace)
- **Parser:** Same VT500 state machine via `HandlerAdapter` + `CsiHandlerImpl` + `OscHandlerImpl`
- **Feed style:** Immutable fluent — `feed(string $bytes): self` returns NEW instance
- **State:** Simplified subset — no hyperlinks, BCE, sync output, alt screen, tab stops, focus events, clipboard events
- **Grid:** `CellGrid` (immutable with dirty-region tracking)

## Current Features

| Feature | Full Path | Renderer Path |
|---------|-----------|---------------|
| VT500 state machine | ✅ | ✅ |
| CSI dispatch (all final bytes) | ✅ | Partial |
| OSC 0/1/2 (title) | ✅ | ✅ |
| OSC 4 (palette) | ✅ | ❌ |
| OSC 8 (hyperlink) | ✅ | ❌ |
| OSC 52 (clipboard) | ✅ | ❌ |
| SGR (full, including underline styles) | ✅ | ❌ truecolor |
| DECAWM auto-wrap | ✅ | ✅ |
| DECOM origin mode | ✅ | ✅ |
| Synchronized output (DEC 2026) | ✅ | ❌ |
| Bracketed paste (DEC 2004) | ✅ | ❌ |
| Alt screen (DEC 47/1047/1049) | ✅ | ❌ |
| Focus events (DEC 1004) | ✅ (recording) | ❌ |
| Mouse modes (DEC 1000-1015) | ✅ (flags only) | ❌ |
| Wide chars + combining | ✅ | ❌ |
| Scrollback ring buffer | ✅ | ❌ |
| BCE (background color erase) | ✅ | ❌ |
| Tab stops | ✅ | ❌ |
| DCS/SOS/PM/APC dispatch | ❌ No-op | ❌ No-op |

## APIs

**Terminal facade (full path):**
```php
Terminal::create(cols: 80, rows: 24, ?scrollbackSize): self
feed(string $bytes): void
screen(): Screen
cursor(): Cursor
mode(): Mode
windowTitle(): ?string
palette(): array
clipboardEvents(): array
resize(int $cols, int $rows): void
enableAltScreen() / disableAltScreen() / isAltScreen()
with*() builders for Buffer/Cursor/Mode/WindowTitle/TabStops/ScrollbackSize
```

**Renderer facade (root namespace):**
```php
new(int $cols, int $rows, ?Theme): self
feed(string $bytes): self  // Returns NEW instance
snapshot(float $time): Snapshot
cursor(): Cursor
grid(): CellGrid
windowTitle(): string
```

## Rendering Systems

1. **Cell Grid:** `Buffer` (mutable) vs `CellGrid` (immutable + dirty-region tracking)
2. **Cell representation:** Full path has `Cell` with `grapheme`, `?Sgr`, `?Hyperlink`, `combining`, `continuation`; Renderer path uses simplified `Cell` with `char`, `fg`, `bg`, `attrs` bitfield
3. **Dirty-region tracking:** `CellGrid` tracks `minRow/maxRow/minCol/maxCol` bounding box to avoid full-grid scans

## Extension Systems

- `Parser\CsiHandler` interface — plug in custom CSI handlers
- `Parser\OscHandler` interface — plug in custom OSC handlers
- `Parser\Handler` interface — combines CSI + OSC into parser driver
- `HandlerAdapter` — wires `CsiHandlerImpl` + `OscHandlerImpl` to `Handler`

## Strengths

1. **Faithful upstream port** — 1:1 mapping of VT500 state machine
2. **Comprehensive test coverage** — 36 test files covering parser, handlers, modes, integration
3. **Immutable + fluent patterns** — All state objects use `with*()` pattern
4. **Dual entry-points** — Separates full VT emulation from lightweight renderer path
5. **Real upstream usage** — Powers candy-vcr's render pipeline
6. **UTF-8 synthetic state** — PHP-specific `State::Utf8` handles multi-byte characters cleanly

## Weaknesses

1. **🟡 Incomplete status** — DCS no-op, mouse event emission, truecolor in renderer path
2. **PHP performance** — Per-byte iteration, immutable CellGrid copies on write
3. **Renderer path is simplified** — Missing many features from full path
4. **Resize in alt screen** — Only resizes active buffer, not saved main buffer
5. **No Windows ConPTY support** — Not a target for this library

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|---------------------------|----------|
| `charmbracelet/x/vt` | Direct upstream | Full VT500 implementation, state machine | Critical |
| `charmbracelet/bubbletea` | Downstream user | Uses x/vt as backend; elm architecture | High |
| `charmbracelet/sequin` | Comparison | ANSI debugging/decoding; golden file testing | High |
| `ratatui/ratatui` | Comparison | Buffer diffing, widget system, layout algorithm | Medium |
| `php-tui/php-tui` | Comparison | PHP TUI port of ratatui; buffer/cell model | Medium |
| `charmbracelet/vhs` | Downstream user | Uses x/vt for frame capture; tape DSL | High |
| `pterm/pterm` | Comparison | Comprehensive terminal output library | Medium |
| `blacktop/go-termimg` | Integration | Terminal image rendering, CSI queries | Low |
| `charmbracelet/lipgloss` | Downstream | Uses x/ansi for styling; Style system | Medium |
| `charmbracelet/glamour` | Integration | Markdown rendering with ANSI output | Low |

---

# Feature Gap Analysis

## Critical Priority

### 1. Truecolor in CsiHandlerImpl (Renderer Path)

**Description:** `CsiHandlerImpl::sgrExtended()` only handles 256-color (kind=5), not truecolor (kind=2). Upstream `charmbracelet/x/vt` supports `38;2;R;G;B` and `48;2;R;G;B`.

**Why it matters:** Many modern terminal applications use truecolor for smooth gradients and precise color matching. Without truecolor, the renderer path produces incorrect color output for applications that emit RGB values.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:661`, `charmbracelet_bubbletea.md:30`

**Implementation ideas:**
1. Extend `CsiHandlerImpl::sgrExtended()` to handle kind=2 with 5 parameters (R, G, B)
2. Store truecolor as packed integer in `Cell::$fg`/`$bg` with a sentinel value indicating truecolor mode
3. Add `Cell::TRUE_COLOR_FLAG` constant and decode at render time

**Estimated complexity:** Low — ~20 lines in `CsiHandlerImpl::sgrExtended()`

**Expected impact:** High — enables correct color rendering for most modern TUI applications

---

### 2. Mouse Event Emission

**Description:** Mode flags for mouse tracking (`mouseSgr`, `mouseAny`, etc.) are set but no handler actually emits mouse events to a consumer.

**Why it matters:** The parser tracks that mouse modes are active but provides no way for applications to receive mouse coordinate data. This makes candy-vt unsuitable as a complete terminal emulator for interactive applications.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:539`, `charmbracelet_bubbletea.md:22`

**Implementation ideas:**
1. Create `MouseMsg` value object with X, Y, button, modifier fields
2. Add `ScreenHandler::mouseEvent(MouseMsg $msg)` that appends to `$mouseEvents[]`
3. Wire `Terminal::mouseEvents()` accessor to expose accumulated events
4. Handle SGR coordinate format (DEC mode 1006) in mouse coordinate parsing

**Estimated complexity:** Medium — requires understanding of mouse protocol encoding

**Expected impact:** High — enables interactive TUI applications using candy-vt

---

### 3. DCS Dispatch Implementation

**Description:** `ScreenHandler::dcsDispatch()` is explicitly a no-op per CALIBER_LEARNINGS: "DCS dispatch is scoped to later slices."

**Why it matters:** Some terminal applications use DCS sequences for device control (e.g., termcap/terminfo queries via DCS q). Incomplete DCS support limits compatibility.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:541`

**Implementation ideas:**
1. Implement basic DCS q (termcap) parsing similar to `charmbracelet/sequin` (`dcsHandlers map` in `handlers.go`)
2. Store DCS payload in `ScreenHandler::$dcsPayload` for later retrieval
3. Add `Terminal::dcsPayload(): ?string` accessor

**Estimated complexity:** Medium — requires understanding of DCS protocol structure

**Expected impact:** Medium — improves compatibility with specialized terminal tools

---

## High Value Priority

### 4. Wide-Char in Renderer Path

**Description:** `CsiHandlerImpl` doesn't handle wide characters. It should consult `SugarCraft\Core\Util\Width` and write continuation cells.

**Why it matters:** CJK characters and many emoji occupy 2 cells. Without wide-char handling, the renderer path miscalculates cursor position and overwrites adjacent cells.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:663`

**Implementation ideas:**
1. In `CsiHandlerImpl::printable()`, call `Width::string($rune)` before writing
2. If width >= 2, write continuation cell with `Cell::$continuation = true`
3. Advance cursor by full width

**Estimated complexity:** Low — reuse existing `Width` utility

**Expected impact:** High — correct display of international text and emoji

---

### 5. DECRTMM (Margin Mode Queries)

**Description:** The margin mode queries (`CSI ?s` / `CSI ?t`) are not implemented.

**Why it matters:** Applications that query terminal capabilities need DECRTMM to determine margin mode state.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:545`

**Implementation ideas:**
1. Add `Mode::$marginMode` boolean field
2. Handle `CSI ?s` (query) in `ModeHandler::csiDispatch()` returning mode state via OSC
3. Handle `CSI ?t` (set) to update margin mode

**Estimated complexity:** Low — straightforward mode handling

**Expected impact:** Medium — improves terminal capability detection

---

### 6. Soft Terminal Reset (DECSTR)

**Description:** `CSI !p` (DECSTR) reset sequence not implemented.

**Why it matters:** Many applications send DECSTR to reset the terminal to a clean state. Without it, terminals retain incorrect state.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:548`

**Implementation ideas:**
1. Add `ScreenHandler::softReset()` that:
   - Resets SGR to defaults
   - Clears tab stops
   - Resets scroll region to full buffer
   - Resets origin mode
   - Enables auto-wrap
   - Shows cursor

**Estimated complexity:** Low — composition of existing reset operations

**Expected impact:** Medium — improves application compatibility

---

### 7. Synchronized Output in Renderer Path

**Description:** The renderer path doesn't implement DEC 2026 synchronized output batching.

**Why it matters:** Many applications (especially editors like vim) rely on synchronized output mode to prevent mid-sequence screen updates.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:567`

**Implementation ideas:**
1. Add `$syncUpdate` flag to renderer path state
2. When active, queue mutations instead of applying immediately
3. On mode disable, replay all queued mutations

**Estimated complexity:** Medium — requires mutation queue infrastructure

**Expected impact:** High — proper editor behavior

---

## Medium Priority

### 8. DECUDK (User-Defined Keys)

**Description:** Not implemented. Allows applications to customize key sequences.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:547`

### 9. Window Manipulation (OSC 224-229)

**Description:** Not implemented. These sequences allow resizing terminal windows.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:549`

### 10. Character Set Selection (G0/G1/G2/G3)

**Description:** Not wired. The escape sequence handlers for character set designation are not implemented.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:551`

### 11. Hyperlink in Renderer Path

**Description:** `CsiHandlerImpl` only handles title, not OSC 8 hyperlinks.

**Why it matters:** Many modern TUIs use OSC 8 hyperlinks for clickable elements.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:561`

**Implementation ideas:**
1. Add `OscHandlerImpl::hyperlink($uri, $id)` method
2. Track `$currentHyperlink` state in renderer handler
3. Attach hyperlink to cells during `printable()`

**Estimated complexity:** Medium — requires state tracking in handler

**Expected impact:** Medium — enables clickable TUI elements

---

### 12. BCE (Background Color Erase) in Renderer Path

**Description:** `CsiHandlerImpl` doesn't handle BCE mode for erase operations.

**Why it matters:** Erased cells should inherit the current SGR background color when BCE is active.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:562`

### 13. Focus Events in Renderer Path

**Description:** Not implemented in renderer path.

**Why it matters:** Applications that use focus events (mode 1004) won't work correctly.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:563`

### 14. Clipboard Events in Renderer Path

**Description:** OSC 52 clipboard handling is full-path only.

**Why it matters:** Clipboard access is a common TUI requirement.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:564`

### 15. Alt Screen in Renderer Path

**Description:** Not implemented in renderer path.

**Why it matters:** Many TUI applications use alternate screen buffer.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:563`

---

## Low Priority

### 16. Tab Stops in Renderer Path

**Description:** Not implemented. Tab handling is full-path only.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:563`

### 17. DECSCA (Select Character Protection Attribute)

**Description:** Erase protected characters not implemented.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:553`

### 18. DECelf (Locator)

**Description:** Not implemented. Locator input for precise pointing.

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:555`

### 19. BiDi/RTL Support

**Description:** Not implemented (not in upstream either).

**Source:** `docs/repo_map/sugarcraft_candy-vt.md:557`

---

# Algorithm / Performance Opportunities

## Current Approach vs External Approach

### 1. Byte-Level Iteration (candy-vt) vs Chunked Processing (sequin)

**candy-vt approach:** `Parser::feed()` iterates `ord($bytes[$i])` per character.

**External approach:** `charmbracelet/sequin` uses `ansi.GetParser()` / `ansi.DecodeSequence()` with pool pattern for incremental streaming parsing (`docs/repo_map/charmbracelet_sequin.md:116`).

**Why external is better:** Pool pattern reduces allocation pressure; chunked parsing can handle partial sequences more gracefully.

**Tradeoffs:** PHP lacks Go's goroutines and object pooling primitives; similar optimization would require careful memory management.

**Applicability:** Medium — would improve performance for high-throughput parsing scenarios

---

### 2. CellGrid Copy-on-Write vs ratatui's Buffer Diffing

**candy-vt approach:** Immutable CellGrid copies entire grid on every `set()`.

**External approach:** `ratatui/ratatui` uses immediate-mode rendering with buffer diffing — only changed cells are written to terminal (`docs/repo_map/ratatui_ratatui.md:95-109`).

**Why external is better:** Ratatui's approach computes minimal diff between current and previous buffer, writing only changed cells. This is highly efficient for large terminal buffers.

**Tradeoffs:** Ratatui is a rendering library, not an emulator. candy-vt must maintain accurate state for correctness; performance optimization is secondary.

**Applicability:** Medium — the dirty-region tracking in CellGrid is already a step in this direction

---

### 3. PHP Per-Byte Parsing vs Go's Native Performance

**candy-vt approach:** PHP bytecode interpreter processes bytes one at a time through the transition table.

**External approach:** Native Go performs the same operations with ~10-100x better per-byte throughput.

**Why external is better:** Go compiles to native code; PHP interpreter overhead per byte is significant for large streams.

**Tradeoffs:** This is inherent to PHP vs Go. sugarcraft/vt cannot match upstream performance but can optimize within PHP's constraints.

**Applicability:** Low — fundamental language limitation

---

### 4. Fuzzer-Based Testing vs Golden File Testing

**candy-vt approach:** `FuzzerTest` uses deterministic RNG to generate random bytes; `SnapshotTest` uses binary `.ansi` fixtures (`docs/repo_map/sugarcraft_candy-vt.md:505`, `docs/repo_map/sugarcraft_candy-vt.md:517`).

**External approach:** `charmbracelet/sequin` uses `x/exp/golden` for snapshot testing with per-kind test data maps (`docs/repo_map/charmbracelet_sequin.md:112`).

**Why external is better:** Golden file testing provides exact expected output for each sequence type; easier to diagnose failures and add regression cases.

**Tradeoffs:** candy-vt's fixture approach is similar; the main improvement would be standardizing on golden file format.

**Applicability:** Medium — improve test maintainability

---

# Architecture Improvements

## 1. Unified Cell Representation

**Current state:** Two separate `Cell` classes — one in `src/Cell/Cell.php` (full path) and one in `src/Cell.php` (renderer path).

**Improvement:** Consider a unified `Cell` that can represent both full SGR state and simplified fg/bg/attrs. The renderer path's simplified cell could be a performance optimization, but the divergence makes code sharing difficult.

**Risk:** Low — internal refactoring only

---

## 2. Transition Table Caching

**Current state:** Transition table generated at first use via `Transitions::build()` and cached in static.

**Improvement:** Consider pre-generating and embedding the transition table as a constant to avoid runtime computation on first use. Could also cache in APCu/opcache for repeated instantiation (`docs/repo_map/sugarcraft_candy-vt.md:679`).

**Risk:** Low — cache hit is perfect after first generation

---

## 3. Handler Interface Segregation

**Current state:** `Handler` interface combines CSI + OSC, but implementations often only need one or the other.

**Improvement:** Follow `charmbracelet/sequin`'s pattern of separate `csiHandlers map`, `oscHandlers map`, `dcsHandlers map` with type-safe dispatch (`docs/repo_map/charmbracelet_sequin.md:38-44`).

**Risk:** Low — improves extensibility

---

# API / Developer Experience Improvements

## 1. Snapshot Equality Semantics

**Current:** `Snapshot::equals()` compares grid + cursor; `equalsWithTime()` adds time comparison.

**Improvement:** Add `equalsIgnoringTime()` for common dedup case where frame content matters but timing doesn't.

**Risk:** Low — additive API

---

## 2. Terminal Event Accessors

**Current:** Focus events accessible via `$vt->{internal handler reference}->focusEvents`; no mouse event accessor.

**Improvement:** Add `Terminal::focusEvents(): array` and `Terminal::mouseEvents(): array` accessors for cleaner consumer API.

**Risk:** Low — additive API

---

## 3. Resize Behavior Documentation

**Current:** CALIBER_LEARNINGS notes resize in alt screen mode only resizes active buffer (`docs/repo_map/sugarcraft_candy-vt.md:535`).

**Improvement:** Document this as a known limitation with expected behavior. Consider whether this is intentional or worth fixing.

**Risk:** Low — documentation only

---

# Documentation / Cookbook Opportunities

## 1. ANSI Sequence Reference

**Opportunity:** Add a comprehensive ANSI sequence reference to the README, similar to `charmbracelet/sequin`'s coverage (`docs/repo_map/charmbracelet_sequin.md`).

**Content:** Complete coverage of CSI, OSC, DCS, ESC sequences with examples.

---

## 2. Protocol Debugging Guide

**Opportunity:** Document how to use candy-vt to debug ANSI sequences from real terminal applications.

**Content:** Show how to capture bytes, feed them through Terminal, and inspect the resulting screen state.

---

## 3. Rendering Pipeline Diagram

**Opportunity:** Add architecture diagram showing the flow from bytes → parser → handler → buffer → screen.

**Content:** ASCII diagram with code references.

---

# UX / TUI Improvements

## 1. Cursor Shape Constants

**Current:** Cursor shapes use raw int values (0-6).

**Improvement:** Ensure `CursorShape` enum is well-documented and consistent across the codebase. Consider adding human-readable accessors like `cursorShapeName(): string`.

**Risk:** Low — additive API

---

## 2. Theme Application Guide

**Current:** `Theme` provides 256-color palette and factory methods for named themes.

**Improvement:** Document how themes map to ANSI colors and how to create custom themes.

**Risk:** Low — documentation

---

# Testing / Reliability Improvements

## 1. Expand Golden File Testing

**Current:** Fixtures in `tests/fixtures/` are binary `.ansi` files tested via `SnapshotTest`.

**Improvement:** Add comprehensive golden file tests for each handler, similar to `sequin`'s per-kind test data maps (`docs/repo_map/charmbracelet_sequin.md:112`).

**Risk:** Low — test improvement

---

## 2. Property-Based Testing

**Current:** Fuzzer uses deterministic RNG for random byte generation.

**Improvement:** Consider adding property-based tests that verify invariants (e.g., cursor always within bounds, grid dimensions never negative) across random inputs.

**Risk:** Low — test improvement

---

## 3. Performance Benchmarking

**Current:** No performance benchmarks exist.

**Improvement:** Add benchmarks for:
- Parser throughput (bytes/second)
- CellGrid set operations (ops/second)
- Screen diff computation (diffs/second)

**Risk:** Low — test improvement

---

# Ecosystem / Integration Opportunities

## 1. php-tui Integration

**Opportunity:** `php-tui/php-tui` provides widgets and layout but lacks ANSI parsing (`docs/repo_map/php-tui_php-tui.md:22`). candy-vt could serve as the ANSI parsing backend.

**Implementation:** Create a `candy-vt` adapter for php-tui's `Backend` interface that feeds ANSI bytes through Terminal and renders the resulting screen.

**Risk:** Medium — requires interface compatibility analysis

---

## 2. Terminal Image Rendering

**Opportunity:** `blacktop/go-termimg` provides Kitty/Sixel/iTerm2 image protocols with CSI queries for terminal capabilities (`docs/repo_map/blacktop_go-termimg.md:102-118`).

**Implementation:** Add CSI query handling to candy-vt for font size and terminal capability detection.

**Risk:** Low — additive feature

---

## 3. candy-vcr Enhancement

**Current:** candy-vcr uses candy-vt's `Terminal` + `Snapshot` for frame capture.

**Opportunity:** Enhance vcr integration with:
- Frame deduplication improvements
- Cassette compression
- VCR assertions for mode/cursor verification (`docs/repo_map/sugarcraft_candy-vt.md:683-686`)

**Risk:** Low — existing integration

---

# Notable PRs / Issues / Discussions

## 1. charmbracelet/x/vt Development

**Summary:** Upstream `charmbracelet/x/vt` continues development with ongoing VT500 compliance improvements.

**Relevance:** candy-vt should track upstream changes to maintain parity.

**Lessons learned:** The VT500 spec is complex; upstream issues often reveal edge cases in the state machine implementation.

---

## 2. sequin's Golden File Testing Infrastructure

**Summary:** `charmbracelet/sequin` uses `x/exp/golden` for comprehensive ANSI sequence testing (`docs/repo_map/charmbracelet_sequin.md:27`).

**Relevance:** Could improve candy-vt's test infrastructure.

**Lessons learned:** Golden files make regression testing precise and diagnose failures clearly.

---

## 3. ratatui's Buffer Diffing Algorithm

**Summary:** ratatui computes minimal diff between buffers for efficient rendering (`docs/repo_map/ratatui_ratatui.md:95-109`).

**Relevance:** CellGrid's dirty-region tracking could be enhanced with full buffer diffing.

**Lessons learned:** Computing minimal terminal updates is more efficient than full redraws.

---

## 4. pterm's Comprehensive Test Coverage

**Summary:** pterm has 28,952 automated tests covering all printer components (`docs/repo_map/pterm_pterm.md:133`).

**Relevance:** Sets a high bar for test coverage that candy-vt could aspire to.

**Lessons learned:** Comprehensive testing enables confident refactoring and prevents regressions.

---

# Recommended Roadmap

## Immediate Wins

1. **Truecolor in CsiHandlerImpl** — Low complexity, high impact
2. **Wide-char handling in renderer path** — Reuses existing Width utility
3. **Soft reset (DECSTR)** — Composition of existing operations
4. **Golden file tests for handlers** — Improves test maintainability

## Medium-Term Improvements

5. **Mouse event emission** — Enables interactive applications
6. **Synchronized output in renderer path** — Proper editor behavior
7. **Hyperlink support in renderer path** — Modern TUI feature
8. **DCS dispatch implementation** — Protocol completeness

## Major Architectural Upgrades

9. **Unified Cell representation** — Reduce code duplication
10. **Transition table pre-generation** — Performance optimization
11. **Handler interface segregation** — Improve extensibility

## Experimental Ideas

12. **Property-based fuzzing** — Verify invariants across random inputs
13. **Performance benchmarks** — Establish performance baselines
14. **php-tui backend adapter** — Cross-library integration

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|------------|--------|------------|------|---------------------|
| Truecolor in renderer | High | Low | Low | Immediate |
| Wide-char in renderer | High | Low | Low | Immediate |
| Soft reset (DECSTR) | Medium | Low | Low | Immediate |
| Mouse event emission | High | Medium | Medium | Medium |
| Synchronized output | High | Medium | Medium | Medium |
| Hyperlink in renderer | Medium | Medium | Low | Medium |
| DCS dispatch | Medium | Medium | Low | Medium |
| Golden file tests | Medium | Low | Low | Immediate |
| DECRTMM support | Medium | Low | Low | Medium |
| Wide-char in renderer | High | Low | Low | Immediate |
| Tab stops (renderer) | Low | Low | Low | Low |
| BCE (renderer) | Medium | Low | Low | Medium |
| php-tui integration | Medium | High | Medium | Low |
| BiDi support | Low | High | Low | Low |

---

# Final Strategic Assessment

candy-vt is a well-engineered, faithful port of `charmbracelet/x/vt` that successfully brings VT500 terminal emulation to PHP. Its dual-entry-point architecture (full emulator vs. lightweight renderer) reflects thoughtful design that balances completeness with performance for the vcr use case.

**Key differentiators:**
1. **VT500 state machine** — Complete implementation of the Paul Williams algorithm with a synthetic UTF-8 state for PHP
2. **Immutable/fluent patterns** — All state objects use `with*()` pattern per SugarCraft conventions
3. **Comprehensive CSI/OSC/SGR coverage** — All major sequences handled
4. **Real upstream usage** — Powers the entire candy-vcr render pipeline

**Strategic priorities:**
1. **Complete the renderer path** — Truecolor, wide-char, hyperlinks, synchronized output would make the renderer path suitable for most TUI applications
2. **Enable interactive applications** — Mouse event emission is the missing piece for interactive terminal emulators
3. **Improve test infrastructure** — Golden file testing and performance benchmarks would enable confident development
4. **Explore ecosystem integration** — php-tui integration could expand candy-vt's utility beyond the vcr use case

**Risk considerations:**
- PHP performance inherently limits per-byte throughput compared to Go; optimization within PHP constraints is the best approach
- The renderer path's simplicity is a feature (not a bug) for vcr, but needs clear documentation about feature limitations
- The full path's incomplete features (DCS, mouse events) should be addressed before using candy-vt in production interactive applications

**Competitive position:**
- No direct PHP equivalent exists for terminal emulation
- Compared to Go's `x/vt`, candy-vt is a complete port with minimal divergence
- Compared to `php-tui/php-tui`, candy-vt provides lower-level terminal emulation rather than widget rendering
- Positioned as the ANSI parsing foundation for the entire SugarCraft TUI ecosystem
