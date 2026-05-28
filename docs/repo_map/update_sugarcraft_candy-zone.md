# Overview

**candy-zone** is a complete, production-ready PHP port of lrstanley/bubblezone — providing invisible ANSI APC marker-based mouse zone tracking for TUI applications. It solves the fundamental problem of routing mouse events to the correct UI component without requiring every component to manually track its screen coordinates.

**Ecosystem positioning:** candy-zone sits at the event-handling layer, integrating with `candy-core` (TEA runtime via `MouseMsg`), `candy-sprinkles` (styling system), and `sugar-bits` (interactive components). It is the sole SugarCraft library addressing mouse zone tracking.

**Biggest opportunity areas:**
1. Debug/visualization mode for zone boundaries (upstream issue #7, still open)
2. Mouse event deduplication helpers for drag operations (upstream issue #10)
3. Spatial indexing (O(log n) hit testing) for apps with many zones
4. Shape-agnostic hit testing beyond AABB

**Biggest missing capabilities:**
1. No debug rendering mode to visualize zone boundaries
2. No built-in mouse event deduplication (drag produces duplicate events)
3. O(n) zone iteration instead of O(log n) spatial index
4. No z-order handling for overlapping zones

---

# Internal Capability Summary

## Current Architecture

### Core Components (7 source files in src/)

| Class | Lines | Responsibility |
|-------|-------|----------------|
| `Manager` | ~350 | APC marker injection, single-pass state-machine scanner, zone storage |
| `Zone` | 61 | Bounding box storage + AABB collision + relative position |
| `Zones` | ~100 | Static facade over shared Manager singleton |
| `ZoneHoverTracker` | 140 | Enter/exit state machine across MouseMsg events |
| `DragTracker` | 191 | Press→move→release drag sequence tracking |
| `ClickCounter` | 152 | Double/triple click streak detection |
| `MsgZoneInBounds` | 30 | TEA message wrapping zone + mouse event |

### Zone Marking Mechanism

candy-zone uses **APC (Application Program Command)** escape sequences — terminals ignore them for display, making them ideal invisible markers:

```
Start: ESC _ "candyzone:S:<id>" ESC \
End:   ESC _ "candyzone:E:<id>" ESC \
```

Format in `Manager.php:27-34`. The `mark()` method (`Manager.php:109-118`) wraps content with these markers. The `scan()` method (`Manager.php:127-234`) is a single-pass state machine that:
- Strips APC markers from output (no width contribution)
- Passes CSI/OSC sequences through unchanged (no width contribution)
- Tracks column/row position, accounting for wide characters (CJK = 2 cells)
- Records bounding box for each zone

### Zone Representation

`Zone.php` stores a 1-based AABB bounding box. `inBounds(MouseMsg)` performs standard axis-aligned collision:
```
mouse.X ∈ [startCol, endCol] ∧ mouse.Y ∈ [startRow, endRow]
```

### Mouse Event Routing

`Manager::anyInBounds()` (`Manager.php:284-295`) iterates all zones and returns the **first hit** in insertion order. `Manager::anyInBoundsAndUpdate()` wraps this in the TEA pattern, dispatching `MsgZoneInBounds` through `model->update()`.

### Mouse Event Type Coverage

The library processes `SugarCraft\Core\Msg\MouseMsg` with `MouseButton` (Left/Right/Middle) and `MouseAction` (Press/Release/Move/Drag/Scroll) enumerations. Motion tracking uses SGR mode 1003 (`Manager::setMotionTracking()`).

---

## Current Features

| Feature | Status | Notes |
|---------|--------|-------|
| APC zone markers | ✅ Complete | Mark/scan round-trip, markers stripped from output |
| AABB collision detection | ✅ Complete | Standard axis-aligned bounding box |
| Prefix-based ID isolation | ✅ Complete | `Manager::newPrefix()` for nested components |
| Hover enter/exit tracking | ✅ Complete | `ZoneHoverTracker` state machine |
| Drag sequence tracking | ✅ Complete | `DragTracker` (press→move→release) |
| Double/triple click tracking | ✅ Complete | `ClickCounter` with configurable interval |
| Global singleton facade | ✅ Complete | `Zones` static facade |
| Explicit manager instances | ✅ Complete | `Manager::newPrefix()` |
| Motion tracking escape sequences | ✅ Complete | SGR mode 1003 (all motion events) |
| Disabled mode | ✅ Complete | `setEnabled(false)` — markers become no-op |
| Wide character support | ✅ Complete | CJK = 2 cells, ZWSP = 0 cells |
| ext-intl fallback | ⚠️ Partial | Falls back to UTF-8 byte parsing, may split grapheme clusters |
| Synchronous scan | ✅ Complete | No goroutine/channel, simpler concurrency model |
| TEA integration | ✅ Complete | `anyInBoundsAndUpdate()` follows [Model, ?Cmd] pattern |

---

## API Surface

### Manager (core)
- `newGlobal()` — Create global shared manager
- `newPrefix(?string)` — Create prefixed manager for nested component isolation
- `mark(id, content)` — Wrap content with APC zone markers
- `scan(rendered)` — Record zone positions, strip markers, return clean frame
- `get(id)` — Retrieve zone by id (null if not found)
- `all()` — Get all recorded zones
- `clear(?id)` — Drop one zone or all zones
- `setEnabled(bool)` / `isEnabled()` — Toggle zone tracking
- `close()` — Drop all zones + disable manager
- `anyInBounds(MouseMsg)` — Return first zone under the mouse
- `anyInBoundsAndUpdate(Model, Msg)` — TEA-style dispatch to model
- `setMotionTracking(bool)` — Return CSI 1003 h/l escape sequence

### Zone
- `inBounds(MouseMsg)` — AABB collision test
- `pos(MouseMsg)` — Relative mouse position [col, row]
- `width()` / `height()` — Zone dimensions in cells
- `isZero()` — Detect uninitialized/degenerate zone

### Trackers
- `ZoneHoverTracker::update(MouseMsg)` — Returns `[$tracker, ?ZoneEnterMsg|?ZoneExitMsg]`
- `DragTracker::update(MouseMsg)` — Returns `[$tracker, ?ZoneDragStartMsg|?ZoneDragMoveMsg|?ZoneDragEndMsg]`
- `ClickCounter::update(MouseMsg)` — Returns `[$counter, ?DoubleClickMsg|?TripleClickMsg]`

---

## Extension Systems

No formal plugin/extension system. Extensibility is achieved through:
- **Prefix isolation** — `Manager::newPrefix()` allows component libraries to namespace their own zones
- **Custom manager injection** — Trackers accept any `Manager` instance via `withManager()`
- **State serialization** — Trackers provide `withZoneIds()`, `withCurrentZoneId()` for state restoration

---

## Strengths

1. **Elegant problem decomposition** — Components call `mark(id, content)`; root calls `scan(frame)` once; handlers call `anyInBounds()`. No coordinate math in components.

2. **Zero-width markers are layout-safe** — APC sequences don't affect `Width::string()` measurement from candy-core/candy-sprinkles. Integration tested and documented.

3. **Synchronous scan is PHP-idiomatic** — No goroutine/channel complexity. Zone map ready immediately after `scan()` returns.

4. **Comprehensive tracker suite** — Four layers of sophistication: raw `anyInBounds()`, hover tracker, drag tracker, click counter.

5. **Full edge case coverage** — Tests cover CJK, ZWSP, combining marks, multi-line zones, rescan replacement, prefix isolation.

6. **Idempotent disabled mode** — `setEnabled(false)` cleanly disables all operations without removing wiring.

7. **TEA-idiomatic integration** — `anyInBoundsAndUpdate()` returns `[Model, ?Cmd]` matching SugarCraft's runtime pattern.

---

## Weaknesses

1. **AABB collision only** — Organic shapes (circular buttons, ASCII art) still report rectangular bounding box. Empty corners register as hits.

2. **No z-order handling for overlapping zones** — `anyInBounds()` returns first hit in iteration order, not z-order. Overlapping elements require manual deduplication.

3. **O(n) zone iteration** — For apps with 100+ zones, iterating all zones on every mouse event could be a bottleneck.

4. **ext-intl grapheme fallback is imperfect** — Fallback UTF-8 byte parsing splits multi-unit graphemes (emoji with modifiers, composed characters). No warning when `grapheme_extract()` unavailable.

5. **Static singleton in Zones facade** — Shared global state makes parallel testing harder. `setDefaultManager(null)` required for teardown.

6. **No debug visualization mode** — Upstream issue #7 (still open after years) requests zone boundary visualization. candy-zone has no equivalent.

7. **No mouse event deduplication** — Drag operations produce duplicate events as cursor crosses cell boundaries (upstream issue #10). No built-in helper to deduplicate.

8. **Non-alt-screen coordinate failure** — Like upstream, candy-zone assumes the scanned view starts at row 1. Without alt-screen, coordinates are wrong and there is no offset parameter.

---

# Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|------|-----------|---------------------------|---------|
| `lrstanley/bubblezone` | 🔴 Direct upstream | APC markers, state-machine scanner, AABB collision, prefix isolation, tracker state machines | Critical |
| `textualize/textual` | 🟡 Spatial mapping reference | `_spatial_map.SpatialMap` (R-tree-like O(log n) hit testing), widget hit testing, z-order handling | High |
| `pr_lrstanley_bubblezone.md` | 🔴 Upstream PR analysis | Debug mode request, mouse deduplication gap, non-alt-screen coordinate failure, lipgloss v2 incompatibility | Critical |
| `pr_textualize_textual.md` | 🟡 Architectural reference | Weakref DOM, GC management, event bubbling performance, async/thread boundary patterns | Medium |

---

# Feature Gap Analysis

## Critical Priority

### 1. Debug Visualization Mode

**Title:** Zone boundary visualization for development

**Description:** No way to render zone boundaries with visible characters during development. Upstream issue #7 has been open for years with no implementation.

**Why it matters:** Developers cannot validate zone placement, detect incorrect marker usage, or debug hit detection without visible boundaries. This is a basic developer experience gap.

**Source repo:** `lrstanley/bubblezone`, issue #7

**Source discussion:** Issue explicitly requests: bounds checking validation, incorrect usage detection, zone boundary visualization.

**Implementation ideas:**
- Add `Manager::setDebugVisualization(bool)` that changes `mark()` to wrap content in box-drawing characters instead of invisible APC markers
- Add `RENDER_DEBUG=1` environment variable that auto-enables visualization
- Provide separate `Manager::renderDebugBoundaries()` that returns zone boundaries as overlaid text

**Estimated complexity:** Medium — requires a parallel rendering path, not changing core logic

**Expected impact:** High — significantly improves developer experience, reduces bug reports

---

### 2. Mouse Event Deduplication Helpers

**Title:** Built-in deduplication for drag operations

**Description:** When holding a mouse button and dragging, multiple `MouseDown` events fire as the cursor crosses cell boundaries. No built-in mechanism to distinguish intentional single clicks from drag artifacts.

**Why it matters:** Applications must implement their own deduplication logic. Upstream deferred this to BubbleTea v2 mouse event changes, but the problem is real and affects user experience. PHP can solve this in userland without waiting for upstream.

**Source repo:** `lrstanley/bubblezone`, issue #10

**Source discussion:** Issue shows screenshot of drag producing multiple `MouseDown` events. Maintainer response: "As part of bubbletea v2, this should be a non-issue."

**Implementation ideas:**
- Create `ZoneClickTracker` class that wraps a `Manager` and tracks in-progress clicks
- `trackClick(MouseMsg)` returns `?ZoneClickMsg` — only fires once per zone per press until release
- Track `MouseDown` zone + `MouseUp` pairs, discard intermediate events in same zone
- Provide `ZoneClickTracker::clear()` on `MouseUp` to reset

**Estimated complexity:** Medium — new class, no changes to existing logic

**Expected impact:** High — competitive differentiation over Go bubblezone (Go users still implement this manually)

---

### 3. ext-intl Grapheme Handling

**Title:** Declare ext-intl as recommended dependency, warn on fallback

**Description:** `Manager::nextGrapheme()` falls back to UTF-8 byte parsing when `grapheme_extract()` is unavailable. This fallback only handles single-unit graphemes correctly; multi-unit graphemes (emoji with skin tone modifiers, composed characters) get split, potentially miscounting width.

**Why it matters:** Zone boundaries would be calculated incorrectly for content containing emoji with modifiers or other complex grapheme clusters. Silent failure mode — no warning when falling back.

**Source:** candy-zone internal (sugarcraft_candy-zone.md, lines 429-458)

**Implementation ideas:**
- Add `"suggest": { "ext-intl": "Required for correct grapheme cluster handling in scan()" }` to `composer.json`
- Add a warning log or exception when `grapheme_extract()` is unavailable and fallback produces potentially incorrect results
- Document the requirement in README

**Estimated complexity:** Low — composer.json change + optional warning

**Expected impact:** Medium — correctness issue only affects edge cases but could cause confusing bugs

---

## High Priority

### 4. Spatial Index for O(log n) Hit Testing

**Title:** Spatial map for efficient zone lookup at scale

**Description:** `anyInBounds()` iterates all zones O(n). For apps with many zones (100+), this could be a performance bottleneck on every mouse event.

**Why it matters:** textual uses `_spatial_map.SpatialMap` (R-tree-like structure) for O(log n) widget lookup. This is a proven pattern from a 30k+ star framework.

**Source repo:** `textualize/textual` (spatial_map.SpatialMap)

**Implementation ideas:**
- Add optional `ZoneSpatialIndex` class that stores zones in an R-tree-like structure
- `Manager::withSpatialIndex(bool)` to enable
- Maintain both O(n) iteration (simple, default) and O(log n) index (opt-in)
- For PHP, a simple quadtree or sorted list with spatial hashing would suffice

**Estimated complexity:** Medium-High — new spatial index class, dual code paths

**Expected impact:** Medium — only matters at scale with many zones

---

### 5. Z-Order Handling for Overlapping Zones

**Title:** Built-in z-index support for overlapping interactive elements

**Description:** When two zones visually overlap (e.g., a button inside a panel), `anyInBounds()` returns the first hit in iteration order, not z-order.

**Why it matters:** Overlapping interactive elements are common (panels with buttons, overlays). Users must implement their own deduplication.

**Source:** sugarcraft_candy-zone.md, lines 416-421

**Implementation ideas:**
- Add optional `zIndex` parameter to `mark(id, content, zIndex: 0)`
- Sort zones by z-index before iteration in `anyInBounds()`
- Default z-index of 0, higher values render on top
- Document that overlapping zones require explicit z-index assignment

**Estimated complexity:** Medium — parameter addition + sort in hot path

**Expected impact:** Medium — simplifies common overlapping UI pattern

---

### 6. Strict Mode Validation

**Title:** Enforce zone requirements at initialization

**Description:** When alt-screen is not enabled or mouse mode is not correctly configured, candy-zone produces silently wrong coordinates. No validation or warning.

**Why it matters:** This is the most common confusion point with bubblezone (issue #11). Users spend hours debugging why hit detection is wrong, only to discover alt-screen was not enabled.

**Source repo:** `lrstanley/bubblezone`, issue #11

**Implementation ideas:**
- Add `Manager::setStrictMode(bool)` that validates:
  - Mouse mode is set to SGR mode 1003 (all motion events) when using motion tracking
  - All zone IDs are unique within the manager
  - Zone IDs use proper prefixing convention
- Emit warnings or throw exceptions in strict mode when misconfiguration detected
- Document alt-screen requirement prominently

**Estimated complexity:** Low — validation checks added to scan path

**Expected impact:** Medium — prevents silent failures, improves developer experience

---

## Medium Priority

### 7. Non-Alt-Screen Offset Parameter

**Title:** Allow explicit vertical offset for zone coordinate calculations

**Description:** bubblezone fundamentally requires alt-screen mode to compute correct coordinates. Without it, applications that start rendering at arbitrary vertical offsets produce wrong hit detection.

**Why it matters:** This is a fundamental architectural constraint. Applications that cannot use alt-screen (some legacy terminals, specific terminal multiplexers) cannot use bubblezone correctly.

**Source repo:** `lrstanley/bubblezone`, issue #11

**Implementation ideas:**
- Add `Manager::setViewOffset(int $row, int $col)` to specify where the scanned view starts on screen
- Add offset to all recorded zone coordinates
- Document that alt-screen is required for correct operation, or provide offset parameter

**Estimated complexity:** Low — offset added to all zone coordinate recording

**Expected impact:** Low-Medium — niche use case, most TUI apps use alt-screen

---

### 8. Iteration Invalidation via Monotonic Counter

**Title:** Replace nanosecond time with monotonic counter for stale zone eviction

**Description:** Upstream uses `time.Now().Nanosecond()` as an iteration marker for stale zone eviction. candy-zone uses direct replacement (same effect). A monotonic counter would be cleaner.

**Why it matters:** Theoretically, rapid re-renders could produce the same nanosecond. Practically unlikely but the approach is inelegant.

**Source:** sugarcraft_candy-zone.md, lines 473-475

**Implementation ideas:**
- Add `Manager::$scanIteration` static counter incremented on each `scan()` call
- Store iteration on each zone
- On rescan, mark zones with old iteration as stale and remove them

**Estimated complexity:** Low — counter replacement, no behavioral change

**Expected impact:** Low — cosmetic improvement, no functional difference

---

### 9. Zone ID Uniqueness Validation

**Title:** Detect duplicate zone IDs at scan time

**Description:** No validation that zone IDs are unique within a manager. Duplicate IDs silently overwrite previous zone data.

**Why it matters:** Debugging missing zones is difficult when the cause is ID collision.

**Source:** sugarcraft_candy-zone.md, weakness #7

**Implementation ideas:**
- In strict mode, detect when `mark()` is called with an ID already in the zones map
- Warn or throw when duplicate detected
- Suggest using `Manager::newPrefix()` for isolation

**Estimated complexity:** Low — hashmap lookup added to mark path

**Expected impact:** Low-Medium — only matters during development

---

## Low Priority

### 10. Shape Mask Support (Non-Rectangular Hit Regions)

**Title:** Support for mask-based hit regions beyond AABB

**Description:** No native support for non-rectangular hit testing. Organic shapes (circles, triangles, ASCII art) require manual padding to approximate bounding box.

**Why it matters:** This is a known limitation of the ANSI marker approach. Upstream has no solution, only documentation of the padding workaround.

**Source:** sugarcraft_candy-zone.md, lines 410-415

**Implementation ideas:**
- Accept a callback `isPointInShape(x, y)` in addition to the rectangular bounds
- Store shape mask alongside each zone's bounding box
- Call mask function during `inBounds()` check
- This would be a significant API addition

**Estimated complexity:** High — new callback type, new zone property, new validation path

**Expected impact:** Low — most UIs are rectangular; workaround (padding) exists

---

# Algorithm / Performance Opportunities

## Current Approach vs External Approach

### Upstream bubblezone (Go)

**Approach:** Goroutine + channel to decouple scanning from zone storage. State-machine scanner produces zone data and sends via channel; background worker applies updates under mutex.

**Performance characteristics:**
- `scan()` is non-blocking (producer)
- Worker processes zones asynchronously
- O(n) iteration for `AnyInBounds()`
- Atomic counters for lock-free ID generation

### textual (Python)

**Approach:** R-tree-like spatial map (`_spatial_map.SpatialMap`) for O(log n) widget lookup by screen position. Compositor maintains spatial index updated on each layout pass.

**Performance characteristics:**
- O(log n) hit detection at scale
- Automatic z-ordering
- More sophisticated but requires layout engine to pre-compute widget positions

### candy-zone (PHP) — Current

**Approach:** Synchronous single-pass scan. No background worker. O(n) iteration for `anyInBounds()`.

**Performance characteristics:**
- `scan()` is blocking (simpler, no concurrency)
- Immediate zone map availability after scan
- O(n) hit detection (acceptable for <100 zones)

---

## Why External Is Better

### Why textual's spatial map is superior at scale

For applications with many interactive zones (100+), textual's O(log n) spatial lookup scales better than O(n) iteration. However:

**Tradeoffs:**
- Textual's approach requires a layout engine that pre-computes widget positions. candy-zone's marker-based approach works without a layout engine — any rendered string with markers is scannable.
- The synchronous approach in candy-zone is simpler and avoids concurrency bugs.
- For typical TUI apps (<50 zones), O(n) vs O(log n) is not perceptible.

**Applicability to candy-zone:**
- Adding an optional spatial index would benefit apps with many zones
- For most SugarCraft apps, the current O(n) approach is fine
- Consider adding `ZoneSpatialIndex` as opt-in for scale

---

## Scan Performance

### Current bottleneck

`Manager::scan()` is O(n) in the rendered output length. For very large terminal frames with many zones, this could be expensive on every render cycle.

### Opportunity

1. **Incremental scan** — Only rescan changed regions, not the full frame
2. **Zone map caching** — Cache the zone map between frames when content hasn't changed
3. **Parallel scanning** — For very wide output, scan lines in parallel (complex for PHP)

**Recommendation:** Monitor for real-world performance issues before optimizing. The single-pass state machine is already efficient.

---

# Architecture Improvements

## 1. Separate Zone Logic from Styling

**Current issue:** Tight coupling risk with candy-sprinkles. The APC marker approach works with current styling but could break if styling library changes rendering approach (like lipgloss v2 canvas).

**Improvement:**
- Keep zone scanning strictly isolated from styling
- Do not depend on any internal behavior of candy-sprinkles
- Test integration explicitly but keep packages loosely coupled
- Document that coupling is intentional and tested

**Source:** pr_lrstanley_bubblezone.md, section 18 Problem D

## 2. Strict Isolation of Scan Path

**Current issue:** `scan()` handles CSI, OSC, newlines, graphemes all in one method. This is efficient but hard to extend.

**Improvement:**
- Consider extracting the state machine into a separate `Scanner` class
- Keep `scan()` as the orchestrator but delegate state handling
- This would make it easier to add new escape sequence types
- No behavioral change, just internal refactoring

## 3. Provide Explicit Manager as Default

**Current issue:** The global `Zones` facade is convenient but makes testing harder.

**Improvement:**
- Make `Manager` the documented default pattern
- Keep `Zones` facade as opt-in convenience
- Add `DI` container integration examples for dependency injection
- Document explicit manager pattern in README

**Source:** sugarcraft_candy-zone.md, weakness #5

## 4. Add Zone Lifecycle Hooks

**Current issue:** No callbacks for zone enter/exit other than through trackers.

**Improvement:**
- Add optional `onEnter(callable)` and `onExit(callable)` to `Manager`
- Callbacks fire when `anyInBounds()` detects a new zone
- Allows inline handlers without full tracker class
- Low priority but improves ergonomics

---

# API / Developer Experience Improvements

## 1. Introduce ZoneBuilder Pattern

**Current:** `mark(id, content)` returns marked string directly.

**Opportunity:** Add fluent `ZoneBuilder` for more complex zone creation:
```php
$zone = $manager->zone('my-btn')
    ->withContent('Click me')
    ->withStyle($style)
    ->withZIndex(1)
    ->mark();
```

**Tradeoff:** Adds API surface. Current simple `mark()` may be sufficient.

## 2. Improve Error Messages

**Current:** Silent failures when alt-screen not enabled, markers used incorrectly.

**Opportunity:**
- Add `Manager::validate()` method that checks configuration and emits warnings
- Throw `ZoneException` in strict mode when misconfiguration detected
- Include terminal escape sequences in error messages to help users debug

## 3. Add Type-Safe Zone ID Generics

**Current:** Zone IDs are bare strings. No compile-time checking.

**Opportunity:**
- Document zone ID naming conventions
- Consider `ZoneId` value object for type safety
- Add `assertZoneId(string)` validation in strict mode

---

# Documentation / Cookbook Opportunities

## 1. Add "Common Patterns" Section to README

**Missing:** No cookbook showing typical usage patterns.

**Opportunity sections:**
- **Nested components with prefix isolation** — How to use `newPrefix()` in list items
- **Drag-and-drop** — Complete example with `DragTracker`
- **Hover tooltips** — How to combine `ZoneHoverTracker` with tooltip rendering
- **Double-click to edit** — How to combine `ClickCounter` with edit mode
- **Zone debugging** — How to visualize zones during development

## 2. Document Alt-Screen Requirement Prominently

**Current:** Alt-screen requirement is buried in tips section.

**Opportunity:**
- Add prominent "Requirements" section at top of README
- Document that `setMotionTracking()` return value must be written to TTY
- Show complete minimal example with proper terminal setup

## 3. Add Troubleshooting Section

**Current:** No common issues documented.

**Opportunity:**
- Document that `len()` should not be used for width (use `Width::string()`)
- Document that `scan()` must be called on full root frame
- Document non-alt-screen coordinate offset issue
- Document drag event deduplication workaround

## 4. API Reference Documentation

**Current:** README has API summary table but no full API docs.

**Opportunity:**
- Add PHPDoc to all public methods (already present but could be enhanced)
- Consider generating HTML docs with apiGen or phpDocumentor
- Publish to docs.sugarcraft.io

---

# UX / TUI Improvements

## 1. Debug Visualization Mode (High Priority)

**Requested feature:** Visual zone boundaries during development.

**Implementation:**
- Add `Manager::setDebugVis(bool)` that changes `mark()` output
- Debug mode wraps zones in visible box-drawing characters
- Different colors for different zone types
- Auto-enable with `CANDY_ZONE_DEBUG=1` environment variable

**Reference:** upstream issue #7, still open

## 2. Zone Hit Flash Animation

**Idea:** When a zone is clicked, briefly flash the zone border.

**Implementation:**
- Add `Manager::flashZone(string $id, int $ms)` 
- Returns escape sequence to draw attention to a specific zone
- Useful for confirming click registration

## 3. Keyboard Navigation Support

**Idea:** Zones could also be navigated via keyboard (arrow keys).

**Implementation:**
- Not a core feature (out of scope for mouse zone library)
- Could be added via `ZoneNavigator` class that wraps a `Manager`
- Detects keyboard focus movement and emits enter/exit events

---

# Testing / Reliability Improvements

## 1. Snapshot Testing for Zone Bounds

**Current:** Unit tests assert specific bounds values but no snapshot infrastructure.

**Opportunity:**
- Add snapshot tests for scanner output with known inputs
- Capture zone bounds for complex renderings (CJK, emoji, ANSI sequences)
- Guard against regressions as scanner evolves

**Reference:** sugarcraft_candy-zone.md recommendation #4

## 2. Fuzz Testing for Scanner

**Current:** Table-driven unit tests with known inputs.

**Opportunity:**
- Add fuzz tests for `scan()` with random byte sequences
- Edge case: malformed escape sequences, partial markers, binary data
- Would catch parser edge cases before users encounter them

## 3. Integration Tests with candy-sprinkles

**Current:** Tests use raw styled output but don't exercise full integration.

**Opportunity:**
- Add integration tests that render actual candy-sprinkles components with zone markers
- Verify interaction between zone scanning and styling library
- Test with `Border::rounded()`, `Style::new()->padding()`, etc.

---

# Ecosystem / Integration Opportunities

## 1. sugar-bits Integration

**Current:** sugar-bits (button, list, etc. components) doesn't use candy-zone.

**Opportunity:**
- Add candy-zone as optional dependency to sugar-bits
- sugar-bits components automatically wrap themselves in zone markers
- Provide `WithZone` trait for components that want zone support
- Simplifies building interactive components

## 2. candy-sprinkles Canvas Overlay

**Current:** No canvas system in candy-sprinkles.

**Opportunity:**
- If/when candy-sprinkles adds canvas overlay, zone scanning must work with it
- Do NOT tightly couple — keep scanning logic independent of rendering
- Test explicitly when canvas is added

## 3. candy-vcr Testing Integration

**Current:** candy-vcr records and replays terminal sessions.

**Opportunity:**
- Add helper to replay mouse events and verify zone hit detection
- Combine with snapshot testing for reliable visual regression
- Could serve as basis for mouse interaction testing

## 4. ReactPHP Event Loop Integration

**Current:** Synchronous scan only. No async integration.

**Opportunity:**
- Add `Manager::scanAsync()` that wraps scanning in a ReactPHP promise
- Allows non-blocking scan for very large outputs
- Not critical (scan is already fast) but would align with ReactPHP ecosystem

---

# Notable PRs / Issues / Discussions

## Issue #11: Non-Alt-Screen Coordinate Failure

**Summary:** When not using alt-screen mode, zone Y-positions are calculated as if content starts at top of terminal. Real applications may render starting at arbitrary vertical offsets.

**Relevance to candy-zone:** Fundamental architectural constraint. The zero-width ANSI marker approach relies on scanning the full view output and computing absolute coordinates. Any offset in the rendering surface breaks the coordinate system.

**Lessons learned:**
- Document alt-screen requirement prominently in README
- Consider adding offset parameter for non-alt-screen scenarios
- Make this an explicit error in strict mode

**Source:** docs/repo_map/pr_lrstanley_bubblezone.md, section 4

---

## Issue #10: Mouse Event Deduplication Gap

**Summary:** During drag operations, `MouseDown` events fire repeatedly as the mouse crosses cell boundaries. No built-in mechanism to distinguish intentional clicks from drag artifacts.

**Relevance to candy-zone:** This is critical for good UX. SugarCraft can solve this in userland without waiting for upstream changes. The Go library couldn't solve it without upstream v2 mouse event changes; PHP can provide helpers entirely in user code.

**Lessons learned:**
- Provide `ZoneClickTracker` class that tracks in-progress clicks and only fires once per zone until mouseup
- This is a competitive differentiation opportunity — Go bubblezone users still implement this manually
- Implement regardless of whether upstream provides it

**Source:** docs/repo_map/pr_lrstanley_bubblezone.md, section 5

---

## Issue #7: Debug Mode Request (Still Open)

**Summary:** Users request bounds checking validation, incorrect usage detection, and zone boundary visualization during development.

**Relevance to candy-zone:** No debug tooling exists. This is a basic developer experience gap that upstream has not addressed.

**Lessons learned:**
- Add `RENDER_DEBUG=1` mode that visualizes zone boundaries with box-drawing characters
- Add strict mode validation that detects incorrect marker usage
- This would significantly improve developer experience

**Source:** docs/repo_map/pr_lrstanley_bubblezone.md, section 4

---

## Issue #51: lipgloss v2 Canvas Incompatibility

**Summary:** bubblezone v2 may not work with lipgloss v2's canvas/compositor feature. The successor library will have "improved mouse event tracking."

**Relevance to candy-zone:** Signals that the ANSI marker approach has architectural limits. The successor library hints at a more layered, composable approach to mouse tracking — possibly one that doesn't rely on string-scanning ANSI markers.

**Lessons learned:**
- Keep zone logic strictly isolated from styling logic
- Do not depend on internal behavior of candy-sprinkles
- Prepare for potential future changes in rendering approach
- The marker-based approach may not be viable for all future rendering architectures

**Source:** docs/repo_map/pr_lrstanley_bubblezone.md, section 5

---

## textual v8.1.0: Weak Reference DOM

**Summary:** Textual replaced circular references in DOM with weak references to improve GC performance.

**Relevance to candy-zone:** If SugarCraft's component system holds similar reference cycles (parent→child strong references), could hit GC issues similar to textual's.

**Lessons learned:**
- Use weakrefs for parent/owner references in any widget-style system
- Ensure caching structures can be cleared
- Test long-running applications for GC behavior

**Source:** docs/repo_map/pr_textualize_textual.md, section 9H

---

# Recommended Roadmap

## Immediate Wins (Do First)

1. **Declare ext-intl as recommended dependency** — Low effort, prevents edge case bugs. Add to `composer.json` suggest section + document.

2. **Add debug visualization mode** — High value, medium effort. `Manager::setDebugVis(bool)` + `CANDY_ZONE_DEBUG=1` env var.

3. **Add mouse event deduplication helpers** — High value, competitive differentiation. New `ZoneClickTracker` class.

## Medium-Term Improvements (Do Next)

4. **Add strict mode validation** — Prevents silent failures. Validate alt-screen, unique IDs, proper configuration.

5. **Add z-order handling for overlapping zones** — Simplifies common overlapping UI pattern. Optional z-index parameter.

6. **Add non-alt-screen offset parameter** — Addresses niche use case. `Manager::setViewOffset()`.

7. **Add monotonic counter for iteration invalidation** — Cleaner than nanosecond time. Replace with counter in `Manager`.

8. **Add snapshot tests for scanner** — Guards against regressions. Known input→expected bounds.

## Major Architectural Upgrades (Consider)

9. **Spatial index for O(log n) hit testing** — Only needed at scale (100+ zones). Optional `ZoneSpatialIndex` class.

10. **Separate Scanner class** — Internal refactoring. More maintainable state machine.

11. **Declarative zone API** — Instead of string-transforming `mark()` calls, register zones separately. More robust, easier to test.

## Experimental Ideas (Explore)

12. **Shape mask support** — Non-rectangular hit regions. High complexity, low priority.

13. **ReactPHP async scan** — Non-blocking scan for very large outputs. Not critical.

14. **Zone lifecycle hooks** — `onEnter`/`onExit` callbacks on Manager. Improves ergonomics.

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|-------------|--------|------------|------|---------------------|
| ext-intl as recommended dep | Medium | Low | Low | **Immediate** |
| Debug visualization mode | High | Medium | Low | **Immediate** |
| Mouse event deduplication | High | Medium | Low | **Immediate** |
| Strict mode validation | Medium | Low | Low | **Medium-term** |
| Z-order handling | Medium | Medium | Low | **Medium-term** |
| Non-alt-screen offset | Low-Medium | Low | Low | **Medium-term** |
| Monotonic counter | Low | Low | Low | **Medium-term** |
| Snapshot tests | Medium | Medium | Low | **Medium-term** |
| Spatial index | Medium | High | Medium | **Major (opt-in)** |
| Scanner refactor | Medium | Medium | Low | **Major (internal)** |
| Declarative zone API | High | High | Medium | **Experimental** |
| Shape masks | Low | High | Medium | **Experimental** |
| ReactPHP async scan | Low | Medium | Medium | **Experimental** |
| Zone lifecycle hooks | Low | Low | Low | **Medium-term** |

---

# Final Strategic Assessment

candy-zone is a **complete, production-ready port** of bubblezone that faithfully replicates the upstream design while adapting to PHP's synchronous execution model. The marker-based approach elegantly solves mouse event routing without requiring components to track their own screen coordinates. The layered tracker suite (hover, drag, click) provides progressively sophisticated behavior.

**Competitive position:**
- Go bubblezone users still implement mouse event deduplication manually — candy-zone can provide this as a built-in helper
- No debug visualization exists in upstream — candy-zone can add this as a competitive differentiator
- The APC marker approach is fragile when upstream changes rendering (as happened with lipgloss v2) — candy-zone should keep zone logic strictly isolated

**Architectural concerns:**
- AABB-only collision detection is inherited from upstream and acceptable for rectangular UI
- O(n) zone iteration is fine for typical apps (<100 zones)
- ext-intl fallback is a correctness edge case that should be documented and warned about

**Strategic direction:**
1. **Solve what upstream hasn't** — Debug mode, mouse deduplication helpers, strict mode validation
2. **Keep loosely coupled** — Zone scanning must remain strictly isolated from styling logic
3. **Provide opt-in sophistication** — Spatial index for scale, z-order for overlapping zones
4. **Document prominently** — Alt-screen requirement, len() vs Width::string(), scan-on-root-frame requirement

The port is ready for production use and integration with sugar-bits components. The main evolution path is adding the developer experience improvements (debug mode, strict mode) and mouse event deduplication helpers that upstream has left as an exercise for users.
