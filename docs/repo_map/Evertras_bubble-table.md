# Evertras/bubble-table

## Metadata
- **URL:** https://github.com/Evertras/bubble-table
- **Language:** Go
- **Stars:** ~600-800 (estimated based on repo activity; GitHub API did not return data)
- **License:** MIT (Copyright (c) 2022 Brandon Fulljames)
- **Description:** A customizable, interactive table component for the Bubble Tea framework (Charmbracelet's TUI framework for Go). Provides terminal-rendered tables with sorting, filtering, pagination, selection, and extensive styling options.

## Feature List

- **Column Types:** Fixed-width columns, flexible-width (flex) columns with flex factors
- **Row Data:** Arbitrary `map[string]any` (RowData) keyed by column IDs; supports hidden metadata attachment
- **Styling:** Multi-level style precedence (base → column → row → cell); lipgloss-powered ANSI SGR styling
- **Styled Cells:** `StyledCell` with explicit style or `StyleFunc` for dynamic per-cell styling
- **Borders:** Customizable border characters (12 border elements); pre-built `BorderDefault` and `BorderRounded` styles; lipgloss `BorderForeground` support
- **Selection:** Selectable rows with checkbox column (`[x]`/`[ ]`); row selection state tracking
- **Navigation:** Keyboard-driven cursor (up/down, j/k); configurable `KeyMap` bindings
- **Pagination:** Configurable page size; page navigation (first/last/next/prev) with optional wrapping
- **Sorting:** Ascending/descending sort; multi-column stable sort (chained `ThenSortBy*`); numeric vs string comparison
- **Filtering:** Built-in text input with `/` trigger; case-insensitive "contains" filter; fuzzy filter via `WithFuzzyFilter()`; custom `FilterFunc` support
- **Horizontal Scrolling:** `WithMaxTotalWidth` for overflow; frozen columns (`WithHorizontalFreezeColumnCount`); scroll left/right
- **Vertical Features:** Missing data indicator; zebra striping via `RowStyleFunc`; multiline cell wrapping
- **Events:** `UserEvent` system for user interactions (highlight change, row select toggle, filter focus/unfocus)
- **Help:** Built-in `ShortHelp()`/`FullHelp()` implementing bubble's help.Model interface
- **Visibility:** Toggle header/footer visibility; custom static footer text

## Key Classes and Methods

### `table.Model` (main table model)
- `New(columns []Column) Model` — Factory creating a new table with given columns
- `Init() tea.Cmd` — Bubble Tea initialization (returns nil)
- `Update(msg tea.Msg) (Model, tea.Cmd)` — Bubble Tea update; handles keyboard input, filter text, pagination
- `View() string` — Renders the complete table as ANSI string
- **State modifiers (all return new Model):**
  - `WithRows(rows []Row)`, `WithColumns(columns []Column)`, `WithKeyMap(keyMap KeyMap)`
  - `SelectableRows(selectable bool)`, `Focused(focused bool)`
  - `WithPageSize(n)`, `WithCurrentPage(n)`, `WithPaginationWrapping(bool)`
  - `SortByAsc(col)`, `SortByDesc(col)`, `ThenSortByAsc(col)`, `ThenSortByDesc(col)`
  - `Filtered(bool)`, `WithFilterFunc(fn)`, `WithFuzzyFilter()`, `StartFilterTyping()`
  - `WithBaseStyle(s)`, `HeaderStyle(s)`, `HighlightStyle(s)`, `WithRowStyleFunc(fn)`
  - `WithMissingDataIndicator(s)`, `WithMissingDataIndicatorStyled(sc)`
  - `Border(border)`, `BorderDefault()`, `BorderRounded()`
  - `WithMaxTotalWidth(n)`, `WithHorizontalFreezeColumnCount(n)`
  - `ScrollRight()`, `ScrollLeft()`
  - `WithStaticFooter(s)`, `WithHeaderVisibility(bool)`, `WithFooterVisibility(bool)`
  - `WithMultiline(bool)`
- **Queries:**
  - `GetVisibleRows() []Row`, `HighlightedRow() Row`, `SelectedRows() []Row`
  - `CurrentPage() int`, `MaxPages() int`, `PageSize() int`
  - `VisibleIndices() (start, end)`, `TotalRows() int`
  - `GetLastUpdateUserEvents() []UserEvent`
  - `GetHeaderVisibility() bool`

### `table.Column` (column definition)
- `NewColumn(key, title string, width int) Column` — Fixed-width column factory
- `NewFlexColumn(key, title string, flexFactor int) Column` — Flexible width column
- Accessors: `Title()`, `Key()`, `Width()`, `FlexFactor()`, `IsFlex()`, `Filterable()`, `Style()`, `FmtString()`
- Modifiers: `WithStyle(s)`, `WithFiltered(bool)`, `WithFormatString(fmt)`

### `table.Row` (table row)
- `NewRow(data RowData) Row` — Factory; copies RowData, assigns unique atomic ID
- `WithStyle(style lipgloss.Style) Row` — Apply row-level style
- `Selected(selected bool) Row` — Toggle selection state (returns new Row)

### `table.RowData` (row data container)
- Type: `map[string]any` — arbitrary data keyed by column string keys

### `table.Border` (border styling)
- Fields: 12 Unicode border characters (Top, Left, Right, Bottom, corners, junctions, inner divider)
- `generateStyles()` — Pre-computes lipgloss border styles for all table configurations
- Pre-built: `borderDefault` (heavy box), `borderRounded` (light rounded)

### `table.StyledCell` (styled cell wrapper)
- `NewStyledCell(data any, style lipgloss.Style) StyledCell` — Simple styled cell
- `NewStyledCellWithStyleFunc(data any, fn StyledCellFunc) StyledCell` — Dynamic style function
- `StyleFunc`: `func(StyledCellFuncInput) lipgloss.Style` — receives Data, Column, Row, GlobalMetadata

### `table.KeyMap` (keyboard bindings)
- `DefaultKeyMap() KeyMap` — Sensible defaults: up/down/j/k, space/enter, left/right/pgup/pgdn, home/end, `/` filter
- Full help: `FullHelp() [][]key.Binding`, `ShortHelp() []key.Binding`

### `table.FilterFunc` / `FilterFuncInput`
- `type FilterFunc func(FilterFuncInput) bool` — Return true to include row
- Built-in: `filterFuncContains` (case-insensitive contains on filterable columns), `filterFuncFuzzy` (subsequence match)

### `table.SortColumn` / `SortDirection`
- `SortDirectionAsc` / `SortDirectionDesc` constants
- Multi-column stable sort via Go's `sort.Stable`

### `table.UserEvent` types
- `UserEventHighlightedIndexChanged{PreviousRowIndex, SelectedRowIndex int}`
- `UserEventRowSelectToggled{RowIndex int, IsSelected bool}`
- `UserEventFilterInputFocused{}`, `UserEventFilterInputUnfocused{}`

## Notable Algorithms / Named Patterns

- **Builder/Fluent Pattern:** All `With*` methods and `SortBy*`/`ThenSortBy*` return a new `Model` (immutable); no in-place mutations
- **Bubble Tea Architecture:** Full `tea.Model` interface implementation (`Init`/`Update`/`View`); leverages `key.Matches` for keyboard handling
- **Lipgloss Styling:** All styles built on charmbracelet/lipgloss for ANSI SGR codes; `Style.Inherit()` for precedence layering
- **Visible Row Caching:** `visibleRowCache` with `visibleRowCacheUpdated` flag; invalidated on data/filter/sort changes to avoid recomputation
- **Atomic Row IDs:** `uint32` atomic counter (`lastRowID`) assigned to each `NewRow` for stable identity after row copy
- **Stable Multi-Column Sort:** Go's `sort.Stable` applied iteratively per sort column (first element = primary sort)
- **Numeric vs String Sort:** `extractNumber()` attempts float64 parsing; falls back to `extractString()` for lexicographic comparison
- **Fuzzy Subsequence Match:** Custom `fuzzySubsequenceMatch(haystack, needle)` iterates runes to check ordered subsequence (non-contiguous)
- **Style Precedence:** `rowStyle.Inherit(column.style).Inherit(baseStyle)` — cell most specific, base least
- **Multiline Word Wrap:** Uses `muesli/reflow` `wordwrap.String()` for proper Unicode-aware text wrapping

## Strengths

- **Comprehensive Feature Set:** One of the most full-featured TUI table libraries; covers virtually every table use case (sorting, filtering, pagination, selection, scrolling, styling)
- **Excellent Documentation:** README with extensive feature descriptions, code examples, and references to working demos
- **Clean Architecture:** Follows Bubble Tea's `Model` pattern rigorously; clean separation of state, update logic, and rendering
- **Immutable API:** All modifier methods return new instances; predictable state management without side effects
- **Flexible Styling:** Lipgloss integration provides full ANSI SGR control; multi-level precedence system is intuitive
- **Customizable Keybindings:** `KeyMap` struct allows complete rebinding; `additionalShortHelpKeys`/`additionalFullHelpKeys` for extensibility
- **Event System:** `UserEvent` abstraction allows application to react to user interactions without polling state
- **Fuzzy Filtering:** Built-in fuzzy matching as alternative to simple contains; custom `FilterFunc` enables any filtering logic
- **Frozen Columns:** Practical feature for wide tables; combined with horizontal scrolling is a complete solution
- **Well-Tested:** Extensive test files including fuzz tests for scrolling

## Weaknesses

- **Go-Only:** No direct port to other languages; the PHP port (sugar-table) must be manually maintained
- **Tight Bubble Tea Coupling:** Cannot be used outside the Bubble Tea ecosystem without significant refactoring
- **No Column Resizing at Runtime:** Column widths are fixed at creation; no drag-to-resize like terminal file managers
- **Hidden Metadata Pattern is Manual:** Attaching metadata to rows requires using unused column keys; no first-class `WithMetadata()` on Row
- **Filter State Not Separable:** Filtering requires the table to own filter textinput state; external filter management is awkward
- **Fuzzy Filter Concatenates Columns:** `filterFuncFuzzy` concatenates all filterable column values into one string; cannot target specific columns
- **Style Func Overrides HighlightStyle:** When `WithRowStyleFunc` is set, it completely replaces `HighlightStyle` rather than layering
- **No Row Reordering via Drag:** No built-in support for drag-and-drop row reordering

## SugarCraft Mapping

SugarCraft already has a direct port: **`sugar-table`**

### Direct Mappings (bubble-table → sugar-table):

| bubble-table | sugar-table | Notes |
|---|---|---|
| `Model` | `Table` | Main table class, both use fluent `with*` pattern |
| `Column` | `Column` | Column definition; sugar-table adds `ColumnWidth` enum, `WrapMode`, `Percent` |
| `Row` | `Row` | Row with `RowData`; both are immutable copies |
| `RowData` | `RowData` | Both use `map[string]any` / associative array |
| `StyledCell` | `StyledCell` | Cell with explicit style override |
| `Border` | `Border` (from `SugarCraft\Sprinkles\Border`) | Pre-built border families (normal/rounded/thick/double/block/ascii/hidden/markdownBorder) |
| `KeyMap` | (partially via KeyMap in sugar-prompt) | Keyboard bindings not fully ported |
| `FilterFunc` / `FilterFuncInput` | `Table::Filter()` | Per-column filter map; sugar-table filters by `stripos` |
| `filterFuncContains` | Built into `filteredSortedRows()` | Case-insensitive contains |
| `filterFuncFuzzy` | Not yet ported | Fuzzy subsequence matching |
| `SortColumn` / `SortDirection` | `sortColumns` array + `SortBy()` | Multi-column stable sort |
| `UserEvent*` types | Not ported | sugar-table is render-only; no event system |
| `WithMultiline` | `withMultilineMode()` | Word/character wrap modes via `WrapMode` enum |
| `WithMaxTotalWidth` + scrolling | `withScrollX()` | Horizontal scrolling |
| `WithHorizontalFreezeColumnCount` | `withFrozenCols()` | Frozen left columns |
| `WithRowStyleFunc` (zebra) | `withZebra()` + zebra styling | Built-in zebra striping |
| `WithMissingDataIndicator` | `withMissingIndicator()` | Missing cell placeholder |

### Additional sugar-table Features (not in bubble-table):
- `ColumnWidth` enum: `Fixed`, `Percent`, `Dynamic`, `Content` — column width specification
- `WrapMode` enum: `None`, `WordWrap`, `Character` — text wrapping behavior
- `viewportHeight` / `withViewportHeight()` — viewport virtualization for large tables
- `scrollY` / `withScrollY()` — vertical scrolling offset
- Direct `SelectNext()`, `SelectPrevious()`, `SelectPage()` navigation methods

### Related SugarCraft Libraries:
- **`sugar-prompt`:** Shares `KeyMap` concept and some input handling patterns
- **`sugar-charts`:** For rendering data visualizations alongside tables
- **`candy-core`:** Lower-level ANSI rendering primitives (`Ansi` utility class)

## Analysis

Evertras/bubble-table is a best-in-class TUI table library for Go, tightly integrated with the Charmbracelet ecosystem (Bubble Tea, lipgloss, bubbles). Its design philosophy emphasizes immutability, composability, and comprehensive feature coverage. The architecture follows the Bubble Tea `Model` pattern strictly, making it a first-class citizen of the ecosystem alongside other bubbles like `textinput`, `paginator`, and `help`.

The library's most impressive feature is its layered styling system — base style cascades through column → row → cell, with `lipgloss.Style.Inherit()` providing clean precedence. Combined with `StyledCell` and `StyleFunc`, this enables sophisticated data-dependent formatting (zebra striping, conditional coloring, etc.) without complexity. The event system is similarly elegant: `UserEvent` types provide a clean abstraction for user interactions that the host application can query via `GetLastUpdateUserEvents()`.

The PHP port in sugar-table is a faithful adaptation that preserves most functionality while adapting to PHP's limitations (no atomic row IDs, no built-in stable sort, different style representation as ANSI code strings). The most significant omission is the event system — sugar-table is render-only and would need an additional layer to support interactive TUI use cases. The fuzzy filtering and frozen column features are also not yet fully ported. sugar-table does add some Go-unique features like `WrapMode`, `ColumnWidth` enums, and viewport virtualization, suggesting organic growth beyond simple porting.

This library represents the reference implementation for interactive terminal tables in the Charmbracelet ecosystem. Any SugarCraft TUI work involving tables should use sugar-table directly or build upon its patterns. The fuzzy filtering and user event system would be valuable additions to sugar-table for parity.
