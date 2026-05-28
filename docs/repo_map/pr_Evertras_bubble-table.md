# PR: Second-Stage Ecosystem Intelligence Report for Evertras/bubble-table

## 1. Repository Overview

| Attribute | Value |
|---|---|
| **URL** | https://github.com/Evertras/bubble-table |
| **Language** | Go |
| **Stars** | 567 |
| **Forks** | 34 |
| **License** | MIT |
| **Created** | 2022-02-12 |
| **Latest Release** | v0.19.2 (2025-09-06) |
| **Contributors** | 20 |
| **Open Issues** | ~20 |
| **Default Branch** | main |
| **Ecosystem** | Charmbracelet/Bubble Tea TUI framework |

**Purpose**: A customizable, interactive table component for the Bubble Tea framework (Charmbracelet's TUI framework for Go). Provides terminal-rendered tables with sorting, filtering, pagination, selection, and extensive styling options.

**Architecture**: Full Bubble Tea `tea.Model` interface implementation (`Init`/`Update`/`View`). Immutable/fluent API where all `With*` methods return new Model instances. Tight integration with lipgloss for ANSI SGR styling.

---

## 2. Existing SugarCraft Mapping

The `sugar-table` library is the direct PHP port of bubble-table. Per the original analysis:

**Direct mappings exist for:**
- `Model` → `Table` (main table class, fluent `with*` pattern)
- `Column` → `Column` (sugar-table adds `ColumnWidth` enum, `WrapMode`, `Percent`)
- `Row` → `Row` (both immutable copies)
- `RowData` → `RowData` (both use `map[string]any` / associative array)
- `StyledCell` → `StyledCell`
- `Border` → `Border` (from `SugarCraft\Sprinkles\Border`)
- `FilterFunc` / `FilterFuncInput` → `Table::Filter()`
- `filterFuncContains` → Built into `filteredSortedRows()`
- `SortColumn` / `SortDirection` → `sortColumns` array + `SortBy()`
- `WithMultiline` → `withMultilineMode()`
- `WithMaxTotalWidth` + scrolling → `withScrollX()`
- `WithHorizontalFreezeColumnCount` → `withFrozenCols()`
- `WithRowStyleFunc` (zebra) → `withZebra()` + zebra styling
- `WithMissingDataIndicator` → `withMissingIndicator()`

**Not yet ported:**
- `filterFuncFuzzy` (fuzzy subsequence matching)
- `UserEvent*` types (event system for user interactions)

**Sugar-table additions beyond bubble-table:**
- `ColumnWidth` enum: `Fixed`, `Percent`, `Dynamic`, `Content`
- `WrapMode` enum: `None`, `WordWrap`, `Character`
- `viewportHeight` / `withViewportHeight()` — viewport virtualization
- `scrollY` / `withScrollY()` — vertical scrolling offset
- Direct `SelectNext()`, `SelectPrevious()`, `SelectPage()` navigation methods

---

## 3. Previously Identified Gaps

From the original analysis:

1. **Event system not ported** — sugar-table is render-only; no `UserEvent` system
2. **Fuzzy filtering** — Not yet implemented (`filterFuncFuzzy`)
3. **Frozen columns** — Partial (via `withFrozenCols()`)
4. **Hidden metadata pattern** — Not first-class; requires using unused column keys
5. **Filter state not separable** — Filtering requires table to own filter textinput state
6. **Go-only tight coupling** — Cannot be used outside Bubble Tea ecosystem

---

## 4. High-Signal Open Issues

### Issue #216: Request to migrate to BubbleTea V2 (Mar 2026)
- **Author**: ankkyprasad
- **Signal**: High — indicates active users wanting to stay current with upstream
- **Key insight**: v2 migration is a user priority; the library must track upstream framework evolution
- **Relevance to SugarCraft**: SugarCraft ports must plan for framework version migrations and avoid hard-coupled assumptions

### Issue #213: Feature request - literal match tokens in fuzzy filter (Sep 2025)
- **Author**: TLINDEN
- **Signal**: User needs more control over fuzzy matching behavior
- **Content**: User wants to be able to match literal tokens (not fuzzy) in fuzzy filter mode
- **Direct Risk to SugarCraft**: If sugar-table implements fuzzy filtering, it needs the same escape/literal-match capability
- **Strategic Opportunity**: SugarCraft could implement better fuzzy matching API from the start

### Issue #212: Update() is not being called every "tick" (Sep 2025)
- **Author**: TLINDEN
- **Signal**: Confusion about update lifecycle; user expects continuous rendering ticks
- **Content**: User expecting periodic refresh but Update() only called on events
- **Direct Risk to SugarCraft**: Sugar-table likely has same issue; users expect continuous rendering

### Issue #211: Unable to apply a style to header fields only (Sep 2025)
- **Author**: TLINDEN
- **Signal**: Styling granularity gap; user wants per-section (header vs body) styling
- **Content**: Can only style entire header row, not individual header cells
- **Direct Risk to SugarCraft**: Same limitation likely exists in sugar-table

### Issue #201: Question - How to update table data? (Sep 2025)
- **Author**: TLINDEN
- **Signal**: Confusing data update pattern in immutable architecture
- **Content**: User doesn't know how to update row data in an immutable model
- **Key insight**: The immutable/fluent pattern is intuitive for configuration but confusing for dynamic data updates. **This was addressed via PR #210 with `WithGlobalMetadata`** as a workaround
- **Direct Risk to SugarCraft**: Sugar-table likely has same confusion; need clear patterns for data updates

### Issue #194: Planned support for bubbletea/lipgloss/bubbles v2? (Jul 2025)
- **Author**: lrstanley
- **Signal**: Active v2 branch tracking; maintainer created `v2-exp` branch
- **Key insight**: Maintainer acknowledged and began work proactively
- **Status (2026-02)**: Bubbletea v2 is now out; community has workarounds (e.g., ignoring returned command)
- **Relevance to SugarCraft**: Same version-coupling risk exists for PHP ports

### Issue #192: Adding a Row Border (Apr 2025)
- **Author**: smitt04
- **Signal**: Feature gap; users want row-level (not cell-level) borders
- **Content**: Request for visual separation between rows
- **Direct Risk to SugarCraft**: If sugar-table doesn't have row borders, users will request them

### Issue #191: Hiding header columns breaks the table (Feb 2025)
- **Author**: zthreee
- **Signal**: Bug when column visibility is toggled
- **Content**: Hiding columns causes rendering breakage
- **Direct Risk to SugarCraft**: Potential similar bug in sugar-table

### Issue #190: Feature Request - Ability to change highlighted row color! (Feb 2025)
- **Author**: WhipMeHarder
- **Signal**: High-demand styling feature (color customization of cursor/selection)
- **Content**: User wants to customize highlight color for accessibility/ aesthetics
- **Direct Risk to SugarCraft**: sugar-table has `withHighlight()` method; must ensure it works reliably

### Issue #189: Can the tables be edited? (Jan 2025)
- **Author**: cognociente
- **Signal**: Common expectation that tables should support inline editing
- **Content**: User wants editable cells/rows
- **Maintainer response**: Out of scope — library is display-only
- **Relevance to SugarCraft**: Sugar-table should clarify its scope (display vs. editing)

### Issue #188: How to set target height of a table? (Nov 2024)
- **Author**: hemanth2004
- **Signal**: **HIGH** — Long-running pain point; affects layout integration
- **Content**: When multiline is enabled, cell content overflows and breaks layout calculations
- **User workaround**: Extremely hacky solution calculating overflow lines manually
- **Key insight**: **Multiline + PageSize is fundamentally broken for dynamic terminal layouts**
- **Maintainer acknowledgment**: Issue #129 is "the same problem but rephrased differently"
- **Pattern**: Users recommended wrapping table's View() in a viewport bubble as workaround
- **Direct Risk to SugarCraft**: sugar-table's `withMultilineMode()` likely has same issue

### Issue #186: Feature Request - Allow sorting by a 'Time' column (Nov 2024)
- **Author**: MaximilianSoerenPollak
- **Signal**: Users want type-aware sorting (dates, timestamps)
- **Content**: Currently no built-in support for time/date sorting
- **Workaround**: User must implement custom sort or pre-sort data
- **Direct Risk to SugarCraft**: sugar-table's `SortBy()` should support time/date types

### Issue #129: Flexible max number of rows based on terminal size (Jan 2023)
- **Author**: PrimalPimmy
- **Signal**: **CRITICAL** — Long-running (3+ years), multi-participant, unresolved
- **Content**: Dynamic page size based on terminal resize
- **Key pattern**: `WithPageSize` creates a copy that must be reassigned; calling it in View() does nothing
- **Root cause**: Immutable architecture + dynamic sizing = awkward
- **User discovery**: Wrapping table in viewport bubble works around this
- **Direct Risk to SugarCraft**: sugar-table likely has identical issue

---

## 5. Important Closed Issues

### Issue #179: Compatibility with charmbracelet/bubbles >= v0.19.0 (Aug-Sep 2024)
- **Author**: nikklassen
- **Status**: Closed as completed (v0.17.0)
- **Content**: Generic function signature change in upstream `key.Matches` broke compatibility
- **Fix**: Bump Go version to 1.18+; merged PR #180
- **Lesson**: Tightly coupled to ecosystem; breaking changes in upstream require reactive updates
- **Relevance to SugarCraft**: SugarCraft must track upstream changes actively

### Issue #178: Upgrading bubbletea v0.25.0 → v0.26.6 breaks table rendering (Jul-Aug 2024)
- **Author**: dnalor
- **Status**: Closed (self-resolved; couldn't reproduce)
- **Content**: Terminal resize causes rendering artifacts
- **Pattern**: Related to issue #121 (unverified)
- **Lesson**: Rendering artifacts on resize are a recurring problem in TUI libraries
- **Relevance to SugarCraft**: Sugar-table should have resize handling tests

### Issue #170: Selecting a row while filtered loses all other rows (2024)
- **Author**: (implied from PR #171)
- **Status**: Fixed in PR #171
- **Content**: Critical bug — row selection while filtered destroys unfiltered row data
- **Signal**: This was a real data loss bug affecting user workflows
- **Direct Risk to SugarCraft**: sugar-table's filtering + selection interaction should be tested

---

## 6. Recurring Pain Points

### Pain Point 1: Immutable Architecture + Dynamic Data = Confusion
**Frequency**: High across multiple issues (#201, #129)
**Pattern**: Users create table with `WithRows()`, then try to update data imperatively
**Root cause**: Fluent/builder pattern is intuitive for configuration but not for runtime data changes
**Workaround needed**: Clear patterns for "replace all rows" vs "update specific row" vs "append row"

### Pain Point 2: Multiline + Dynamic Sizing = Broken Layouts
**Frequency**: High (#188, #129)
**Pattern**: When multiline cells exist, page size ≠ terminal lines; table grows unbounded
**Root cause**: Page size counts rows, not terminal lines; multiline breaks the assumption
**Maintainer position**: Non-trivial to fix due to dynamic footers etc.

### Pain Point 3: Terminal Resize Rendering Artifacts
**Frequency**: Medium (issues #178, #121)
**Pattern**: Vertical resize beyond table size causes visual corruption
**Root cause**: Redraw logic doesn't handle resize gracefully
**Workaround**: None documented; depends on terminal emulator

### Pain Point 4: Filter State Management
**Frequency**: Medium (#201, #213, #187)
**Pattern**: Users want external control over filter textinput
**Current limitation**: Table owns filter textinput state; external management is "hacky"
**Signal**: The built-in filter input is convenient but limiting for complex apps

### Pain Point 5: Hidden Metadata is Awkward
**Frequency**: Low but persistent
**Pattern**: Attaching metadata to rows requires using unused column keys
**Requested**: First-class `WithMetadata()` on Row
**Maintainer pattern**: Not addressed in core; community forks exist

---

## 7. Frequently Requested Features

### 1. Inline Table Editing (Issue #189)
- **Demand**: High
- **Request**: Editable cells/rows directly in table
- **Maintainer position**: Out of scope (display-only library)
- **Strategic position for SugarCraft**: Could implement but would significantly increase complexity

### 2. Dynamic Terminal-Responsive Sizing (Issues #129, #188)
- **Demand**: Very High
- **Request**: Table height adapts to terminal resize
- **Current workaround**: Wrap in viewport bubble
- **SugarCraft opportunity**: sugar-table's `withViewportHeight()` already addresses this partially

### 3. Row Borders / Separators (Issue #192)
- **Demand**: Medium
- **Request**: Visual separation between rows
- **Current state**: Not available
- **SugarCraft opportunity**: Could implement via row-level border characters

### 4. Custom Highlight Color (Issue #190)
- **Demand**: High
- **Request**: Customize cursor/selection highlight color
- **SugarCraft status**: sugar-table has `withHighlight()` — verify it works for this use case

### 5. Type-Aware Sorting (Issue #186)
- **Demand**: Medium
- **Request**: Sort by time/date columns
- **Current workaround**: Pre-sort data or custom sort function
- **SugarCraft opportunity**: Built-in date/time sorting

### 6. Global Metadata for Style/Filter Funcs (Addressed in PR #210)
- **Demand**: Medium
- **Request**: Access shared context in style/filter functions
- **Solution**: `WithGlobalMetadata()` added in v0.19.2
- **SugarCraft relevance**: sugar-table should add equivalent

### 7. Literal Match in Fuzzy Filter (Issue #213)
- **Demand**: Medium
- **Request**: Escape fuzzy matching for literal token match
- **SugarCraft relevance**: If implementing fuzzy filter, must address

### 8. Header-Only Styling (Issue #211)
- **Demand**: Medium
- **Request**: Style header cells independently
- **SugarCraft relevance**: Check if sugar-table has this capability

---

## 8. Important PRs

### PR #210: Global metadata for style and filter funcs (Sep 2025)
- **Author**: Evertras (owner)
- **Status**: Merged v0.19.2
- **Significance**: Addresses #201 (how to update table data) as a clean solution
- **Pattern**: Added `WithGlobalMetadata()` method; passed `GlobalMetadata` to `FilterFuncInput` and `StyledCellFuncInput`
- **Lesson**: Adding metadata context to callback inputs is a good pattern for extensibility

### PR #207: Fix filter example when entering 'q' (Sep 2025)
- **Author**: Evertras
- **Status**: Merged
- **Significance**: Filter input focus wasn't being checked before handling 'q' to quit
- **Pattern**: Need to check `GetIsFilterInputFocused()` before consuming keyboard events

### PR #209: Add Row to StyleFuncInput (Sep 2025)
- **Author**: Evertras
- **Status**: Merged
- **Significance**: Style functions can now access the full Row object
- **Pattern**: Adding more context to callback inputs improves usability

### PR #180: Fix bubbles compatibility (Aug 2024)
- **Author**: ashmckenzie (community)
- **Status**: Merged v0.17.0
- **Significance**: Fixes generic function signature incompatibility with bubbles >= v0.19.0
- **Lesson**: Community contributions are critical for ecosystem compatibility

### PR #171: Fix selecting row while filtered losing other rows
- **Author**: Evertras
- **Status**: Merged
- **Significance**: Critical bug fix for data integrity
- **Lesson**: Filtered selection state needs careful handling

### PR #214: Add support for literal token match, use levenshtein fuzzy mod (Sep 2025)
- **Author**: TLINDEN
- **Status**: Open (at time of data collection)
- **Significance**: Enhanced fuzzy filtering with Levenshtein distance
- **Pattern**: Community actively extending functionality

---

## 9. Architectural Changes

### v0.19.x Series (Latest)
- **FilterFuncInput refactor**: Changed from individual parameters to struct input (backward compatible via ignore unused param)
- **GlobalMetadata addition**: Shared context for style/filter functions
- **Row added to StyleFuncInput**: More context for cell styling

### v0.17.x (Go 1.18 bump)
- **Generics requirement**: Upstream bubbles library required generics
- **Breaking change**: Required Go 1.18+

### v0.16.x → v0.15.x
- **Horizontal scrolling fixes** (#182)
- **Filter func invalidation on apply** (#193)

### Architectural Pattern: Immutable with()
All `With*` methods return new Model instance. This is:
- **Positive**: Thread-safe, predictable, no hidden state
- **Negative**: Users must reassign; calling in View() does nothing (common mistake, see #129)

---

## 10. Performance Discussions

### Issue #276 (charmbracelet/bubbles): Table efficiency (Oct 2022)
- **Signal**: CRITICAL — 4.5k rows causes 0.5 second move operations (250x slower than 1k rows)
- **Root cause**: `UpdateViewPort()` iterates and renders entire table on each MoveUp/MoveDown
- **Suggested fix**: Only render visible rows
- **Note**: This is for charmbracelet/bubbles table, NOT Evertras bubble-table, but the architecture pattern is similar
- **Implication**: Both tables have O(n) viewport updates where n = total rows
- **SugarCraft relevance**: Must implement viewport-cached rendering to avoid this

### Issue #810 (charmbracelet/bubbles): Paginator performance (Jul 2025)
- **Signal**: 8000 items causes lag on navigation
- **Root cause**: String concatenation in `dotsView()` was O(n²)
- **Fix**: Replace concatenation with `strings.Builder` (O(n))
- **Lesson**: Pagination controls can become performance bottlenecks at scale

### Performance Pattern: Visible Row Caching
- bubble-table uses `visibleRowCache` with `visibleRowCacheUpdated` flag
- Cache invalidated on data/filter/sort changes
- This helps but doesn't solve O(n) rendering for large tables

---

## 11. Extensibility Discussions

### Style Functions
- `StyledCellFunc`: `func(StyledCellFuncInput) lipgloss.Style` — receives Data, Column, Row, GlobalMetadata
- `RowStyleFunc`: For zebra striping and row-level styles
- **Pattern**: Functions receive context structs; return styled output

### Filter Functions
- `FilterFunc`: `func(FilterFuncInput) bool` — return true to include row
- Built-in: `filterFuncContains` (case-insensitive), `filterFuncFuzzy` (subsequence match)
- **Pattern**: Custom filters enable any filtering logic

### KeyMap Extensibility
- Full rebinding of keyboard shortcuts
- `additionalShortHelpKeys` / `additionalFullHelpKeys` for help menu extension

### Extensibility Gap: Metadata
- No first-class row metadata; must use unused column keys
- Workaround via `WithGlobalMetadata()` for table-level metadata
- **Gap**: Row-level metadata injection is still awkward

---

## 12. API/UX Complaints

### 1. "How to update table data?" (#201)
- **Complaint**: Immutable pattern is confusing for dynamic updates
- **User confusion**: Don't know they need to reassign `m = m.WithRows(newRows)`
- **Maintainer solution**: GlobalMetadata helps but doesn't fully solve
- **SugarCraft lesson**: Must provide clear examples for "replace all rows" pattern

### 2. "WithPageSize doesn't work when called in View()" (#129)
- **Complaint**: Calling `m.table.WithPageSize(m.height - 8)` in View() does nothing
- **Root cause**: `With*` returns a new instance; must be assigned
- **User discovery**: This is a very common mistake
- **SugarCraft lesson**: sugar-table likely has same issue; must test and document

### 3. "Filter 'q' quits app even when filter input is focused" (#187/#207)
- **Complaint**: Pressing 'q' to quit while filter is active quits app instead of clearing filter
- **Fix**: Check `GetIsFilterInputFocused()` before handling quit
- **Lesson**: Focus state must be considered for all keyboard handlers

### 4. "Selecting row while filtered loses all other rows" (#170)
- **Complaint**: Selection state doesn't persist when filter is cleared
- **Bug**: Real data loss issue
- **SugarCraft lesson**: Selection + filter interaction must be tested

---

## 13. Migration Problems

### Go Version Migrations
- **Go 1.13 → 1.18**: Required for generics support in bubbles >= v0.19.0 (#179)
- **Pattern**: SugarCraft faces PHP version coupling similarly
- **Risk**: PHP 8.x version changes can break SugarCraft ports

### Bubble Tea v1 → v2 Migration (#216)
- **Status**: Active (Mar 2026)
- **Problem**: v2 changes break compatibility
- **Maintainer response**: Created `v2-exp` branch for testing
- **Community workaround**: Ignore returned command when calling `Update()` (#216 workaround from inkel)
- **SugarCraft risk**: sugar-table must track Bubble Tea v2 changes if PHP port follows

### Terminal Emulator Compatibility (#178)
- **Problem**: Different terminal emulators (urxvt, kitty) render differently on resize
- **Root cause**: Some artifacts are terminal-specific
- **Lesson**: TUI libraries have inherent terminal-dependent behavior

---

## 14. Clever Fixes & Workarounds

### Workaround 1: Viewport Bubble Wrapper for Dynamic Height
```go
// From issue #129 — wrap table's View() in a viewport bubble
// "I currently have the table's .Update() supplanted by the viewport's"
```
**When to use**: When multiline + dynamic height is needed
**SugarCraft relevance**: sugar-table's `withViewportHeight()` may provide this natively

### Workaround 2: Calculate Overflow Lines Manually
```go
// From issue #188 — user calculates max overflow in each row
// then subtracts from WithMinimumHeight() input
```
**Pattern**: Pre-compute line count before setting page size
**SugarCraft relevance**: Complex; may need to provide helper for this

### Workaround 3: Filter Input Focus Check Before Quit
```go
// From issue #207 — check if filter has focus before consuming 'q'
if !m.table.GetIsFilterInputFocused() {
    cmds = append(cmds, tea.Quit)
}
```
**Pattern**: All global key handlers should check component focus state
**SugarCraft relevance**: Implement in sugar-table's keyboard handling

### Workaround 4: Ignore Returned Command for v2 Compatibility
```go
// From issue #216 — inkel's workaround
// When calling table.Update(), ignore returned command
_, _ = table.Update(msg)
```
**Pattern**: Simpler but loses some interactivity
**SugarCraft relevance**: May need similar compatibility shim

---

## 15. Community Workarounds

### Fork: bluedone/bubble-table
- Minimal modifications (1 fork)
- Shows pattern of forking for custom needs

### Fork: SoftwareKings/bubble-table  
- Minimal modifications (1 fork)
- Purpose: Unknown

### Community PRs
- #180 (ashmckenzie): Fix bubbles compatibility
- #182 (wheelibin): Horizontal scrolling fix
- #175 (trevorstarick): Add filterFunc to model
- #196 (IAL32): Add WithFuzzyFilter

**Pattern**: Community actively contributes bug fixes and minor features

---

## 16. Maintainer Guidance Patterns

### Pattern 1: "Won't fix / Out of scope"
- Inline editing (#189): "The library is meant to be a display library"
- This keeps scope tight but users must build their own editing UI

### Pattern 2: "Workaround available"
- Dynamic height (#129): "Wrap in a viewport bubble"
- Gives users a path forward without expanding scope

### Pattern 3: "Check the examples"
- Many questions answered by pointing to working examples
- Examples directory is a first-class documentation artifact

### Pattern 4: "Breaking change for clarity"
- FilterFuncInput refactor: "We can add more things without breaking compatibility"
- Willing to break API for cleaner design when justified

### Pattern 5: "Community fix welcome"
- Compatibility issues sometimes fixed by community first (#180)
- Shows importance of community contribution pipeline

---

## 17. Rejected Ideas Worth Revisiting

### 1. Inline Cell Editing
- **Requested**: #189 and others
- **Maintainer position**: Out of scope (display library)
- **Strategic position for SugarCraft**: Could be a differentiator but would require significant work
- **Assessment**: Correct decision for v1; consider for v2

### 2. Direct Terminal Height Coupling
- **Requested**: #129
- **Maintainer position**: Won't couple directly; use `WithPageSize` with `msg.Height`
- **Rationale**: Many layout scenarios beyond just terminal height
- **Assessment**: Reasonable; viewport wrapper is the escape hatch

### 3. Column Resizing at Runtime
- **Not requested but noted in original analysis**: No drag-to-resize like terminal file managers
- **Assessment**: Would be complex; not worth it for most use cases

---

## 18. Problems Likely Relevant to SugarCraft

| Issue | bubble-table | SugarCraft Risk | Mitigation |
|-------|---------------|-----------------|------------|
| Immutable updates confusion | #201 | **HIGH** — same confusion likely | Clear "replace rows" example |
| Multiline + page size | #129, #188 | **HIGH** — same issue | Use viewportHeight() properly |
| Filter + selection loss | #170 | **HIGH** — test needed | Add test for filtered selection |
| Terminal resize artifacts | #178 | **MEDIUM** — TUI issue | Test resize handling |
| Go version coupling | #179 | **HIGH** — PHP version risk | Track PHP 8.x changes |
| Event system absence | N/A (not ported) | **HIGH** — no interactivity | Implement event/callback system |
| Fuzzy filter gaps | #213 | **MEDIUM** — if implementing | Design proper fuzzy API |
| Global metadata | Addressed in v0.19.2 | **MEDIUM** — sugar-table lacks | Add `withGlobalMetadata()` |

---

## 19. Features SugarCraft Should Consider

### Priority 1: Critical Gaps
1. **`withGlobalMetadata()` / Global context** — Style/filter functions need shared context
2. **Event/Callback system** — UserEvent equivalents for interactivity
3. **Viewport-based rendering** — Only render visible rows for performance
4. **Proper data update patterns** — Clear API for "replace all rows"

### Priority 2: High-Value Additions
1. **Fuzzy filtering with literal token support** — Issue #213 pattern
2. **Type-aware sorting** — Date/time column sorting (#186)
3. **Row borders** — Visual separation between rows (#192)
4. **Header cell styling** — Independent header cell styles (#211)
5. **Zebra striping improvements** — Ensure `withZebra()` is robust

### Priority 3: Nice to Have
1. **Column resizing at runtime** — Drag-to-resize (complex)
2. **Inline editing** — Editable cells (out of scope for v1)
3. **Keyboard shortcut customization** — Full KeyMap replacement

---

## 20. Architectural Lessons

### Lesson 1: Immutable + Fluent is Great for Config, Hard for Dynamic State
- **Evidence**: Multiple issues show users confused about updating data
- **Pattern**: Consider separating "table configuration" (immutable builder) from "table state" (mutable ref)
- **SugarCraft approach**: sugar-table is already immutable; must provide clear update patterns

### Lesson 2: Viewport-Only Rendering is Essential for Performance
- **Evidence**: O(n) viewport updates cause 250x slowdown at 4.5k rows
- **Pattern**: Cache visible row renders; only re-render on data/scroll changes
- **SugarCraft approach**: sugar-table's `viewportHeight()` helps; ensure it's efficient

### Lesson 3: Callbacks Need Maximum Context
- **Evidence**: PRs #209, #210 continuously add more context to callback inputs
- **Pattern**: `StyledCellFuncInput` now has: Data, Column, Row, GlobalMetadata
- **SugarCraft approach**: Provide rich context to style/filter functions

### Lesson 4: Focus State is Critical for Composite UIs
- **Evidence**: Issue #207 — 'q' handler needed focus check
- **Pattern**: Any component that can hold focus must be queried before consuming keys
- **SugarCraft approach**: Implement `isFilterInputFocused()`, `isFocused()`, etc.

### Lesson 5: Filter State Should Be Separable
- **Evidence**: Users want external filter control; current design couples filter input to table
- **Pattern**: `WithFilterInput()` allows external textinput; `WithFilterFunc()` for custom logic
- **SugarCraft approach**: Allow external filter control; don't force built-in input

---

## 21. Defensive Design Lessons

### Lesson 1: Always Invalidate Caches on Data Changes
- **Evidence**: Bug #170 (selecting filtered row loses data) was cache invalidation issue
- **Pattern**: Any transformation cache (filtered rows, sorted rows, visible rows) must invalidate on source changes
- **SugarCraft approach**: Ensure `filteredSortedRows()` cache is invalidated properly

### Lesson 2: Test Filter + Selection Interaction
- **Evidence**: #170 was a real data loss bug
- **Pattern**: Test: filter rows → select some → clear filter → verify selection is correct
- **SugarCraft approach**: Add integration test for filter + selection

### Lesson 3: Terminal Resize Handling
- **Evidence**: #178 shows resize can cause rendering artifacts
- **Pattern**: Handle `WindowSizeMsg` properly; re-render on resize
- **SugarCraft approach**: Test table with various terminal sizes; verify resize handling

### Lesson 4: Prevent Common Immutable Mistakes
- **Evidence**: #129 — users calling `With*` in View() and wondering why it doesn't work
- **Pattern**: Consider adding deprecation warning or runtime check for this pattern
- **SugarCraft approach**: Document clearly; consider if PHP's reference semantics help or hurt

### Lesson 5: Check Focus Before Global Key Handling
- **Evidence**: #207 — filter focus was not checked before quit handling
- **Pattern**: When table has focus, check if any sub-component has focus before handling keys
- **SugarCraft approach**: Implement focus hierarchy properly

---

## 22. Ecosystem Trends

### Trend 1: Toward More Context in Callbacks
- **Pattern**: FilterFuncInput, StyleFuncInput getting more fields over time
- **Direction**: More context, not less; future-proof via struct inputs

### Trend 2: Global Metadata for Theme/Configuration
- **Pattern**: PR #210 shows need for shared context
- **Direction**: Enables dynamic theming without rebuilding table

### Trend 3: Bubble Tea v2 Migration
- **Pattern**: Active work on v2-exp branch
- **Direction**: Ecosystem is evolving; ports must track actively

### Trend 4: Performance at Scale
- **Pattern**: Issues at 4.5k-8k rows show scaling problems
- **Direction**: Viewport-only rendering becoming standard

### Trend 5: Fuzzy Matching Demand
- **Pattern**: #213 (literal tokens), #196 (fuzzy filter)
- **Direction**: Users want smarter search beyond simple contains

---

## 23. Strategic Opportunities

### Opportunity 1: Implement Event System for SugarCraft
- **Why**: sugar-table lacks UserEvent equivalents; limits interactivity
- **Approach**: Add event callbacks: `onSelectionChange()`, `onFilterChange()`, `onHighlightChange()`
- **ROI**: High — enables reactive TUI patterns

### Opportunity 2: Better Fuzzy Filtering
- **Why**: bubble-table's fuzzy is limited; community wants Levenshtein distance
- **Approach**: Implement proper fuzzy matching with configurable sensitivity
- **ROI**: Medium — differentiates from simple contains

### Opportunity 3: Viewport Virtualization
- **Why**: O(n) rendering kills performance at scale
- **Approach**: Only render visible rows + buffer; cache aggressively
- **ROI**: High — enables 10k+ row tables

### Opportunity 4: Global Metadata API
- **Why**: Pattern established in bubble-table v0.19.2; sugar-table lacks it
- **Approach**: Add `withGlobalMetadata()` equivalent
- **ROI**: Medium — enables dynamic theming

### Opportunity 5: Type-Aware Sorting
- **Why**: Date/time sorting is a common need (#186)
- **Approach**: Detect column type or allow format string hints
- **ROI**: Medium — improves usability

---

## 24. Cross-Ecosystem Pattern Matches

### Pattern: Viewport + Table Composition
- **Where**: bubble-table issue #129, charmbracelet/bubbles issue #276
- **Pattern**: Wrapping table in viewport to control height
- **SugarCraft**: sugar-table's `withViewportHeight()` directly addresses this

### Pattern: Filter + Selection Data Loss
- **Where**: bubble-table #170, charmbracelet/bubbles #632, #638
- **Pattern**: Removing/selecting items while filtered causes inconsistency
- **SugarCraft**: Must test this interaction thoroughly

### Pattern: String Builder for Performance
- **Where**: charmbracelet/bubbles #810, #882
- **Pattern**: String concatenation in loops causes O(n²) behavior
- **Lesson**: Always use string builder for repeated concatenations

### Pattern: Cursor Positioning on Boundary
- **Where**: charmbracelet/bubbles #363, #428, #429
- **Pattern**: Scrolling up vs down behaves differently at boundaries
- **SugarCraft**: Ensure symmetric behavior for scroll-to-top and scroll-to-bottom

---

## 25. High ROI Recommendations

### For SugarCraft sugar-table Development:

1. **Add global metadata support** (High ROI, Low Effort)
   - Add `withGlobalMetadata(array $metadata): self`
   - Pass to style/filter callbacks
   - Directly ports PR #210 pattern

2. **Implement event/callback system** (High ROI, High Effort)
   - Add `onSelectionChange(callable)`, `onHighlightChange(callable)`, etc.
   - Enables reactive TUI patterns
   - Most significant gap vs bubble-table

3. **Add proper viewport rendering** (High ROI, Medium Effort)
   - Only render visible rows + buffer
   - Essential for large datasets (10k+ rows)
   - Key performance fix

4. **Test filter + selection interaction** (High ROI, Low Effort)
   - Add integration test for filtered selection consistency
   - Prevents regression of bug like #170

5. **Document immutable update patterns** (Medium ROI, Low Effort)
   - Clear examples for "replace all rows"
   - Address confusion from issue #201
   - Common stumbling block

6. **Add fuzzy filter with Levenshtein option** (Medium ROI, Medium Effort)
   - Address issue #213 pattern
   - Community contribution available in PR #214

7. **Implement row borders** (Low ROI, Medium Effort)
   - Address issue #192
   - Adds visual separation capability

8. **Test terminal resize handling** (Medium ROI, Low Effort)
   - Add automated tests for various terminal sizes
   - Prevents regressions like #178

---

## Summary

**bubble-table** is a mature, well-architected library with an active user community. The most significant patterns for SugarCraft are:

1. **Performance at scale** — Viewport-only rendering is essential for large tables
2. **Immutable patterns need clear documentation** — Users consistently struggle with update patterns
3. **Callbacks need rich context** — GlobalMetadata, Row, Column all passed to style/filter functions
4. **Event system enables interactivity** — UserEvent equivalents are missing from sugar-table
5. **Filter + selection interaction is error-prone** — Must test thoroughly

The main risks for sugar-table are:
- Performance with large datasets (needs viewport virtualization)
- Missing event system (limits interactivity)
- Confusion about immutable update patterns
- Ecosystem version coupling (Bubble Tea v2 migration)

SugarCraft has the opportunity to learn from bubble-table's evolution and implement a cleaner, more complete port with better performance characteristics and richer APIs.
