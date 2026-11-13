<img src=".assets/icon.png" alt="sugar-table" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-table)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-table)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-table?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-table)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarTable

sugar-table — a customizable data table component for PHP 8.3+ terminal UIs: programmatic row cursor, keyboard-driven scrolling, and per-cell styling. It does not handle mouse input itself — wire events from sugar-input/sugar-mouse to the cursor + scroll API.

## Features

- **Column definitions**: key (the caller owns uniqueness — nothing in the table enforces it), title, width (fixed or flexible), optional style
- **Row data**: each `Row` carries a key-value map (`RowData`); `withRows()` takes
  `Row` objects only (passing a raw key-map array is a `TypeError`). A value renders
  as text when it is scalar or `__toString`-able (booleans print as `1` or nothing),
  and as an empty string otherwise — arrays and resources included; a key the row
  omits shows the missing-data placeholder (see Missing Data Indicator)
- **Styled cells**: `StyledCell` wraps value + ANSI style, overrides row/column/base styles (never string-cast one directly — see Rows)
- **Row styles**: zebra striping, bold rows, per-row ANSI styling
- **Selection**: single-row cursor via `SelectNext()`/`SelectPrevious()`/`withSelectedIndex()`; row 0 carries the highlight on first render (use `withSelectable(false)` for a cursorless table); `handleKey()` scrolls
- **Pagination**: page size, page navigation, auto footer
- **Sorting**: asc/desc, multi-column sort, numeric + string sort
- **Filtering**: filter by column text
- **Frozen columns**: pin a contiguous prefix of columns from the left (any other index set throws)
- **Horizontal scroll**: max width with overflow, frozen columns stay visible
- **Missing data indicator**: configurable placeholder for absent cells — the default `-` is a lone hyphen that reads ambiguously beside dashes inside your data, so `withMissingIndicator('(empty)')` gives an unmistakable absent-cell signal
- **Border styling**: `withBorder(Border $border)` — consume any `SugarCraft\Style\Border` family (normal/rounded/thick/double/block/ascii/hidden/markdownBorder) + `withBorderStyle(string $ansiStyle)` for ANSI color/styling on default border
- **Borderless mode**: `withBorderless()` — render NO outer box (no top/bottom border rows, no left/right border columns; a single space separates columns) so the table composes inside another bordered shell (e.g. a sugar-panels content box) without a double border. A selected row's reverse-video highlight stays continuous across the column gaps
- **Width control**: `withWidth(int $cols)` pins the total render width and `ColumnWidth::Flex` columns split the leftover room by weight. Lines are clamped at the frame edge, never padded past it: an over-subscribed layout truncates content and can render narrower than `$cols` (borderless + Flex lays out exactly when no column is hidden or scrolled out of the window). Default `cellPadding` is 0 (flush) — chosen for width economy; `withCellPadding(1)` buys readability when columns run together
- **Viewport virtualization**: render only visible rows via `withViewportHeight()` + `withScrollY()`
- **Render cost**: O(visible cells) per frame (flat-grid pass); the Content/Dynamic width solve scans the full row set once per width memo — keep large datasets viewport-scoped
- **Column width modes**: `ColumnWidth` enum — Fixed, Percent, Dynamic, Content, Flex
- **Cell text wrapping**: `WrapMode` enum — None, WordWrap, Character
- **Multi-line row rendering**: `withMultilineMode(bool $multiline)` — when enabled, rows expand to the maximum height of any cell; when disabled (default), cells are clamped to one line (backward compatible)

## Install

```bash
composer require sugarcraft/sugar-table
```

## Quick Start

```php
use SugarCraft\Table\{Column, Row, RowData, Table};

$t = Table::fromColumns([
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
Column::new($key, $title, $width)        // key, display title, fixed width
    ->withFlexibleWidth($share)          // weight when ColumnWidth::Flex splits leftover
    ->withMaxWidth($max)                 // hard width ceiling, applied after flex distribution (content truncates with '…'; 0 = none)
    ->withStyle('1;34')                  // ANSI SGR style
    ->withFilterable()                   // opt into search/Filter scanning (see Global Search)
    ->withAlignLeft()                    // left-align (default is right)
    ->withColumnWidth($mode, $value)     // ColumnWidth::Fixed|Percent|Dynamic|Content|Flex
    ->withWrapMode(WrapMode::None)       // WrapMode::None|WordWrap|Character
```

### ColumnWidth Enum

`ColumnWidth` specifies how a column's width is computed at render time.
The table computes actual widths in `computeColumnWidths($tableWidth)` and uses
them consistently throughout rendering (header, data cells, separators).
A `Fixed` width is expected to be a positive integer: `Column::new()` does not
validate it, so zero lays out an empty column and a negative value fails late
at render time (a `ValueError` from the buffer fill) rather than at definition.

| Case        | Description                                              |
|-------------|----------------------------------------------------------|
| `Fixed`     | Fixed character count (uses `Column.width`)                |
| `Percent`   | Percentage of total table width (uses `Column.percentValue`, 0–100) |
| `Flex`      | Weighted share of the leftover room (weight = `withFlexibleWidth`, default 1) |
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
$t = Table::fromColumns([
    Column::new('id',   'ID',     5)
        ->withColumnWidth(ColumnWidth::Fixed, 0),
    Column::new('name', 'Name',  20)
        ->withColumnWidth(ColumnWidth::Dynamic, 0),   // auto-size to content
    Column::new('note', 'Note',  10)
        ->withColumnWidth(ColumnWidth::Content, 0),  // exact content fit
    Column::new('pct',  'Pct',    0)
        ->withColumnWidth(ColumnWidth::Percent, 25.0), // always 25% of table
])->withRows([...]);

echo $t->View();  // pct takes 25% of the table width, id stays 5, the three border
                  // gaps come off first — name (Dynamic) and note (Content) share
                  // what remains
```

### WrapMode Enum

`WrapMode` controls how cell text is wrapped:

| Case        | Description                                              |
|-------------|----------------------------------------------------------|
| `None`      | Truncate at column width, appending a trailing … (U+2026) |
| `WordWrap`  | Break at word boundaries                                  |
| `Character` | Break at any character, no padding on last line          |

```php
use SugarCraft\Table\{Column, WrapMode};

$col = Column::new('desc', 'Description', 20)
    ->withWrapMode(WrapMode::WordWrap);  // or Character or None
```

## Rows

```php
$row = Row::new($rowData)
    ->withStyle('1')                    // bold entire row
    ->withZebra();                      // opt this row into a stripe (needs the table switch)

// Styled cell (overrides row+column style)
StyledCell::new('value', '31;1')        // red bold cell

// Zebra striping is two-level: the table switch arms it (default off),
// row opt-ins select which rows stripe.
$t = $t->withZebra(true);
```

**Stripe model**: the two stripes are hard-wired — the even slot is
`30;47` (black on white), the odd slot carries no style.
`Row::withZebra(int $styleIndex = 0)` picks the slot: index 0 takes the
stripe, index 1 the bare one (higher indices behave as 0). Rows without an
opt-in fall back to position parity while the table switch is on: even
`rowIndex` stripes, odd does not. With the switch off — the default —
nothing stripes, whatever the rows ask for.

**String-cast warning**: `(string)` on a `StyledCell` (and every implicit
`Stringable` coercion — `echo`, `print`, `sprintf('%s')`, concatenation) emits
its raw value wrapped in the cell's SGR codes with **no sanitization**. Never
cast cells that hold untrusted input; render through `Table::View()`, which
sanitizes every cell's text.

## Navigation & Sorting

```php
$t = $t->SortBy('name', ascending: true);
$t = $t->Filter('name', 'alice');       // filter column by text
$t = $t->SelectNext();                  // move cursor down
$t = $t->SelectPrevious();              // move cursor up
$t->CurrentRow();                   // selected Row or null (not RowData!)
$t->CurrentRowData();                // selected RowData or null
```

The rendered table carries no sort-direction or filter marker in its output, and
no public accessor exposes the active sort/filter/search state — hosts that show
one (sort arrows, filter chips) must track the state they pass in themselves.

`SortBy()` has the full signature `SortBy(string $colKey, bool $ascending = true, bool $primary = true)`:

```php
// Multi-column: city ascending, then age descending within each city.
// Secondary sorts require primary: false.
$t = $t->SortBy('city')
        ->SortBy('age', ascending: false, primary: false);
```

- Repeating `SortBy()` on the sole active sort key with the SAME direction
  silently flips that column's direction (the header-toggle behaviour)
- An unknown column key throws `InvalidArgumentException`
- `ClearSort()` removes all sorting

## Keyboard Navigation

Scroll the table vertically using keyboard input via `scrollYForKey()` and `handleKey()`:

`handleKey()` drives vertical scrolling ONLY. Moving the row cursor
(`SelectNext()`/`SelectPrevious()`), turning pages (`withPage()`/`NextPage()`/
`PreviousPage()`) and horizontal panning (`withScrollX()`) are separate API
calls — no key constant performs them, so the host must map its own keys to them.

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
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Table\Table;

// Create table with viewport virtualization enabled
$t = Table::fromColumns([...])
    ->withRows([...])  // Row objects — see Rows
    ->withViewportHeight(15);

// sugar-input decodes key presses into KeyEvent (sugar-pty carries the raw
// bytes; sugar-mouse is the pointer companion). Its $key names are
// PascalCase ("ArrowDown"), while handleKey()/scrollYForKey() match the
// Table::KEY_* values ('arrowDown') strictly — translate the names:
/** @var KeyEvent $event */
$key = match ($event->key) {
    'ArrowUp'   => Table::KEY_ARROW_UP,
    'ArrowDown' => Table::KEY_ARROW_DOWN,
    'PageUp'    => Table::KEY_PAGE_UP,
    'PageDown'  => Table::KEY_PAGE_DOWN,
    'Home'      => Table::KEY_HOME,
    'End'       => Table::KEY_END,
    default     => null,
};
if ($key !== null) {
    $t = $t->handleKey($key);
}

// Or pass the constants directly
$t = $t->handleKey(Table::KEY_PAGE_DOWN);
```

### How It Works

- **Key mapping**: `scrollYForKey()` uses a `match` expression to map key names to scroll deltas
- **Bounds clamping**: Scroll values are clamped to `0` at the top and `maxScrollY()` at the bottom
- **maxScrollY()**: public; returns `max(0, rows on the current page - viewportHeight)` when a viewport is set, else `0` — page-aware, so End can never strand the viewport
- **No-op for unknown keys**: Unrecognized keys return the current `scrollY` unchanged
- **Requires viewport**: Keyboard scrolling only works when `withViewportHeight()` is set

```php
// Combined: keyboard scroll + cursor selection
$t = $t->withScrollY($t->scrollYForKey($key))  // update scroll
       ->SelectNext();                         // move selection
```

## Global Search

Call `search()` to filter rows across the scanned columns simultaneously: every
column is scanned while none is marked with `withFilterable()`, and only the
marked ones once any column opts in (the gate is detailed under "How It Works"
below):

```php
$t = Table::fromColumns([
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
- **Filterable gate**: once **any** column opts in with `withFilterable()`, `search()` and `Filter()` scan **only** the marked columns; with none marked, every column is scanned
- **Combines with filters**: Global search is ANDed with column filters — a row must match both
- **Resets view state**: `search()` — like `Filter()`, `SortBy()` and `withRows()` —
  re-anchors cursor, page and scroll offset to the start

```php
// Combined: Filter by name column AND search the scanned columns for "ny"
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
| `search()` | Filterable columns (all when none marked) | OR (row matches if ANY scanned column contains text) |

- **Unknown column key**: `Filter()` and `SortBy()` throw `InvalidArgumentException`
- **Unmarked column**: once any column carries `withFilterable()`, `Filter()` on a
  column that lacks the mark is a silent no-op — mark the column first

Both can be active simultaneously:

```php
$t = $t->Filter('city', 'ny')     // city must contain "ny"
        ->search('li');           // some column must contain "li"

// Row 1 (Alice, NYC): city contains "ny", search finds "li" in "Alice" ✅
// Row 2 (Bob, LA):    "LA" has no "ny" — fails Filter ❌
// Row 3 (Carol, CHI): "CHI" has no "ny" — fails Filter ❌
//                          ("li" matches neither "Carol" nor "CHI" anyway)
```

## Missing Data Indicator

A cell whose row carries no entry for the column renders the missing-data
placeholder instead of blank space (an empty string is a value and does NOT
trigger it):

```php
$t = $t->withMissingIndicator('(empty)');   // default is a lone '-'
```

The default `-` is a single hyphen — it reads ambiguously beside real hyphens
inside data (host names, minus signs), so pick a placeholder that cannot collide
with your content.

## Empty states

The degenerate shapes have defined, non-crashing output:

- **No columns to paint** — a table with zero columns, or with every column hidden
  (`withHiddenCols()` covering them all), renders as the empty string.
- **No rows** — columns with no rows render the translatable `No data` line
  (`no_data`, see Internationalization) centered in the content area; it shows
  whether or not the header row is visible, and in borderless and footer-armed
  tables alike. The same message replaces the body when `Filter()` or `search()`
  narrows every row away.
- **Scrolled past the end** — `withScrollX()` at or beyond the number of scrollable
  (non-frozen) columns leaves none visible: with no frozen prefix the whole table
  renders as the empty string; with one, only the frozen columns paint.

Pinned by `tests/TableEmptyStateTest.php`.

## Pagination

```php
$t = $t->withPageSize(25)               // 25 rows per page
    ->withPage(2);                      // 0-based: jumps to the THIRD page
echo $t->PageFooter();                  // 'Page 3 of 4' (i18n-aware)
```

### Footer Types

Control what the footer displays using the `FooterType` enum:

```php
use SugarCraft\Table\{Table, FooterType};

$t = Table::fromColumns([...])
    ->withRows([...])
    ->withPageSize(25)
    ->withFooterType(FooterType::Page);  // Default: "Page 1 of 4"
```

| Case   | Footer Display                          | Method                   |
|--------|----------------------------------------|--------------------------|
| `Page` | `Page N of M`                          | `PageFooter()`           |
| `Rows` | `Showing X to Y of Z rows`             | `RowsFooter()`           |
| `Both` | `Page N of M \|  Showing X to Y of Z rows` | Combines both |

```php
// Show only row count footer
$t = $t->withFooterType(FooterType::Rows);
echo $t->View();  // Footer: "Showing 1 to 25 of 100 rows"

// Show both page and row count
$t = $t->withFooterType(FooterType::Both)
    ->withPage(1);   // page index 1 (0-based)
echo $t->View();  // Footer: "Page 2 of 4  |  Showing 26 to 50 of 100 rows"
```

Regardless of type, the footer row itself renders only when `withShowFooter(true)`
and pagination is active (`withPageSize(n)` with `n > 0`); with pagination off
there is no footer at all.

The row count footer uses the `showing_rows` i18n key and updates automatically based on the current page and any active filters/searches.

### Numeric setters: coercion vs throw

- `withScrollX()`, `withScrollY()`, `withViewportHeight()`, `withWidth()` and
  `withCellPadding()` clamp negatives to `0` (a negative viewport height means
  virtualization off, same as `0`)
- `withPageSize(n)` with `n <= 0` disables pagination entirely: all rows render
  and the footer disappears
- `withPage()` and `SelectPage()` clamp out-of-range pages to the nearest valid
  one and move the cursor onto the page's first row
- `ColumnWidth::Percent` outside `0.0`–`100.0` throws `InvalidArgumentException`

## Viewport Virtualization

Render only a visible slice of rows for large datasets:

```php
$t = Table::fromColumns([...])
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
$t = Table::fromColumns([
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
- **The frozen set must be a contiguous prefix starting at index 0** — `withFrozenCols([1])` or `[0, 2]` throws `InvalidArgumentException` (and frozen indices must stay disjoint from hidden ones); to freeze non-adjacent columns, re-order them first or hide the columns in between
- **Non-frozen columns** scroll horizontally: they become visible starting at index `count(frozenCols) + scrollX`
- Use `withScrollX($offset)` to scroll the non-frozen columns

### Frozen Columns with Horizontal Scroll

Combine frozen columns with `scrollX` for a spreadsheet-like experience:

```php
$t = Table::fromColumns([
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
// Given: frozenCols = [0, 1], scrollX = 1
// Non-frozen columns start at index: 2 + 1 = 3
// Column 0 (frozen):  visible
// Column 1 (frozen):  visible
// Column 2 (index 2): NOT visible (2 < 3)
// Column 3 (index 3): visible (3 >= 3)
// Column 4 (index 4): visible (4 >= 3)
```

## Horizontal Scroll

Scroll horizontally through columns that exceed the table width:

```php
$t = Table::fromColumns([
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
- **Excessive scroll** is tolerated but total: at or beyond the number of scrollable
  columns no scrollable column remains — without a frozen prefix the table renders
  as the empty string, with one only the frozen columns paint (see Empty states)

### Interaction with Frozen Columns

When combining `withScrollX()` with `withFrozenCols()`:

```php
$t = Table::fromColumns([
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

$t = Table::fromColumns([
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
- **Out-of-range and non-numeric indices are silently ignored** — matching is a strict integer comparison against the original column index, so `withHiddenCols([99, '2'])` on a four-column table hides nothing and raises nothing
- **Data, filters, and sorting still work** on hidden columns — you can filter by a hidden column's data
- **Useful for optional columns** that can be toggled visible/invisible via UI
- **Column indices refer to the original column order** — not affected by scroll position

```php
// Hide multiple columns
$t = $t->withHiddenCols([2, 3]);         // hide columns at indices 2 and 3

// Show all columns (empty array)
$t = $t->withHiddenCols([]);             // no columns hidden

// Frozen and hidden must be DISJOINT: withFrozenCols([0])->withHiddenCols([0]) throws
$t = $t->withFrozenCols([0])
        ->withHiddenCols([2]);           // hide a non-frozen column
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
2. **Pass 2**: Distribute the remaining space across the flex slots
3. **Flex**: weighted share (weight = `withFlexibleWidth`); with `withWidth()` set the last Flex
   column absorbs the rounding slack so the sum is exact
4. **Dynamic**: `max(contentWidth, one-share baseline)`
5. **Content**: exact content width (min 1)

## Internationalization

All translatable strings live in `lang/en.php` under the `'table'` namespace.

**Available keys** (`lang/en.php`):

| Key            | Default string                    | Parameters            |
|----------------|-----------------------------------|-----------------------|
| `page_of`      | `Page {page} of {total}`          | `{page}`, `{total}`   |
| `no_data`      | `No data`                         | —                     |
| `showing_rows` | `Showing {from} to {to} of {total} rows` | `{from}`, `{to}`, `{total}` |
| `sort`         | `Sort`                            | —                     |
| `filter`       | `Filter`                          | —                     |

The `sort` and `filter` labels are reserved for future UI chrome — no production
render path uses them today (`lang/en.php` carries the same caveat).

To add a locale, copy `lang/en.php` to `lang/<code>.php` and translate the
values. The lookup chain follows `SugarCraft\Core\I18n\T`:
exact locale → base language → `en` → the raw namespaced key (the
`'table.'`-prefixed string, e.g. `table.page_of` — not the short key passed to
`Lang::t()`).

**Adding new translatable strings:**

```php
// In any source file:
use SugarCraft\Table\Lang;

$label = Lang::t('sort');                  // 'Sort'
$pager = Lang::t('page_of', ['page' => 2, 'total' => 4]); // 'Page 2 of 4'
```

## Security & untrusted content

Every cell value, column title and expanded-detail line passes through
`Sanitize::value()` (`src/Sanitize.php`) on its way to the buffer, so untrusted
data cannot emit escape sequences:

- Control bytes are neutralised — TAB, every other C0 byte and DEL become `·`,
  C1 codepoints are replaced, and invalid UTF-8 is repaired to `U+FFFD` rather
  than silently dropped. Raw ANSI arriving inside a value (a `\x1b[2J` off the
  network) paints as visible junk (`·[2J`) and is never honored. Newlines
  collapse to `↵` in single-line cells; `withMultilineMode()` paths keep them
  as row structure.
- The sugar-mouse zone sentinels (U+E000/U+E001) are stripped, so a forged
  click target cannot ride cell data, titles or the missing-value indicator
  into a host that zones around — or consumes the output of — the table.
- Trojan-Source bidi overrides and zero-width space render as visible
  `<U+XXXX>` markers instead of silently reordering or blanking the line a
  human reads.
- Style channels are parse-restricted: SGR strings given to `withStyle`,
  `withHeaderStyle` or a `StyleFunc` are parsed into a structured attribute set
  (canonicalised to 24-bit at render), never echoed as raw bytes.

This is terminal-injection defence, not HTML escaping — the output is ANSI for
a terminal. One bypass is deliberate: casting a `StyledCell` to string emits
its raw value wrapped in its SGR codes **unsanitized** (see the string-cast
warning under Rows); render untrusted content only through `View()`. Pinned by
`tests/TableSanitizeTest.php`; see also the fleet-wide
[`docs/TRUST-BOUNDARY.md`](https://github.com/detain/sugarcraft/blob/master/docs/TRUST-BOUNDARY.md).

## Accessibility

What the renderer does for you:

- **Selection is pure reverse video** (`ESC[0;7m`) composed over the row's
  winning style — it needs no colour channel, so the cursor row stays readable
  on light and dark palettes alike. A cursorless table is one call away:
  `withSelectable(false)`.
- **Zebra striping is a fixed black-on-white inversion** (`30;47` on even
  stripes, odd stripes plain) rather than a dimmed grey — again readable
  without a colour vocabulary. Enable it with `Table::withZebra(true)`.
- **The empty-state message is translatable** (`no_data`, see
  Internationalization) instead of a silent blank box.

Known limits — there is no colour-blind or a11y mode:

- No `NO_COLOR`, non-TTY or plain-text degrade path exists: SGR sequences are
  always emitted (only the Security section's sanitisation ever removes bytes).
- Box-drawing border glyphs and the default `1;37` header style (slot 7 paints
  as the fixed TrueColor `#E5E5E5`, which can vanish on light themes — see
  Border Styling) add screen-reader noise and contrast risk. The quiet form is
  `withBorder(Border::ascii())` (or `Border::hidden()`) plus
  `withHeaderStyle('')`.
- The output is a text grid: there is no semantic association between a header
  cell and its column beyond visual alignment, so assistive technology reads
  positions, not relationships.

## Border Styling

Customize the table border using the `SugarCraft\Style\Border` family:

```php
use SugarCraft\Table\Table;
use SugarCraft\Style\Border;

$t = Table::fromColumns([...])
    ->withRows([...])
    ->withBorder(Border::rounded());   // ─ │ ╭ ╮ ╰ ╯
    // ->withBorder(Border::thick())  // ━ ┃ ┏ ┓ ┗ ┛
    // ->withBorder(Border::double())  // ═ ║ ╔ ╗ ╚ ╝
    // ->withBorder(Border::block())  // █ all eight position glyphs
    // ->withBorder(Border::ascii())  // - | + + + +
    // ->withBorder(Border::hidden())  // all spaces
    // ->withBorder(Border::markdownBorder())  // | - | ... visual style only

// Or style the default border with ANSI colors:
$t = $t->withBorderStyle('1;32');       // bold green
```

Header cells default to `1;37` (bold + ANSI colour slot 7). Slot colours resolve
through a fixed TrueColor table — slot 7 renders as `#E5E5E5`, which can vanish on
light terminal themes. Palette-adaptive hosts should pass `withHeaderStyle('1')`
(bold only, terminal default foreground) or `withHeaderStyle('')`.

Only corner/vertical/horizontal glyphs are ever drawn — sugar-table consumes
`Border`'s eight position glyphs (top/bottom/left/right + four corners) and
never its junction glyphs (├/┤/┬/┴/┼): the header separator rule is built from
the horizontal glyph with the vertical glyph in the inter-column gaps, and the
inter-column separators are the vertical glyph throughout. Rendered `block()`
frame:

```
████████
█   A█   B█
████████
█  xx█  yy█
████████
```

Available border factories: `Border::normal()`, `rounded()`, `thick()`, `double()`, `block()`, `ascii()`, `hidden()`, `markdownBorder()`.

Note: `Border::markdownBorder()` is a visual style only — rendered tables are NOT
parseable as GitHub-flavoured markdown (the rules are full-width single-cell
separators, not pipe rows). Do not route `View()` output into markdown renderers.

## Cell Padding Control

Add inner spacing inside each cell for better visual breathing room:

```php
use SugarCraft\Table\Table;

$t = Table::fromColumns([
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
// With padding 1 (right-aligned default): "│  1  │             Alice  │          NYC  │"
// Without padding:              "│    1│               Alice│            NYC│"
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

$t = Table::fromColumns([
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
            'city' => "Los\nAngeles",  // embedded newline
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
- **Cell wrapping** respects the column's `WrapMode`: `WordWrap` breaks at word boundaries, `Character` breaks at any character, `None` truncates with a trailing … (U+2026)

When disabled (the default), cells are clamped to one line for backward compatibility.

### Interaction with WrapMode

A literal newline in a value splits the cell into multiple lines even under `WrapMode::None` (each segment is still clipped to the column width). `WrapMode` controls *flowing* of content that exceeds one line: `WordWrap` breaks at spaces, `Character` at cell boundaries. `withMultilineMode(true)` grows rows to the tallest cell; disabled (default) clips to the first line.

```php
// Word wrap example — breaks at word boundaries within 8 characters
Column::new('bio', 'Bio', 8)->withWrapMode(WrapMode::WordWrap);
// "one two three four" → "one two", "three", "four" (3 lines)

// Character wrap example — breaks at any character within 5 characters
Column::new('code', 'Code', 5)->withWrapMode(WrapMode::Character);
// "ABCDEFGHIJ" → "ABCDE", "FGHIJ" (2 lines)
```

## Row Expansion

Expand a row to render it as one full-width detail line spanning the table
(clip marks at the table edge only when even that cannot fit):

```php
use SugarCraft\Table\{Table, Column, Row, RowData};

$t = Table::fromColumns([
    Column::new('id',   'ID',     5),
    Column::new('name', 'Name',  20),
    Column::new('desc', 'Desc',  15),   // truncated at 15 chars (trailing …) normally
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
// Row 0 (Alice): one joined detail line across the table width —
//                not squeezed into the 15-char Desc column
// Row 1 (Bob):   normal per-column truncation applies
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

- **Full-width bypass**: an expanded row ignores the per-column widths — its cell
  texts join into ONE line spanning the table's full width (two-space gap between
  values); content longer than that span is still edge-clipped with a trailing …
  (U+2026). Row-level styling (row style, zebra, selection reverse) applies to the
  whole line; per-cell StyledCell styles do not.
- **Row identity**: Expanded rows are tracked by object identity (`Row` instance),

  not by index. `withRows()` prunes identities that leave the new data set (no stale-
  object retention); note an equal-valued refresh builds new `Row` objects, so re-wrapping
  identical data collapses an active expansion.

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

## Accessors

sugar-table exposes **zero** `getFoo()`-form property accessors: property reads
ride public `readonly` state (`$column->style`, `$column->width`,
`$row->data`, `$cell->value`) or bare methods (`Table::Columns()`,
`Table::CurrentRow()`, `Table::scrollY()`). The only public names carrying
get/has/is vocabulary are ruled lookups, not property accessors:
`RowData::get($key)` and `RowData::has($key)` are keyed map lookup and
key-presence test (the fleet's single-word lookup vocabulary), and
`Table::isExpanded($rowIndex)` is a page-indexed predicate with no bare
accessor to duplicate. Nothing in this lib carries a `@deprecated` accessor.

`tests/BareAccessorEraTest.php` fails closed if a `getFoo()` accessor is ever
shipped here, if a keeper above disappears without a ruling, or if this list
and the roster in that test drift apart. Rename-era history lives in
`CALIBER_LEARNINGS.md`.

## Shared foundations

sugar-table adopts `sugar-cellbuffer` for all buffer-based rendering. Layout fills a flat mutable
`RenderCanvas` grid in a single pass; it is frozen into an immutable `Buffer` exactly once per
`View()` call. `View()` writes only internal render memos (width solve, filter/sort cache, style
parse) — never visible state.

### styleFunc signature

`Table::withStyleFunc()` accepts a callable with the signature:

```php
function(int $row, int $col, string $value): SugarCraft\CellBuffer\Style|string
```

- **Returns `Style`** — new preferred style (PHP 8.3+ typed return, immutable)
- **Returns `string`** — legacy ANSI SGR string. It is NOT echoed verbatim: every
  style string is parsed at render time into a restricted attribute set (bold,
  faint, italic, underline, blink, reverse, strike, overline, the 16 base colour
  codes, and `38;2`/`38;5`/`48;2`/`48;5` colours); unrecognised tokens are dropped
  silently, `38;5`/`48;5` codes expand to 24-bit through the fixed xterm cube
  (levels 0/51/102/153/204/255), and the frame emits canonicalised 24-bit SGR —
  golden byte comparisons must never assume the input codes survive to output.
  SGR strings remain valid input; `Table::normalizeStyleResult()` folds them into
  the internal representation.

Anything else returned (e.g. a `SugarCraft\Style\Style` object) falls back to the inherited
cell style. Prefer `SugarCraft\CellBuffer\Style`.

## Snapshot tests

Render output is covered by golden-file snapshot tests. Fixture files live
in `tests/fixtures/` with a `.golden` extension and are compared against
actual ANSI byte output via `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi()`.
The comparator is **create-only**: an existing fixture is never rewritten.
To re-baseline after intentional output changes, delete the stale
`tests/fixtures/<name>.golden` first, then recreate it with the update flag:

```sh
SUGAR_TESTING_UPDATE_GOLDENS=1 vendor/bin/phpunit
```

(`UPDATE_GOLDENS=1` is the legacy spelling of the same switch; a mismatch
failure message names the file to delete.)

## License

MIT

## Credits & inspiration

Design antecedent: [Evertras/bubble-table](https://github.com/Evertras/bubble-table); SugarCraft is developed as a native PHP project.
