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
    ->withFlexibleWidth($flex)          // flexible width share
    ->withMaxWidth($max)                // horizontal scroll cap
    ->withStyle('1;34')                 // ANSI SGR style
    ->withFilterable()                  // enable built-in filter
    ->withAlignLeft()                   // left-align (default is right)
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
$t = $t->WithPageSize(25)               // 25 rows per page
    ->WithPage(2);                      // show page 2
echo $t->PageFooter();                  // 'Page 2 of 4'
```

## License

[MIT](LICENSE)
