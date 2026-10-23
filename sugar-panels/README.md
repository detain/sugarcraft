<img src=".assets/icon.png" alt="sugar-panels" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-panels)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-panels)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-panels?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-panels)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarPanels

> Formerly published as `sugarcraft/sugar-boxer` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Boxer\` → `SugarCraft\Panels\`. <!-- @rename-keep -->

sugar-panels — a box-drawing layout engine that composes terminal content into horizontal and vertical panel layouts with borders and padding, for PHP 8.3+.

## Features

- **H/V composition** — build arbitrary layouts by nesting `horizontal()` and `vertical()` panels
- **Box-drawing borders** — classic ANSI box characters (╭ ╮ ╰ ╯ │ ─), any
  sugar-style `Border` style
- **No-border mode** — render adjacent panels without separators
- **Per-panel padding** — inner whitespace around content
- **ANSI-aware content** — styled/coloured leaf text places by *visible* columns: escape sequences ride with the grapheme they style (zero width), wide graphemes keep their two columns, and a clipped or unbalanced span is auto-reset so colour never bleeds past the box
- **Flex / grow children** — `withFlex(int)` / `withGrow()`: fixed children take their natural size, flex children fill the leftover by weight — so a layout can say "header = 1 fixed, content = FILL, status = 1 fixed" instead of an even weight split
- **Width/Height hints** — nodes can specify min/max dimensions
- **Dynamic dimension calculation** — the engine computes total viewport from children
- **Leaf content** — any stringable content at leaf nodes
- **Stateful diff renderer** — first paint and every resize emit the full frame;
  later same-size renders emit only diff ops from the per-instance frame cache.
  Call `resetPreviousFrame()` whenever terminal state may be unknown (clear,
  alt-screen switch). Outputs plain ANSI strings; works with any TUI framework
- **Immutable nodes** — every `with*()` returns a *new* `Node`; an existing node
  is never modified, so keep the chained result

## Install

```bash
composer require sugarcraft/sugar-panels
```

## Quick Start

```php
use SugarCraft\Panels\SugarPanels;

$panels = SugarPanels::new();

$layout = $panels->vertical(
    $panels->horizontal(
        $panels->leaf("Left panel"),
        $panels->leaf("Right panel"),
    ),
    $panels->leaf("Bottom bar"),
);

echo $panels->render($layout, 60, 20);
```

## Layout API

```php
use SugarCraft\Panels\SugarPanels;

$panels = SugarPanels::new();

// Leaf node with string content
$leaf = $panels->leaf('Hello, World!');

// Horizontal split (side by side)
$row = $panels->horizontal($leaf, $panels->leaf('second'));

// Vertical split (stacked)
$column = $panels->vertical($row, $leaf);

// Node with explicit dimensions. Every with*() returns a NEW node — a chain
// whose result is discarded has no effect on the original.
$sized = $panels->leaf('Panel')
    ->withMinWidth(20)
    ->withMaxWidth(80)
    ->withMinHeight(5)
    ->withMaxHeight(40)
    ->withPadding(1)              // inner padding
    ->withBorder(true)            // show box border
    ->withSpacing(1);             // gap between children

// No-border (flat) layout
$flat = $panels->noBorder($column);

echo $panels->render($sized, 40, 10);
```

## Styling with sugar-style

sugar-panels composes canonical styling primitives from
[`sugar-style`](https://github.com/sugarcraft/sugar-style):

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Panels\SugarPanels;
use SugarCraft\Style\{Align, Border, Style, VAlign};

$panels = SugarPanels::new();

// Start from a plain leaf; every with*() returns a NEW node, so reassign.
$node = $panels->leaf('Styled panel');

// Set border character set (normal / rounded / thick / double / block /
// ascii / hidden). Passing null clears the explicit style but preserves
// border visibility.
$node = $node->withBorderStyle(Border::rounded());  // ╭ ╮ ╰ ╯ │
$node = $node->withBorderStyle(Border::double());   // ╔ ╗ ╚ ╝ ║ ═
$node = $node->withBorderStyle(null);               // clear explicit style

// Apply foreground/background colors and attributes via Style
// (Style's constructor is private — build through the fluent API)
$node = $node->withStyle(Style::new()->fg(Color::parse('cyan'))->bg(Color::parse('black')));

// Box title text rendered in the top border
$node = $node->withTitle('My Panel');

// Outer spacing (top, right, bottom, left) — sugar-panels-specific
$node = $node->withMargin(1);                  // all sides
$node = $node->withMargin(1, 2);               // top/bottom=1, left/right=2
$node = $node->withMargin(1, 2, 1, 2);         // explicit all four

// Text alignment within the content area
$node = $node->withAlignH(Align::Center);
$node = $node->withAlignH(Align::Right);
$node = $node->withAlignV(VAlign::Middle);
$node = $node->withAlignV(VAlign::Bottom);

echo $panels->render($node, 40, 10);
```

## API Reference

| Method | Description |
|--------|-------------|
| `$panels->leaf(string)` | Leaf node with string content |
| `$panels->horizontal(Node ...)` | Horizontal (row) layout |
| `$panels->vertical(Node ...)` | Vertical (column) layout |
| `$panels->noBorder(Node)` | Flat layout without separators |
| `$panels->render(Node, int $width, int $height)` | Render to ANSI string |
| `$panels->resetPreviousFrame()` | Drop the frame cache — next `render()` repaints full frame |
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
| `->withContent(string)` | Replace leaf content (new node) |
| `->withFlex(int)` | Fill leftover space by weight (0 = fixed size) |
| `->withGrow()` | Shorthand for `withFlex(1)` |
| `->withBorderStyle(?Border)` | Border char set from sugar-style |
| `->withStyle(?Style)` | Style (color, attributes) from sugar-style |
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
[sugar-cellbuffer](https://github.com/sugarcraft/sugar-cellbuffer)), and emits only
the delta ANSI ops via `DiffEncoder::encode($ops)`. The current frame then replaces
the stored one for the next render.

**Stateful contract:** the diff cache lives on the `SugarPanels` instance. The
first render (and every render after a size change) emits the full frame;
subsequent same-size renders emit only diff ops. If the terminal may have been
touched outside this renderer — a clear, an alt-screen switch, another program
— call `resetPreviousFrame()` so the next render repaints fully.

**SSH bandwidth + flicker win:** a one-character change in an 80×24 viewport
produces ~8 bytes of delta ops instead of ~1 940 bytes for a full repaint.
Over an SSH session this means far less per-frame data on the wire and
eliminates the full-screen flicker of rewrite-based terminals. The first render
after startup or a resize still emits a full Buffer (no diff possible), so
behaviour is always correct.

## Trust boundary

Leaf and title content is treated as **trusted input**: escape sequences are
preserved verbatim (they ride in the cell of the grapheme they style), exactly
as the ANSI-aware feature above promises. Do not embed text from untrusted
sources without sanitising it first — e.g. `SugarCraft\Core\Sanitize::untrusted()`
(or strip control sequences) before handing it to `leaf()` / `withTitle()` —
otherwise a payload can forge SGR, OSC-8 hyperlinks, or other terminal control
strings.

Hostile-size runaway is contained in any case: the renderer applies per-cell
fail-soft caps (a zero-width byte budget per cell and a bounded pending-escape
budget, documented in `SugarPanels`). Past the caps, styling is dropped; widths
and truncation are never corrupted, and only trusted input is expected to rely
on long escape payloads.

## Internationalization

The `Lang` facade and `lang/en.php` keys (`align.*`, `valign.*`) are wired but
currently **dormant**: the render path emits no user-facing strings, so no
production `Lang::t()` caller exists today. The pre-wired surface gives a
future label UI its locale plumbing; lookup follows the house chain exact
locale → base locale → `en` → raw key.

## License

MIT

## Credits & inspiration

Design antecedent: [treilik/bubbleboxer](https://github.com/treilik/bubbleboxer); SugarCraft is developed as a native PHP project.
