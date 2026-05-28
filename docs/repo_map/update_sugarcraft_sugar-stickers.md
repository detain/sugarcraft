# Overview

Sugar-stickers is a foundational TUI layout library providing two primary components — FlexBox (CSS flexbox-inspired ratio-based layout engine) and Table (sortable/filterable data table) — ported from the Go library 76creates/stickers. It sits at the intersection of primitive layout (FlexBox) and self-contained widgets (Table), with additional Viewport and Scrollbar wrappers that compose sugar-bits counterparts via SSOT composition.

**Biggest opportunity areas:**
- Implementing the upstream ContentGenerator pattern for per-cell adaptive content
- Adding HorizontalFlexBox (column-first layout) for full upstream parity
- Implementing multi-column sorting and per-column filter maps
- Adding viewport virtualization and pagination to Table

**Biggest missing capabilities:**
- No ContentGenerator/dynamic content per cell (upstream feature not ported)
- No HorizontalFlexBox (column-first layout)
- No style inheritance chain (unlike lipgloss's Style.Inherit())
- No dimension locking APIs (LockRowHeight/LockColumnWidth)
- Table: single-column sort only, OR-logic global filter, no pagination, no frozen columns, no viewport virtualization

---

# Internal Capability Summary

## Current Architecture

**Core Components:**
- `FlexBox` (330 lines) — CSS flexbox-inspired ratio-based layout engine
- `FlexItem` (86 lines) — Atomic layout unit with ratio/basis/style
- `Table` (305 lines) — Sortable/filterable data table
- `Column` (112 lines) — Column definition with formatter/padding
- `Viewport` (368 lines) — Scrollable viewport with sticky header/footer (SSOT wrapper)
- `Scrollbar` (65 lines) — Scrollbar compositor (SSOT wrapper)
- `Lang.php` (22 lines) — i18n facade

**Key design patterns:**
- SSOT composition: Viewport/Scrollbar wrap `SugarCraft\Bits` counterparts rather than reimplementing
- Immutable + fluent: All setters return new instances via clone-then-mutate
- Measure → compute → render pipeline
- Raw ANSI SGR strings for styling (no structured style object system)

**Dependencies:**
- `sugarcraft/candy-core` — ANSI primitives (Ansi class)
- `sugarcraft/sugar-bits` — Viewport, Scrollbar (SSOT inner types)

## Current Features

### FlexBox
- **Direction**: Row (horizontal) / Column (vertical)
- **Justify**: Start/Center/End/SpaceBetween/SpaceAround
- **Align**: Start/Center/End/Stretch
- **Gap**: inter-item spacing
- **Wrap**: items wrap to next line when exceeding available space
- **Ratio-based sizing**: items with grow ratios fill available space
- **Basis**: fixed-dimension locking for specific items

### Table
- **Sortable columns**: single-column sort with ascending/descending toggle
- **Filterable**: global case-insensitive OR filter across all cells
- **Cursor tracking**: row index in filtered view
- **Cell formatting**: per-column callable formatter
- **Configurable columns**: title, width, alignment, formatter

### Viewport
- Keyboard navigation (line-up/down, page-up/down, goto-top/bottom)
- Mouse wheel support with configurable delta
- Horizontal scrolling with step-based navigation
- Smooth scroll and scrollbar toggle
- Sticky header/footer positioning (deferred from upstream)

### Scrollbar
- Vertical and horizontal scrollbars
- Configurable thumb/track characters
- Arrow toggling for scrollbar ends

## Strengths

1. **Faithful ratio-based layout algorithm**: Two-pass allocation correctly handles integer rounding without floating-point errors
2. **Lightweight**: No external dependencies beyond sugar-core and sugar-bits
3. **SSOT composition**: Clean pattern for customizing scroll/viewport without duplicating logic
4. **Immutable API**: Fluent `with*()` setters return new instances

## Weaknesses

1. **No ContentGenerator**: Upstream `Cell.SetContentGenerator(func(maxX, maxY) string)` not ported — would enable per-cell adaptive text wrapping
2. **No HorizontalFlexBox**: Upstream's column-first layout variant not implemented
3. **No style inheritance chain**: lipgloss's `Style.Inherit()` chain not ported; styles are raw ANSI strings only
4. **No dimension locking API**: Upstream's `LockRowHeight()` / `LockColumnWidth()` not implemented
5. **Table limitations**: Single-column sort, OR-logic global filter, no pagination, no viewport virtualization
6. **No gap in Table**: Uses a simple separator string, not gap-based layout like FlexBox
7. **Missing upstream features**: No frozen columns, no column width modes, no wrap modes
8. **No min-width constraints**: Items with `ratio=0` get minimum of 1 cell; no explicit minimum constraint system

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|---------------------------|---------|
| `docs/repo_map/76creates_stickers.md` | Primary upstream | ContentGenerator, HorizontalFlexBox, style inheritance, dimension locking, bubble-table comparison | Critical |
| `docs/repo_map/Evertras_bubble-table.md` | High — full-featured table reference | Multi-column sort, fuzzy filter, pagination, frozen columns, zebra striping, viewport scrolling, event system, keymaps | High |
| `docs/repo_map/sugarcraft_sugar-table.md` | High — sibling table library | ColumnWidth enum, WrapMode, zebra striping, frozen columns, pagination, viewport height, multi-column sort, per-column filters, Border system | High |
| `docs/repo_map/textualize_textual.md` | Medium — CSS flexbox reference | CSS flexbox layout algorithm, reactive state, message pump, widget system | Medium |
| `docs/repo_map/ratatui_ratatui.md` | Medium — constraint-based layout | Cassowary constraint solver, buffer diffing, widget traits, immediate mode rendering | Medium |
| `docs/repo_map/charmbracelet_lipgloss.md` | Medium — styling system | Style inheritance (Style.Inherit()), CSS shorthand, CIELAB color blending, layer compositing, borders | Medium |
| `docs/repo_map/sugarcraft_sugar-dash.md` | Medium — dashboard layout | StackedGrid, responsive collapse at breakpoints, panel/frame system | Medium |
| `docs/repo_map/charmbracelet_bubbletea.md` | Low — architecture reference | Elm architecture, command pattern, subscription system | Low |

---

# Feature Gap Analysis

## Critical Priority

### 1. ContentGenerator for Dynamic Cell Content
**Title:** Per-cell adaptive content via callable content generator
**Description:** The upstream Go library supports `Cell.SetContentGenerator(func(maxX, maxY) string)` which enables cell content to adapt to allocated dimensions (for text truncation/wrapping without pre-measuring).
**Why it matters:** Enables dynamic text truncation, adaptive content display, and responsive cells that adjust to available space — essential for responsive TUI layouts.
**Source:** `docs/repo_map/76creates_stickers.md` — "ContentGenerator: Cells can hold either static content (SetContent) or dynamic content via SetContentGenerator(func(maxX, maxY) string) for per-cell adaptive text wrapping/display"
**Implementation ideas:**
- Add `withContentGenerator(callable $fn): self` to `FlexItem`
- Generator signature: `function(int $maxX, int $maxY): string`
- Call generator during render pass after layout allocation is computed
- Fall back to static content if no generator set
**Estimated complexity:** Medium — requires tracking generator state and calling at render time
**Expected impact:** High — enables responsive cell content that adapts to allocated space

### 2. HorizontalFlexBox (Column-First Layout)
**Title:** Horizontal FlexBox variant for column-first layouts
**Description:** The upstream Go library has a separate `HorizontalFlexBox` type (columns flow left-to-right, cells within columns flow top-to-bottom). The PHP port only has `FlexBox::row()` / `FlexBox::column()`.
**Why it matters:** Full upstream parity and complete flexbox capability coverage. HorizontalFlexBox is a distinct layout mode used in dashboards and multi-column UIs.
**Source:** `docs/repo_map/76creates_stickers.md` — "HorizontalFlexBox: Horizontally-stacked responsive grid (columns flow left-to-right, cells within columns flow top-to-bottom)"
**Implementation ideas:**
- Add `FlexBox::horizontal()` factory for column-first layout
- Reuse row layout algorithm but swap width/height axes
- Or create a separate `HorizontalFlexBox` class mirroring upstream
**Estimated complexity:** Medium — requires understanding axis-swapped layout algorithm
**Expected impact:** Medium — enables column-first dashboard layouts

## High Priority

### 3. Multi-Column Sorting
**Title:** Support sorting by multiple columns with priority chaining
**Description:** sugar-stickers Table sorts only on a single column. bubble-table and sugar-table support multi-column stable sort via `ThenSortBy()` chaining.
**Why it matters:** Essential for data tables where primary sort key may tie and need secondary sort key.
**Source:** `docs/repo_map/Evertras_bubble-table.md` — "SortByAsc(col), SortByDesc(col), ThenSortByAsc(col), ThenSortByDesc(col)"
**Source:** `docs/repo_map/sugarcraft_sugar-table.md` — "sortColumns array" with multi-column sorting
**Implementation ideas:**
- Replace `$sortColIndex` with `list<array{colIndex:int, ascending:bool}>` 
- Apply sort iteratively starting from highest priority (last in list)
- Add `thenSortBy(int $colIndex, bool $ascending = true): self` method
**Estimated complexity:** Medium — requires changing sort state structure
**Expected impact:** High — essential for real data tables

### 4. Per-Column Filter Map
**Title:** Support filtering by specific columns rather than global OR filter
**Description:** sugar-stickers uses OR logic across all cells with a single global filter text. sugar-table supports AND logic per-column with a filter map (`colKey => filterText`).
**Why it matters:** Enables precise data filtering when users need to filter specific columns independently.
**Source:** `docs/repo_map/sugarcraft_sugar-table.md` — "filterText: array<string, string>" per-column filter map
**Implementation ideas:**
- Add private `$filters: array<int, string>` (column index → filter text)
- Add `withFilter(int $colIndex, string $text): self` method
- Modify `applyFilter()` to apply AND logic across all non-empty column filters
- Keep `filter(string $text)` as convenience for global filter
**Estimated complexity:** Low — additive changes to filter state and method
**Expected impact:** Medium — enables precise column-specific filtering

### 5. Pagination for Large Datasets
**Title:** Add page-based pagination to Table
**Description:** sugar-stickers Table holds all rows in memory and renders them all. sugar-table supports `pageSize`, `page`, and `PageFooter()` rendering.
**Why it matters:** Essential for large datasets where rendering all rows at once is impractical or slow.
**Source:** `docs/repo_map/Evertras_bubble-table.md` — "Pagination: Configurable page size; page navigation (first/last/next/prev) with optional wrapping"
**Source:** `docs/repo_map/sugarcraft_sugar-table.md` — "pageSize: int, page: int, withPageSize(), withPage(), nextPage(), prevPage(), pageFirst(), pageLast(), PageFooter()"
**Implementation ideas:**
- Add `$pageSize: int` and `$page: int` state
- Add `withPageSize(int): self`, `withPage(int): self`, navigation methods
- Add `pagedRows(): array` to return only current page's rows
- Add `PageFooter()` method for page count display
**Estimated complexity:** Medium — requires understanding pagination state machine
**Expected impact:** High — enables tables with thousands of rows

### 6. Viewport Virtualization for Table
**Title:** Add viewport-based row windowing for Table
**Description:** sugar-stickers Table renders all visible rows. sugar-table supports `withViewportHeight()` and `withScrollY()` for virtualized rendering.
**Why it matters:** Essential for large datasets where only visible rows should be rendered each frame.
**Source:** `docs/repo_map/sugarcraft_sugar-table.md` — "viewportHeight: int, withViewportHeight(), withScrollY()"
**Implementation ideas:**
- Add `$viewportHeight: int` and `$scrollY: int` state
- Add `withViewportHeight(int): self`, `withScrollY(int): self` methods
- Modify `render()` to only render rows in `[scrollY, scrollY + viewportHeight)` range
- Add `ScrollDown()`/`ScrollUp()` methods to advance scroll position
**Estimated complexity:** Medium — requires understanding viewport windowing
**Expected impact:** High — enables large table rendering without performance degradation

## Medium Priority

### 7. Frozen (Fixed) Columns
**Title:** Support freezing columns to left edge during horizontal scroll
**Description:** sugar-stickers Table has no frozen column support. bubble-table and sugar-table support freezing leftmost columns.
**Why it matters:** Essential for wide tables with key identifiers (names, IDs) that should remain visible during horizontal scroll.
**Source:** `docs/repo_map/Evertras_bubble-table.md` — "WithHorizontalFreezeColumnCount(n)"
**Source:** `docs/repo_map/sugarcraft_sugar-table.md` — "frozenCols: array, withFrozenCols()"
**Implementation ideas:**
- Add `$frozenCols: array<int>` state
- Add `withFrozenCols(array<int>): self` method
- During render, render frozen columns first without horizontal scroll offset
- Apply scroll offset only to non-frozen columns
**Estimated complexity:** Medium — requires changes to render pipeline
**Expected impact:** High — widely requested feature for wide tables

### 8. Style Inheritance Chain
**Title:** Implement lipgloss-style style inheritance for FlexBox → FlexItem chain
**Description:** The upstream Go library supports `StylePassing(bool)` which propagates lipgloss styles down FlexBox → Row → Cell via `Style.Inherit()`. sugar-stickers uses raw ANSI strings only.
**Why it matters:** Enables hierarchical style application where parent styles cascade to children but can be overridden.
**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — "Inherit(Style) — overlays unset properties from another style"
**Source:** `docs/repo_map/76creates_stickers.md` — "Style inheritance: Optional StylePassing propagates lipgloss styles from FlexBox → Row → Cell chain"
**Implementation ideas:**
- Add optional `$style: string` parameter to FlexBox
- Add `withStyle(string $style): self` and `withStylePassing(bool): self` to FlexBox
- When rendering, compose parent style with child style: child overrides unset parent properties
- For ANSI strings: concatenate with `;` separator or layer using ANSI composite
**Estimated complexity:** High — requires defining style composition semantics for ANSI strings
**Expected impact:** Medium — enables more ergonomic style management

### 9. Zebra Striping
**Title:** Built-in alternating row background colors
**Description:** sugar-stickers Table has no zebra striping. sugar-table supports `withZebra()` with alternating styles.
**Why it matters:** Improves table readability by providing visual row boundaries.
**Source:** `docs/repo_map/sugarcraft_sugar-table.md` — "zebraEnabled: bool, withZebra(), zebraStyleOdd/Even"
**Implementation ideas:**
- Add `$zebraStyleOdd: string` and `$zebraStyleEven: string` state
- Add `withZebra(string $oddStyle = '100', string $evenStyle = ''): self`
- In render loop, apply alternating styles to even/odd rows
**Estimated complexity:** Low — additive style application in render loop
**Expected impact:** Medium — common table accessibility feature

### 10. Border System (Full)
**Title:** Implement proper border rendering with multiple border styles
**Description:** sugar-stickers Table uses a simple separator string (` │ `). sugar-table has a full 13-character Border object with multiple border styles (normal, rounded, thick, double, block, ascii, hidden, markdown).
**Why it matters:** Essential for professional-looking table presentation.
**Source:** `docs/repo_map/sugarcraft_sugar-table.md` — "Border chars (13 characters), Border family from candy-sprinkles"
**Implementation ideas:**
- Add `Border` class or import from `SugarCraft\Sprinkles\Border`
- Add `withBorderStyle(string $style): self` or `withBorder(Border $border): self`
- Update render loop to build border lines from Border characters
- Support multiple border families (normal, rounded, thick, double, etc.)
**Estimated complexity:** Medium — requires defining border character set and rendering logic
**Expected impact:** Medium — significantly improves visual presentation

## Low Priority

### 11. Dimension Locking API
**Title:** LockRowHeight() / LockColumnWidth() APIs
**Description:** Upstream Go library has `LockRowHeight()` / `LockColumnWidth()` methods to disable vertical/horizontal responsiveness. Not implemented in PHP port.
**Why it matters:** Enables fixed-size layout sections that don't respond to available space changes.
**Source:** `docs/repo_map/76creates_stickers.md` — "Fixed dimension locking: LockRowHeight() / LockColumnWidth() disables vertical/horizontal responsiveness for fixed-size sections"
**Implementation ideas:**
- Add `$rowHeightLocked: bool` and `$colWidthLocked: bool` to FlexBox state
- Add `lockRowHeight(bool $locked = true): self` and `lockColWidth(bool $locked = true): self` methods
- In layout algorithm, skip ratio distribution for locked dimensions
**Estimated complexity:** Low — small state flag with conditional in layout algorithm
**Expected impact:** Low — rarely needed, can be achieved with basis=fixed approach

### 12. Minimum Dimension Constraints
**Title:** Cell-level minWidth/minHeight constraints
**Description:** Upstream Go has `Cell.SetMinWidth(int)` / `SetMinHeight(int)` for minimum constraints that take precedence over ratio-based sizing. Not implemented in PHP port.
**Why it matters:** Enables cells with minimum space requirements that won't be violated even when space is constrained.
**Source:** `docs/repo_map/76creates_stickers.md` — "Minimum dimensions: Cells support minWidth/minHeight constraints that take precedence over ratio-based sizing when space is constrained"
**Implementation ideas:**
- Add `withMinWidth(int): self` and `withMinHeight(int): self` to FlexItem
- In layout algorithm, implement `calculateRatioWithMinimum` recursive algorithm (see upstream utils.go)
- Lock minimum cells first, redistribute remaining space among non-minimum cells
**Estimated complexity:** High — requires implementing recursive constraint satisfaction algorithm
**Expected impact:** Low — rarely needed for typical TUI layouts

### 13. Fuzzy Filtering
**Title:** Fuzzy substring matching filter
**Description:** bubble-table supports fuzzy filter via `WithFuzzyFilter()`. sugar-stickers only has simple contains filter.
**Why it matters:** Improves filter UX when exact substring match is too strict.
**Source:** `docs/repo_map/Evertras_bubble-table.md` — "fuzzy filter via WithFuzzyFilter()"
**Implementation ideas:**
- Add `withFuzzyFilter(bool $enabled = true): self` method
- Implement fuzzy subsequence matching: check if needle runes appear in order (non-contiguously) in haystack
- Use algorithm from upstream: custom `fuzzySubsequenceMatch(haystack, needle)` iterating runes
**Estimated complexity:** Medium — requires implementing fuzzy matching algorithm
**Expected impact:** Low — simple contains is sufficient for most use cases

### 14. Copy Accessors (Lazy Recalculation Avoidance)
**Title:** GetRowCopy() / GetCellCopy() for read-only inspection without triggering recalc
**Description:** Upstream Go has copy accessors that read state without triggering layout recalculation. Not ported.
**Why it matters:** Enables querying cell dimensions or content without dirtying the recalculation flag.
**Source:** `docs/repo_map/76creates_stickers.md` — "Copy accessors: GetRowCopy() / GetCellCopy() read state without triggering layout recalculation"
**Implementation ideas:**
- Add `getRowCopy(int $index): ?FlexItem` and `getCellCopy(int $rowIndex, int $cellIndex): ?FlexItem`
- These return immutable snapshots of current item state
- In PHP this is less relevant than Go due to no lazy recalculation flag system
**Estimated complexity:** Low — straightforward accessor methods
**Expected impact:** Low — PHP doesn't have the same lazy recalculation model

### 15. Multi-Type Row Validation
**Title:** Typed row values with validation on AddRows
**Description:** Upstream Go uses generics to enforce column types at AddRows time with typed errors.
**Why it matters:** Catches type mismatches early with descriptive error messages.
**Source:** `docs/repo_map/76creates_stickers.md` — "Multi-type rows: SetTypes(...any) sets per-column types (must satisfy Ordered interface); rows validated on AddRows() with typed errors"
**Implementation ideas:**
- Add `$types: array<int, string>` (column index → type name) state
- Add `withTypes(array<int, string>): self` method
- On `addRow()`, validate each value against its column type
- Use PHP's `is_numeric()`, `is_int()`, `is_float()` for type checking
**Estimated complexity:** Low — additive type validation
**Expected impact:** Low — PHP's dynamic typing reduces need for this

### 16. Table Footer Status Bar
**Title:** Render cursor coordinates and filter state in footer
**Description:** Upstream Go renders a footer status bar with cursor coordinates and filter state.
**Why it matters:** Provides user feedback on table state and cursor position.
**Source:** `docs/repo_map/76creates_stickers.md` — "Footer status bar: Renders cursor coordinates and filter state"
**Implementation ideas:**
- Add `withShowFooter(bool): self` method
- Add `footer(): string` method that renders cursor position and filter state
- In `render()`, append footer if enabled
**Estimated complexity:** Low — simple string formatting
**Expected impact:** Low — nice-to-have for interactive use

### 17. Filtering Indicator Glyph
**Title:** Append filter indicator glyph to filtered column header
**Description:** Upstream Go appends `⑂` to filtered column header.
**Why it matters:** Visual indicator that a column has an active filter.
**Source:** `docs/repo_map/76creates_stickers.md` — "Filtering indicator glyph: ⑂ appended to filtered column header"
**Implementation ideas:**
- In render loop, append `⑂` to column title if that column has a filter
- Track which columns have filters in state
**Estimated complexity:** Low — simple conditional string append
**Expected impact:** Low — cosmetic improvement

---

# Algorithm / Performance Opportunities

## Current Approach vs External Approach

### Layout Algorithm
**Current (sugar-stickers FlexBox):**
- Two-pass ratio allocation: floor division + excess distribution
- Items with `basis > 0` are dimension-locked
- Items with `ratio = 0` get minimum of 1 cell
- Uses `\round()` for float-to-int conversion in allocation

**External Approach (76creates/stickers upstream):**
- Same two-pass approach but uses integer floor division + round-robin remainder distribution
- Upstream's `distributeRemainder` distributes 1 unit at a time to highest-ratio cells
- Upstream has `calculateRatioWithMinimum` recursive algorithm for minimum constraints

**Why External is Better:**
- Upstream's round-robin remainder distribution is more even than PHP's `round()`-based excess
- Upstream's minimum constraint algorithm handles edge cases where cell minimums exceed ratio share

**Tradeoffs:**
- Upstream's recursive algorithm is more complex to implement and verify
- PHP's approach is simpler and works for most cases

**Applicability:** Medium — the current algorithm is sufficient for typical use cases but could be improved for edge cases

### Sorting Algorithm
**Current (sugar-stickers Table):**
- Uses PHP's `usort()` with numeric detection
- Case-insensitive string comparison via `\strcasecmp()`
- Single-column sort only

**External Approach (ratatui, bubble-table):**
- ratatui uses Rust's built-in sort (likely quicksort/mergesort hybrid)
- bubble-table uses Go's `sort.Stable` for multi-column stable sort
- sugar-table already uses `usort` (same as sugar-stickers)

**Why External is Better:**
- `usort` already uses efficient sorting (quicksort in most PHP versions)
- Multi-column sort requires re-sorting iteratively with stable sort

**Tradeoffs:**
- PHP's `usort` is already efficient
- Single-column limitation is the actual gap, not sort performance

**Applicability:** High — implementing multi-column sort is the key improvement

### Rendering Performance
**Current:**
- Full re-render on every `render()` call
- No buffer diffing or incremental update

**External Approach (ratatui):**
- Immediate mode rendering with buffer diffing
- Only changed cells written to terminal

**Why External is Better:**
- Buffer diffing reduces terminal I/O significantly
- Essential for smooth animations and large views

**Tradeoffs:**
- Full re-render is simpler and correct for most cases
- SugarCraft's retained-mode approach (candy-core Model) already handles diffing at the view level

**Applicability:** Medium — sugar-core's rendering model already handles diffing at a higher level

---

# Architecture Improvements

## 1. Style System Enhancement
**Current state:** Raw ANSI SGR strings per FlexItem, no inheritance
**Improvement:** Add optional `StyleInheritance` trait providing `Style.Inherit()` equivalent
```php
trait StyleInheritanceTrait {
    private ?string $parentStyle = null;
    
    public function withInheritedStyle(string $parentStyle): self {
        $composed = composeAnsiStyles($parentStyle, $this->style);
        // ...
    }
}
```
**Rationale:** Enables hierarchical styling without breaking immutable pattern

## 2. ContentGenerator Integration
**Current state:** FlexItem content is static string
**Improvement:** Add `?callable $contentGenerator` state, call during render after layout
```php
public function withContentGenerator(callable $fn): self {
    $clone = clone $this;
    $clone->contentGenerator = $fn;
    return $clone;
}
```
**Rationale:** Enables per-cell adaptive text that responds to allocated dimensions

## 3. FlexItem Type Safety
**Current state:** FlexItem properties are `public readonly` primitives
**Improvement:** Add `WidthMode` and `HeightMode` enums for clearer intent
```php
enum WidthMode { Auto, Fixed, Ratio, Fill }
```
**Rationale:** More expressive API than boolean flags

## 4. Table Row Type
**Current state:** Rows are plain `list<list<string>>` arrays
**Improvement:** Add `Row` readonly class with typed accessors
```php
final readonly class Row {
    public function __construct(public array $cells) {}
    public function cell(int $colIndex): string { return $this->cells[$colIndex] ?? ''; }
}
```
**Rationale:** Better type safety and IDE support than bare arrays

---

# API / Developer Experience Improvements

## 1. Named Constructors for Common Patterns
**Current:** `FlexBox::row()` / `FlexBox::column()` / `FlexItem::new()`
**Improvement:** Add named constructors for common layouts
```php
FlexBox::equal(int $count, string $content = ''): self  // N equal-ratio items
FlexBox::sidebar(int $sidebarWidth, FlexItem $sidebar, FlexItem $main): self
FlexBox::grid(int $columns, FlexItem ...$items): self
```
**Rationale:** Reduces boilerplate for common layout patterns

## 2. Table Builder Pattern
**Current:** Add columns/rows via `addColumn()` / `addRow()` chain
**Improvement:** Add static factory with column definitions
```php
Table::withColumnsAndRows(
    [new Column('Name', 20), new Column('Age', 5)],
    [['Alice', 30], ['Bob', 25]]
): self
```
**Rationale:** More compact table initialization

## 3. Render Context Object
**Current:** `render(int $totalWidth, int $totalHeight)` takes primitive ints
**Improvement:** Add `RenderContext` object with additional context
```php
final readonly class RenderContext {
    public function __construct(
        public int $width,
        public int $height,
        public int $scrollX = 0,
        public int $scrollY = 0,
    ) {}
}
```
**Rationale:** Extensible for additional context without changing signatures

## 4. Consistent Method Naming
**Current:** `withGap(int)`, `withBorder(bool)`, `withDirection(Direction)` — inconsistent bool handling
**Improvement:** Standardize — `withBorder(bool $enabled = true): self`
**Rationale:** Consistency across the API

---

# Documentation / Cookbook Opportunities

## 1. FlexBox Layout Patterns
- Sidebar layout with fixed sidebar + fluid main content
- Dashboard grid with multiple panels
- Holy Grail layout (header + sidebar + main + sidebar + footer)
- Equal-width columns with gap spacing
- Nested FlexBox for complex hierarchies

## 2. Table Styling Recipes
- zebra striping with custom colors
- Frozen first column + scrollable rest
- Multi-column sort with visual indicators
- Filter bar integration with external text input

## 3. Viewport Integration Examples
- Terminal scrollable log viewer
- Diff viewer with synchronized scrolling
- Sticky header table with scrollable body

## 4. Performance Cookbook
- Large dataset handling (10,000+ rows)
- Incremental rendering optimization
- Memory-efficient content generation

---

# UX / TUI Improvements

## 1. Mouse Interaction for Table
**Current:** No mouse handling in Table
**Improvement:** Add mouse click to set cursor position
- Click on row sets cursor to that row
- Double-click could trigger row selection event

**Source:** `docs/repo_map/Evertras_bubble-table.md` — "Mouse support" not explicitly handled
**Source:** `docs/repo_map/charmbracelet_bubbletea.md` — "Mouse Input: Click, release, wheel, and motion events"

## 2. Keyboard Navigation Enhancement
**Current:** `sortBy()`, `filter()` are manual method calls
**Improvement:** Add keyboard bindings for table navigation
- `j/k` or `↑/↓` for cursor movement
- `Space` for row selection
- `/` to focus filter input
- `Home/End` for page navigation

**Source:** `docs/repo_map/Evertras_bubble-table.md` — "KeyMap withDefaultKeyMap()"

## 3. Visual Feedback
**Current:** Sort direction shown with ▲/▼ in header
**Improvement:** 
- Highlight current cursor row more prominently
- Show filter indicator glyph (⑂) when filter active
- Animate sort reordering for large datasets

## 4. Spreadsheet-Style Cell Editing
**Current:** Table is read-only display
**Improvement:** Add optional in-place cell editing
- Press `Enter` to edit current cell
- `Tab` to move to next cell
- `Escape` to cancel editing

**Rationale:** Enables data entry use cases

---

# Testing / Reliability Improvements

## 1. Property-Based Testing
**Current:** Example-based tests in `StickersTest.php`
**Improvement:** Add property-based tests for layout algorithm
- Fuzz the ratio allocation algorithm
- Verify invariants: allocated widths sum to totalWidth
- Test edge cases: 0 items, 1 item, all basis, all ratio, mixed

**Source:** `docs/repo_map/Evertras_bubble-table.md` — "Extensive test files including fuzz tests for scrolling"

## 2. Snapshot Testing for Render Output
**Current:** Tests use assertions on render output
**Improvement:** Add golden file snapshots for complex layouts
- Snapshot FlexBox with specific items/gap/direction
- Snapshot Table with specific columns/rows/sort/filter
- CI compares against committed snapshots

**Rationale:** Catches rendering regressions automatically

## 3. Performance Regression Tests
**Current:** No performance tests
**Improvement:** Add benchmarks for:
- FlexBox render with N items
- Table sort with N rows
- Table filter with N rows

**Source:** `docs/repo_map/Evertras_bubble-table.md` — No explicit performance testing
**Rationale:** Prevents performance regressions in layout algorithms

## 4. Cross-Terminal Compatibility Tests
**Current:** ANSI rendering assumes standard terminal capabilities
**Improvement:** Add tests for:
- Wide Unicode character handling (CJK, emoji)
- ANSI color profile degradation (TrueColor → 256 → ANSI)
- Synchronized output mode (ANSI 2026) fallback

**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — "Automatic downsampling: Detects terminal color profile"

---

# Ecosystem / Integration Opportunities

## 1. Integration with sugar-bits Table
**Current:** sugar-stickers Table and sugar-bits Table are separate implementations
**Improvement:** Consider converging on a shared table interface or base class
- sugar-stickers Table is simpler (positional access)
- sugar-bits Table is more featureful (keyed access)
- Could share `TableInterface` with render contract

## 2. Integration with sugar-dash Layout
**Current:** sugar-dash has its own StackedGrid layout system
**Improvement:** Explore using FlexBox within sugar-dash panels
- FlexBox provides CSS flexbox-like layout within a panel
- StackedGrid provides dashboard-level column layout
- Compose together for complex dashboards

**Source:** `docs/repo_map/sugarcraft_sugar-dash.md` — "StackedGrid multi-column stacked grid"

## 3. Integration with candy-sprinkles Style System
**Current:** sugar-stickers uses raw ANSI strings for styling
**Improvement:** Support `SugarCraft\Sprinkles\Style` objects as alternative to raw ANSI strings
- Style objects provide structured styling API
- Automatically convert to ANSI strings during render
- Enables style composition and inheritance

**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — "Style definition for nice terminal layouts"

## 4. ReactPHP Integration for Async Content
**Current:** Content is static; ContentGenerator is synchronous
**Improvement:** Support async content generation via ReactPHP promises
```php
public function withAsyncContentGenerator(
    callable $generator, // function(int $maxX, int $maxY): PromiseInterface
): self
```
**Rationale:** Enables fetching content from network/disk asynchronously

## 5. LSP/Folding Ranges for FlexBox
**Current:** No editor integration
**Improvement:** Provide TextMate grammar or LSP extension for VSCode
- Fold regions for FlexBox items
- Syntax highlighting for FlexBox builders
- Autocomplete for Direction/Justify/Align enums

**Rationale:** Improves developer experience

---

# Notable PRs / Issues / Discussions

## 1. bubble-table Multi-Column Sort Discussion
**Summary:** bubble-table added `ThenSortBy*` for chained multi-column sorting
**Source:** `docs/repo_map/Evertras_bubble-table.md` — "SortByAsc(col), SortByDesc(col), ThenSortByAsc(col), ThenSortByDesc(col)"
**Lesson:** Multi-column sort is essential for real-world data tables; should be prioritized

## 2. FlexBox ContentGenerator Discussion
**Summary:** 76creates/stickers introduced ContentGenerator for adaptive cell content
**Source:** `docs/repo_map/76creates_stickers.md` — "Content generators: Cells can hold either static content or dynamic content via SetContentGenerator"
**Lesson:** Static content is limiting; dynamic content that adapts to allocated space is more flexible

## 3. ratatui Cassowary Layout vs Simple Ratios
**Summary:** ratatui uses Cassowary constraint solver for layout; bubble Tea libraries use simple ratios
**Source:** `docs/repo_map/ratatui_ratatui.md` — "Cassowary constraint solver-based layout"
**Lesson:** Simple ratios work for most cases; constraint solver needed only for complex conflicting constraints

## 4. lipgloss Style Inheritance
**Summary:** lipgloss implements style inheritance via `Style.Inherit()` method
**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — "Inherit(Style) — overlays unset properties from another style"
**Lesson:** Inheritance enables hierarchical style management without verbose per-element specification

## 5. textual CSS Flexbox vs Manual Layout
**Summary:** textual implements CSS flexbox algorithm directly vs manual ratio calculation
**Source:** `docs/repo_map/textualize_textual.md` — "display: flex; flex-direction: row; TCSS stylesheet"
**Lesson:** CSS flexbox is the mental model most developers know; ratio-based is simpler but less familiar

---

# Recommended Roadmap

## Immediate Wins (0-1 sprint)
1. **Multi-column sorting** — High impact, medium complexity
2. **Per-column filter map** — Medium impact, low complexity  
3. **Zebra striping** — Low impact, low complexity

## Medium-Term Improvements (1-2 sprints)
4. **ContentGenerator** — High impact, medium complexity
5. **Pagination** — High impact, medium complexity
6. **Viewport virtualization** — High impact, medium complexity
7. **Frozen columns** — Medium impact, medium complexity
8. **HorizontalFlexBox** — Medium impact, medium complexity

## Major Architectural Upgrades (2-3 sprints)
9. **Style inheritance chain** — Medium impact, high complexity
10. **Full border system** — Medium impact, medium complexity
11. **Minimum dimension constraints** — Low impact, high complexity

## Experimental Ideas (future)
12. **Async content generation** — Requires ReactPHP integration
13. **Spreadsheet-style editing** — Requires significant design work
14. **LSP/editor integration** — Developer experience enhancement
15. **Buffer diffing for render optimization** — Low-level optimization

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|-------------|--------|-----------|------|----------|
| Multi-column sorting | High | Medium | Low | Immediate |
| Per-column filter map | Medium | Low | Low | Immediate |
| Zebra striping | Medium | Low | Low | Immediate |
| ContentGenerator | High | Medium | Medium | Medium-term |
| Pagination | High | Medium | Low | Medium-term |
| Viewport virtualization | High | Medium | Low | Medium-term |
| Frozen columns | Medium | Medium | Low | Medium-term |
| HorizontalFlexBox | Medium | Medium | Low | Medium-term |
| Style inheritance | Medium | High | Medium | Major |
| Full border system | Medium | Medium | Low | Major |
| Min dimension constraints | Low | High | Medium | Experimental |
| Fuzzy filtering | Low | Medium | Low | Experimental |
| Async content gen | Medium | High | High | Experimental |
| Spreadsheet editing | High | High | High | Experimental |
| Buffer diffing | Medium | High | Medium | Experimental |

---

# Final Strategic Assessment

**sugar-stickers** occupies a unique niche in the SugarCraft ecosystem as the layout foundation library. Its FlexBox component provides the only ratio-based layout system in SugarCraft (vs. the StackedGrid in sugar-dash and constraint-based layout in ratatui-inspired systems), making it essential for responsive TUI layouts. The Table component, while simpler than sugar-table, serves as a lightweight alternative for cases where the full sugar-table feature set is unnecessary.

**Key strategic observations:**

1. **FlexBox is the primary value**: The ratio-based layout algorithm is the crown jewel of sugar-stickers. The Table component, while useful, overlaps significantly with sugar-table and could potentially be deprecated in favor of sugar-table for table-specific use cases. The FlexBox layout engine, however, has no equivalent in SugarCraft.

2. **ContentGenerator is the most important missing feature**: The ability to generate content dynamically based on allocated dimensions enables responsive TUI patterns that are otherwise impossible. This should be prioritized for implementation.

3. **SSOT composition pattern is a model for SugarCraft**: The Viewport and Scrollbar wrappers that compose sugar-bits counterparts without reimplementing scroll logic is an elegant pattern that should be applied elsewhere. This "outer customization, inner SSOT" approach avoids duplication while enabling customization.

4. **Table and sugar-table should be rationalized**: The existence of two table implementations (sugar-stickers simple table + sugar-table full-featured table) creates maintenance burden and potential confusion. Consider either (a) deprecating sugar-stickers Table in favor of sugar-table, or (b) clearly differentiating them — sugar-stickers Table for simple display, sugar-table for interactive use.

5. **HorizontalFlexBox enables new layout patterns**: Column-first layout is essential for dashboard-style UIs where content flows horizontally first, then vertically within columns. Combined with the existing row-first FlexBox, this would provide complete CSS flexbox parity.

6. **Style inheritance chain would improve ergonomics**: While raw ANSI strings work, a lipgloss-style style inheritance system would make styled layouts more maintainable and composable.

**Recommended focus areas:**
- Prioritize ContentGenerator and HorizontalFlexBox for full upstream parity
- Invest in multi-column sort and viewport virtualization for Table
- Consider rationalizing the relationship between sugar-stickers Table and sugar-table
- Document SSOT composition as a pattern to apply elsewhere in SugarCraft
