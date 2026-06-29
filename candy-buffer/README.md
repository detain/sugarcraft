# candy-buffer

Cell-grid value objects for terminal rendering — immutable `Buffer` and `Cell` types that form the foundation for all SugarCraft rendering components.

## Overview

`candy-buffer` provides the core data model for terminal rendering:

- **`Buffer`** — a 2-D cell grid with immutable `with*()` mutation and bounds-checked access.
- **`Cell`** — a single terminal cell: rune, style, hyperlink, and display width.
- **`Position`** / **`Region`** — geometric primitives for buffer navigation and sub-region blitting.
- **`Style`** — minimal style record (fg colour, bg colour, attributes bitmask) for per-cell styling.
- **`Hyperlink`** — OSC 8 hyperlink anchor (url + id).

> **`Buffer::diff()`** produces minimal delta ANSI ops — see [`## Diffing & delta ANSI`](#diffing--delta-ansi) for details.

## Install

```sh
composer require sugarcraft/candy-buffer
```

## Quickstart

```php
use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Position;
use SugarCraft\Buffer\Region;
use SugarCraft\Buffer\Style;

// Create a 20x3 buffer filled with blank cells.
$buf = Buffer::new(20, 3);

// Set a cell at (col=5, row=1).
$buf = $buf->withCellAt(5, 1, Cell::new('H'));

// Blit a 2x2 sub-buffer at origin.
$sub = Buffer::new(2, 2);
$buf = $buf->withRegion(new Region(Position::new(0, 0), 2, 2), $sub);

// Read a cell back.
$cell = $buf->cellAt(5, 1);
echo $cell->rune(); // 'H'

// Access dimensions.
echo $buf->width();  // 20
echo $buf->height(); // 3
```

## Wide characters

Wide characters (CJK, many emoji) have a display width of 2. The next cell in the grid is reserved as an empty "continuation" cell (rune `''`, width 0):

```php
$cell = Cell::new('中'); // width=2, next cell must be a continuation
```

## API

### Buffer

- `Buffer::new(int $width, int $height): self` — factory, creates a grid filled with blank cells.
- `Buffer::cellAt(int $col, int $row): Cell` — bounds-checked accessor; throws `\OutOfRangeException` on miss.
- `Buffer::withCellAt(int $col, int $row, Cell $cell): self` — immutable cell setter.
- `Buffer::withRegion(Region $region, Buffer $source): self` — blit `$source` into `$region`.
- `Buffer::width(): int`, `Buffer::height(): int`, `Buffer::region(): Region`.
- `Buffer::diff(Buffer $previous): list<DiffOp>` — returns minimal delta ops (see [Diffing & delta ANSI](#diffing--delta-ansi)).

### Cell

- `Cell::new(string $rune = ' ', ?Style $style = null, ?Hyperlink $link = null, int $width = 1): self`
- `Cell::rune(): string`, `Cell::style(): ?Style`, `Cell::link(): ?Hyperlink`, `Cell::width(): int`

## Diffing & delta ANSI

`Buffer::diff(Buffer $previous)` compares two frames and returns a minimal list of `DiffOp` objects. Passing those ops through `DiffEncoder::encode()` produces a tight ANSI byte stream — no full repaint, just the cells that changed.

**DiffOp types**

| Op | ANSI | When used |
|---|---|---|
| `MoveCursorOp` | CUP `\x1b[row;colH` | Reposition before a run of changes |
| `SetStyleOp` | SGR `\x1b[...m` | Style transition (only when style differs) |
| `SetCellOp` | raw rune bytes | One or more consecutive changed cells |
| `RepeatRunOp` | REP `\x1b[N b` | 2+ identical adjacent cells with same style |
| `EraseRunOp` | ECH `\x1b[N X` | Large regions cleared to blanks |
| `SetHyperlinkOp` | OSC 8 | Open/close OSC 8 hyperlink anchors |

**Worked example**

Two 5×2 buffers, before (all blanks) and after (styled 'A' at col 0, bold 'BBB' at col 1):

```php
use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style;
use SugarCraft\Buffer\Diff\DiffEncoder;

$prev = Buffer::new(5, 2);                                          // all blanks
$curr = $prev
    ->withCellAt(0, 0, Cell::new('A', Style::new(0xFF0000)))             // red A
    ->withCellAt(1, 0, Cell::new('B', Style::new(0x0000FF)->withAttrs(Style::ATTR_BOLD)))  // blue bold B
    ->withCellAt(2, 0, Cell::new('B', Style::new(0x0000FF)->withAttrs(Style::ATTR_BOLD)))
    ->withCellAt(3, 0, Cell::new('B', Style::new(0x0000FF)->withAttrs(Style::ATTR_BOLD)));

$ops = $curr->diff($prev);
// [MoveCursorOp(0,0), SetStyleOp(red), SetCellOp([A]),
//  SetStyleOp(blue+bold), SetCellOp([B]), RepeatRunOp('B',2)]

$bytes = (new DiffEncoder())->encode($ops);
```

Emitted bytes (annotated):

```
\x1b[1;1H           # CUP → col 0, row 0  (from MoveCursorOp)
\x1b[38;2;255;0;0m  # SGR → red fg         (from SetStyleOp)
A                  # SetCellOp 'A'
\x1b[38;2;0;0;255;1m  # SGR → blue fg + bold  (style transition)
B                  # SetCellOp first 'B'
\x1b[2b            # REP → repeat 'B' 2×   (from RepeatRunOp)
\x1b[0m            # SGR reset             (DiffEncoder close)
```

Total: ~38 bytes vs ~95 bytes for a full 5×2 repaint. The diff is round-trip verified: `$prev->applyDiff($curr->diff($prev))` equals `$curr`.

## Upstream

Mirrors the Buffer/Cell data model from [charmbracelet/vte](https://github.com/charmbracelet/vte) and the terminal cell representation in [charmbracelet/lipgloss](https://github.com/charmbracelet/lipgloss).

## License

MIT
