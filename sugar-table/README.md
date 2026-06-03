<img src=".assets/icon.png" alt="sugar-table" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-table)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-table)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcore/sugar-table?label=packagist)](https://packagist.org/packages/sugarcore/sugar-table)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarTable

PHP port of [Evertras/bubble-table](https://github.com/Evertras/bubble-table) — customizable interactive table component for terminal UIs.

## Features

- **Column definitions**: unique key, title, width (fixed or flexible), optional style
- **Row data**: key-value map (`RowData`), arbitrary values rendered via `fmt.Sprintf("%v")`
- **Styled cells**: `StyledCell` wraps value + ANSI style, overrides row/column/base styles
- **Row styles**: zebra striping, bold rows, per-row ANSI styling
- **Selection**: single-row cursor, up/down navigation
- **Pagination**: page size, page navigation, auto footer
- **Sorting**: asc/desc, multi-column sort, numeric + string sort
- **Filtering**: filter by column text
- **Frozen columns**: pin columns from the left
- **Horizontal scroll**: max width with overflow, frozen columns stay visible
- **Missing data indicator**: configurable placeholder for absent cells
- **Border styling**: `withBorder(Border $border)` — consume any `SugarCraft\Sprinkles\Border` family (normal/rounded/thick/double/block/ascii/hidden/markdownBorder) + `withBorderStyle(string $ansiStyle)` for ANSI color/styling on default border
- **Viewport virtualization**: render only visible rows via `withViewportHeight()` + `withScrollY()`
- **Column width modes**: `ColumnWidth` enum — Fixed, Percent, Dynamic, Content
- **Cell text wrapping**: `WrapMode` enum — None, WordWrap, Character
- **Multi-line row rendering**: `withMultilineMode(bool $multiline)` — when enabled, rows expand to the maximum height of any cell; when disabled (default), cells are clamped to one line (backward compatible)

## Install

```bash
composer require sugarcraft/sugar-table
```

## Quick Start

```php
use SugarCraft\Table\{Column, Row, RowData, Table};

$t = Table::withColumns([
    Column::new('id',   'ID',     5),
    Column::new('name', 'Name',  20),
    Column::new('city', 'City',  15),
])->withRows([
    Row::new(RowData::from(['id' => '1', 'name' => 'Alice',   'city' => 'NYC'])),
    Row::new(RowData::from(['id' => '2', 'name' => 'Bob',     'city' => 'LA'])),
    Row::new(RowData::from(['id' => '3', 'name' => 'Carol',   'city' => 'CHI'])),
]);

echo $t->View();
```

## Columns

```php
Column::new($key, $title, $width)       // key, display title, fixed width
    ->withFlexibleWidth($flex)           // flexible width share
    ->withMaxWidth($max)                // horizontal scroll cap
    ->withStyle('1;34')                  // ANSI SGR style
    ->withFilterable()                   // enable built-in filter
    ->withAlignLeft()                    // left-align (default is right)
    ->withColumnWidth($mode, $value)    // ColumnWidth::Fixed|Percent|Dynamic|Content
    ->withWrapMode(WrapMode::None)       // WrapMode::None|WordWrap|Character
```

### ColumnWidth Enum

`ColumnWidth` specifies how a column's width is computed at render time.
The table computes actual widths in `computeColumnWidths($tableWidth)` and uses
them consistently throughout rendering (header, data cells, separators).

| Case        | Description                                              |
|-------------|----------------------------------------------------------|
| `Fixed`     | Fixed character count (uses `Column.width`)                |
| `Percent`   | Percentage of total table width (uses `Column.percentValue`, 0–100) |
| `Dynamic`   | Min-width from content, flex share of remaining space     |
| `Content`   | Exactly fit content (min 1 char)                           |

```php
use SugarCraft\Table\ColumnWidth;

// Fixed 10-character column
$col = Column::new('id', 'ID', 10)
    ->withColumnWidth(ColumnWidth::Fixed, 0);

// 25% of table width
$col = Column::new('name', 'Name', 20)
    ->withColumnWidth(ColumnWidth::Percent, 25.0);

// Dynamic (content width or flex share, whichever is larger)
$col = Column::new('city', 'City', 15)
    ->withColumnWidth(ColumnWidth::Dynamic, 0);

// Content-based (exact fit to widest cell)
$col = Column::new('email', 'Email', 30)
    ->withColumnWidth(ColumnWidth::Content, 0);
```

**Dynamic + Content example** — auto-size two columns while a third takes 25%:

```php
$t = Table::withColumns([
    Column::new('id',   'ID',     5)
        ->withColumnWidth(ColumnWidth::Fixed, 0),
    Column::new('name', 'Name',  20)
        ->withColumnWidth(ColumnWidth::Dynamic, 0),   // auto-size to content
    Column::new('note', 'Note',  10)
        ->withColumnWidth(ColumnWidth::Content, 0),  // exact content fit
    Column::new('pct',  'Pct',    0)
        ->withColumnWidth(ColumnWidth::Percent, 25.0), // always 25% of table
])->withRows([...]);

echo $t->View();  // columns 1+2 sized by content; column 3 fills remaining 75%
```

### WrapMode Enum

`WrapMode` controls how cell text is wrapped:

| Case        | Description                                              |
|-------------|----------------------------------------------------------|
| `None`      | Truncate at column width                                  |
| `WordWrap`  | Break at word boundaries                                  |
| `Character` | Break at any character, no padding on last line          |

```php
use SugarCraft\Table\{Column, WrapMode};

$col = Column::new('desc', 'Description', 20)
    ->withWrapMode(WrapMode::WordWrap);  // or Character or None
```

## Rows

```php
Row::new($rowData)
    ->withStyle('1')                    // bold entire row
    ->withZebra()                       // alternating style

// Styled cell (overrides row+column style)
StyledCell::new('value', '31;1')        // red bold cell
```

## Navigation & Sorting

```php
$t = $t->SortBy('name', ascending: true);
$t = $t->Filter('name', 'alice');       // filter column by text
$t = $t->SelectNext();                  // move cursor down
$t = $t->SelectPrevious();              // move cursor up
$t = $t->CurrentRow();                  // get selected RowData
```

## Keyboard Navigation

Scroll the table vertically using keyboard input via `scrollYForKey()` and `handleKey()`:

```php
use SugarCraft\Table\Table;

// Key constants for navigation
$t = $t->handleKey(Table::KEY_ARROW_UP);    // scroll up one row
$t = $t->handleKey(Table::KEY_ARROW_DOWN);  // scroll down one row
$t = $t->handleKey(Table::KEY_PAGE_UP);     // scroll up one viewport
$t = $t->handleKey(Table::KEY_PAGE_DOWN);   // scroll down one viewport
$t = $t->handleKey(Table::KEY_HOME);        // scroll to top
$t = $t->handleKey(Table::KEY_END);         // scroll to bottom
```

### Key Constants

| Constant          | Description                      |
|-------------------|----------------------------------|
| `KEY_ARROW_UP`    | Scroll up by 1 row                |
| `KEY_ARROW_DOWN`  | Scroll down by 1 row              |
| `KEY_PAGE_UP`     | Scroll up by one viewport height |
| `KEY_PAGE_DOWN`   | Scroll down by one viewport height |
| `KEY_HOME`        | Scroll to first row              |
| `KEY_END`         | Scroll to last row               |

### scrollYForKey() — Raw Scroll Calculation

Returns the new `scrollY` value for a key without modifying the table:

```php
$newScrollY = $t->scrollYForKey(Table::KEY_ARROW_UP);
$t = $t->withScrollY($newScrollY);
```

This is useful when you need the raw integer value for your own integration logic.

### handleKey() — Convenience Wrapper

Returns a new Table with `scrollY` already adjusted:

```php
$t = $t->handleKey($keyFromInputHandler);
```

This combines `scrollYForKey()` + `withScrollY()` in one call.

### Integration Example

```php
use SugarCraft\Table\Table;

// Create table with viewport virtualization enabled
$t = Table::withColumns([...])
    ->withRows([...])
    ->withViewportHeight(15);

// Simulate keyboard input
$key = 'arrowDown';  // from your input library (e.g., candy-pty)
$t = $t->handleKey($key);

// Or use the constants
$t = $t->handleKey(Table::KEY_PAGE_DOWN);
```

### How It Works

- **Key mapping**: `scrollYForKey()` uses a `match` expression to map key names to scroll deltas
- **Bounds clamping**: Scroll values are clamped to `0` at the top and `maxScrollY()` at the bottom
- **maxScrollY()**: Returns `max(0, totalFilteredRows - viewportHeight)` when viewport is active; `0` otherwise
- **No-op for unknown keys**: Unrecognized keys return the current `scrollY` unchanged
- **Requires viewport**: Keyboard scrolling only works when `withViewportHeight()` is set

```php
// Combined: keyboard scroll + cursor selection
$t = $t->withScrollY($t->scrollYForKey($key))  // update scroll
       ->SelectNext();                         // move selection
```

## Global Search

Search across all columns simultaneously with `search()`:

```php
$t = Table::withColumns([
    Column::new('id',   'ID',     5),
    Column::new('name', 'Name',  20),
    Column::new('city', 'City',  15),
])->withRows([
    Row::new(RowData::from(['id' => '1', 'name' => 'Alice',   'city' => 'NYC'])),
    Row::new(RowData::from(['id' => '2', 'name' => 'Bob',     'city' => 'LA'])),
    Row::new(RowData::from(['id' => '3', 'name' => 'Carol',   'city' => 'CHI'])),
]);

$t = $t->search('alice');    // finds row with "Alice" (case-insensitive)
$t = $t->search('ny');       // finds row with "NYC"
$t = $t->search('');         // clears search (shows all rows)
$t = $t->ClearSearch();     // alias for search('')
```

### How It Works

- **Case-insensitive**: `search('ALICE')` matches `"alice"`, `"Alice"`, `"ALICE"`
- **OR logic**: A row matches if **any** column contains the search text
- **Combines with filters**: Global search is ANDed with column filters — a row must match both
- **Resets selection**: `search()` automatically resets `selectedIndex` to 0

```php
// Combined: Filter by name column AND search all columns for "ny"
$t = $t->Filter('name', 'alice')  // name must contain "alice"
        ->search('ny');           // some column must contain "ny"

// Row 1 (Alice, NYC): passes Filter, passes search ✅
// Row 2 (Bob, LA):    fails Filter ❌
// Row 3 (Carol, CHI): fails both ❌
```

### Interaction with Filter()

| Method     | Scope        | Logic   |
|------------|--------------|---------|
| `Filter()` | Single column | AND (row must match ALL column filters) |
| `search()` | All columns  | OR (row matches if ANY column contains text) |

Both can be active simultaneously:

```php
$t = $t->Filter('city', 'ny')     // city must contain "ny"
        ->search('li');           // some column must contain "li"

// Row 1 (Alice, NYC): city matches "ny", search matches "li" in "Alice" ✅
// Row 2 (Bob, LA):    city matches "la"? No ❌
// Row 3 (Carol, CHI): city fails, search matches "li" in "Carol" — fails Filter ❌
```

## Pagination

```php
$t = $t->withPageSize(25)               // 25 rows per page
    ->withPage(2);                      // show page 2
echo $t->PageFooter();                  // 'Page 2 of 4' (i18n-aware)
```

### Footer Types

Control what the footer displays using the `FooterType` enum:

```php
use SugarCraft\Table\{Table, FooterType};

$t = Table::withColumns([...])
    ->withRows([...])
    ->withPageSize(25)
    ->withFooterType(FooterType::Page);  // Default: "Page 2 of 4"
```

| Case   | Footer Display                          | Method                   |
|--------|----------------------------------------|--------------------------|
| `Page` | `Page N of M`                          | `PageFooter()`           |
| `Rows` | `Showing X to Y of Z rows`             | `RowsFooter()`           |
| `Both` | `Page N of M  |  Showing X to Y of Z rows` | Combines both |

```php
// Show only row count footer
$t = $t->withFooterType(FooterType::Rows);
echo $t->View();  // Footer: "Showing 1 to 25 of 100 rows"

// Show both page and row count
$t = $t->withFooterType(FooterType::Both);
echo $t->View();  // Footer: "Page 2 of 4  |  Showing 26 to 50 of 100 rows"
```

The row count footer uses the `showing_rows` i18n key and updates automatically based on the current page and any active filters/searches.

## Viewport Virtualization

Render only a visible slice of rows for large datasets:

```php
$t = Table::withColumns([...])
    ->withRows($bigDataset)
    ->withViewportHeight(15)           // show 15 rows at a time
    ->withScrollY(30);                 // start at row 30

echo $t->View();                        // renders rows 30-44

$currentScroll = $t->scrollY();        // get current scroll offset
```

The table automatically slices the visible row range from the filtered+sorted view.
`scrollY()` returns the current vertical scroll offset.

## Frozen Columns

Pin columns from the left so they remain visible when scrolling horizontally:

```php
$t = Table::withColumns([
    Column::new('id',   'ID',     5),
    Column::new('name', 'Name',  20),
    Column::new('city', 'City',  15),
    Column::new('note', 'Note',  40),
])->withRows([...])
  ->withFrozenCols([0, 1]);           // freeze ID and Name columns

echo $t->View();                       // ID and Name always visible
```

### How It Works

- **Frozen columns** (specified by index) are always rendered, regardless of scroll position
- **Non-frozen columns** scroll horizontally: they become visible starting at index `count(frozenCols) + scrollX`
- Use `withScrollX($offset)` to scroll the non-frozen columns

### Frozen Columns with Horizontal Scroll

Combine frozen columns with `scrollX` for a spreadsheet-like experience:

```php
$t = Table::withColumns([
    Column::new('id',   'ID',     5),
    Column::new('name', 'Name',  20),
    Column::new('city', 'City',  15),
    Column::new('note', 'Note',  40),
    Column::new('tags', 'Tags',  20),
])->withRows([...])
  ->withFrozenCols([0, 1])            // freeze ID and Name
  ->withScrollX(2);                   // skip 2 non-frozen columns (City, Note)

echo $t->View();
// Visible columns: ID, Name, Tags
// City and Note columns are hidden (scrolled out of view)
```

### Visibility Logic

A column is visible when:

1. Its index is in the `frozenCols` array (always visible), OR
2. Its index >= `count(frozenCols) + scrollX` (in the scrollable region)

```php
// Given: frozenCols = [0, 2], scrollX = 1
// Non-frozen columns start at index: 2 + 1 = 3
// Column 0 (frozen):  visible
// Column 1 (index 1): NOT visible (1 < 3)
// Column 2 (frozen):  visible
// Column 3 (index 3): visible (3 >= 3)
// Column 4 (index 4): visible (4 >= 3)
```

## Horizontal Scroll

Scroll horizontally through columns that exceed the table width:

```php
$t = Table::withColumns([
    Column::new('id',   'ID',     5),
    Column::new('name', 'Name',  20),
    Column::new('city', 'City',  15),
    Column::new('note', 'Note',  40),
])->withRows([...])
  ->withScrollX(2);                 // skip first 2 non-frozen columns

echo $t->View();
// Columns 0 and 1 are hidden from view
```

### How It Works

- **scrollX** skips `$offset` non-frozen columns from the left of the scrollable region
- **Frozen columns** (if any) are always visible regardless of scrollX
- **Negative values** are clamped to 0 automatically
- **Excessive scroll** values are tolerated — extra columns simply don't render

### Interaction with Frozen Columns

When combining `withScrollX()` with `withFrozenCols()`:

```php
$t = Table::withColumns([
    Column::new('id',   'ID',     5),
    Column::new('name', 'Name',  20),
    Column::new('city', 'City',  15),
    Column::new('note', 'Note',  40),
    Column::new('tags', 'Tags',  20),
])->withRows([...])
  ->withFrozenCols([0])             // freeze ID column
  ->withScrollX(2);                 // skip 2 non-frozen columns

echo $t->View();
// Column 0 (ID, frozen):  visible
// Column 1 (Name):        skipped (index 1 < 1 + 2 = 3)
// Column 2 (City):        skipped (index 2 < 3)
// Column 3 (Note):        visible (index 3 >= 3)
// Column 4 (Tags):        visible (index 4 >= 3)
```

The visibility formula: a column is visible when its index is in `frozenCols` OR `index >= count(frozenCols) + scrollX`.

## Column Visibility Toggle

Hide columns by index without removing them from the table:

```php
use SugarCraft\Table\Table;

$t = Table::withColumns([
    Column::new('id',   'ID',     5),
    Column::new('name', 'Name',  20),
    Column::new('city', 'City',  15),
    Column::new('note', 'Note',  40),   // this column can be hidden
])->withRows([...])
  ->withHiddenCols([3]);                 // hide the Note column (index 3)

echo $t->View();
// Only ID, Name, City columns are rendered; Note column is hidden
```

### How It Works

- **Hidden columns are excluded from rendering** but still exist in the table
- **Data, filters, and sorting still work** on hidden columns — you can filter by a hidden column's data
- **Useful for optional columns** that can be toggled visible/invisible via UI
- **Column indices refer to the original column order** — not affected by scroll position

```php
// Hide multiple columns
$t = $t->withHiddenCols([2, 3]);         // hide columns at indices 2 and 3

// Show all columns (empty array)
$t = $t->withHiddenCols([]);             // no columns hidden

// Combine with frozen columns - hide a frozen column
$t = $t->withFrozenCols([0])
        ->withHiddenCols([0]);           // freeze and hide are independent
```

### Interaction with Frozen Columns and Scroll

Hidden columns are **never rendered**, regardless of frozen status or scroll position:

```php
// Given: frozenCols = [0], hiddenCols = [2], scrollX = 0
// Column 0 (frozen):   visible
// Column 1 (index 1):   visible (>= 1 + 0 = 1)
// Column 2 (index 2):   NEVER visible (in hiddenCols)
// Column 3 (index 3):   visible (>= 1 + 0 = 1)
```

## Column Width Computation

Compute actual column widths from `ColumnWidth` enum values:

```php
$widths = $t->computeColumnWidths(80);
// Returns: [5, 20, 15, 40]  (colIndex => character width)

foreach ($t->Columns() as $i => $col) {
    printf("%s => %d chars\n", $col->title, $widths[$i]);
}
```

Uses multi-pass computation:
1. **Pass 1**: Collect Fixed/Percent widths, count Dynamic/Content columns
2. **Pass 2**: Distribute remaining space among Dynamic/Content columns
3. **Dynamic**: `max(contentWidth, flexShare)`
4. **Content**: exact content width (min 1)
All translatable strings live in `lang/en.php` under the `'table'` namespace.

**Available keys** (`lang/en.php`):

| Key            | Default string                    | Parameters            |
|----------------|-----------------------------------|-----------------------|
| `page_of`      | `Page {page} of {total}`          | `{page}`, `{total}`   |
| `no_data`      | `No data`                         | —                     |
| `showing_rows` | `Showing {from} to {to} of {total} rows` | `{from}`, `{to}`, `{total}` |
| `sort`         | `Sort`                            | —                     |
| `filter`       | `Filter`                          | —                     |

To add a locale, copy `lang/en.php` to `lang/<code>.php` and translate the
values. The lookup chain follows `SugarCraft\Core\I18n\T`:
exact locale → base language → `en` → raw key.

**Adding new translatable strings:**

```php
// In any source file:
use SugarCraft\Table\Lang;

$label = Lang::t('sort');                  // 'Sort'
$pager = Lang::t('page_of', ['page' => 2, 'total' => 4]); // 'Page 2 of 4'
```

## Border Styling

Customize the table border using the `SugarCraft\Sprinkles\Border` family:

```php
use SugarCraft\Table\Table;
use SugarCraft\Sprinkles\Border;

$t = Table::withColumns([...])
    ->withRows([...])
    ->withBorder(Border::rounded());   // ─ │ ╭ ╮ ╰ ╯
    // ->withBorder(Border::thick())  // ━ ┃ ┏ ┓ ┗ ┛
    // ->withBorder(Border::double())  // ═ ║ ╔ ╗ ╚ ╝
    // ->withBorder(Border::ascii())  // - | + + + +
    // ->withBorder(Border::hidden())  // all spaces
    // ->withBorder(Border::markdownBorder())  // | - | ...

// Or style the default border with ANSI colors:
$t = $t->withBorderStyle('1;32');       // bold green
```

Available border factories: `Border::normal()`, `rounded()`, `thick()`, `double()`, `block()`, `ascii()`, `hidden()`, `markdownBorder()`.

## Cell Padding Control

Add inner spacing inside each cell for better visual breathing room:

```php
use SugarCraft\Table\Table;

$t = Table::withColumns([
    Column::new('id',   'ID',     5),
    Column::new('name', 'Name',  20),
    Column::new('city', 'City',  15),
])->withRows([
    Row::new(RowData::from(['id' => '1', 'name' => 'Alice',   'city' => 'NYC'])),
    Row::new(RowData::from(['id' => '2', 'name' => 'Bob',     'city' => 'LA'])),
    Row::new(RowData::from(['id' => '3', 'name' => 'Carol',   'city' => 'CHI'])),
])->withCellPadding(1);                // 1 space on each side
    // ->withCellPadding(2);            // 2 spaces for more breathing room
    // ->withCellPadding(0);            // no padding (flush with borders)

echo $t->View();
// With padding 1: "│  1  │  Alice              │  NYC   │"
// Without padding:  "│ 1 │ Alice │ NYC │"
```

### How It Works

- **Padding** adds whitespace on the left and right sides of each cell's content
- **Does not affect column width calculations** — the column width remains the same; padding is subtracted from the effective content width
- **Applied to header and data cells** — both headers and row data benefit from consistent inner padding
- **Combined with other features** — works with frozen columns, horizontal scroll, multiline mode, and row expansion

```php
// Combine padding with other features
$t = $t->withCellPadding(2)
        ->withFrozenCols([0])
        ->withMultilineMode(true);
```

## Multi-line Rows

Enable multi-line row rendering to display tall cell content that wraps within its column width:

```php
use SugarCraft\Table\{Table, Column, Row, RowData, WrapMode};

$t = Table::withColumns([
    Column::new('id',   'ID',     5),
    Column::new('name', 'Name',  20)->withWrapMode(WrapMode::WordWrap),
    Column::new('city', 'City',  15)->withWrapMode(WrapMode::Character),
])
    ->withRows([
        Row::new(RowData::from([
            'id'   => '1',
            'name' => 'Alice Johnson',
            'city' => 'New York City',
        ])),
        Row::new(RowData::from([
            'id'   => '2',
            'name' => 'Bob Smith with a very long name',
            'city' => 'Los\nAngeles',  // embedded newline
        ])),
    ])
    ->withMultilineMode(true);          // rows expand to max cell height
    // ->withMultilineMode(false);     // default, clamps to single line

echo $t->View();
```

### How It Works

When `withMultilineMode(true)` is enabled:
- **Row height** equals the maximum number of lines across all visible cells after text wrapping
- **Short cells** are vertically padded with empty space to match row height
- **Borders** span the full row height on each line
- **Cell wrapping** respects the column's `WrapMode`: `WordWrap` breaks at word boundaries, `Character` breaks at any character, `None` truncates

When disabled (the default), cells are clamped to one line for backward compatibility.

### Interaction with WrapMode

Multiline mode requires `WrapMode::WordWrap` or `WrapMode::Character` on columns to produce multiple lines. Without wrapping enabled, cells remain single-line even in multiline mode.

```php
// Word wrap example — breaks at word boundaries within 8 characters
Column::new('bio', 'Bio', 8)->withWrapMode(WrapMode::WordWrap);
// "one two three four" → "one two", "three", "four" (3 lines)

// Character wrap example — breaks at any character within 5 characters
Column::new('code', 'Code', 5)->withWrapMode(WrapMode::Character);
// "ABCDEFGHIJ" → "ABCDE", "FGHIJ" (2 lines)
```

## Row Expansion

Expand rows to display full content without column width truncation:

```php
use SugarCraft\Table\{Table, Column, Row, RowData};

$t = Table::withColumns([
    Column::new('id',   'ID',     5),
    Column::new('name', 'Name',  20),
    Column::new('desc', 'Desc',  15),   // truncated at 15 chars normally
])
    ->withRows([
        Row::new(RowData::from([
            'id'   => '1',
            'name' => 'Alice',
            'desc' => 'This is a very long description that would normally be truncated',
        ])),
        Row::new(RowData::from([
            'id'   => '2',
            'name' => 'Bob',
            'desc' => 'Short',
        ])),
    ])
    ->withExpandedRows([0]);              // expand row 0 (Alice)

echo $t->View();
// Row 0 (Alice): full description visible — not truncated to 15 chars
// Row 1 (Bob):   normal truncation applies
```

### Toggle Expansion

Use `toggleExpanded()` to interactively expand/collapse rows:

```php
$t = $t->toggleExpanded(0);   // expand row 0 (Alice)
$t = $t->toggleExpanded(0);   // collapse row 0 (back to truncated)
$t = $t->toggleExpanded(1);   // expand row 1 (Bob)
```

### Check Expansion State

Use `isExpanded()` to query whether a row is currently expanded:

```php
$t = $t->withExpandedRows([0]);
$t->isExpanded(0);   // true — row 0 is expanded
$t->isExpanded(1);   // false — row 1 is not expanded
```

### How It Works

- **Row identity**: Expanded rows are tracked by object identity (`Row` instance),
  not by index — this is stable across page navigation
- **Pagination**: All expansion methods (`withExpandedRows`, `toggleExpanded`,
  `isExpanded`) use page-relative indices via `pagedRows()`
- **Multiline mode**: In `multilineMode=true`, expanded rows also bypass column
  width constraints so all wrapped content is visible
- **No content filtering**: Expansion does not filter or transform row data;
  it only controls rendering behavior (truncation vs full display)
- **Fail fast**: Invalid indices throw `OutOfBoundsException`

```php
// Combined with pagination — page 1
$t = $t->withPageSize(10)->withPage(1)->withExpandedRows([0]);

// Row index 0 refers to the first row on page 1, not the first row overall
$t->isExpanded(0);   // true — row 0 on page 1 is expanded

// Row index 5 on page 2 might be a different Row object
$t = $t->withPage(2);
$t->isExpanded(5);   // depends on whether that row was expanded on page 2
```

## Shared foundations

sugar-table adopts `candy-buffer` for all buffer-based rendering. The table's internal
`Buffer` instance is constructed once per `View()` call and passed through all
layout methods.

### styleFunc signature

`Table::withStyleFunc()` accepts a callable with the signature:

```php
function(int $row, int $col, string $value): Style|string
```

- **Returns `Style`** — new preferred style (PHP 8.3+ typed return, immutable)
- **Returns `string`** — legacy ANSI SGR string, automatically wrapped via
  `Style::fromAnsiString()` for backward compatibility

Implementations should return `Style` when possible; the wrapper path is identical
to the old behavior but adds one allocation.

## Snapshot tests

Render output is covered by golden-file snapshot tests. Fixture files live
in `tests/fixtures/` with a `.golden` extension and are compared against
actual ANSI byte output via `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi()`.
To re-record fixtures after intentional output changes:

```sh
UPDATE_GOLDENS=1 vendor/bin/phpunit
```

## License

[MIT](LICENSE)
