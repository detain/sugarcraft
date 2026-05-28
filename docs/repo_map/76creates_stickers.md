# 76creates/stickers

## Metadata
- **URL**: https://github.com/76creates/stickers
- **Language**: Go
- **Stars**: ~500 (estimate; GitHub API credentials unavailable for exact count)
- **License**: MIT (Copyright (c) 2022 Dusan Gligoric)
- **Description**: A collection of TUI (Terminal User Interface) elements — FlexBox and Table — built for [Charmbracelet Bubbletea](https://github.com/charmbracelet/bubbletea) using [lipgloss](https://github.com/charmbracelet/lipgloss) for styling. Provides CSS flexbox-inspired responsive grid layouts and a responsive, x/y scrollable, sortable table component.

---

## Feature List

### FlexBox (`flexbox/` package)
- **FlexBox**: Vertically-stacked responsive grid (rows flow top-to-bottom, cells within rows flow left-to-right)
- **HorizontalFlexBox**: Horizontally-stacked responsive grid (columns flow left-to-right, cells within columns flow top-to-bottom)
- **Ratio-based layout**: Cells and rows/columns sized by integer ratios (ratioX, ratioY) that distribute available space proportionally
- **Minimum dimensions**: Cells support `minWidth`/`minHeight` constraints that take precedence over ratio-based sizing when space is constrained
- **Fixed dimension locking**: `LockRowHeight()` / `LockColumnWidth()` disables vertical/horizontal responsiveness for fixed-size sections
- **Style inheritance**: Optional `StylePassing` propagates lipgloss styles from FlexBox → Row → Cell chain
- **Content generators**: Cells can hold either static content (`SetContent`) or dynamic content via `SetContentGenerator(func(maxX, maxY int) string)` for per-cell adaptive text wrapping/display
- **Copy accessors**: `GetRowCopy()` / `GetCellCopy()` read state without triggering layout recalculation
- **Lazy recalculation**: Dimensions are recomputed only on render when the `recalculateFlag` is set

### Table (`table/` package)
- **Responsive table**: Built on top of FlexBox, inherits ratio-based column sizing
- **Bidirectional scrolling**: X-axis (column) scrolling driven by cursor position; Y-axis (row) scrolling via visible window (`rowsTopIndex`)
- **Sortable columns**: Supports `int`, `int8/16/32/64`, `float32/64`, and `string` types via Go generics (`Ordered` interface). `OrderByAsc(index)`, `OrderByDesc(index)` methods. Bubble sort implementation.
- **Filterable columns**: Single-column filtering with case-insensitive substring match; `SetFilter(colIdx, string)`, `UnsetFilter()`, `GetFilter()`
- **Cursor navigation**: `CursorUp()`, `CursorDown()`, `CursorLeft()`, `CursorRight()`, `GetCursorLocation()`, `GetCursorValue()`
- **Multi-type rows**: `SetTypes(...any)` sets per-column types (must satisfy `Ordered` interface); rows validated on `AddRows()` with typed errors (`ErrorBadType`, `ErrorRowLen`, `ErrorBadCellType`)
- **Customizable styling**: 6 style keys (`StyleKeyHeader`, `StyleKeyFooter`, `StyleKeyRows`, `StyleKeyRowsSubsequent`, `StyleKeyRowsCursor`, `StyleKeyCellCursor`) via `SetStyles(map[StyleKey]lipgloss.Style)`
- **Sorting indicator glyphs**: `▲` (ascending) / `▼` (descending) appended to sorted column header
- **Filtering indicator glyph**: `⑂` appended to filtered column header
- **Footer status bar**: Renders cursor coordinates and filter state

---

## Key Classes and Methods

### `flexbox.FlexBox` (`flexbox/flexbox.go`)
- `New(width, height int) *FlexBox` — constructor
- `SetStyle(style lipgloss.Style) *FlexBox` — set box style
- `StylePassing(bool) *FlexBox` — toggle style inheritance
- `NewRow() *Row` — create a new row inheriting box width
- `AddRows([]*Row) *FlexBox` / `SetRows([]*Row) *FlexBox` — mutate rows
- `GetRow(int) *Row` / `GetRowCopy(int) *Row` — fetch row (copy avoids recalc trigger)
- `GetRowCellCopy(rowIdx, cellIdx int) *Cell` — fetch cell copy without recalc
- `UpdateRow(int, *Row) *FlexBox` — replace row at index
- `LockRowHeight(int) *FlexBox` — disable vertical scaling
- `SetWidth(int) / SetHeight(int)` — update dimensions, propagate to rows
- `GetWidth() / GetHeight()` — query computed dimensions
- `Render() string` — compute layout and render final string
- `ForceRecalculate()` — force layout recomputation

### `flexbox.Row` (`flexbox/row.go`)
- `AddCells(...*Cell) *Row` — append cells (variadic)
- `GetCell(int) *Cell` / `GetCellCopy(int) *Cell` / `GetCellWithID(string) *Cell`
- `UpdateCellWithIndex(int, *Cell)` — replace cell
- `SetStyle(lipgloss.Style) *Row` / `StylePassing(bool) *Row`
- `CellsLen() int`

### `flexbox.Column` (`flexbox/column.go`)
- Mirrors `Row` API but for `HorizontalFlexBox` columns
- `AddCells(...*Cell) *Column`, `GetCell(int) *Cell`, `SetStyle`, `StylePassing`, `CellsLen()`
- `setHeight(int) / setWidth(int)` (package-private)

### `flexbox.Cell` (`flexbox/cell.go`)
- `NewCell(ratioX, ratioY int) *Cell`
- `SetID(string) *Cell`
- `SetContent(string) *Cell` — static content
- `SetContentGenerator(func(maxX, maxY int) string) *Cell` — dynamic content
- `GetContent() string`
- `SetMinWidth(int) *Cell` / `SetMinHeight(int) *Cell`
- `SetStyle(lipgloss.Style) *Cell` / `GetStyle() lipgloss.Style`
- `GetWidth() / GetHeight()`

### `table.Table` (`table/table.go`)
- `NewTable(width, height int, columnHeaders []string) *Table`
- `SetRatio([]int) *Table` — column width ratios
- `SetTypes(...any) (*Table, error)` — set per-column types (must be `Ordered`)
- `SetMinWidth([]int) *Table` — per-column minimum widths
- `SetHeight(int) / SetWidth(int)`
- `SetStyles(map[StyleKey]lipgloss.Style) *Table`
- `SetStylePassing(bool) *Table`
- `SetFilter(int, string) *Table` / `UnsetFilter() *Table` / `GetFilter() (int, string)`
- `AddRows([][]any) (*Table, error)` / `MustAddRows([][]any) *Table` / `ClearRows() *Table`
- `CursorUp/Down/Left/Right() *Table`
- `GetCursorLocation() (int, int)` / `GetCursorValue() string`
- `OrderByAsc(int) *Table` / `OrderByDesc(int) *Table` / `GetOrder() (int, SortingOrderKey)`
- `GetVisibleColumnRange() (int, int)`
- `Render() string`

---

## Notable Algorithms / Named Patterns

### Ratio-based responsive layout (`flexbox/utils.go`)
The core layout algorithm distributes available space (width or height) across cells/rows/columns using integer ratios:

- **`calculateRatio(distribute int, matrix []int) []int`**: Proportionally divides `distribute` units across `matrix` of ratio values. Uses `distributeToMatrix` for base floor division, then `distributeRemainder` to distribute leftover units one-by-one to the highest-ratio cell.
- **`distributeToMatrix(distribute, combinedRatio int, matrix []int) (distribution []int, remainder int)`**: Floor-division each cell: `floor((max_i / combinedRatio) * distribute)`. Returns remainder for secondary distribution pass.
- **`distributeRemainder(remainder int, matrixMaxRatio []int) []int`**: Greedily distributes 1 unit at a time to the highest-ratio cell until `remainder` is exhausted — a simple weighted round-robin for integer rounding errors.
- **`calculateMatrixRatio(distribute int, matrix [][]int) []int`**: For nested matrices (e.g., rows each with their own cell ratios). Takes the max ratio per row/column group and passes to `calculateRatio`.
- **`calculateRatioWithMinimum(distribute int, matrix []int, minimumMatrix []int) []int`**: Recursive algorithm handling minimum constraints — if a cell's minimum exceeds its ratio-allocated share, the minimum is locked and the remaining space is re-distributed among the rest recursively.

### Bubble sort for table ordering (`table/ordering.go`)
- **`sortIndex[T Ordered](slice []T, order SortingOrderKey) []int`**: Generic bubble sort returning a sorted **index** permutation rather than sorting in-place. Maintains parallel index array alongside value swaps.

### Bitmask cursor direction (`table/cursor.go`)
- `cursorDirection` is a `uint8` with 2 bits encoding (vertical: up/down, horizontal: left/right). Methods use bitwise ops (`|`, `&^`) for direction changes and checks.

### Style inheritance chain
- `FlexBox → Row → Cell` supports optional `StylePassing` where lipgloss styles are inherited via `style.Inherit(parent)` down the chain before rendering.

---

## Strengths
- **CSS flexbox mental model**: Developers familiar with CSS flexbox will find the ratio-based layout intuitive — the gap between "I know what I want" and "I've implemented it" is small
- **Deep bubbletea integration**: Natively follows the `tea.Model` pattern (`Update`/`View`/`Init`), fits cleanly into bubbletea applications
- **Responsive without reinitialization**: Dimensions can be updated via `SetWidth`/`SetHeight` without recreating the component; layout recalculates lazily on next `Render`
- **ContentGenerator for dynamic cells**: Cell content can adapt to available dimensions (`maxX`, `maxY`), enabling text truncation/wrapping without pre-measuring
- **Generics for type-safe tables**: Go 1.18+ generics (`Ordered` interface) ensure row values match column types at AddRows time, catching errors early with typed error types
- **Copy accessors for read-only inspection**: `GetRowCopy`, `GetCellCopy` allow querying state (e.g., cell dimensions) without dirtying the recalculation flag
- **Well-isolated packages**: `flexbox` and `table` are separate packages with clean imports — Table depends on FlexBox, but FlexBox has no knowledge of Table
- **Practical examples**: Five runnable examples covering simple flexbox, horizontal flexbox, flexbox-with-embedded-table, simple string table, and multi-type table with CSV loading
- **MIT license**: Permissive, no commercial restrictions

---

## Weaknesses
- **Bubble sort**: The `sortIndex` implementation in `ordering.go` uses bubble sort (O(n²) worst case). For tables with thousands of rows this could be a noticeable performance issue. The author explicitly notes this in a comment and invites faster-algorithm PRs.
- **No built-in key event handling**: Unlike some TUI frameworks, `FlexBox` and `Table` are pure render/calculation components — they don't handle keyboard/mouse input themselves. Applications must wire `tea.KeyMsg` to `CursorUp/Down/Left/Right` manually. This is by design (follows bubbletea's philosophy) but adds boilerplate for common patterns.
- **Single-column filtering only**: `applyFilter` iterates over `filteredColumn` only; multi-column filtering is explicitly TODO. Users needing cross-column filters must implement filtering externally.
- **No cell-level click/mouse handling in Table**: `Table` has `cursorDirection` tracking but no mouse event handlers (e.g., mouse click to place cursor). The `MouseMsg` from bubbletea is not handled.
- **No pagination API**: For very large datasets, all rows are held in memory. There's no page/offset API; scrolling is virtual (windowed via `rowsTopIndex`) but the full dataset must be supplied upfront.
- **Recalculation flag is a footgun**: The lazy recalculation system (`recalculateFlag`) works correctly for render loops but could cause subtle bugs if callers mix `GetRow`/`GetCell` (which trigger recalc) with direct struct field access or when threading a FlexBox across goroutines.
- **Integer-only ratios**: No floating-point ratios; minimum constraints and fixed dimensions are all `int`. On terminals with non-integer character cell dimensions (some terminals with certain fonts), this can lead to off-by-one accumulation.
- **Lipgloss v2 API**: Uses `charm.land/lipgloss/v2` (not the Charmbracelet `github.com/charmbracelet/lipgloss` v0.x still common in many bubbletea apps), which may cause dependency conflicts in some projects.

---

## SugarCraft Mapping

SugarCraft ports the Charmbracelet TUI ecosystem to PHP. The `stickers` library maps as follows:

| stickers (Go) | SugarCraft (PHP) | Notes |
|---|---|---|
| `flexbox.FlexBox` | `SugarCraft\Bits\FlexBox` | Vertically-stacked responsive grid; ratio-based sizing |
| `flexbox.HorizontalFlexBox` | `SugarCraft\Bits\HorizontalFlexBox` | Horizontally-stacked variant |
| `flexbox.Row` | `SugarCraft\Bits\Row` | Row container for cells |
| `flexbox.Column` | `SugarCraft\Bits\Column` | Column container for cells (used by HorizontalFlexBox) |
| `flexbox.Cell` | `SugarCraft\Bits\Cell` | Atomic unit with ratioX/ratioY, minWidth/minHeight, content generator |
| `flexbox/utils.go` ratio algorithms | Internal to `SugarCraft\Bits` | `calculateRatio`, `distributeToMatrix`, `distributeRemainder`, `calculateMatrixRatio`, `calculateRatioWithMinimum` |
| `table.Table` | `SugarCraft\Bits\Table` | Responsive sortable/filterable table — built on FlexBox |
| `table.Ordered` interface | `SugarCraft\Bits\Table::ORDERED_TYPES` | Supported types: `string`, `int`, `int8-64`, `float32/64` |
| `table/sortIndex` bubble sort | Internal to Table implementation | Replace with quicksort/mergesort in PHP port for large datasets |
| `table/cursor.go` bitmask | `SugarCraft\Bits\TableCursorDirection` enum | 2-bit cursor direction tracking |
| `table/error.go` typed errors | `SugarCraft\Bits\Exception\*` | `ErrorBadType`, `ErrorRowLen`, `ErrorBadCellType` |
| `Cell.SetContentGenerator` | `SugarCraft\Bits\Cell::withContentGenerator(callable)` | PHP closure/Callable for dynamic content |
| `StylePassing` inheritance | `SugarCraft\Bits\StyleInheritanceTrait` | FlexBox → Row → Cell style chain |

### Mapping Rationale
- `flexbox/` maps to `sugar-bits` because `Cell`, `Row`, `FlexBox`, and `HorizontalFlexBox` are foundational/primitive TUI building blocks, not app-level components.
- `table/` maps to `sugar-bits` as a leaf component (the table is a self-contained widget, not a foundational primitive) or optionally a sibling `sugar-table` if the table widget grows large enough to warrant separation.
- The ratio-based layout algorithm in `flexbox/utils.go` is the core algorithmic contribution and must be faithfully ported — it is the mechanism that makes the CSS flexbox model work in a terminal character-cell environment.
- The `ContentGenerator` pattern (`func(maxX, maxY int) string`) would become a PHP `Closure(int $maxX, int $maxY): string` passed to `Cell::withContentGenerator()`.

---

## Analysis

**76creates/stickers** is a focused, well-scoped TUI component library that fills a genuine gap in the Bubbletea ecosystem: the absence of a built-in responsive grid and table. While Bubbletea itself provides an excellent model/view/event architecture, layout primitives are intentionally left to external libraries, and stickers occupies that space with a CSS flexbox-inspired API that will feel immediately familiar to web developers. The ratio-based layout algorithm is the intellectual core — it solves the problem of how to distribute terminal character cells proportionally across rows/columns using only integer arithmetic, without floating-point layouts or external measurement passes.

The Table component is where the library shows both its maturity and its current limits. It successfully composes on top of FlexBox to produce a full-featured data viewer with sorting (multi-type aware), filtering, cursor navigation, and x/y scrolling. However, the bubble sort implementation and the absence of multi-column filtering or mouse interaction reveal that it is still a work in progress. The bitmask-based cursor direction tracker is a clever, minimal pattern that avoids a full struct for a simple 2-bit state.

From a SugarCraft porting perspective, this is a high-value target. The FlexBox/Cell/Row hierarchy maps naturally to PHP with immutable fluent builders (the `with*()` pattern described in the SugarCraft conventions). The most algorithmically intensive piece is `utils.go` — specifically the recursive `calculateRatioWithMinimum` and the remainder distribution logic — which must be precisely transcribed since off-by-one errors in terminal layout produce visible visual corruption. The Table's `sortIndex` should be replaced with PHP's built-in `usort` with a generic callback rather than bubble sort, both for correctness and performance. The `ContentGenerator` closure pattern translates cleanly to PHP 8.1+ closures, but care must be taken to ensure the closure captures `$maxX` and `$maxY` correctly in the `$this` context.
