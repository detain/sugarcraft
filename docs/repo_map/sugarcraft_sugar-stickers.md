# sugar-stickers — Innovation & Comparison Report

## Metadata
- **Package**: `sugarcraft/sugar-stickers`
- **Namespace**: `SugarCraft\Stickers`
- **Upstream**: [76creates/stickers](https://github.com/76creates/stickers) (Go, ~500 stars, MIT)
- **Status**: 🟢 v1 ready
- **Primary Mapping**: `sugar-bits` (FlexBox, Table primitives)
- **Additional Mapping**: `sugar-table` (Table component — different design)
- **Canonical Source**: `/home/sites/sugarcraft/sugar-stickers/`

---

## 1. Package Overview

Sugar-stickers provides two foundational TUI components ported from the Go ecosystem:

| Component | File | Lines | Description |
|----------|------|-------|-------------|
| **FlexBox** | `src/Flex/FlexBox.php` | 330 | CSS flexbox-inspired ratio-based layout engine |
| **FlexItem** | `src/Flex/FlexItem.php` | 86 | Atomic layout unit with ratio/basis/style |
| **Table** | `src/Table/Table.php` | 305 | Sortable/filterable data table |
| **Column** | `src/Table/Column.php` | 112 | Column definition with formatter/padding |
| **Viewport** | `src/Viewport.php` | 368 | Scrollable viewport with sticky headers/footers |
| **Scrollbar** | `src/Scrollbar.php` | 65 | Scrollbar compositor |

**Architecture**: sugar-stickers uses **SSOT composition** — Viewport and Scrollbar wrap `SugarCraft\Bits` counterparts rather than reimplementing scroll logic. This keeps sticker-level customization open without duplication.

---

## 2. FlexBox Layout Engine

### 2.1 Core Design

FlexBox (`src/Flex/FlexBox.php`) implements a CSS flexbox-inspired layout algorithm for terminal character cells:

**Direction Enum** (`src/Flex/FlexBox.php:10-13`):
```php
enum Direction {
    case Row;     // horizontal: items flow left-to-right
    case Column;   // vertical: items flow top-to-bottom
}
```

**Justify Enum** (`src/Flex/FlexBox.php:16-22`) — main-axis distribution:
```php
enum Justify {
    case Start;       // pack at start (CSS: flex-start)
    case Center;      // pack at center
    case End;        // pack at end (CSS: flex-end)
    case SpaceBetween; // equal space between items
    case SpaceAround;  // equal space around items
}
```

**Align Enum** (`src/Flex/FlexBox.php:25-30`) — cross-axis alignment:
```php
enum Align {
    case Start;    // align at start
    case Center;  // align at center
    case End;     // align at end
    case Stretch; // stretch to fill (CSS: stretch)
}
```

### 2.2 Ratio-Based Sizing Algorithm

The heart of FlexBox is the **ratio-based responsive layout** algorithm at `src/Flex/FlexBox.php:149-233`:

```
INPUT:  list<FlexItem> items, int totalWidth, int gap
OUTPUT: rendered string (lines)

1. MEASURE each item:
   - measureContent() → [content, width, height, ratio, basis]

2. CALCULATE totals:
   - totalRatio  = Σ(item.ratio)    for items with ratio > 0
   - totalBasis = Σ(item.basis)    for items with basis > 0

3. COMPUTE free space:
   freeSpace = totalWidth - totalBasis - (gap × (itemCount - 1))

4. FIRST PASS — ratio allocation (lines 169-179):
   for each item:
     if basis > 0:
       allocated = basis           // locked, never resized
     else if ratio > 0 && freeSpace > 0:
       allocated = floor(freeSpace × ratio / totalRatio)
     else:
       allocated = 1               // minimum of 1 cell

5. SECOND PASS — excess distribution (lines 181-191):
   excess = totalWidth - Σ(allocated) - (gap × (itemCount - 1))
   if excess > 0 && totalRatio > 0:
     for each item with ratio > 0:
       allocated += floor(excess × ratio / totalRatio)

6. RENDER: for each item, pad/truncate content to allocated width, apply style, join with gap
```

**Key invariant**: Items with explicit `basis > 0` are **dimension-locked** and never resized. Items with `ratio = 0` get minimum of 1 cell.

### 2.3 FlexItem Properties

`src/Flex/FlexItem.php:18-25`:
```php
public readonly string $content;   // raw string content
public readonly int   $ratio;     // relative size share (default: 1)
public readonly int  $basis;     // fixed basis, 0 = fill available (default: 0)
public readonly int  $grow;      // grow factor (unused in current impl)
public readonly int  $shrink;    // shrink factor (unused in current impl)
public readonly string $style;   // ANSI style string
```

**Factory** (`src/Flex/FlexItem.php:46`):
```php
public static function new(string $content = ''): self
```

**Fluent setters** (all return new instance):
- `withRatio(int)` — set ratio
- `withBasis(int)` / `withWidth(int)` — set fixed basis
- `withStyle(string)` — set ANSI style
- `withContent(string)` — update content

### 2.4 Column Layout (renderColumn)

`src/Flex/FlexBox.php:235-292` mirrors `renderRow()` but operates on height instead of width. The algorithm is identical but uses vertical space distribution.

### 2.5 Style Inheritance System

FlexBox uses **ANSI SGR style strings** (not a full style object system). Style inheritance at `src/Flex/FlexBox.php:325-329`:

```php
private function applyStyle(string $s, string $style): string
{
    if ($style === '') return $s;
    return Ansi::CSI . $style . 'm' . $s . Ansi::reset();
}
```

Styles are applied per-item via `$flexItem->style`. There is **no explicit style-passing inheritance chain** like lipgloss's `Style.Inherit()`. Each FlexItem carries its own ANSI string.

---

## 3. Table Component

### 3.1 Architecture

**Data structure** (`src/Table/Table.php:24-38`):
```php
private array $columns = [];        // list<Column>
private array $allRows = [];         // list<list<string>> — SSOT
private array $rows = [];            // list<list<string>> — filtered/sorted view
private int   $sortColIndex = -1;   // -1 = unsorted
private bool  $sortAscending = true;
private string $filterText = '';
private int   $cursorRow = 0;        // cursor position in $rows
```

**Row storage**: Plain `list<list<string>>` — no Row objects, no key-value maps. Access by position: `$row[$colIndex]`.

### 3.2 Sorting Algorithm

**Not bubble sort** — uses `usort()` with numeric detection at `src/Table/Table.php:236-250`:

```php
\usort($rows, function (array $a, array $b) use ($colIdx, $asc): int {
    $va = $a[$colIdx] ?? '';
    $vb = $b[$colIdx] ?? '';

    $na = \is_numeric($va) ? (float) $va : null;
    $nb = \is_numeric($vb) ? (float) $vb : null;

    if ($na !== null && $nb !== null) {
        $cmp = $na <=> $nb;              // numeric comparison
    } else {
        $cmp = \strcasecmp((string) $va, (string) $vb);  // case-insensitive string
    }

    return $asc ? $cmp : -$cmp;          // ascending or descending
});
```

**Important correction**: The [upstream report](./76creates_stickers.md) states the Go original uses bubble sort. The PHP port correctly uses `usort` (PHP's built-in sorting which typically uses quicksort/mergesort), making it more efficient for large datasets.

**Single-column only**: Unlike sugar-table's multi-column `sortColumns` array, sugar-stickers Table sorts on only one column at a time.

### 3.3 Filtering

Global case-insensitive filter at `src/Table/Table.php:261-276`:

```php
private function applyFilter(array $rows): array
{
    if ($this->filterText === '') return $rows;
    $lower = \strtolower($this->filterText);
    return \array_values(
        \array_filter($rows, function (array $row) use ($lower): bool {
            foreach ($row as $cell) {
                if (\str_contains(\strtolower($cell), $lower)) {
                    return true;   // OR logic: match any cell
                }
            }
            return false;
        })
    );
}
```

**Key difference from sugar-table**:
- sugar-stickers: **OR logic** across all cells, **single global filter text**
- sugar-table: **AND logic** across explicitly keyed columns, **per-column filter map**

### 3.4 Column Definition

`src/Table/Column.php:18-44`:
```php
public readonly string $title;
public readonly int   $width;
public string        $align = 'left';
public string        $ansiStyle = '';
private mixed        $formatter = null;  // callable
private int          $sortDir = 0;       // +1 asc, -1 desc, 0 none
private int          $sortPriority = 0;
```

**Factory** (`src/Table/Column.php:60`):
```php
public static function make(string $title, int $width): self
```

**Cell formatting** at `src/Table/Column.php:96-113`:
```php
public function format(mixed $value, int $rowIndex): string
{
    $str = $this->formatter !== null
        ? ($this->formatter)($value, $rowIndex) ?? ''
        : (string) ($value ?? '');
    return $this->padded($str, $rowIndex);
}
```

---

## 4. Viewport & Scrollbar (SSOT Composition)

### 4.1 Viewport

`src/Viewport.php` wraps `SugarCraft\Bits\Viewport\Viewport` with sticky header/footer support:

```php
final readonly class Viewport
{
    private BitsViewport $inner;
    private int $stickyHeader = 0;       // sticky header row count
    private int $stickyFooter = 0;          // sticky footer row count
    private ?Viewport $syncedViewport = null;  // scroll-sync partner
}
```

**Sticky header/footer** (`src/Viewport.php:139-187`): The scrollable middle region is windowed by `(scrollOffset + stickyHeader)` so sticky regions always appear at their respective edges regardless of scroll position.

### 4.2 Scrollbar

`src/Scrollbar.php` wraps `SugarCraft\Bits\Scrollbar\Scrollbar`:

```php
final readonly class Scrollbar
{
    private BitsScrollbar $inner;
}
```

Factories: `vertical()` (with ▲▼ arrows), `horizontal()`.

---

## 5. Comparison: sugar-stickers vs sugar-table

| Feature | sugar-stickers | sugar-table | Assessment |
|---------|---------------|-------------|------------|
| **Row storage** | `list<list<string>>` plain arrays | `list<Row>` with `RowData` key-value map | Different — positional vs keyed |
| **Column lookup** | By index `$row[$ci]` | By column key `$row->data->get($key)` | Different paradigms |
| **Sort** | Single-column `usort` | Multi-column stable sort | sugar-table more capable |
| **Sort indicator** | `▲`/`▼` in header | Via `SortState` + external rendering | Equivalent |
| **Filter logic** | OR across all cells | AND per-column (multiple filters) | sugar-table more flexible |
| **Filter scope** | Global single text | Per-column filter map | sugar-table more flexible |
| **Pagination** | None | Full (`pageSize`, `page`, `PageFooter()`) | sugar-table more capable |
| **Viewport** | None | `withViewportHeight()` + `withScrollY()` | sugar-table more capable |
| **Border** | Simple `│` separator string | Full 13-rune `Border` object (8 styles) | sugar-table more capable |
| **Frozen columns** | None | `withFrozenCols([0,1])` | sugar-table more capable |
| **Column width modes** | Fixed only | `ColumnWidth` enum (Fixed/Percent/Dynamic/Content) | sugar-table more capable |
| **Wrap modes** | None | `WrapMode` enum (None/WordWrap/Character) | sugar-table more capable |
| **Multi-line rows** | None | `withMultilineMode()` | sugar-table more capable |
| **Zebra striping** | Via per-row callback | `withZebra()` built-in | sugar-table more capable |
| **Cursor** | Row index in filtered view | Selected index with `SelectNext/Previous` | Similar |
| **Style system** | Raw ANSI SGR strings | Structured `baseStyle` + layered precedence | sugar-table more ergonomic |
| **FlexBox layout** | Built-in ratio-based | None (relies on external layout) | sugar-stickers unique |

### Key Insight

**sugar-stickers** and **sugar-table** are complementary, not competing:

- **sugar-stickers** provides a **layout engine** (FlexBox) + a **simple table** as a self-contained widget
- **sugar-table** provides a **full-featured data table** with pagination, viewport virtualization, multi-column sort/filter, and structured styling

The [upstream report](./76creates_stickers.md) noted that the Go original's `table/` is "built on top of FlexBox, inherits ratio-based column sizing". The PHP port does not implement ratio-based column sizing in the Table component — columns use fixed widths only.

---

## 6. Comparison: FlexBox Layout Implementations

### 6.1 Against Upstream (76creates/stickers)

| Aspect | Go Original | PHP Port |
|--------|------------|----------|
| **Layout algorithm** | `calculateRatio()` + `distributeRemainder()` in `flexbox/utils.go` | `renderRow()` / `renderColumn()` direct port |
| **Ratio allocation** | Two-pass: floor division + remainder round-robin | Two-pass: floor division + excess distribution |
| **Minimum constraints** | `calculateRatioWithMinimum()` recursive algorithm | Items with `ratio=0` get minimum of 1 cell |
| **Style inheritance** | `StylePassing(bool)` propagates lipgloss styles down FlexBox→Row→Cell | Per-item ANSI string only (no inheritance chain) |
| **ContentGenerator** | `func(maxX, maxY int) string` per cell | Not ported (FlexItem content is static string) |
| **Dimension locking** | `LockRowHeight()` / `LockColumnWidth()` | `wrap` boolean only (no explicit lock API) |
| **Style passing** | lipgloss `Style.Inherit()` chain | Raw ANSI strings |
| **Table sort** | Bubble sort (`sortIndex` in `ordering.go`) | `usort` (more efficient) |
| **HorizontalFlexBox** | Separate `HorizontalFlexBox` type | Not implemented (only `FlexBox::row()` / `FlexBox::column()`) |

### 6.2 Against ratatui Layout (Constraint-Based)

| Aspect | sugar-stickers FlexBox | ratatui Layout |
|--------|----------------------|----------------|
| **Type** | Ratio-based distribution | Cassowary constraint solver |
| **API** | `ratio`, `basis`, `gap`, `justify`, `align` | `Constraint::Length()`, `Percentage()`, `Ratio()`, `Fill()` |
| **Flexibility** | Simpler, less flexible | More powerful for complex layouts |
| **Performance** | O(n) per render | O(n²) constraint propagation |
| **Nested layouts** | Via `FlexBox` items containing `FlexBox` | Via nested `Layout::vertical()/horizontal()` |

ratatui's Cassowary solver handles **conflicting constraints** (e.g., item A wants 50% width, item B wants fixed 20 chars, total is 80 chars). sugar-stickers resolves this through the two-pass ratio algorithm which is simpler but less powerful.

### 6.3 Against textualize CSS Flexbox

| Aspect | sugar-stickers FlexBox | textualize CSS Flexbox |
|--------|----------------------|------------------------|
| **Layout definition** | PHP API (`withDirection()`, `withJustify()`, etc.) | TCSS stylesheet (`display: flex; flex-direction: row;`) |
| **Style system** | Raw ANSI strings | Full CSS property system |
| **Layout algorithm** | Two-pass ratio distribution | CSS flexbox algorithm (computed) |
| **Nesting** | Explicit PHP nesting of FlexBox objects | DOM tree + CSS selectors |
| **Responsiveness** | Via `ratio` + `basis` sizing | Via `flex-grow`, `flex-shrink`, `flex-basis` |

textualize's CSS flexbox is a full CSS box model with margin/padding/border, computed widths, and cascade. sugar-stickers is a simpler ratio distributor.

### 6.4 Against Other Layout Libraries

| Library | Layout Model | Key Difference from sugar-stickers |
|---------|-------------|-----------------------------------|
| **rasjonell/dashbrew** | Recursive flexbox (Go) | Dashboard-focused with bounding-box navigation |
| **tealeaves/bubbletea-tilelayout** | Constraint-based tiling | Uses constraint solver, not ratios |
| **treilik/bubbleboxer** | Composite tree (Boxer) | Wraps tea.Model instances, not pure content |
| **charmbracelet/lipgloss** | Layout utilities (Place, Join) | No layout algorithm — just positioning helpers |
| **sugar-dash StackedGrid** | Column-based dashboard | Dashboard-specific, not general flexbox |
| **sugar-boxer** | Tree-based H/V distribution | Uses weight ratios, similar to FlexBox but different algorithm |

---

## 7. Notable Patterns

### 7.1 SSOT Composition (CALIBER_LEARNINGS.md)

Viewport and Scrollbar are **SSOT composition wrappers** around `SugarCraft\Bits` types:

```
SugarCraft\Bits\Viewport\Viewport     (inner/canonical)
SugarCraft\Bits\Scrollbar\Scrollbar    (inner/canonical)
         ↑ wrap
SugarCraft\Stickers\Viewport          (outer/customizable)
SugarCraft\Stickers\Scrollbar        (outer/customizable)
```

This avoids duplicating scroll/viewport logic while allowing sticker-level customization.

### 7.2 Immutable + Fluent Pattern

All mutators clone and return new instances:
```php
public function withGap(int $cells): self
{
    $clone = clone $this;
    $clone->gap = $cells;
    return $clone;
}
```

Properties like `$direction`, `$justify`, `$align` are **mutable** (not `readonly`) — the clone-then-mutate pattern applies to the object as a whole, not each property.

### 7.3 Render Pipeline

FlexBox and Table both use a **measure → compute → render** pipeline:
1. Measure content dimensions (without rendering)
2. Compute layout (ratios, gaps, offsets)
3. Render with styles applied

---

## 8. Innovation Assessment

### 8.1 What sugar-stickers Does Well

1. **Faithful ratio-based layout**: The two-pass allocation (floor division + excess distribution) correctly handles integer rounding without floating-point errors
2. **Lightweight**: No external dependencies beyond `sugarcraft/candy-core` and `sugarcraft/sugar-bits`
3. **SSOT composition**: Viewport/Scrollbar wrapping is a clean pattern for customization without duplication
4. **Immutable API**: Fluent `with*()` setters return new instances

### 8.2 Gaps and Opportunities

1. **No ContentGenerator**: Upstream Go `Cell.SetContentGenerator(func(maxX, maxY int) string)` not ported — would enable per-cell adaptive text wrapping
2. **No HorizontalFlexBox**: Upstream's `HorizontalFlexBox` (column-first layout) not implemented
3. **No style inheritance chain**: lipgloss's `Style.Inherit()` chain not ported — styles are raw ANSI strings only
4. **No dimension locking API**: Upstream's `LockRowHeight()` / `LockColumnWidth()` not implemented
5. **Table limitations**: Single-column sort, OR-logic global filter, no pagination, no viewport virtualization
6. **No gap in Table**: Table uses a simple separator string, not a gap-based layout like FlexBox
7. **Missing upstream features**: No frozen columns, no column width modes, no wrap modes (unlike sugar-table which has these)

---

## 9. File Index

```
sugar-stickers/
├── src/
│   ├── Flex/
│   │   ├── FlexBox.php       — 330 lines: CSS flexbox layout engine
│   │   └── FlexItem.php      — 86 lines: atomic layout unit
│   ├── Table/
│   │   ├── Table.php         — 305 lines: sortable/filterable table
│   │   └── Column.php         — 112 lines: column definition
│   ├── Viewport.php          — 368 lines: scrollable viewport wrapper
│   ├── Scrollbar.php         — 65 lines: scrollbar wrapper
│   └── Lang.php              — 22 lines: i18n facade
├── tests/
│   ├── StickersTest.php      — 339 lines: FlexBox + Table tests
│   ├── StickyViewportTest.php — 260 lines: sticky header/footer
│   ├── SyncViewportTest.php   — 156 lines: viewport scroll-sync
│   └── LangCoverageTest.php — 56 lines: i18n coverage
├── examples/
│   ├── flexbox.php           — row/column with ratios
│   ├── table.php             — sort + filter demo
│   └── sort-filter.php       — cursor navigation demo
├── CALIBER_LEARNINGS.md      — SSOT composition pattern
└── README.md                 — quickstart + feature list
```

---

## 10. Related Reports

- [`./76creates_stickers.md`](./76creates_stickers.md) — upstream Go library
- [`./Evertras_bubble-table.md`](./Evertras_bubble-table.md) — alternative table library
- [`./sugar-table/`](./sugar-table.md) — sugar-table full-featured table
- [`./ratatui_ratatui.md`](./ratatui_ratatui.md) — constraint-based layout reference
- [`./textualize_textual.md`](./textualize_textual.md) — CSS flexbox reference
- [`./charmbracelet_lipgloss.md`](./charmbracelet_lipgloss.md) — styling system reference
- [`./sugar-boxer.md`](./sugar-boxer.md) — tree-based layout renderer
- [`./sugar-dash.md`](./sugar-dash.md) — dashboard layout (includes FlexBox)
