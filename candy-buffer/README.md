# candy-buffer

Cell-grid value objects for terminal rendering — immutable `Buffer` and `Cell` types that form the foundation for all SugarCraft rendering components.

## Overview

`candy-buffer` provides the core data model for terminal rendering:

- **`Buffer`** — a 2-D cell grid with immutable `with*()` mutation and bounds-checked access.
- **`Cell`** — a single terminal cell: rune, style, hyperlink, and display width.
- **`Position`** / **`Region`** — geometric primitives for buffer navigation and sub-region blitting.
- **`Style`** — minimal style record (fg colour, bg colour, attributes bitmask) for per-cell styling.
- **`Hyperlink`** — OSC 8 hyperlink anchor (url + id).

> **`Buffer::diff()`** is declared but not yet implemented — see [step-26](https://github.com/sugarcraft/sugarcraft/blob/master/docs/repo_map_step_26.md) for the delta-ANSI emitter.

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
- `Buffer::diff(Buffer $previous): list<DiffOp>` — **stub**: returns `[]`; full implementation in step-26.

### Cell

- `Cell::new(string $rune = ' ', ?Style $style = null, ?Hyperlink $link = null, int $width = 1): self`
- `Cell::rune(): string`, `Cell::style(): ?Style`, `Cell::link(): ?Hyperlink`, `Cell::width(): int`

## Upstream

Mirrors the Buffer/Cell data model from [charmbracelet/vte](https://github.com/charmbracelet/vte) and the terminal cell representation in [charmbracelet/lipgloss](https://github.com/charmbracelet/lipgloss).

## License

MIT
