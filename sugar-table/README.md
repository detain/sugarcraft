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

## Pagination

```php
$t = $t->withPageSize(25)               // 25 rows per page
    ->withPage(2);                      // show page 2
echo $t->PageFooter();                  // 'Page 2 of 4' (i18n-aware)
```

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
