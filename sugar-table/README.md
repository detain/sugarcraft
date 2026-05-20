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
- **Border styling**: customizable border chars + ANSI color
- **Viewport virtualization**: render only visible rows via `withViewportHeight()` + `withScrollY()`
- **Column width modes**: `ColumnWidth` enum — Fixed, Percent, Dynamic, Content
- **Cell text wrapping**: `WrapMode` enum — None, WordWrap, Character

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

`ColumnWidth` specifies how a column's width is computed:

| Case        | Description                                              |
|-------------|----------------------------------------------------------|
| `Fixed`     | Fixed character count (uses `Column.width`)                |
| `Percent`   | Percentage of total table width (uses `Column.percentValue`, 0–100) |
| `Dynamic`   | Min-width from content, max from table                     |
| `Content`   | Exactly fit content, compress if needed                    |

```php
use SugarCraft\Table\ColumnWidth;

// Fixed 10-character column
col = Column::new('id', 'ID', 10)
    ->withColumnWidth(ColumnWidth::Fixed, 0);

// 25% of table width
col = Column::new('name', 'Name', 20)
    ->withColumnWidth(ColumnWidth::Percent, 25.0);

// Dynamic (content or flex share, whichever is larger)
col = Column::new('city', 'City', 15)
    ->withColumnWidth(ColumnWidth::Dynamic, 0);

// Content-based (exact fit)
col = Column::new('email', 'Email', 30)
    ->withColumnWidth(ColumnWidth::Content, 0);
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

## License

[MIT](LICENSE)
