<img src=".assets/icon.png" alt="sugar-flexbox" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-flexbox)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-flexbox)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-flexbox?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-flexbox)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarFlexbox

> Formerly published as `sugarcraft/sugar-stickers` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Stickers\` → `SugarCraft\Flexbox\`. <!-- @rename-keep -->

sugar-flexbox — layout building blocks for terminal UIs, for PHP 8.3+. Provides four components: `FlexBox` (flexbox-like row/column layout), `Table` (sortable, filterable, styled grids with a cursor), `Viewport` (scrollable window with sticky regions), and `Scrollbar` (decorative scroll indicators).

The library carries the house i18n plumbing (`src/Lang.php` plus locale files under `lang/`) but nothing in the shipped interface calls it yet: every label and message is English-only today, and the wiring stands ready for the day a string needs translating.

## Features

### FlexBox
- **CSS flexbox-like** responsive grid layout for terminal UIs
- **Direction**: row (horizontal) or column (vertical)
- **Align** items with the `Align` enum: `Align::Start` / `Align::Center` /
  `Align::End` / `Align::Stretch`, set via `withAlign()`
- **Gap** between items
- **Ratio-based sizing** — items with grow ratios fill available space

### Table
- **Sortable columns** — `withSort($column, $ascending = true)` sets an order, `sortByNext($column)` flips it (mouse wiring belongs to the app); when BOTH cells are numeric they compare as numbers — 2 sorts before 100, and a column mixing '9' and text sorts as text; string comparison is Unicode case-folded, so 'a' precedes 'B' and (with ext-intl loaded) 'Ärger' sorts with the A's. Columns authored with `Column::sorted($dir, $priority)` are priority-ordered tiebreakers under the clicked primary (1 = first, 0 = last, each leg with its own direction) — a click never authors or erases them — and their arrows show beside the active one. The sort arrow is prepended before the header is truncated, so a sorted column narrower than arrow plus text shows the arrow alone
- **Filterable** — keeps rows where any cell contains a Unicode case-folded substring ('ärger' finds 'Ärger', 'strasse' finds 'Straße'; ext-intl adds full accent folding — without it, letters still case-fold but accented words keep their code-point neighbourhood); matching runs on the stored raw strings, not the render-time sanitized text
- **Configurable columns** — title, width (0 = hidden: the column contributes no cell, joint, or ruler cluster), alignment, formatter (`Column::new()`, `withAlign()`, `withStyle()`, `withFormatter()`); the render pipeline runs formatter → sanitize → truncate/pad, so a formatter's output is sanitized like any other cell value and cannot smuggle color to the screen — styling belongs to `withStyle()`; a formatter answers string or `null` (null keeps the raw value) — anything else refuses at format time naming the column
- **Cell styling** — per-column ANSI style support
- **Empty states** — a filter that eats every row leaves a bare frame: `withEmptyText('no match')` paints one spanning row across the table width in that state only. The NO-DATA case (nothing was ever added) stays bare on purpose — apps own that canvas, and the pair `totalRowCount()` (rows supplied) vs `rowCount()` (settled view) plus `filterText()` is the API for painting custom copy per state
- **Themeable borders** — `withSeparator()` themes the column joint; `withRulerChars($fill, $joint)` themes the ruler's two runes under the same ingress (sanitized, exactly one cell each or the door refuses by name), so an ASCII theme renders a fully ASCII frame. Defaults ('─' / '┬') are byte-identical to every frame ever shipped
- **Cursor tracking** — get current row/cell on selection. Note the cursor
  style is empty by default, so `withCursor()` alone marks nothing on screen —
  call `withCursorStyle()` to paint the cursor row. The style wraps the whole
  row, separator joints included, so the focused row renders solid; pair it
  with `withCursorMarker('> ')` for focus a colour-less host can still see —
  the marker rides inside the first column's allocated width (the first
  VISIBLE one when width-0 columns hide parts of the frame), so frame
  dimensions never move.
- **Delta rendering** — the stateful `TableRenderer` emits only the cells that changed between frames (see Buffer diffing below)

### Viewport
- **Scrollable viewport** — composes the canonical `sugar-forms` `Viewport`
  (the `sugar-widgets` symbol is a deprecated `class_alias` façade onto it)
- **Keyboard navigation** — line-up/down, page-up/down, half-page-up/down (`halfPageUp()`/`halfPageDown()`), goto-top/bottom
- **Sticky regions** — `withStickyHeader($rows)` / `withStickyFooter($rows)` pin lines across scrolling; a row can never appear
  in both regions (the footer is clamped when the document is shorter than header + footer)
- **Sync partner** — `syncWith($other)` is a decorative association: it marks the viewport as synced and is readable via
  `isSynced()`, but propagates nothing (scroll state never crosses to the partner)
- **Mouse wheel** support with configurable delta
- **Horizontal scrolling** with step-based navigation
- **Smooth scroll** and scrollbar toggle (`showScrollbar()`; the old boolean
  `withScrollbar()` spelling is a deprecated forwarder); attaching a component
  with `withVerticalScrollbar()` arms the bar and renders the SAME thumb/track
  in the plain and sticky paths; wide scrollbar runes reserve their true cell width

### Scrollbar
- **Scrollbar** — composes the canonical `sugar-forms` `Scrollbar` (same alias chain)
- **Vertical and horizontal** scrollbars with configurable thumb/track characters
- **Arrow toggling** for scrollbar ends

## Install

```bash
composer require sugarcraft/sugar-flexbox
```

## FlexBox Quick Start

All dimensions (widths, heights, gaps, bases, ratios across FlexBox, Table and
Viewport) are **terminal cells** — columns × rows — not bytes or pixels.

```php
use SugarCraft\Flexbox\Flex\{FlexBox, FlexItem};

$box = FlexBox::row(
    FlexItem::new('Panel A')->withRatio(1),
    FlexItem::new('Panel B')->withRatio(2),
    FlexItem::new('Panel C')->withRatio(1),
)->withGap(1);

echo $box->render(80, 24);
```

Build the item set in one go: `row()`/`column()`/`withItems()` take the whole list and
copy it ONCE, while `addItem()` appends immutably — O(n) list copies per call, O(n²) to
build n items. Measured: chained `addItem()` costs 2.6 ms at 1k items and 18.1 ms at 3k;
bulk construction stays flat.

### How sizing works

Every dimension is in **cells**, and allocation follows one deterministic model (no CSS
`justify-content`, `flex-wrap`, or reverse-direction counterparts exist):

1. **Basis first.** Each item's `withBasis(cells)` reserves a fixed main-axis slice
   (row: width, column: height), clamped to the container: ANY positive basis is a
   fixed-size item that never grows — even one that also carries a ratio takes exactly
   its basis and is excluded from the flex pool. `withWidth()` is a legacy alias of
   `withBasis()` — in column mode the reserved axis is the HEIGHT, so prefer `withBasis`.
2. **Gaps next.** `n` items cost `(n - 1) x gap` cells before any ratio math; the gap is
   clamped at render time if the budget cannot pay for it.
3. **Ratios split the rest.** Remaining cells are shared by grow ratios with an exact-sum
   guarantee: each flex item's base share is the rounded-down quotient, and the rounding
   remainder is handed out in item order (80 cells across ratios 1:1:1 yields 27/27/26,
   always the same way). A base share may therefore be 0 when the container cannot pay
   every flex sibling a cell (3 ratio-1 items across 2 cells yield 1/1/0, never 1/1/1);
   the 1-cell floor is the fallback for ratio-0/basis-0 items and for the no-flex-space
   path, not a per-item minimum. Within the ratio-split regime a width never shrinks as
   the container grows; the no-flex-space fallback sits outside that monotonicity — there
   every basis-less item is pinned to the 1-cell floor, so growing into the split can
   hand one a SMALLER share than the fallback gave it (two ratio-1 items beside one
   ratio-0 sibling allocate 1/1/1 across one cell but 1/0/1 across two).
4. **Align** (`start`/`center`/`end`/`stretch`) is ONE value that drives BOTH axes — there
   is no separate cross-axis knob and no CSS `justify-content` counterpart, and rows are
   always placed top-down. In row mode the case selects the box height (`stretch` fills the
   declared height, every other case the tallest item) as well as how each item's text
   sits inside its allocated cell; in column mode it selects the width each item's lines
   render into plus the same horizontal placement. Content beyond the declared height is
   clamped, never distributed; `render(w, h)` output always fits the requested box.

To build a sidebar plus a fill panel: give the sidebar `withBasis(20)` and the panel
`withRatio(1)`. There is no center-a-dialog primitive in this lib — pad with fixed-ratio
spacers (`withRatio(1)` on each side, content `withRatio(n)`) instead.

The alignment cases come from the `Align` enum: `FlexBox::withAlign()` takes the enum,
not a bare string (passing `'center'` is a `TypeError`; the string-taking method is
`Column::withAlign()`).

```php
use SugarCraft\Flexbox\Flex\{Align, FlexBox, FlexItem};

$boxes = FlexBox::row(
    FlexItem::new('ab'),
    FlexItem::new('cd'),
)->withAlign(Align::Center);

echo $boxes->render(20, 1);
```

## Table Quick Start

Table and Column are immutable: every builder returns a NEW instance, so the
result must be assigned (or chained) — calling a method for effect discards it.

```php
use SugarCraft\Flexbox\Table\{Table, Column};

$table = Table::new()
    ->withColumn(Column::new('Name', 20))
    ->withColumn(Column::new('Age', 5)->withAlign('right'))
    ->withColumn(Column::new('City', 15))
    ->withRow(['Alice', '30', 'NYC'])
    ->withRow(['Bob', '25', 'LA'])
    ->withRow(['Carol', '35', 'Chicago']);

$sorted = $table->withSort(0);     // sort by Name column → new Table
$filtered = $sorted->withFilter('ca'); // case-folded substring in ANY cell → keeps the Carol row (its "Chicago" city matches too)

$atCursor = $filtered
    ->withCursorStyle('1;36')    // the default style ('') marks nothing on screen
    ->withCursor(0);             // cursor row of the materialized view

echo $atCursor->render();
echo $atCursor->rawCurrentCell(0), "\n";
```

The cursor is guarded at its door: `withCursor()` throws unless the index
addresses the settled materialized view (a pending sort or filter is resolved
first). Sorting and filtering carry the selection with the row it stood on —
matched by stored cell values, the first identical row wins — and a selection
whose row a filter drops lands back at row 0. `cursor()` answers the raw index
(`null` only while the view holds no rows); `rawCurrentRow()` and
`rawCurrentCell()` answer that row's stored values or `null`.

## Viewport Quick Start

```php
use SugarCraft\Flexbox\Viewport;

$lines = implode("\n", array_map(
    static fn (int $i): string => sprintf('Line %02d', $i),
    range(1, 50),
)) . "\n";

$viewport = Viewport::withContent($lines, 80, 24);
$viewport = $viewport->showScrollbar(true);

// Viewport is a plain immutable component, not a Core\Model: drive it from
// your own Model's update() and render the view it returns.
$viewport = $viewport->lineDown(3)->withStickyHeader(1);
echo $viewport->view();                    // Line 01 stays pinned; the numbered body has scrolled past Line 04
```

## Trust boundary

Table, Column and FlexBox treat everything they render as **untrusted data**.
Cell values and column titles pass through `Sanitize::untrusted()`, and
newlines, tabs and carriage returns are replaced with spaces at render time,
so hostile content can never smuggle a terminal escape sequence into a frame.
Style strings (`FlexItem::withStyle()`, `Column::withStyle()`,
`Table::withHeaderStyle()`, `Table::withCursorStyle()`) pass through one door:
a legal SGR parameter string — codes 0..255 separated by single `;` — or the
empty string/null for no style; anything else throws `InvalidArgumentException`
naming the refused property, so the style knobs cannot be repurposed as an
escape channel.

Viewport and Scrollbar are **trusted-input by design**: the Viewport splits
your `withContent()` string on `\n` and renders the lines as given (the
delegated widget stores content without a sweep), and the Scrollbar emits the
track/thumb runes and labels you passed straight to the terminal. Feed them
program-generated frames, or sanitize user input yourself first.

One asymmetry to know about: the FlexBox **measures** an item's content —
width, height and the ratio split are computed from the raw string — before
the render-time sweep replaces control characters. Control bytes inside
content therefore still consume measured cells even though the printed frame
carries the swept text.

The cursor reads break the sweep the other way: `Table::rawCurrentRow()` and
`Table::rawCurrentCell()` answer with the stored strings **as given** — the
sanitize pass belongs to `render()`, not to the accessors — which is exactly
why the `raw` word sits in their names (they used to be the benign
`currentRow()`/`currentCell()`; those remain as pinned deprecated forwarders
until 2.0.0). A value that came from an untrusted row must be cleaned by you
before it is echoed or logged.

## Buffer diffing (TableRenderer)

`Table::render()` is pure — it always returns a full frame. `TableRenderer` is the stateful
companion that maintains the previous frame as a `SugarCraft\CellBuffer\Buffer` and emits
deltas instead. On each `render()` it builds the current Buffer, computes `current->diff(previous)`
(from [sugar-cellbuffer](https://github.com/detain/sugarcraft/tree/master/sugar-cellbuffer)),
and returns only the changed cells' ANSI ops via `encode($ops)` on a per-render `DiffEncoder`
instance; the current frame
then replaces the previous one. Style changes are visible to the diff (colors are carried per
cell), and a dimension change resets the state so the next frame is complete again.

```php
use SugarCraft\Flexbox\Table\{Column, Table, TableRenderer};

$renderer = TableRenderer::new();

$table = Table::new()
    ->withColumn(Column::new('Status', 8))
    ->withColumn(Column::new('Job', 20))
    ->withRow(['running', 'nightly-backup']);

echo $renderer->render($table);            // full first frame

$changed = Table::new()
    ->withColumn(Column::new('Status', 8))
    ->withColumn(Column::new('Job', 20))
    ->withRow(['done', 'nightly-backup']);

echo $renderer->render($changed);          // only the 'Status' cell is re-painted
```

Call `reset()` to force the next `render()` to emit a full frame (e.g. after the terminal
was cleared externally).

**SSH bandwidth + flicker win:** a one-character change emits only the ops for
the affected cells instead of a full repaint — on an 80×24 screen the
full-frame ceiling is 1,920 cell ops (one per cell), so a delta that touches a
handful of cells sends orders of magnitude less per-frame data on the wire, and
it avoids the flicker of rewrite-based terminals. A full frame is re-emitted on
the first render after startup or an explicit `reset()`; when the table's
dimensions change, the next frame diffs against the previous one on a union
canvas so cells the new size no longer paints arrive as erases (a SHRINK does
not ghost) — callers that clear the terminal out-of-band must still call
`reset()`.

## Accessor deprecation (2026-10-24)

`Viewport`'s dimension accessors follow the house bare-accessor convention:
call `width()` and `height()`; sizing itself has ONE spelling,
`withSize($width, $height)` (`setWidth()`/`setHeight()` were the second
vocabulary and now forward to it, carrying the receiver's other
dimension); and `Table`'s mutation-flavoured builders are
now `withColumn()/withRow()/withSort()/withFilter()/withCursor()` — every one
of them returns a **new** Table; the receiver is unchanged, so muscle-memory
chaining like `$t->addRow($r);` inside a loop dropped rows silently. The
scrollbar toggle's old boolean
`withScrollbar()` reads as a fluent setter and shadows the component-attach
verb `withVerticalScrollbar()` — its honest name is `showScrollbar()`. The
legacy spellings still work — they are one-line backward-compatibility
forwarders that return the same values — but each is marked `@deprecated`
with `REMOVED-IN: 2.0.0` in its docblock and they will be deleted in
sugar-flexbox v2.0.0. Migrating is a name swap with no behavior change:

| Namespace | Class | Deprecated | Use instead |
|-----------|-------|------------|-------------|
| `Flexbox` | `Viewport` | `getWidth()` | `width()` |
| `Flexbox` | `Viewport` | `getHeight()` | `height()` |
| `Flexbox` | `Viewport` | `withScrollbar()` | `showScrollbar()` |
| `Flexbox` | `Viewport` | `setWidth()` | `withSize()` |
| `Flexbox` | `Viewport` | `setHeight()` | `withSize()` |
| `Flexbox\Table` | `Table` | `addColumn()` | `withColumn()` |
| `Flexbox\Table` | `Table` | `addRow()` | `withRow()` |
| `Flexbox\Table` | `Table` | `sortBy()` | `withSort()` |
| `Flexbox\Table` | `Table` | `filter()` | `withFilter()` |
| `Flexbox\Table` | `Table` | `setCursor()` | `withCursor()` |
| `Flexbox\Table` | `Table` | `currentRow()` | `rawCurrentRow()` |
| `Flexbox\Table` | `Table` | `currentCell()` | `rawCurrentCell()` |
| `Flexbox\Table` | `Column` | `make()` | `new()` |

## License

MIT

## Credits & inspiration

Design antecedent: [76creates/stickers](https://github.com/76creates/stickers); SugarCraft is developed as a native PHP project.
The badges and CI above live in the [SugarCraft monorepo](https://github.com/detain/sugarcraft) where this library is developed; the package is published standalone as `sugarcraft/sugar-flexbox`.
