<img src=".assets/icon.png" alt="sugar-boxer" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-boxer)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-boxer)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-boxer?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-boxer)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarBoxer

sugar-boxer — a box-drawing layout engine that composes terminal content into horizontal and vertical panel layouts with borders and padding, for PHP 8.3+.

## Features

- **H/V composition** — build arbitrary layouts by nesting `horizontal()` and `vertical()` panels
- **Box-drawing borders** — classic ANSI box characters (╭ ╮ ╰ ╯ │ ─), any
  candy-sprinkles `Border` style
- **No-border mode** — render adjacent panels without separators
- **Per-panel padding** — inner whitespace around content
- **ANSI-aware content** — styled/coloured leaf text places by *visible* columns: escape sequences ride with the grapheme they style (zero width), wide graphemes keep their two columns, and a clipped or unbalanced span is auto-reset so colour never bleeds past the box
- **Flex / grow children** — `withFlex(int)` / `withGrow()`: fixed children take their natural size, flex children fill the leftover by weight — so a layout can say "header = 1 fixed, content = FILL, status = 1 fixed" instead of an even weight split
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
$leaf = $boxer->leaf('Hello, World!');

// Horizontal split (side by side)
$row = $boxer->horizontal($leaf, $boxer->leaf('second'));

// Vertical split (stacked)
$column = $boxer->vertical($row, $leaf);

// Node with explicit dimensions
$node->withMinWidth(20)
     ->withMaxWidth(80)
     ->withMinHeight(5)
     ->withMaxHeight(40)
     ->withPadding(1)           // inner padding
     ->withBorder(true)         // show box border
     ->withSpacing(1);          // gap between children

// No-border (flat) layout
$flat = $boxer->noBorder($column);
```

## Styling with candy-sprinkles

sugar-boxer composes canonical styling primitives from
[`candy-sprinkles`](https://github.com/sugarcraft/candy-sprinkles):

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\{Align, Border, Style, VAlign};

// Set border character set (rounded / sharp / double / ascii / ...)
// Passing null clears the style but preserves border visibility.
$node->withBorderStyle(Border::rounded());  // ╭ ╮ ╰ ╯ │
$node->withBorderStyle(Border::double());    // ╔ ╗ ╚ ╝ ║ ═
$node->withBorderStyle(null);                // clear explicit style

// Apply foreground/background colors and attributes via Style
// (Style's constructor is private — build through the fluent API)
$node->withStyle(Style::new()->fg(Color::parse('cyan'))->bg(Color::parse('black')));

// Box title text rendered in the top border
$node->withTitle('My Panel');

// Outer spacing (top, right, bottom, left) — sugar-boxer-specific
$node->withMargin(1);                  // all sides
$node->withMargin(1, 2);               // top/bottom=1, left/right=2
$node->withMargin(1, 2, 1, 2);         // explicit all four

// Text alignment within the content area
$node->withAlignH(Align::Center);
$node->withAlignH(Align::Right);
$node->withAlignV(VAlign::Middle);
$node->withAlignV(VAlign::Bottom);
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
| `->withAlignH(?Align)` | Horizontal text alignment (null clears) |
| `->withAlignV(?VAlign)` | Vertical text alignment (null clears) |

## Border Characters

```
╭────┬────╮   ← top-left, top horiz, top-right, cross
│    │    │   ← vert bar
├────┼────┤   ← left-join, cross, right-join
╰────┴────╯   ← bottom-left, bottom horiz, bottom-right
```

Each panel draws its own frame — the renderer does **not** merge adjacent
borders into the junction glyphs (├ ┤ ┬ ┴ ┼) sketched above. Where two
bordered panels meet, the neighbour's left/top edge characters stand in for
the join, so a nested layout reads as doubled `││` seams rather than crosses.

## Buffer diffing

The renderer keeps the previous frame's cell grid across renders and rebuilds a
`Buffer` from it (widths re-derived per cell, so wide graphemes and embedded SGR
spans stay intact), computes `current->diff(previous)` (from
[candy-buffer](https://github.com/detain/sugarcraft-candy-buffer)), and emits only
the delta ANSI ops via `DiffEncoder::encode($ops)`. The current frame then replaces
the stored one for the next render.

**SSH bandwidth + flicker win:** a one-character change in an 80×24 viewport
produces ~8 bytes of delta ops instead of ~1 940 bytes for a full repaint.
Over an SSH session this means far less per-frame data on the wire and
eliminates the full-screen flicker of rewrite-based terminals. The first render
after startup or a resize still emits a full Buffer (no diff possible), so
behaviour is always correct.

## License

[MIT](LICENSE)

## Credits & inspiration

Design antecedent: [treilik/bubbleboxer](https://github.com/treilik/bubbleboxer); SugarCraft is developed as a native PHP project.
