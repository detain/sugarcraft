# SugarCraft/sugar-table

## Metadata
- **URL:** https://github.com/sugarcraft/sugar-table
- **Language:** PHP 8.3+
- **License:** MIT
- **Status:** 🟢 v1 Ready
- **Description:** PHP port of Evertras/bubble-table — full-featured interactive terminal table with column definitions, StyledCell ANSI formatting, pagination, multi-column sorting, filtering, frozen rows/columns, viewport virtualization, and ANSI border styling.

---

## Architecture Overview

sugar-table is a focused, standalone table component library. Unlike sugar-bits which is a multi-component monorepo, sugar-table concentrates exclusively on data table rendering with a complete feature set.

### Package Structure

```
sugar-table/
├── src/
│   ├── Table.php        # Main table class (857 lines)
│   ├── Column.php       # Column definition (266 lines)
│   ├── Row.php          # Row wrapper (47 lines)
│   ├── RowData.php      # Row data container (61 lines)
│   ├── StyledCell.php   # Styled cell wrapper (44 lines)
│   ├── ColumnWidth.php  # ColumnWidth enum (26 lines)
│   ├── WrapMode.php     # WrapMode enum (20 lines)
│   └── Lang.php         # i18n facade (22 lines)
├── tests/              # 10 test files
├── examples/           # basic.php, features.php
├── lang/               # i18n (en.php currently)
├── .vhs/               # VHS demos (basic.gif, features.gif)
└── CALIBER_LEARNINGS.md
```

### Dependencies
```json
{
  "sugarcraft/candy-core": "dev-master",     // ANSI rendering primitives (Ansi class)
  "sugarcraft/candy-sprinkles": "dev-master"   // Border family for table borders
}
```

---

## Component-by-Component Analysis

### 1. Table (`src/Table.php` — 857 lines)

**Main state fields:**
```php
/** @var list<Column> */
private array $columns = [];

/** @var list<Row> */
private array $rows = [];

private int $selectedIndex = 0;        // Cursor position in current view
private string $baseStyle = '';        // Base ANSI style applied to all cells
private string $missingIndicator = '-'; // Placeholder for null values
private string $borderStyle = '';      // ANSI style for borders
private bool $selectable = true;       // Row selection enabled

// Pagination
private int $pageSize = 0;   // 0 = no pagination
private int $page = 0;

// Sort state: list of ['key' => string, 'asc' => bool]
private array $sortColumns = [];

// Filter state: colKey => filterText
private array $filterText = [];

// Frozen columns (indices)
private array $frozenCols = [];

// Horizontal scroll offset
private int $scrollX = 0;

// Viewport virtualization
private int $viewportHeight = 0;   // 0 = render all
private int $scrollY = 0;            // Vertical scroll offset

// Zebra striping
private bool $zebraEnabled = false;
private string $zebraStyleOdd = '100';   // bright black (dim)
private string $zebraStyleEven = '';

// Border chars (13 characters)
private string $borderTopLeft, $borderTop, $borderTopRight;
private string $borderBottomLeft, $borderBottom, $borderBottomRight;
private string $borderLeft, $borderRight;
private string $borderCenterH, $borderCenterV, $borderCross;

private string $headerStyle = '1;37';   // bold white
private string $footerStyle = '90';     // bright black
private bool $showHeader = true;
private bool $showFooter = true;
private ?Border $border = null;         // Border family from candy-sprinkles
private bool $multilineMode = false;    // Multi-line row rendering
```

**Factory:**
```php
public static function withColumns(array $columns): self
```

**Fluent configuration methods:** `withRows()`, `withBaseStyle()`, `withMissingIndicator()`, `withBorderStyle()`, `withSelectable()`, `withPageSize()`, `withPage()`, `withFrozenCols()`, `withScrollX()`, `withViewportHeight()`, `withScrollY()`, `withZebra()`, `withHeaderStyle()`, `withShowHeader()`, `withShowFooter()`, `withBorder()`, `withMultilineMode()`

**Navigation:** `SelectNext()`, `SelectPrevious()`, `SelectPage()`, `NextPage()`, `PreviousPage()`

**Sorting:** `SortBy()`, `ClearSort()`

**Filtering:** `Filter()`, `ClearFilter()`, `ClearAllFilters()`

**Queries:** `Columns()`, `Rows()`, `filteredSortedRows()`, `pagedRows()`, `CurrentRow()`, `CurrentRowData()`, `TotalRows()`, `TotalPages()`, `SelectedIndex()`, `CurrentPage()`, `PageSize()`, `PageFooter()`, `computeColumnWidths()`

**Rendering:** `View()` — returns ANSI-formatted table string

### 2. Column (`src/Column.php` — 266 lines)

**State:**
```php
public readonly string $key;           // Unique identifier
public readonly string $title;         // Display header
public readonly int $width;            // Total cell width
public readonly int $flexibleWidth;    // 0 = fixed, >0 = flexible share
public readonly int $maxWidth;          // Horizontal scroll cap (0 = no max)
public readonly bool $filterable;      // Participates in filtering
public readonly bool $alignLeft;        // Left-align (default: right)
public readonly string $style;          // Column-level ANSI style
public readonly ColumnWidth $columnWidth;  // Fixed/Percent/Dynamic/Content
public readonly float $percentValue;    // For Percent mode (0-100)
public readonly WrapMode $wrapMode;     // None/WordWrap/Character
```

**Factory & modifiers:**
```php
public static function new(string $key, string $title, int $width): self
public function withFlexibleWidth(int $share): self
public function withMaxWidth(int $max): self
public function withFilterable(bool $v = true): self
public function withAlignLeft(bool $v = true): self
public function withStyle(string $ansiStyle): self
public function withColumnWidth(ColumnWidth $mode, float $percentValue = 0.0): self
public function withWrapMode(WrapMode $wrapMode): self
```

**Rendering:**
```php
public function renderHeader(int $totalWidth = 0): string
public function renderCell(mixed $value, int $width = 0): array  // Returns list<string>
```

### 3. Row (`src/Row.php` — 47 lines)

```php
public readonly RowData $data;
public readonly string $style;              // Row-level ANSI style
public readonly bool $zebra;               // Apply zebra striping
public readonly ?int $zebraStyleIndex;      // Which alternating style

public static function new(RowData $data): self
public function withStyle(string $ansiStyle): self
public function withZebra(int $styleIndex = 0): self
```

### 4. RowData (`src/RowData.php` — 61 lines)

```php
private array $data;   // map<string, mixed>

public static function from(array $data): self
public function get(string $key): mixed       // null if key not present
public function has(string $key): bool
public function all(): array
public function with(string $key, mixed $value): self  // Immutable with()
```

### 5. StyledCell (`src/StyledCell.php` — 44 lines)

```php
public readonly mixed $value;
public readonly string $style;

public static function new(mixed $value, string $ansiStyle = ''): self
public function withStyle(string $ansiStyle): self
public function __toString(): string   // Applies ANSI style if set
```

### 6. ColumnWidth Enum (`src/ColumnWidth.php` — 26 lines)

```php
enum ColumnWidth: string
{
    case Fixed = 'fixed';      // Fixed character count (uses Column.width)
    case Percent = 'percent';  // Percentage of total table width (uses percentValue 0-100)
    case Dynamic = 'dynamic';  // Min-width from content, max from table
    case Content = 'content';  // Exactly fit content, compress if needed
}
```

### 7. WrapMode Enum (`src/WrapMode.php` — 20 lines)

```php
enum WrapMode
{
    case None;       // Truncate at column width
    case WordWrap;   // Break at word boundaries
    case Character;  // Break at any character, no padding on last line
}
```

### 8. Lang (`src/Lang.php` — 22 lines)

Per-library i18n facade extending `SugarCraft\Core\I18n\Lang`, namespace `'table'`, translations in `lang/en.php`.

---

## Table Architecture

### Data Flow

```
rows (原始数据)
    ↓
filteredSortedRows() — 应用 filterText + sortColumns
    ↓
pagedRows() — 应用 pageSize + page offset
    ↓
viewportHeight > 0 ? array_slice(rows, scrollY, viewportHeight) : rows
    ↓
renderRowLines() — 每行渲染为1或多行
    ↓
View() — 组装完整表格字符串
```

### Filtering Implementation

**Location:** `Table.php` lines 416–427 (`filteredSortedRows()`)

```php
if ($this->filterText !== []) {
    $rows = \array_values(
        \array_filter($rows, function (Row $row) use (&$filters): bool {
            foreach ($this->filterText as $key => $text) {
                $val = $row->data->get($key);
                $str = \is_object($val) && method_exists($val, '__toString')
                    ? (string) $val
                    : (string) ($val ?? '');
                if (\stripos($str, $text) === false) return false;
            }
            return true;
        })
    );
}
```

**Algorithm:** Case-insensitive `stripos()` (PHP's native substring search). All active filters must match (AND logic). Filter is per-column via `$filterText[$colKey]`.

**NOT IMPLEMENTED:** Fuzzy subsequence matching (upstream has `filterFuncFuzzy`).

### Sorting Implementation

**Location:** `Table.php` lines 429–455 (`filteredSortedRows()`)

```php
if ($this->sortColumns !== []) {
    \usort($rows, function (Row $a, Row $b): int {
        foreach ($this->sortColumns as $sort) {
            $key = $sort['key'];
            $asc = $sort['asc'];

            $va = $a->data->get($key);
            $vb = $b->data->get($key);

            $sa = \is_object($va) && method_exists($va, '__toString')
                ? (string) $va : (string) ($va ?? '');
            $sb = \is_object($vb) && method_exists($vb, '__toString')
                ? (string) $vb : (string) ($vb ?? '');

            // Numeric sort
            if (\is_numeric($sa) && \is_numeric($sb)) {
                $cmp = (float) $sa <=> (float) $sb;
            } else {
                $cmp = \strcasecmp($sa, $sb);
            }

            if ($cmp !== 0) {
                return $asc ? $cmp : -$cmp;
            }
        }
        return 0;
    });
}
```

**Algorithm:** Multi-column stable sort using PHP's `usort()` (not stable, but works for equal primary keys). Primary sort column is `$sortColumns[0]`. Subsequent columns only used when earlier columns are equal.

**Toggle semantics:** `SortBy($colKey, $ascending, $primary=true)` — if primary and already sorting by same key/direction, flips direction.

**Numeric detection:** `is_numeric()` check on string values before comparison, uses float comparison.

### Frozen Column Handling

**Location:** `Table.php` lines 175–179 (`withFrozenCols()`) + rendering at `View()` time

`sugar-table` stores frozen column indices but the actual freeze rendering appears to be a placeholder — the scrollX offset is stored but the rendering doesn't apply differential treatment for frozen columns in the current implementation. This is a gap vs upstream which fully implements frozen column rendering where those columns always appear on the left regardless of horizontal scroll position.

### Multi-line Row Rendering

**Location:** `Table.php` lines 771–843 (`renderRowLines()`)

```php
private function renderRowLines(Row $row, int $rowIndex, int $totalWidth, bool $isSelected): array
```

When `multilineMode=true`:
1. Each cell is rendered via `Column::renderCell()` returning `list<string>`
2. Maximum line count across all columns is computed
3. Each row line is built by taking one line from each column (or space-padding if that column has fewer lines)
4. Row height = max cell height

When `multilineMode=false` (default): `maxLines = 1`, first line only from each cell.

### Column Width Computation

**Location:** `Table.php` lines 506–565 (`computeColumnWidths()`)

**Algorithm: Two-pass**
1. **Pass 1:** Collect Fixed/Percent widths, count Dynamic/Content placeholders
2. **Pass 2:** Calculate remaining space, distribute to Dynamic/Content:
   - `Dynamic`: `max(contentWidth, flexWidth)`
   - `Content`: exact content width (min 1)
3. Remaining placeholders fall back to original `$column->width`

### Style Precedence

From `renderRowLines()` lines 783–796:
```
baseStyle → column.style → row.style → StyledCell.style → zebra override → cursor override
```

Order (least specific to most specific):
1. Table `$baseStyle`
2. Column `$style`
3. Row `$style`
4. `StyledCell::$style` (cell-most specific)
5. Zebra (if enabled, overwrites for odd/even rows)
6. Cursor (`'7'` = reverse video for selected row)

---

## Comparison Against Upstream Repos

### vs. Evertras/bubble-table (primary upstream)

| Feature | bubble-table (Go) | sugar-table (PHP) | Parity |
|---------|------------------|-------------------|--------|
| Multi-column stable sort | ✅ sort.Stable | ✅ usort loop | 🟡 |
| FilterFunc / FilterFuncInput | ✅ | ❌ | Missing |
| filterFuncContains | ✅ stripos | ✅ stripos | ✅ |
| filterFuncFuzzy | ✅ fuzzySubsequenceMatch | ❌ | Missing |
| Horizontal scroll + frozen | ✅ WithMaxTotalWidth + WithHorizontalFreezeColumnCount | 🟡 withFrozenCols + withScrollX (incomplete) | 🟡 Partial |
| UserEvent system | ✅ | ❌ | Missing |
| Bubble Tea integration | ✅ tea.Model | N/A (render-only) | N/A |
| WithMultiline | ✅ muesli/reflow wordwrap | ✅ withMultilineMode + WrapMode | ✅ |
| Lipgloss styling | ✅ Style.Inherit() | ❌ ANSI code strings | Different |
| WithRowStyleFunc zebra | ✅ RowStyleFunc | ✅ withZebra() | ✅ |
| WithMissingDataIndicator | ✅ | ✅ withMissingIndicator() | ✅ |
| Atomic Row IDs | ✅ uint32 atomic | ❌ | N/A |
| Visible row caching | ✅ visibleRowCache | ❌ | Not needed in PHP |

### vs. 76creates/stickers Table

| Feature | stickers (Go) | sugar-table (PHP) | Notes |
|---------|---------------|-------------------|-------|
| Sort algorithm | Bubble sort O(n²) | `usort` O(n log n) | PHP wins |
| Type-safe columns | Go generics Ordered | PHP type coercion | Different |
| Multi-column filter | ❌ single column only | ✅ AND across columns | PHP wins |
| x/y scrolling | ✅ | 🟡 scrollX stored but not rendered | Gap |
| Cursor navigation | Up/Down/Left/Right | Only Up/Down via SelectNext/Previous | Gap |
| ContentGenerator | ✅ func(maxX, maxY) string | ❌ | Missing |
| Ratio-based layout | ✅ (built on FlexBox) | ❌ Fixed/dynamic widths | Different |
| Footer status bar | ✅ | 🟡 only pagination footer | Partial |

### vs. charmbracelet/bubbles Table

| Feature | bubbles Table (Go) | sugar-table (PHP) | Notes |
|---------|-------------------|-------------------|-------|
| Column definitions | ✅ | ✅ | Parity |
| Pagination | ✅ | ✅ | Parity |
| Sorting | ✅ single column | ✅ multi-column | PHP wins |
| Filtering | ✅ via List component | ✅ built-in | PHP wins |
| Keyboard navigation | Full KeyMap | Only SelectNext/Previous | Gap |
| Help integration | ✅ ShortHelp/FullHelp | ❌ | Missing |

---

## Notable Algorithms / Named Patterns

### 1. Two-pass Column Width Computation
`Table::computeColumnWidths()` — First collects explicit widths, then distributes remaining space to flexible columns. See CALIBER_LEARNINGS pattern `column-width-enum`.

### 2. Viewport Virtualization
`Table::View()` at lines 604–611 — when `$viewportHeight > 0`, rows are sliced via `array_slice($rows, $this->scrollY, $this->viewportHeight)`. See CALIBER_LEARNINGS pattern `viewport-virtualization`.

### 3. Multi-line Row Rendering
`Table::renderRowLines()` builds output by iterating cell line arrays in parallel, padding with spaces when a column has fewer lines than the max. See CALIBER_LEARNINGS pattern `cell-wrap-return-list-string`.

### 4. Immutable RowData with with()
`RowData::with()` creates a clone and sets a key — pure immutable update pattern.

### 5. Column wrapping modes
`Column::wrapText()` dispatch:
- `WrapMode::None` — truncate with `substr()`
- `WrapMode::WordWrap` — break at last space before width
- `WrapMode::Character` — `str_split()` at width boundaries

---

## Innovation Points (SugarCraft Enhancements Over Upstream)

1. **ColumnWidth enum with 4 modes** — Fixed/Percent/Dynamic/Content provides more column sizing strategies than upstream's simpler flex/fixed model
2. **WrapMode enum** — Three distinct wrapping strategies (None/WordWrap/Character) as a proper PHP enum
3. **Viewport virtualization** — `withViewportHeight()` + `withScrollY()` for efficient rendering of large datasets
4. **Multi-column AND filtering** — Upstream bubble-table filters on all filterable columns simultaneously; sugar-table allows per-column filters via `Filter($colKey, $text)` with AND semantics
5. **Border family integration** — Full `Border` family from candy-sprinkles (8 border styles) rather than hand-rolled border chars
6. **i18n via Lang facade** — Page footer and future labels translatable via `SugarCraft\Table\Lang::t()`
7. **Strict types + immutability** — All classes `final`, `declare(strict_types=1)`, `readonly` properties throughout

---

## Comparison Matrix

| Feature | sugar-table | bubble-table | stickers Table | bubbles Table |
|---------|------------|---------------|----------------|--------------|
| Multi-column sort | ✅ | ✅ | ❌ | ❌ |
| Multi-column AND filter | ✅ | ✅ (via FilterFunc) | ❌ (single col) | ❌ |
| Fuzzy filter | ❌ | ✅ | ❌ | ❌ |
| Pagination | ✅ | ✅ | ❌ | ✅ |
| Frozen columns | 🟡 (stored, partial) | ✅ | ❌ | ❌ |
| Horizontal scroll | 🟡 (offset stored) | ✅ | ✅ | ❌ |
| Row selection | ✅ | ✅ | ✅ | ✅ |
| Zebra striping | ✅ | ✅ | ❌ | ❌ |
| StyledCell | ✅ | ✅ | ❌ | ❌ |
| Viewport virtualization | ✅ | ❌ | ❌ | ❌ |
| Column width modes | ✅ (4 modes) | ❌ (flex only) | ❌ (ratio only) | ❌ |
| WrapMode | ✅ (3 modes) | ❌ | ❌ | ❌ |
| Border family | ✅ (8 styles) | ✅ (2 styles) | ❌ | ❌ |
| i18n | ✅ | ❌ | ❌ | ❌ |
| Bubble Tea | N/A | ✅ | ✅ | ✅ |
| PHP 8.3+ only | ✅ | N/A | N/A | N/A |

---

## Known Gaps

1. **Fuzzy filtering** — Case-insensitive contains only. No fuzzy subsequence matching like upstream's `filterFuncFuzzy`.
2. **Frozen column rendering** — `withFrozenCols()` stores indices but the rendering logic doesn't properly handle frozen columns staying visible during horizontal scroll. `scrollX` is stored but not applied during rendering.
3. **Keyboard navigation** — Only `SelectNext()` / `SelectPrevious()` / `SelectPage()`. No vim keys (j/k/g/G), no HalfPageUp/Down, no Home/End.
4. **UserEvent system** — No event callbacks for highlight changes, row selection, or filter focus/unfocus. Table is render-only.
5. **Bubble Tea integration** — Not applicable (PHP), but means sugar-table can't participate in the TEA update loop.
6. **StyleFunc dynamic styling** — Unlike sugar-bits' Table which has per-cell `styleFunc(Closure)`, sugar-table relies on StyledCell for cell-level style overrides rather than a callback-based approach.
7. **Horizontal scrolling actual rendering** — `scrollX` is stored but `View()` doesn't shift column rendering based on it.

---

## File References

### Core Source Files
- `/home/sites/sugarcraft/sugar-table/src/Table.php` — 857 lines, main table class
- `/home/sites/sugarcraft/sugar-table/src/Column.php` — 266 lines, column definition
- `/home/sites/sugarcraft/sugar-table/src/Row.php` — 47 lines, row wrapper
- `/home/sites/sugarcraft/sugar-table/src/RowData.php` — 61 lines, row data container
- `/home/sites/sugarcraft/sugar-table/src/StyledCell.php` — 44 lines, styled cell wrapper
- `/home/sites/sugarcraft/sugar-table/src/ColumnWidth.php` — 26 lines, ColumnWidth enum
- `/home/sites/sugarcraft/sugar-table/src/WrapMode.php` — 20 lines, WrapMode enum
- `/home/sites/sugarcraft/sugar-table/src/Lang.php` — 22 lines, i18n facade

### Test Files
- `/home/sites/sugarcraft/sugar-table/tests/TableTest.php` — 257 lines
- `/home/sites/sugarcraft/sugar-table/tests/ColumnTest.php` — 201 lines
- `/home/sites/sugarcraft/sugar-table/tests/StyledCellTest.php` — 145 lines
- `/home/sites/sugarcraft/sugar-table/tests/TableViewportTest.php` — 106 lines
- `/home/sites/sugarcraft/sugar-table/tests/TableColumnWidthTest.php` — 127 lines
- `/home/sites/sugarcraft/sugar-table/tests/TableBorderStyleTest.php` — 213 lines
- `/home/sites/sugarcraft/sugar-table/tests/TableWrappingTest.php` — 141 lines
- `/home/sites/sugarcraft/sugar-table/tests/RowDataTest.php`
- `/home/sites/sugarcraft/sugar-table/tests/LangCoverageTest.php`

### Examples
- `/home/sites/sugarcraft/sugar-table/examples/basic.php` — Basic table demo
- `/home/sites/sugarcraft/sugar-table/examples/features.php` — Wide table with frozen columns, pagination, StyledCell

### VHS Demos
- `/home/sites/sugarcraft/sugar-table/.vhs/basic.tape` + `basic.gif`
- `/home/sites/sugarcraft/sugar-table/.vhs/features.tape` + `features.gif`

### Documentation
- `/home/sites/sugarcraft/sugar-table/README.md` — 248 lines
- `/home/sites/sugarcraft/sugar-table/CALIBER_LEARNINGS.md` — 54 lines

---

## Analysis

**sugar-table** is a mature, well-structured PHP port of Evertras/bubble-table that achieves feature parity on core table functionality while adding several SugarCraft-unique enhancements. The architecture is clean with proper separation of concerns: `Table` owns state and rendering, `Column` encapsulates column-specific behavior, `Row`/`RowData` represent row data, and `StyledCell` provides per-cell styling.

**Strengths:**
- Comprehensive feature coverage (sorting, filtering, pagination, selection, viewport)
- Multi-column AND filtering (superior to bubble-table's single-column or stickers' limited filtering)
- Immutable + fluent builder pattern throughout
- Viewport virtualization for large datasets
- ColumnWidth and WrapMode enums provide more flexibility than upstream
- Integration with candy-sprinkles Border family (8 styles)
- i18n support via Lang facade
- Good test coverage (10 test files)

**Gaps:**
- Fuzzy filtering not implemented (upstream has `filterFuncFuzzy`)
- Frozen column rendering incomplete (stored but not applied during render)
- Keyboard navigation limited (no vim keys, no half-page navigation)
- Horizontal scroll offset stored but not applied during rendering
- No UserEvent system for reactive TUI patterns
- StyledCell only, no callback-based StyleFunc like sugar-bits Table has

**Strategic Position:** sugar-table is the dedicated table component in SugarCraft, distinct from the Table component embedded in sugar-bits. It targets scenarios requiring a full-featured standalone table widget. The v1 status indicates readiness for production use, though the frozen column and horizontal scroll rendering gaps should be addressed for full upstream parity.

---

## Related Reports

- `/home/sites/sugarcraft/repo_map/Evertras_bubble-table.md` — Primary upstream (Go)
- `/home/sites/sugarcraft/repo_map/76creates_stickers.md` — FlexBox/Table for bubbletea (Go)
- `/home/sites/sugarcraft/repo_map/charmbracelet_bubbles.md` — Charm ecosystem components (Go)
- `/home/sites/sugarcraft/repo_map/sugarcraft_sugar-bits.md` — SugarCraft components including Table variant
