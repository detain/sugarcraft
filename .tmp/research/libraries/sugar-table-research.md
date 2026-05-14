# sugar-table Research: Terminal Table Library Patterns

**Date:** 2026-05-13
**Context:** Improving sugar-table (PHP TUI table library) based on patterns from other languages
**Upstream:** Evertras/bubble-table

---

## Executive Summary

Research across Go (bubble-table, lipgloss, tview), Rust (ratatui, comfy-table), Python (textual, rich), and JavaScript (cli-table3, terminal-columns, tty-table) reveals several concrete improvements for sugar-table. The most impactful additions are viewport/virtualization support for large datasets, dynamic column width calculation, and a more flexible style function pattern.

---

## Current Implementation Analysis

**Source:** `/home/sites/sugarcraft/sugar-table/src/`

### Strengths
- Immutable + fluent API with `with*()` pattern
- Multi-column sorting with `ThenSortBy*()` pattern
- Filter by column with case-insensitive substring matching
- Zebra striping support
- Frozen columns via index tracking
- Horizontal scroll offset tracking

### Gaps vs. Other Libraries
1. **No viewport virtualization** — renders all rows on every `View()` call
2. **No dynamic column sizing** — requires explicit widths, no auto-expand/shrink
3. **No percentage-based widths** — only fixed pixel widths
4. **No text wrapping** — content truncated, not wrapped
5. **No row height tracking** — assumes 1 line per row
6. **Limited border styles** — only one border character set

---

## Feature Comparison Matrix

| Feature | bubble-table | lipgloss | ratatui | comfy-table | textual | rich | cli-table3 | sugar-table |
|---------|-------------|----------|---------|-------------|---------|------|------------|-------------|
| **Column Sizing** | | | | | | | | |
| Fixed width | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Flex/grow width | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | partial | ❌ |
| Percentage width | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Dynamic auto-width | ❌ | ✅ | ❌ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Content-based width | ❌ | ❌ | ❌ | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Row Handling** | | | | | | | | |
| Text wrapping | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Multi-line rows | limited | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Row height tracking | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Interactions** | | | | | | | | |
| Sorting (multi-col) | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ✅ |
| Filtering | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ✅ |
| Pagination | built-in | external | external | external | external | N/A | N/A | ✅ |
| Selection | ✅ | ❌ | ✅ | ❌ | ✅ | N/A | N/A | ✅ |
| **Large Datasets** | | | | | | | | |
| Viewport virtualization | ❌* | ❌ | ❌ | ❌ | ✅ | N/A | N/A | ❌ |
| Chunk-based loading | via vtable | N/A | N/A | N/A | ✅ | N/A | N/A | ❌ |
| **Styling** | | | | | | | | |
| StyleFunc per cell | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | partial |
| Border templates | rounded, ascii | many | many | many | N/A | many | many | 1 |
| Zebra striping | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

*\*bubble-table has a community vtable library for virtualization*

---

## Detailed Findings by Category

### 1. Column Sizing

#### lipgloss/table Dynamic Resizing
**Source:** [charmbracelet/lipgloss/table/resizing.go](https://github.com/charmbracelet/lipgloss/blob/main/table/resizing.go)

lipgloss implements sophisticated column width optimization:

```go
// expandTableWidth expands columns to fill available space
// prioritize shortest columns first
// shrinkToMedian: shrink columns that differ most from median
// shrinkBiggestColumns: cut oversized columns first

func (r *resizer) optimizedWidths() (colWidths, rowHeights []int) {
    if r.maxTotal() <= r.tableWidth {
        return r.expandTableWidth(), r.rowHeights
    }
    return r.shrinkTableWidth(), r.rowHeights
}
```

**Key Insight:** Expansion prioritizes shortest columns first; shrinking uses median deviation to preserve important data.

#### comfy-table Constraints
**Source:** [docs.rs/comfy-table](https://docs.rs/comfy-table/latest/src/comfy_table/style/column.rs_search=)

```rust
pub enum ColumnConstraint {
    Hidden,
    ContentWidth,                    // Force content width
    Absolute(Width),                 // Fixed characters
    LowerBoundary(Width),            // Min width (fixed or %)
    UpperBoundary(Width),            // Max width (fixed or %)
    Boundaries { lower: Width, upper: Width },  // Min + max
}

pub enum Width {
    Fixed(u16),        // Character count
    Percentage(u16),   // % of available (0-100)
}
```

**Key Insight:** Explicit lower/upper boundaries with both fixed and percentage options.

#### bubble-table Flex Columns
**Source:** [Context7: evertras/bubble-table](https://context7.com/evertras/bubble-table/llms.txt)

```go
table.NewFlexColumn("element", "Element", 1),   // factor 1
table.NewFlexColumn("description", "Description", 3), // factor 3 (3x wider)

func NewFlexTableModel() table.Model {
    return table.New([]table.Column{
        table.NewColumn("name", "Name", 10),  // Fixed 10 chars
        table.NewFlexColumn("element", "Element", 1),
        table.NewFlexColumn("description", "Description", 3),
    }).WithTargetWidth(80)  // Required for flex calculation
}
```

**Key Insight:** Flex columns use ratio factors; `WithTargetWidth()` enables responsive layout.

---

### 2. Row Rendering & Text Wrapping

#### lipgloss Row Height Calculation
**Source:** [charmbracelet/lipgloss#620](https://github.com/charmbracelet/lipgloss/commit/c289bad531f2588fc7506d7fbd5cdfd3daf4cb27)

lipgloss calculates row heights based on wrapped content:

```go
// expandRowHeights expands row heights based on wrapped content
func (r *resizer) expandRowHeights(colWidths []int) {
    for i, row := range allRows {
        for j := range row {
            column := &r.columns[j]
            content := row[j]
            height := calculateWrappedHeight(content, colWidths[j])
            r.rowHeights[i] = max(r.rowHeights[i], height)
        }
    }
}
```

#### ratatui Table with Multi-line Cells
**Source:** [Context7: ratatui/ratatui](https://context7.com/ratatui/ratatui/llms.txt)

```rust
let rows = vec![
    Row::new(vec!["Alice", "30", "Engineer"]).style(Style::new().white()),
    Row::new(vec!["Bob",   "25", "Designer"]).style(Style::new().cyan()),
    Row::new([
        Cell::from(Line::from(vec![Span::raw("Carol").bold()])),
        Cell::from("28"),
        Cell::from("Manager"),
    ]).height(2).bottom_margin(1),  // Multi-line with explicit height
];
```

**Key Insight:** Cells can have explicit heights; content wrapping is handled automatically.

---

### 3. Sorting

#### bubble-table Multi-Column Sort
**Source:** [Context7: evertras/bubble-table](https://context7.com/evertras/bubble-table/llms.txt)

```go
// Sort by type ascending, then within each type sort by wins descending
return m.SortByAsc("type").ThenSortByDesc("wins")

// Get current sorting configuration
func getSortInfo(m table.Model) []table.SortColumn {
    return m.GetColumnSorting()
}
```

**Key Insight:** `ThenSortBy*()` enables priority-based multi-column sorting.

**Current sugar-table already has this** — `SortBy()` with `$primary=false` appends to sort list.

---

### 4. Filtering

#### bubble-table Column-Filtered Search
**Source:** [Context7: evertras/bubble-table](https://context7.com/evertras/bubble-table/llms.txt)

```go
table.NewColumn("title", "Title", 30).WithFiltered(true),
table.NewColumn("author", "Author", 20).WithFiltered(true),

// Enable filtering mode
return table.New(columns).
    WithRows(rows).
    Filtered(true).    // Enable filtering
    Focused(true)      // '/' starts typing filter
```

**Key Insight:** Per-column `filterable` flag; global `Filtered()` toggle; `/` keybinding to start.

**Current sugar-table has global filtering** — consider per-column `filterable` flag like bubble-table.

---

### 5. Borders & Styling

#### lipgloss StyleFunc Pattern
**Source:** [Context7: charmbracelet/lipgloss](https://context7.com/charmbracelet/lipgloss/llms.txt)

```go
t := table.New().
    Border(lipgloss.RoundedBorder()).
    StyleFunc(func(row, col int) lipgloss.Style {
        switch {
        case row == table.HeaderRow:
            return headerStyle
        case row%2 == 0:
            return evenRowStyle
        default:
            return oddRowStyle
        }
    }).
    Headers("NAME", "AGE", "CITY").
    Row("Alice", "30", "New York")
```

**Key Insight:** `StyleFunc(row, col int)` provides row+column context for dynamic styling.

#### ratatui Cell-Level Styling
**Source:** [Context7: ratatui/ratatui](https://context7.com/ratatui/ratatui/llms.txt)

```rust
Row::new([
    Cell::from(Line::from(vec![Span::raw("Carol").bold()])),
    Cell::from("28"),
    Cell::from("Manager"),
]).height(2)
```

**Key Insight:** Each cell can have its own Rich Text rendering.

**Current sugar-table StyledCell exists** — but only supports ANSI string style, not Rich Text.

---

### 6. Large Dataset Handling (Virtualization)

#### bubble-table Viewport Optimization PR #284
**Source:** [charmbracelet/bubbles#284](https://github.com/charmbracelet/bubbles/pull/284)

The key optimization is rendering only visible rows:

```go
// Before (O(n) per scroll):
for i := 0; i < len(m.rows); i++ {
    m.renderedLines[i] = r.renderRow(i)  // Render ALL rows
}

// After (O(1) per scroll):
start := m.cursor - m.viewport.Height
end := m.cursor + m.viewport.Height
for i := start; i < end; i++ {
    m.renderedLines[i] = r.renderRow(i)  // Render only visible
}
```

**Performance Results:**
```
Rows: 36,932
Before: 0.5 sec per MoveDown()
After:  0.005 sec per MoveDown() (100x faster)
```

#### vtable - Virtualized Table for Bubble Tea
**Source:** [davidroman0O/vtable](https://github.com/davidroman0O/vtable)

```go
// Chunking-based virtualization for millions of rows
type DataSource interface {
    GetChunk(start, end int) []Row  // Async chunk loading
    SortBy(field string, asc bool) tea.Cmd
    Filter(field, value string) tea.Cmd
}

// Features:
// - Viewport virtualization (Chunking)
// - Async data loading via tea.Cmd
// - Multi-column sorting
// - Dynamic filtering
// - Selection modes: Single, Multiple, None
```

#### ratatui TableState for Virtual Scroll
**Source:** [ratatui/ratatui#1004](https://github.com/ratatui-org/ratatui/issues/1004)

```rust
let mut state = TableState::default();
state.select(Some(0));  // Track selection separately

// Only render visible rows based on selection + offset
let visible_rows = rows
    .skip(state.offset())
    .take(table.height())
    .collect();
```

**Key Insight:** Decouple selection state from row rendering; only render visible slice.

**Critical for sugar-table:** Current `View()` renders all `pagedRows()` on every call. Adding viewport tracking would enable constant-time scrolling for large datasets.

---

## Priority Recommendations

### 🔴 High Priority (High Impact, Medium Effort)

#### 1. Add Flex/Grow Column Support
**Pattern from:** bubble-table `NewFlexColumn()`, lipgloss dynamic resizing

**Current:** Columns have fixed `width` + `flexibleWidth` but `computeTotalWidth()` doesn't use flexible widths for dynamic allocation.

**Implementation:**
```php
// Column.php additions
public const int FLEX_RATIO_BASE = 1;

public static function flex(string $key, string $title, int $flexFactor): self
{
    return new self($key, $title, 0, $flexFactor, 0, false, false, '');
}

// Table.php computeTotalWidth() modification
private function computeTotalWidth(int $viewportWidth = 0): int
{
    $fixedTotal = 0;
    $flexSum = 0;
    
    foreach ($this->columns as $col) {
        if ($col->flexibleWidth > 0) {
            $flexSum += $col->flexibleWidth;
        } else {
            $fixedTotal += $col->width;
        }
    }
    
    // If viewportWidth provided and flex columns exist, distribute remaining
    if ($viewportWidth > 0 && $flexSum > 0) {
        $remaining = $viewportWidth - $fixedTotal - (\count($this->columns) - 1);
        foreach ($this->columns as $col) {
            if ($col->flexibleWidth > 0) {
                // Distribute proportionally
                $colWidth = (int)(($col->flexibleWidth / $flexSum) * $remaining);
                // ... track dynamic widths
            }
        }
    }
    // ... fallback to existing logic
}
```

**Effort:** ~2-3 hours

#### 2. Add Viewport Virtualization
**Pattern from:** bubble-table PR #284, vtable

**Current:** `View()` renders all `pagedRows()` every call.

**Implementation:**
```php
// Table.php additions
private int $viewportHeight = 0;  // 0 = render all
private int $viewportOffset = 0;  // First visible row index

public function withViewportHeight(int $height): self { /* ... */ }
public function withViewportOffset(int $offset): self { /* ... */ }

// New method: only render visible rows
public function visibleRows(): array
{
    $rows = $this->pagedRows();
    if ($this->viewportHeight <= 0) {
        return $rows;
    }
    return \array_slice($rows, $this->viewportOffset, $this->viewportHeight);
}

public function scrollViewport(int $delta): self
{
    $clone = clone $this;
    $clone->viewportOffset = \max(0, \min(\count($this->rows) - $this->viewportHeight, $this->viewportOffset + $delta));
    return $clone;
}
```

**Effort:** ~3-4 hours

#### 3. Add StyleFunc for Dynamic Cell Styling
**Pattern from:** lipgloss `StyleFunc(func(row, col int) Style)`

**Current:** Style precedence is fixed: base < column < row < cell

**Implementation:**
```php
// Table.php
/** @var callable(int $rowIndex, int $colIndex, string $colKey, mixed $value): string|null */
private ?callable $styleFunc = null;

public function withStyleFunc(callable $func): self
{
    $clone = clone $this;
    $clone->styleFunc = $func;
    return $clone;
}

// In renderRow():
$style = $this->baseStyle;
if ($this->styleFunc !== null) {
    $dynamicStyle = ($this->styleFunc)($rowIndex, $colIndex, $col->key, $val);
    if ($dynamicStyle !== null) $style = $dynamicStyle;
}
// ... existing precedence continues
```

**Effort:** ~1-2 hours

---

### 🟡 Medium Priority (Medium Impact, Higher Effort)

#### 4. Add Text Wrapping with Row Height Tracking
**Pattern from:** lipgloss resizing.go, ratatui multi-line cells

**Current:** Cells truncated at column width, no wrapping.

**Implementation:**
```php
// Column.php
public readonly bool $wrap;
public readonly int $maxHeight;  // 0 = unlimited

// Table.php - track per-row heights
/** @var array<int, int> rowIndex => height in lines */
private array $rowHeights = [];

// renderRow() returns array of lines instead of single string
private function renderRowLines(Row $row, int $rowIndex, int $totalWidth, bool $isSelected): array
{
    $lines = [];
    foreach ($this->columns as $colIndex => $col) {
        $val = $row->data->get($col->key) ?? $this->missingIndicator;
        $str = $this->valueToString($val);
        $wrapped = $this->wrapText($str, $col->width, $col->maxHeight);
        $lines[] = $this->applyStyle($wrapped, $style);
    }
    return $lines;
}

private function wrapText(string $text, int $width, int $maxHeight = 0): array
{
    if ($width <= 0) return [$text];
    
    $words = \explode(' ', $text);
    $lines = [];
    $currentLine = '';
    
    foreach ($words as $word) {
        $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
        if (\mb_strlen($testLine) <= $width) {
            $currentLine = $testLine;
        } else {
            if ($currentLine !== '') $lines[] = $currentLine;
            $currentLine = $word;
        }
    }
    if ($currentLine !== '') $lines[] = $currentLine;
    
    if ($maxHeight > 0) {
        $lines = \array_slice($lines, 0, $maxHeight);
    }
    
    return $lines ?: [''];
}
```

**Effort:** ~6-8 hours

#### 5. Add Per-Column Filterable Flag
**Pattern from:** bubble-table `.WithFiltered(true)`

**Current:** Global filtering applies to all columns via `filterText` array.

**Implementation:**
```php
// Column.php already has:
// public readonly bool $filterable;

// Filter() method respects filterable flag:
public function Filter(string $colKey, string $text): self
{
    $col = $this->findColumn($colKey);
    if ($col === null || !$col->filterable) {
        return $this;  // No-op for non-filterable columns
    }
    // ... existing filter logic
}

private function findColumn(string $key): ?Column
{
    foreach ($this->columns as $col) {
        if ($col->key === $key) return $col;
    }
    return null;
}
```

**Effort:** ~1 hour

#### 6. Add Border Style Templates
**Pattern from:** lipgloss (rounded, ascii, markdown, hidden)

**Implementation:**
```php
final class Table
{
    public const array BORDER_ROUNDED = [
        'topLeft' => '╭', 'top' => '─', 'topRight' => '╮',
        'bottomLeft' => '╰', 'bottom' => '─', 'bottomRight' => '╯',
        'left' => '│', 'centerV' => '│', 'right' => '│',
        'centerH' => '─', 'cross' => '┼',
    ];
    
    public const array BORDER_ASCII = [
        'topLeft' => '+', 'top' => '-', 'topRight' => '+',
        // ...
    ];
    
    public const array BORDER_NONE = [
        'topLeft' => '', 'top' => '', 'topRight' => '',
        // ...
    ];

    public function withBorderStyle(string $template): self
    {
        $clone = clone $this;
        if (\array_key_exists($template, self::BORDER_PRESETS)) {
            $preset = self::BORDER_PRESETS[$template];
            // Apply preset
        }
        return $clone;
    }
}
```

**Effort:** ~2 hours

---

### 🟢 Lower Priority (Lower Impact, Varying Effort)

#### 7. Percentage-Based Column Widths
**Pattern from:** comfy-table `Width::Percentage()`, terminal-columns `'50%'`

**Effort:** ~3-4 hours

#### 8. Row Span / Column Span Support
**Pattern from:** cli-table3 cell span

**Effort:** ~8+ hours (significant refactor)

#### 9. Async Data Source Interface
**Pattern from:** vtable `DataSource` interface

**Effort:** ~10+ hours (architectural change)

---

## Recommended Implementation Order

1. **StyleFunc** (1-2h) — Lowest risk, immediate utility
2. **Per-column filterable flag** (1h) — Small change, clear benefit
3. **Border presets** (2h) — Quick win, improves aesthetics
4. **Flex/grow columns** (2-3h) — Common request, moderate effort
5. **Viewport virtualization** (3-4h) — Critical for large datasets
6. **Text wrapping + row heights** (6-8h) — Larger effort, significant feature

---

## References

### Go
- **bubble-table:** https://github.com/Evertras/bubble-table
- **lipgloss table:** https://github.com/charmbracelet/lipgloss/tree/main/table
- **bubbles table PR #284 (viewport optimization):** https://github.com/charmbracelet/bubbles/pull/284
- **vtable (virtualized):** https://github.com/davidroman0O/vtable
- **tview:** https://github.com/rivo/tview

### Rust
- **ratatui:** https://github.com/ratatui-org/ratatui
- **comfy-table:** https://github.com/Nicd16/comfy-table

### Python
- **textual DataTable:** https://github.com/textualize/textual
- **rich Table:** https://github.com/textualize/rich

### JavaScript
- **cli-table3:** https://github.com/cli-table/cli-table3
- **terminal-columns:** https://github.com/privatenumber/terminal-columns
- **tty-table:** https://github.com/tecfu/tty-table

---

## Appendix: sugar-table Source Files

| File | Lines | Purpose |
|------|-------|---------|
| `Table.php` | 596 | Main component: state, configuration, rendering |
| `Column.php` | 127 | Column definition: key, title, width, style |
| `Row.php` | 47 | Row wrapper: RowData + optional style |
| `RowData.php` | 61 | Key-value data map with `get()`/`with()` |
| `StyledCell.php` | 42 | Cell with explicit ANSI style override |
