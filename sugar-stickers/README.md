<img src=".assets/icon.png" alt="sugar-stickers" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-stickers)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-stickers)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcore/sugar-stickers?label=packagist)](https://packagist.org/packages/sugarcore/sugar-stickers)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarStickers

PHP port of [76creates/stickers](https://github.com/76creates/stickers) — Lipgloss utility building blocks. Provides FlexBox layout and Table components for terminal UIs.

## Features

### FlexBox
- **CSS flexbox-like** responsive grid layout for terminal UIs
- **Direction**: row (horizontal) or column (vertical)
- **Justify** content: start/center/end/space-between/space-around
- **Align** items: start/center/end/stretch
- **Gap** between items
- **Ratio-based sizing** — items with grow ratios fill available space

### Table
- **Sortable columns** — click to sort by any column, ascending/descending
- **Filterable** — filter rows by cell content
- **Configurable columns** — title, width, alignment, formatter
- **Cell styling** — per-column ANSI style support
- **Cursor tracking** — get current row/cell on selection

### Viewport
- **Scrollable viewport** — composes `sugar-bits` `Viewport` (SSOT)
- **Keyboard navigation** — line-up/down, page-up/down, goto-top/bottom
- **Mouse wheel** support with configurable delta
- **Horizontal scrolling** with step-based navigation
- **Smooth scroll** and scrollbar toggle
- Sticky header/footer positioning is deferred to step 10.12.

### Scrollbar
- **Scrollbar** — composes `sugar-bits` `Scrollbar` (SSOT)
- **Vertical and horizontal** scrollbars with configurable thumb/track characters
- **Arrow toggling** for scrollbar ends

## Install

```bash
composer require sugarcraft/sugar-stickers
```

## FlexBox Quick Start

```php
use SugarCraft\Stickers\Flex\{FlexBox, FlexItem};

$box = FlexBox::row(
    FlexItem::new('Panel A')->withRatio(1),
    FlexItem::new('Panel B')->withRatio(2),
    FlexItem::new('Panel C')->withRatio(1),
)->withGap(1);

echo $box->render(80, 24);
```

## Table Quick Start

```php
use SugarCraft\Stickers\Table\{Table, Column};

$table = new Table();
$table->addColumn(Column::make('Name', 20));
$table->addColumn(Column::make('Age', 5)->withAlign('right'));
$table->addColumn(Column::make('City', 15));

$table->addRow(['Alice', 30, 'NYC']);
$table->addRow(['Bob',   25, 'LA']);
$table->addRow(['Carol', 35, 'Chicago']);

$table->sortBy(0);  // sort by Name column
$table->filter('a'); // filter rows

echo $table->render();
```

## Viewport Quick Start

```php
use SugarCraft\Stickers\Viewport;
use SugarCraft\Stickers\Scrollbar;

$viewport = Viewport::withContent(str_repeat("Line\n", 50), 80, 24);
$viewport = $viewport->withScrollbar(true);

// Use as a model in your BubbleTea app
$model = $viewport;
```

## Buffer diffing

The Table renderer maintains a `?Buffer $previousFrame` across renders. On each render it
builds the current Buffer, computes `current->diff(previous)` (from
[candy-buffer](https://github.com/detain/sugarcraft-candy-buffer)), and emits only
the delta ANSI ops via `DiffEncoder::encode($ops)`. The current frame then replaces
`previousFrame` for the next render.

**SSH bandwidth + flicker win:** a one-character change in an 80×24 viewport
produces ~8 bytes of delta ops instead of ~1 940 bytes for a full repaint.
Over an SSH session this means far less per-frame data on the wire and
eliminates the full-screen flicker of rewrite-based terminals. The first render
after startup or a resize still emits a full Buffer (no diff possible), so
behaviour is always correct.

## License

[MIT](LICENSE)
