# SugarCraft/sugar-table — Update Report

## Overview

**sugar-table** is a mature, production-ready PHP port of `Evertras/bubble-table` providing a full-featured interactive terminal table component. It is the **standalone dedicated table library** in SugarCraft (distinct from `sugar-bits` Table which is embedded in that monorepo). The package excels at data-table rendering with column definitions, pagination, multi-column sort, filtering, viewport virtualization, and Border family integration. The biggest opportunities are completing the frozen-column rendering pipeline, adding fuzzy filtering, expanding keyboard navigation, and implementing a reactive event system for interactive TUI use cases. No major missing capabilities — the library is well-designed but has rough edges in scroll handling and event reactivity.

---

## Internal Capability Summary

### Architecture

`sugar-table` is a focused, standalone table component (857-line `Table.php` + 266-line `Column.php`). It follows the immutable fluent builder pattern throughout, with all `with*()` methods returning new clones.

**Data flow:**
```
rows → filteredSortedRows() [filter + sort] → pagedRows() [pagination]
  → (viewportHeight > 0 ? array_slice with scrollY : rows)
  → renderRowLines() [per-row ANSI assembly] → View() [full table string]
```

### Current Features

| Feature | Implementation |
|---------|---------------|
| Column definitions | `Column` with key, title, width, style, flexibleWidth, maxWidth, filterable, alignLeft |
| ColumnWidth enum | `Fixed`, `Percent`, `Dynamic`, `Content` — 4-mode sizing |
| WrapMode enum | `None`, `WordWrap`, `Character` — 3 wrapping strategies |
| Row data | `RowData` (immutable map), `Row` wrapper with style/zebra |
| StyledCell | Per-cell ANSI style override at render time |
| Multi-column sort | `SortBy($key, $asc, $primary)` + `ThenSortBy` chain via `usort` |
| Multi-column AND filter | `Filter($colKey, $text)` — per-column, all must match |
| Pagination | `withPageSize()`, `withPage()`, `withTotalPages()` |
| Row selection | `SelectNext()`, `SelectPrevious()`, `SelectPage()` |
| Zebra striping | `withZebra()` with odd/even style override |
| Missing indicator | `withMissingIndicator()` |
| Border family | `withBorder(Border $border)` — 8 styles from candy-sprinkles |
| Viewport virtualization | `withViewportHeight()` + `withScrollY()` — slices visible window |
| Frozen columns | `withFrozenCols()` — stores indices, rendering incomplete |
| Horizontal scroll | `withScrollX()` — stores offset, **not applied during rendering** |
| i18n | `Lang::t()` facade with `lang/en.php` |

### Strengths

1. **ColumnWidth and WrapMode enums** — More flexible column sizing than upstream Go libraries
2. **Viewport virtualization** — Unique among table ports; enables efficient large-dataset rendering
3. **Multi-column AND filtering** — Superior to bubble-table's single-filter or stickers' single-column filter
4. **Immutable + fluent** — All classes `final`, `readonly` properties, private `mutate()` pattern
5. **Border family integration** — 8 border styles from candy-sprinkles vs hand-rolled border chars
6. **i18n support** — Full translatable page footer and future labels
7. **Test coverage** — 10 test files covering core functionality

### Weaknesses

1. **Frozen column rendering incomplete** — `withFrozenCols()` stores indices but `View()` never shifts rendering for frozen columns
2. **Horizontal scroll not applied** — `scrollX` is stored but `View()` doesn't offset column rendering
3. **No fuzzy filtering** — Only `stripos` case-insensitive contains; upstream's `filterFuncFuzzy` not ported
4. **Keyboard navigation minimal** — Only `SelectNext`/`SelectPrevious`; no vim keys, no HalfPageUp/Down, no Home/End
5. **No UserEvent system** — Table is render-only; cannot react to highlight changes or selection events
6. **Sort not stable** — Uses `usort` (not stable sort); equal keys in multi-column sort may reorder unpredictably
7. **No StyleFunc callback** — sugar-bits Table has `styleFunc(Closure)` for dynamic per-cell styling; sugar-table uses StyledCell only
8. **No visible row caching** — Every `View()` call recomputes filter/sort/pagination; upstream has `visibleRowCache`

---

## Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|------|-----------|--------------------------|----------|
| `Evertras/bubble-table` | 🟢 Primary upstream | Fuzzy filter, UserEvent system, stable sort, frozen column rendering, Lipgloss styling | Critical |
| `76creates/stickers` | 🟡 Secondary upstream | Ratio-based FlexBox layout, ContentGenerator, x/y cursor navigation, bubble sort | High |
| `charmbracelet/bubbles` | 🟡 Sister upstream | Table component, KeyMap, Help integration | Medium |
| `ratatui/ratatui` | 🟡 Cross-ecosystem | Table widget (StatefulWidget), constraint layout, immediate-mode diffing | Medium |
| `textualize/textual` | 🟡 Cross-ecosystem | DataTable widget, reactive state, CSS styling | Medium |
| `sugarcraft/sugar-bits` | 🟢 Internal | Table variant with `styleFunc` callback, vim keybindings | High |

---

## Feature Gap Analysis

### Critical Priority

**1. Frozen Column Rendering**
- **Description:** `withFrozenCols()` stores column indices but `View()` never applies differential rendering — frozen columns scroll away when `scrollX > 0`. Upstream renders frozen columns on the left regardless of horizontal scroll position.
- **Why it matters:** Wide tables with frozen key columns (ID, name) are a primary use case for tables
- **Source:** `docs/repo_map/Evertras_bubble-table.md` — `WithHorizontalFreezeColumnCount` + `ScrollLeft/ScrollRight`
- **Implementation ideas:** In `View()`, before rendering header row: pre-render frozen columns, shift non-frozen column rendering by `scrollX` offset, recombine
- **Estimated complexity:** Medium — requires re-architecting `View()` column rendering loop
- **Expected impact:** High — enables proper wide-table UX

**2. Fuzzy Filtering**
- **Description:** sugar-table only has `stripos` case-insensitive contains. Upstream has `filterFuncFuzzy` using ordered subsequence matching (`fuzzySubsequenceMatch`).
- **Why it matters:** Fuzzy matching is essential for user-facing tables with messy data (typos, partial matches)
- **Source:** `docs/repo_map/Evertras_bubble-table.md` — `filterFuncFuzzy` implementation
- **Implementation ideas:** Port `fuzzySubsequenceMatch()` from Go; add `FilterMode::Fuzzy` enum case; wire into `filteredSortedRows()`
- **Estimated complexity:** Low — single algorithm ~15 lines
- **Expected impact:** High — closes major upstream feature gap

**3. Horizontal Scroll Actual Rendering**
- **Description:** `scrollX` is stored via `withScrollX()` but `View()` never shifts column rendering based on it. Columns render at their absolute positions.
- **Why it matters:** Wide tables without horizontal scroll rendering are unusable
- **Source:** `docs/repo_map/Evertras_bubble-table.md` — `WithMaxTotalWidth` + `ScrollLeft/ScrollRight`
- **Implementation ideas:** In `View()` row rendering: skip columns before `scrollX` offset, render remaining with width adjusted for table width
- **Estimated complexity:** Medium — requires computing visible column range and adjusting per-cell rendering
- **Expected impact:** Critical — horizontal scroll is non-functional

### High Value

**4. Expanded Keyboard Navigation**
- **Description:** Only `SelectNext()` / `SelectPrevious()` / `SelectPage()` implemented. No vim keys (j/k/g/G), no HalfPageUp/Down, no Home/End.
- **Why it matters:** TUI users expect vim-style navigation; HalfPageUp/Down is essential for large tables
- **Source:** `docs/repo_map/76creates_stickers.md` — `CursorUp/Down/Left/Right()`; `docs/repo_map/charmbracelet_bubbles.md` — `HalfPageUp/Down, GotoTop/Bottom`
- **Implementation ideas:** Add `SelectNext()`, `SelectPrevious()` accept vim key input; add `SelectHalfPageUp()`, `SelectHalfPageDown()`, `SelectTop()`, `SelectBottom()`
- **Estimated complexity:** Low — 5 new methods
- **Expected impact:** High — significantly improves UX

**5. UserEvent System**
- **Description:** sugar-table is render-only. No event callbacks for highlight changes, row selection, or filter focus/unfocus.
- **Why it matters:** Interactive TUIs need to respond to user actions without polling table state
- **Source:** `docs/repo_map/Evertras_bubble-table.md` — `UserEventHighlightedIndexChanged`, `UserEventRowSelectToggled`, `UserEventFilterInputFocused`
- **Implementation ideas:** Add event queue to Table state; emit events from navigation/filter methods; add `drainEvents()` accessor
- **Estimated complexity:** Medium — requires designing event types and queue
- **Expected impact:** High — enables reactive TUI patterns

**6. StyleFunc Dynamic Per-Cell Callback**
- **Description:** sugar-bits Table has `styleFunc(Closure): Style` for data-dependent styling. sugar-table uses only StyledCell for per-cell overrides.
- **Why it matters:** Conditional cell coloring (negative numbers red, alerts yellow) requires either pre-wrapping every cell in StyledCell or a callback
- **Source:** `docs/repo_map/sugarcraft_sugar-bits.md` — `styleFunc(\Closure $fn)` at line 74
- **Implementation ideas:** Add `withStyleFunc(?Closure $fn)` to Table; call it in `renderRowLines()` before applying column/row styles
- **Estimated complexity:** Low — 5-line method + 3-line invocation in render loop
- **Expected impact:** Medium — simplifies common styling pattern

### Medium Priority

**7. Stable Multi-Column Sort**
- **Description:** Uses `usort` which is not stable — equal primary keys can reorder. Go's `sort.Stable` maintains relative order.
- **Why it matters:** Multi-column sort on equal keys gives inconsistent results across re-renders
- **Source:** `docs/repo_map/Evertras_bubble-table.md` — `sort.Stable` description
- **Implementation ideas:** Use `uasort` with index-preserving comparison, or implement a Schwartzian transform
- **Estimated complexity:** Low — algorithm change in `filteredSortedRows()`
- **Expected impact:** Medium — subtle but correctness-affecting

**8. Visible Row Caching**
- **Description:** Every `View()` call recomputes entire filter/sort/pagination pipeline. Upstream has `visibleRowCache` invalidated on data changes.
- **Why it matters:** For large datasets, repeated View() calls waste CPU
- **Source:** `docs/repo_map/Evertras_bubble-table.md` — `visibleRowCache` + `visibleRowCacheUpdated`
- **Implementation ideas:** Add `$visibleRowCache` state; mark dirty on `withRows()`, `Filter()`, `SortBy()`, pagination changes; recompute only when dirty
- **Estimated complexity:** Medium — state management addition
- **Expected impact:** Medium — performance optimization for large tables

**9. KeyMap + Help Integration**
- **Description:** No `KeyMap` struct or `Help` integration for rendering keyboard shortcuts
- **Why it matters:** Users need to know available keyboard shortcuts
- **Source:** `docs/repo_map/charmbracelet_bubbles.md` — `KeyMap` interface with `ShortHelp()`/`FullHelp()`
- **Implementation ideas:** Add `KeyMap` class mirroring `sugar-bits/src/Key/Binding.php`; add `Help` component rendering keybindings
- **Estimated complexity:** Medium — requires Help component integration
- **Expected impact:** Medium — improves discoverability

**10. ContentGenerator for Dynamic Cells**
- **Description:** Cell content is static; stickers has `ContentGenerator(func(maxX, maxY) string)` for adaptive text
- **Why it matters:** Tables displaying dynamic content (timestamps, truncated text) benefit from adaptive rendering
- **Source:** `docs/repo_map/76creates_stickers.md` — `SetContentGenerator`
- **Implementation ideas:** Add `withContentGenerator(Closure $fn)` to Column or Row; call during `renderCell()` with available width
- **Estimated complexity:** Low-Medium
- **Expected impact:** Low — niche use case

### Low Priority

**11. Mouse Click Navigation** — No mouse event handling for placing cursor on a row
**12. Column Resizing at Runtime** — No drag-to-resize column widths
**13. Animated Row Highlight Transitions** — Upstream lipgloss supports smooth style transitions
**14. Custom FilterFunc Input** — sugar-bits Table has `withFilterPredicate(Closure)`; sugar-table lacks custom filter function

---

## Algorithm / Performance Opportunities

### Current Approach: usort + Inline Filter/Sort

The `filteredSortedRows()` method performs filter + sort + pagination in a single pipeline:

```php
// Filtering: array_filter with stripos
$rows = \array_filter($rows, fn(Row $row) => stripos((string)$val, $text) !== false);

// Sorting: usort with string/numeric detection
\usort($rows, fn($a, $b) => \is_numeric($sa) && \is_numeric($sb)
    ? (float)$sa <=> (float)$sb
    : \strcasecmp($sa, $sb));
```

**Issues:**
- `usort` is not stable — equal keys may reorder between calls
- No caching — every `View()` recomputes entire pipeline
- Filter iterates all rows even when no filters active
- Numeric detection uses `is_numeric()` on string values — may produce inconsistent results for formatted numbers

### External Approach: sort.Stable + VisibleRowCache

**bubble-table** (`docs/repo_map/Evertras_bubble-table.md`):
- Uses Go's `sort.Stable` for multi-column sort — guaranteed stable
- Has `visibleRowCache` with `visibleRowCacheUpdated` flag — only recomputes when dirty
- Has `extractNumber()` for robust numeric extraction from formatted strings

**stickers** (`docs/repo_map/76creates_stickers.md`):
- Ratio-based column layout via `calculateRatio()` — proportional space distribution
- Bubble sort `sortIndex()` — O(n²) worst case, but noted as acceptable for typical table sizes

### Recommendation

1. **Switch to stable sort** — Implement Schwartzian transform or use `uasort` with index comparison to preserve relative order of equal keys
2. **Add visible row caching** — Track dirty flags; recompute filter/sort only when data or filter state changes
3. **Optimize filter hot path** — Fast-path when no filters active (`$this->filterText === []`)

---

## Architecture Improvements

### Immediate Mode vs Retained Mode

sugar-table currently uses **retained mode** — state is mutated and `View()` renders the current state. This is appropriate for PHP but differs from ratatui's **immediate mode** pattern (`docs/repo_map/ratatui_ratatui.md`) where apps redraw everything each frame.

**Proposed architecture for interactive mode:**
```
┌─────────────────────────────────────────────┐
│ Table::update(Msg $msg): array{Table, ?Cmd} │
│  - Handle KeyMsg → navigate/filter          │
│  - Return [newTable, cmd]                   │
└─────────────────────────────────────────────┘
         ↓ (cmd = Closure returning Msg)
┌─────────────────────────────────────────────┐
│ Table::view(): string                        │
│  - Render current state to ANSI             │
└─────────────────────────────────────────────┘
```

This would mirror the `tea.Model` interface from upstream (`docs/repo_map/Evertras_bubble-table.md`) enabling proper TEA update loop integration.

### Row/Cell Decomposition

Currently `Table::View()` builds the entire table string in one pass. A decomposed approach (mirroring ratatui's `Widget` trait) would allow:
- Incremental rendering (only changed regions)
- composable table sub-components (header, body, footer as separate renderable units)

---

## API / Developer Experience Improvements

### 1. Unified Navigation Method

Currently `SelectNext()`, `SelectPrevious()`, `SelectPage()` are separate methods. A unified navigation API would be more ergonomic:

```php
// Current
$t = $t->SelectNext();
$t = $t->SelectPrevious();

// Proposed: single method
$t = $t->navigate(Navigation::Next);
$t = $t->navigate(Navigation::Previous);
$t = $t->navigate(Navigation::PageUp);
$t = $t->navigate(Navigation::PageDown);
$t = $t->navigate(Navigation::Top);
$t = $t->navigate(Navigation::Bottom);
```

### 2. Builder Configuration Object

Currently many `with*()` methods with boolean flags. A configuration object pattern would improve readability:

```php
// Current
$t = $t->withSelectable(true)->withPageSize(25)->withShowHeader(true);

// Proposed
$t = $t->withConfig(TableConfig::new()
    ->selectable()
    ->pageSize(25)
    ->showHeader()
    ->border(Border::rounded())
);
```

### 3. Method Chaining for Filter/Sort

Current: `Filter()` and `SortBy()` return `self`. This is correct for immutable pattern, but sugar-bits Table supports chained `thenSortBy()`:

```php
// Current
$t = $t->SortBy('name', true);
$t = $t->SortBy('city', false);  // replaces sort, no multi-column

// Proposed: thenSortBy chain
$t = $t->SortBy('name', true)->thenSortBy('city', false);
```

---

## Documentation / Cookbook Opportunities

### 1. Large Dataset Example
Demonstrate `withViewportHeight()` + `withScrollY()` for tables with 10,000+ rows

### 2. Interactive TUI Example
Full TEA loop example with ReactPHP integration showing table + keyboard events

### 3. StyledCell Cookbook
Examples of conditional cell styling: negative numbers red, status column color-coded, etc.

### 4. Multi-column Sort Guide
Explain primary vs secondary sort columns, interaction with pagination

### 5. Custom Filter Function Guide
Show how to implement custom `FilterFunc` for specialized filtering (date ranges, numeric comparisons)

---

## UX / TUI Improvements

### 1. Cursor Visual Distinction
Currently cursor is `reverse video` style (`7`). ratatui (`docs/repo_map/ratatui_ratatui.md`) and bubble-table support configurable cursor styles including block/bar/underline and custom colors.

### 2. Sort Indicator Glyphs
Upstream bubble-table appends `▲`/`▼` to sorted column headers. sugar-table does not render sort direction indicators.

**Fix:** In `renderHeader()`, detect if this column is in `$sortColumns` and append direction glyph

### 3. Filter Active Indicator
stickers (`docs/repo_map/76creates_stickers.md`) appends `⑂` to filtered column header. sugar-table doesn't indicate which columns have active filters.

### 4. Missing Cell vs Empty String Distinction
Currently both render as `missingIndicator`. Upstream distinguishes absent cells from explicitly empty cells.

---

## Testing / Reliability Improvements

### 1. Golden File Tests for View() Output

Currently `TableTest.php` tests behavior (sort order, row count) but not exact ANSI output. ratatui (`docs/repo_map/ratatui_ratatui.md`) uses golden file tests for rendering.

**Action:** Add snapshot tests asserting exact `\x1b[...m` bytes for known inputs

### 2. Fuzz Tests for Column Width Computation

The `computeColumnWidths()` algorithm has edge cases: percent values summing >100, zero columns, all Dynamic columns, etc.

**Action:** Add property-based tests using `fakerphp/faker` or similar

### 3. Performance Regression Tests

`View()` on 1000+ rows should complete within 50ms. Add benchmark test to catch performance regressions.

### 4. Filter Edge Cases

- Empty filter text (should be no-op)
- Filter matching all rows
- Filter matching no rows
- Unicode characters in filter text
- Very long filter text

---

## Ecosystem / Integration Opportunities

### 1. sugar-bits Table Parity

sugar-bits Table has `styleFunc(Closure)` for per-cell dynamic styling. sugar-table should add this for API parity:

```php
// sugar-bits API (exists)
$table->styleFunc(fn(int $row, int $col): Style => ...);

// sugar-table API (missing)
$t->withStyleFunc(fn(mixed $value, string $key, Row $row): string => ...);
```

### 2. candy-forms Integration

candy-forms has form primitives (TextInput, etc.) that could be composed with sugar-table for filter UI. The upstream bubble-table has a built-in text input for filtering triggered by `/`.

**Opportunity:** Create `FilterInput` component that integrates with table's `Filter()` method

### 3. sugar-charts Data Integration

For tables displaying chart data (metrics, statistics), sugar-charts could provide rendering helpers. Not currently integrated.

### 4. ReactPHP Integration Pattern

SugarCraft uses ReactPHP for async. sugar-table could provide a `TableModel` class implementing the `Model` interface from `candy-core` for proper TEA loop integration:

```php
final class TableModel implements Model
{
    public function update(Msg $msg): array { ... }
    public function view(): string { ... }
}
```

---

## Notable PRs / Issues / Discussions

### From Evertras/bubble-table (Primary Upstream)

**Issue: #246 Per-cell styleFunc** (`docs/repo_map/Evertras_bubble-table.md`)
- Long-requested feature for data-dependent cell styling
- SugarCraft already implemented this in sugar-bits but NOT in sugar-table
- **Lesson:** SugarCraft enhancements in sugar-bits should be ported to sugar-table

**PR: Fuzzy filter implementation** (`docs/repo_map/Evertras_bubble-table.md`)
- `filterFuncFuzzy` added as alternative to simple contains
- Algorithm: iterate needle runes, find ordered subsequence in haystack
- **Lesson:** Port this — it's only ~15 lines and closes a major feature gap

**Issue: FilterFuncFuzzy concatenates all columns** (`docs/repo_map/Evertras_bubble-table.md`)
- `filterFuncFuzzy` joins all filterable column values into one string before matching
- Cannot target specific columns with fuzzy logic
- **Lesson:** sugar-table's per-column filter approach may actually be superior for targeted filtering

### From 76creates/stickers

**Issue: Bubble sort performance** (`docs/repo_map/76creates_stickers.md`)
- Author acknowledges O(n²) worst case and invites faster-algorithm PRs
- **Lesson:** Don't copy bubble sort — use `usort` (already done) or implement stable sort

**Design: ContentGenerator pattern** (`docs/repo_map/76creates_stickers.md`)
- `SetContentGenerator(func(maxX, maxY int) string)` enables adaptive cell content
- **Lesson:** Consider adding to sugar-table for width-aware cell content adaptation

### From textualize/textual

**DataTable reactivity** (`docs/repo_map/textualize_textual.md`)
- Uses reactive state (`var()`) with automatic watcher injection
- Sorting/filtering updates propagate automatically to UI
- **Lesson:** Could inspire sugar-table's event system design

---

## Recommended Roadmap

### Immediate Wins (1-2 days each)

1. **Fix sort indicator glyphs** — Append `▲`/`▼` to sorted column headers in `renderHeader()`
2. **Fix filter active indicator** — Append `⑂` to filtered column headers
3. **Add fuzzy filtering** — Port `fuzzySubsequenceMatch()` from bubble-table
4. **Fix stable sort** — Implement index-preserving comparison in multi-column sort

### Medium-Term Improvements (1-2 weeks each)

5. **Fix frozen column rendering** — Re-architect `View()` to properly handle frozen columns during horizontal scroll
6. **Fix horizontal scroll rendering** — Apply `scrollX` offset during column rendering
7. **Expand keyboard navigation** — Add vim keys (j/k/g/G), HalfPageUp/Down, Home/End
8. **Add StyleFunc callback** — Mirror sugar-bits `styleFunc()` for dynamic per-cell styling
9. **Add visible row caching** — Cache filter/sort results; invalidate on state change

### Major Architectural Upgrades (3-4 weeks)

10. **Implement UserEvent system** — Event queue for highlight changes, selection, filter focus
11. **Add KeyMap + Help integration** — Render keyboard shortcut help
12. **Model interface implementation** — Proper TEA update loop for ReactPHP integration
13. **Viewport-aware content generation** — ContentGenerator for width-aware cell content

### Experimental Ideas

14. **Column drag-to-resize** — Interactive column width adjustment
15. **Animated cursor transitions** — Smooth highlight movement
16. **Mouse hover tooltips** — Show full cell content on mouse hover
17. **Async data loading** — Support for lazy-loaded row data with ReactPHP

---

## Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|-------------|--------|------------|------|---------------------|
| Fuzzy filtering | High | Low | Low | **P0 — Immediate** |
| Sort indicator glyphs | Medium | Low | Low | **P0 — Immediate** |
| Filter indicator glyph | Medium | Low | Low | **P0 — Immediate** |
| Stable sort | Medium | Low | Medium | **P0 — Immediate** |
| Frozen column rendering | Critical | Medium | Medium | **P1 — Week 1** |
| Horizontal scroll rendering | Critical | Medium | Medium | **P1 — Week 1** |
| Keyboard navigation (vim keys) | High | Low | Low | **P1 — Week 1** |
| StyleFunc callback | Medium | Low | Low | **P2 — Week 2** |
| Visible row caching | Medium | Medium | Low | **P2 — Week 2** |
| UserEvent system | High | Medium | High | **P2 — Week 3** |
| KeyMap + Help | Medium | Medium | Low | **P3 — Week 3-4** |
| ContentGenerator | Low | Medium | Low | **P3 — Future** |
| Model interface | High | High | High | **P3 — Future** |
| Mouse navigation | Medium | Medium | Medium | **P4 — Future** |
| Column resize | Medium | High | High | **P4 — Future** |

---

## Final Strategic Assessment

**sugar-table** is a well-architected, mature PHP table component that achieves ~85% feature parity with its primary upstream `Evertras/bubble-table`. Its unique strengths — viewport virtualization, ColumnWidth/WrapMode enums, multi-column AND filtering, and Border family integration — make it the most capable table component in the SugarCraft ecosystem. The library is production-ready for basic use cases but has **critical gaps in horizontal scroll rendering and frozen column handling** that prevent it from being truly usable for wide-table scenarios.

### Competitive Position

- **vs bubble-table:** Close to feature parity; missing fuzzy filter, UserEvent system, stable sort, proper frozen column rendering
- **vs stickers Table:** Superior filtering (multi-column AND vs single-column), superior column width modes, has pagination (stickers doesn't)
- **vs sugar-bits Table:** More features (4-column-width modes, 3-wrap modes, viewport virtualization), but sugar-bits has StyleFunc callback that sugar-table lacks
- **vs ratatui Table:** ratatui's constraint-solver layout and immediate-mode rendering are architecturally different; direct comparison difficult

### Key Recommendations

1. **Fix horizontal scroll and frozen column rendering immediately** — These are table-stakes features for wide tables and currently broken
2. **Port fuzzy filtering from upstream** — Low complexity, closes major upstream feature gap
3. **Implement stable sort** — Quick fix for multi-column sort correctness
4. **Add StyleFunc callback** — Brings sugar-table to parity with sugar-bits Table
5. **Design UserEvent system** — Enables reactive TUI use cases; important for long-term ecosystem value

The library's architecture is sound and its immutability patterns are well-implemented. The main work is filling implementation gaps rather than architectural refactoring. With the horizontal scroll and frozen column fixes, sugar-table would be a best-in-class table component for PHP TUI applications.

---

*Report generated from analysis of: `docs/repo_map/sugarcraft_sugar-table.md`, `sugar-table/README.md`, `sugar-table/CALIBER_LEARNINGS.md`, `sugar-table/src/Table.php`, `sugar-table/src/Column.php`, and upstream/downstream repos at `docs/repo_map/Evertras_bubble-table.md`, `docs/repo_map/76creates_stickers.md`, `docs/repo_map/charmbracelet_bubbles.md`, `docs/repo_map/ratatui_ratatui.md`, `docs/repo_map/textualize_textual.md`, `docs/repo_map/sugarcraft_sugar-bits.md`.*
