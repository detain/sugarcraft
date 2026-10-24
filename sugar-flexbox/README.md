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

sugar-flexbox — layout building blocks for terminal UIs, for PHP 8.3+. Provides FlexBox layout and Table components.

## Features

### FlexBox
- **CSS flexbox-like** responsive grid layout for terminal UIs
- **Direction**: row (horizontal) or column (vertical)
- **Align** items: start/center/end/stretch
- **Gap** between items
- **Ratio-based sizing** — items with grow ratios fill available space

### Table
- **Sortable columns** — sortBy()/sortByNext() toggle ascending/descending (mouse wiring belongs to the app)
- **Filterable** — filter rows by cell content
- **Configurable columns** — title, width, alignment, formatter
- **Cell styling** — per-column ANSI style support
- **Cursor tracking** — get current row/cell on selection

### Viewport
- **Scrollable viewport** — composes `sugar-widgets` `Viewport` (SSOT)
- **Keyboard navigation** — line-up/down, page-up/down, goto-top/bottom
- **Mouse wheel** support with configurable delta
- **Horizontal scrolling** with step-based navigation
- **Smooth scroll** and scrollbar toggle

### Scrollbar
- **Scrollbar** — composes `sugar-widgets` `Scrollbar` (SSOT)
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

## Table Quick Start

Table and Column are immutable: every builder returns a NEW instance, so the
result must be assigned (or chained) — calling a method for effect discards it.

```php
use SugarCraft\Flexbox\Table\{Table, Column};

$table = (new Table())
    ->addColumn(Column::make('Name', 20))
    ->addColumn(Column::make('Age', 5)->withAlign('right'))
    ->addColumn(Column::make('City', 15))
    ->addRow(['Alice', '30', 'NYC'])
    ->addRow(['Bob', '25', 'LA'])
    ->addRow(['Carol', '35', 'Chicago']);

$sorted = $table->sortBy(0);    // sort by Name column → new Table
$filtered = $sorted->filter('a'); // rows containing 'a' → new Table

echo $sorted->render();
```

## Viewport Quick Start

```php
use SugarCraft\Flexbox\Viewport;

$viewport = Viewport::withContent(str_repeat("Line\n", 50), 80, 24);
$viewport = $viewport->withScrollbar(true);

// Viewport is a plain immutable component, not a Core\Model: drive it from
// your own Model's update() and render the view it returns.
$viewport = $viewport->lineDown(3);
echo $viewport->view();
```

## Buffer diffing

The Table renderer maintains a `?Buffer $previousFrame` across renders. On each render it
builds the current Buffer, computes `current->diff(previous)` (from
[sugar-cellbuffer](https://github.com/detain/sugarcraft-sugar-cellbuffer)), and emits only
the delta ANSI ops via `DiffEncoder::encode($ops)`. The current frame then replaces
`previousFrame` for the next render.

**SSH bandwidth + flicker win:** a one-character change emits only the ops for
the affected cells instead of a full repaint — for an 80×24 screen (~1.9 KB of
styled cells) that is orders of magnitude less per-frame data on the wire, and
it avoids the flicker of rewrite-based terminals. The first render
after startup or a resize still emits a full Buffer (no diff possible), so
behaviour is always correct.

## Accessor deprecation (2026-10-24)

Per the house bare-accessor convention (`AGENTS.md`: *"Bare accessors (no* `get`*"), ruling 3b)*, `Viewport`'s dimension accessors were renamed to their bare twins. The bare names were unclaimed in-lib (the mutators are the `setWidth()`/`setHeight()` spellings, so there is no fluent collision). The legacy names remain as byte-identical one-line BC forwarders, each marked `@deprecated` with `REMOVED-IN: 2.0.0` in its docblock — they will be removed in sugar-flexbox v2.0.0. The forwards still answer through the wrapped canonical widgets viewport's own names; that hop belongs to the wrapped class, not this lib's era.

| Namespace | Class | Deprecated | Use instead |
|-----------|-------|------------|-------------|
| `Flexbox` | `Viewport` | `getWidth()` | `width()` |
| `Flexbox` | `Viewport` | `getHeight()` | `height()` |

`tests/AccessorDeprecationTest.php` pins the era from four directions: live-object BC identity per pair, forwarder shape (single return line + markers), a discovery-first `src/` census, and this table — adding another deprecation without all rows goes red.

## License

MIT

## Credits & inspiration

Design antecedent: [76creates/stickers](https://github.com/76creates/stickers); SugarCraft is developed as a native PHP project.
