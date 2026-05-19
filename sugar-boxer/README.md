<img src=".assets/icon.png" alt="sugar-boxer" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-boxer)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-boxer)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcore/sugar-boxer?label=packagist)](https://packagist.org/packages/sugarcore/sugar-boxer)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarBoxer

PHP port of [treilik/bubbleboxer](https://github.com/treilik/bubbleboxer) — box-drawing layout engine for composing terminal content into H/V panel layouts with borders and padding.

## Features

- **H/V composition** — build arbitrary layouts by nesting `horizontal()` and `vertical()` panels
- **Box-drawing borders** — classic ANSI box characters (╭ ╮ ╰ ╯ │ ─ ├ ┤ ┬ ┴ ┼)
- **No-border mode** — render adjacent panels without separators
- **Per-panel padding** — inner whitespace around content
- **Width/Height hints** — nodes can specify min/max dimensions
- **Dynamic dimension calculation** — boxer computes total viewport from children
- **Leaf content** — any stringable content at leaf nodes
- **Pure renderer** — outputs ANSI box-drawing strings; works with any TUI framework

## Install

```bash
composer require sugarcraft/sugar-boxer
```

## Quick Start

```php
use SugarCraft\Boxer\SugarBoxer;

$boxer = SugarBoxer::new();

$layout = $boxer->vertical(
    $boxer->horizontal(
        $boxer->leaf("Left panel"),
        $boxer->leaf("Right panel"),
    ),
    $boxer->leaf("Bottom bar"),
);

echo $boxer->render($layout, 60, 20);
```

## Layout API

```php
// Leaf node with string content
$boxer->leaf('Hello, World!');

// Horizontal split (side by side)
$boxer->horizontal(Node ...$children): Node

// Vertical split (stacked)
$boxer->vertical(Node ...$children): Node

// Node with explicit dimensions
$node->withMinWidth(20)
     ->withMaxWidth(80)
     ->withMinHeight(5)
     ->withMaxHeight(40)
     ->withPadding(1)           // inner padding
     ->withBorder(true)         // show box border
     ->withSpacing(1);          // gap between children

// No-border (flat) layout
$boxer->noBorder(Node): Node
```

## Styling with candy-sprinkles

sugar-boxer composes canonical styling primitives from
[`candy-sprinkles`](https://github.com/sugarcraft/candy-sprinkles):

```php
use SugarCraft\Sprinkles\{Align, Border, Style, VAlign};

// Set border character set (rounded / sharp / double / ascii / ...)
// Passing null clears the style but preserves border visibility.
$node->withBorderStyle(Border::rounded());  // ╭ ╮ ╰ ╯ │
$node->withBorderStyle(Border::double());    // ╔ ╗ ╚ ╝ ║ ═
$node->withBorderStyle(null);                // clear explicit style

// Apply foreground/background colors and attributes via Style
$node->withStyle(new Style(fg: 'cyan', bg: 'black'));

// Box title text rendered in the top border
$node->withTitle('My Panel');

// Outer spacing (top, right, bottom, left) — sugar-boxer-specific
$node->withMargin(1);                  // all sides
$node->withMargin(1, 2);               // top/bottom=1, left/right=2
$node->withMargin(1, 2, 1, 2);         // explicit all four

// Text alignment within the content area
$node->withAlignH(Align::CENTER);
$node->withAlignH(Align::RIGHT);
$node->withAlignV(VAlign::MIDDLE);
$node->withAlignV(VAlign::BOTTOM);
```

## API Reference

| Method | Description |
|--------|-------------|
| `$boxer->leaf(string)` | Leaf node with string content |
| `$boxer->horizontal(Node ...)` | Horizontal (row) layout |
| `$boxer->vertical(Node ...)` | Vertical (column) layout |
| `$boxer->noBorder(Node)` | Flat layout without separators |
| `$boxer->render(Node, int $width, int $height)` | Render to ANSI string |
| `Node::leaf(string)` | Static leaf constructor |
| `Node::horizontal(Node ...)` | Static horizontal constructor |
| `Node::vertical(Node ...)` | Static vertical constructor |
| `Node::noBorder(Node)` | Static no-border constructor |
| `->withMinWidth(int)` | Minimum width hint |
| `->withMaxWidth(int)` | Maximum width hint |
| `->withMinHeight(int)` | Minimum height hint |
| `->withMaxHeight(int)` | Maximum height hint |
| `->withPadding(int)` | Inner padding (cells) |
| `->withBorder(bool)` | Show/hide box border |
| `->withSpacing(int)` | Gap between children (cells) |
| `->withBorderStyle(?Border)` | Border char set from candy-sprinkles |
| `->withStyle(?Style)` | Style (color, attributes) from candy-sprinkles |
| `->withTitle(string)` | Box title text |
| `->withMargin(int $top, ...)` | Outer margin (top/right/bottom/left) |
| `->withAlignH(Align)` | Horizontal text alignment |
| `->withAlignV(VAlign)` | Vertical text alignment |

## Border Characters

```
╭────┬────╮   ← top-left, top horiz, top-right, cross
│    │    │   ← vert bar
├────┼────┤   ← left-join, cross, right-join
╰────┴────╯   ← bottom-left, bottom horiz, bottom-right
```

## License

[MIT](LICENSE)
