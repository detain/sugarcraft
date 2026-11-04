# sugar-cellbuffer

> Formerly published as `sugarcraft/candy-buffer` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Buffer\` → `SugarCraft\CellBuffer\`. <!-- @rename-keep -->

Cell-grid value objects for terminal rendering — immutable `Buffer` and `Cell` types that form the shared render data model of the SugarCraft fleet.

## Overview

`sugar-cellbuffer` provides the core data model for terminal rendering:

- **`Buffer`** — a 2-D cell grid. Every mutation returns a new instance: the fluent `with*()` family plus `fill()`, `copy()` and `applyDiff()` (not named `with*`, equally immutable). Access is bounds-checked.
- **`Cell`** — a single terminal cell: rune, style, hyperlink, and column width. Cells are plain value objects — rebuild via `new` rather than `with*()`.
- **`Position`** / **`Region`** — geometric primitives for buffer navigation and sub-region blitting.
- **`Style`** — per-cell pen: foreground/background (RGB *or* palette-slot identity), underline colour, and an attribute bitmask.
- **`Hyperlink`** — OSC 8 anchor (url + id), validated at construction.

`Buffer::diff()` produces minimal delta ops — see [Diffing & delta ANSI](#diffing--delta-ansi) below.

The model is consumed by sugar-overlay, sugar-toast, sugar-panels, sugar-table, sugar-forms, sugar-style, sugar-charts, sugar-image, sugar-dashboard, sugar-testing, sugar-video, sugar-datepicker, candy-minesweeper and candy-tetris. Some renderers keep independent engines by design (sugar-core's line-diff Renderer, sugar-vterm's mutable grid) and candy-code does not consume this library; the package's role is the shared *data model* and its byte encoders.

## Install

```sh
composer require sugarcraft/sugar-cellbuffer
```

Requires PHP `^8.3`.

## Quickstart

Coordinates are zero-based (`col`, `row`); the CUP sequences the encoders emit are one-based.

```php
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Position;
use SugarCraft\CellBuffer\Region;

// Create a 20x3 buffer filled with blank cells.
$buf = Buffer::new(20, 3);

// Set a cell at (col=5, row=1).
$buf = $buf->withCellAt(5, 1, Cell::new('H'));

// Blit a 2x2 sub-buffer at origin.
$sub = Buffer::new(2, 2);
$buf = $buf->withRegion(Region::new(Position::new(0, 0), 2, 2), $sub);

// Read a cell back.
$cell = $buf->cellAt(5, 1);
echo $cell->rune(); // 'H'

// Paint the whole grid and show it.
echo $buf->toAnsi();
```

## Wide characters

Wide cells (CJK, many emoji) declare `width: 2`. The writing API enforces the pair law for you: `withCellAt()`, `fill()` and `withRegion()` automatically write the width-0 continuation partner to the right, blank a left-behind continuation when a lead is replaced, and blank the displaced partner when a new lead lands on an existing pair's slot — no orphaned half-pair can survive a public mutation.

```php
// Width is declared, never measured: pass width 2 explicitly.
$buf = Buffer::new(4, 1)->withCellAt(1, 0, Cell::new('中', null, null, 2));
// (1,0) carries '中' (width 2); (2,0) is now a continuation (rune '', width 0).

$cont = Cell::continuation(); // only needed for hand-built grids
$manual = Buffer::fromGrid([[Cell::new('中', null, null, 2), $cont]]);
```

`fromGrid()` is the unvalidated escape hatch: it accepts any cell layout as given, so pair maintenance there is the caller's duty.

## API

### Buffer

- `Buffer::new(int $width, int $height): self` — factory, creates a grid filled with blank cells.
- `Buffer::cellAt(int $col, int $row): Cell` — bounds-checked accessor; throws `\OutOfRangeException` on miss.
- `Buffer::withCellAt(int $col, int $row, Cell $cell): self` — immutable cell setter (enforces the wide-pair law).
- `Buffer::withRegion(Region $region, Buffer $source): self` — blit `$source` into `$region`, clipped to the intersection.
- `Buffer::fill(Region $region, Cell $cell): self` — paint a region.
- `Buffer::copy(Region $region): self` — extract a region-sized buffer (verbatim blit; capped by `MAX_COPY_CELLS`).
- `Buffer::diff(Buffer $previous): list<DiffOp>` — minimal delta ops (see [Diffing](#diffing--delta-ansi)).
- `Buffer::applyDiff(array $ops): self` — replay a diff stream onto a buffer; refuses negative cursor ops.
- `Buffer::toAnsi(): string` — full-repaint ANSI for the whole grid.
- `Buffer::text(?Region $region = null): string` — plain-text extraction (continuations skipped).
- `Buffer::fromString(string $ansi): self` — **test-support** ANSI parser (lossy; see Security).
- `Buffer::fromGrid(array $grid): self` — build from a literal cell grid (no pair enforcement).
- `Buffer::width(): int`, `Buffer::height(): int`, `Buffer::region(): Region`.

### Cell

- `Cell::new(string $rune = ' ', ?Style $style = null, ?Hyperlink $link = null, int $width = 1): self` — negative widths are refused; `0` marks a continuation, `2` a wide lead, larger values are run-length spans (see sugar-table's border fills).
- `Cell::continuation(): self` — blank width-0 partner cell.
- `Cell::rune(): string`, `Cell::style(): ?Style`, `Cell::link(): ?Hyperlink`, `Cell::width(): int`, `Cell::equals(Cell $other): bool`.

### Style

- `Style::new(?int $fg = null, ?int $bg = null, int $attrs = 0, ?int $fgSlot = null, ?int $bgSlot = null, ?int $ul = null, ?int $ulSlot = null): self` — colours are `0xRRGGBB`; slots are palette identities `0-255`. Slot and RGB are mutually exclusive per channel by the `with*()` contract; where both are somehow present, emission prefers the slot, which keeps theme-following colours symbolic across delta round-trips instead of baking hex values.
- `withFg()/withBg()/withUl()` set RGB and discard slot identity; `withFgSlot()/withBgSlot()/withUlSlot()` set the slot and clear the RGB twin.
- Attribute bits: `ATTR_BOLD`, `ATTR_ITALIC`, `ATTR_UNDERLINE`, `ATTR_STRIKE`, `ATTR_FAINT`, `ATTR_BLINK`, `ATTR_REVERSE`, `ATTR_OVERLINE`, `ATTR_INVISIBLE` (bold=1, italic=2, underline=4, …). The layout is this package's own and deliberately does not match sugar-vterm's independent bits.
- `Style::fromSgr(string $params, ?Style $carry = null): ?Style` — public SGR-parameter parser: reset, base/bright/256 palette colours (recording slots with an RGB fallback belt), RGB colours, underline colour (`58;2`/`58;5`), attribute on-codes and off-codes (`22`-`29`, `53`), clearing codes `39`/`49`/`59`. Truncated or malformed parameters fail-stop to null/throw respectively.

### Position / Region / Hyperlink

- `Position::new(int $col, int $row): self` with `col()`/`row()`.
- `Region::new(Position $origin, int $width, int $height): self` with `origin()`, `width()`, `height()`, `contains(int $col, int $row): bool`. Zero-area regions no-op in `fill()`/`withRegion()`; `copy()` refuses them (its result is region-sized by contract).
- `Hyperlink::new(string $url, string $id = '')` — rejects C0/DEL controls in both fields and semicolons in the id; on the wire the id is spelled as the spec's `id=` attribute.

## Diffing & delta ANSI

`Buffer::diff(Buffer $previous)` compares two frames and returns a minimal list of `DiffOp` objects. Passing those ops through `DiffEncoder::encode()` produces a tight ANSI byte stream — no full repaint, just the cells that changed.

**DiffOp types**

| Op | ANSI | When used |
|---|---|---|
| `MoveCursorOp` | CUP `\x1b[row;colH` | Reposition before a run of changes (skipped when the encoder cursor is already there) |
| `SetStyleOp` | SGR `\x1b[...m` | Style transition (only when style differs by value); a full reset + restatement, not a minimal delta |
| `SetCellOp` | raw rune bytes | One or more consecutive changed cells |
| `RepeatRunOp` | REP `\x1b[N b` | Runs of identical cells where `RepeatGate` byte-math wins (ASCII: 5+ cells; below that the literal bytes are emitted) |
| `EraseRunOp` | ECH `\x1b[N X` | Runs of 3+ blank default cells |
| `SetHyperlinkOp` | OSC 8 | Open/close OSC 8 hyperlink anchors |

**Worked example**

Two 5×2 buffers, before (all blanks) and after (styled 'A' at col 0, bold 'BBB' at col 1):

```php
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Style;
use SugarCraft\CellBuffer\Diff\DiffEncoder;

$prev = Buffer::new(5, 2);                                          // all blanks
$curr = $prev
    ->withCellAt(0, 0, Cell::new('A', Style::new(0xFF0000)))             // red A
    ->withCellAt(1, 0, Cell::new('B', Style::new(0x0000FF)->withAttrs(Style::ATTR_BOLD)))  // blue bold B
    ->withCellAt(2, 0, Cell::new('B', Style::new(0x0000FF)->withAttrs(Style::ATTR_BOLD)))
    ->withCellAt(3, 0, Cell::new('B', Style::new(0x0000FF)->withAttrs(Style::ATTR_BOLD)));

$ops = $curr->diff($prev);
// [MoveCursorOp(0,0), SetStyleOp(red), SetCellOp([A]),
//  MoveCursorOp(1,0), SetStyleOp(blue+bold), SetCellOp([B]), RepeatRunOp('B',2)]

$bytes = (new DiffEncoder())->encode($ops);
```

Emitted bytes (annotated):

```text
\x1b[0;38;2;255;0;0m  # SGR → red fg            (first MoveCursorOp skipped: encoder is already at 1;1)
A                   # SetCellOp 'A'
\x1b[0;38;2;0;0;255;1m  # SGR → blue fg + bold  (second MoveCursorOp skipped: cursor advanced past 'A')
BBB                 # 'B' + RepeatRunOp encoded LITERAL: RepeatGate::worth(3,1)
                    # says the REP frame would cost more than the three glyphs
\x1b[0m             # closing SGR reset — the frame ends styled; the encoder
                    # always closes a styled stream (the ghost-colour cure)
```

Total: 44 bytes vs 51 bytes for the same 5×2 frame repainted via `toAnsi()` (which pays the reset too, plus per-cell style re-statements across the blanks). Cell styles in the example are equal-by-value instances; the stream is instance-independent — sharing one `Style` object across the three `B` cells yields the identical bytes. The diff is round-trip verified: `$prev->applyDiff($curr->diff($prev))` renders byte-identically to `$curr` via `toAnsi()`, hyperlinks (ids included) surviving intact.

## Security & trust boundaries

- **Cell runes are stored and emitted VERBATIM.** `toAnsi()` and the diff encoder write whatever rune you put in a cell. Stripping control bytes is the CALLER's duty: C0 controls and DEL (and the hostile 8-bit C1 range) must not reach a cell from untrusted input — pass external text through sugar-core's `Sanitize::untrusted()` first. Raw escapes in runes desync the cursor and inject live SGR onto the wire.
- **`Hyperlink` hard-rejects** C0/DEL in url and id, and semicolons in the id, at construction and again on unserialization — the OSC 8 fields cannot be forged through those types.
- **`Buffer::fromString()` is test-support**, not a terminal emulator: it walks bytes, skips OSC frames, and is lossy by contract (hyperlinks never re-attached; a lone trailing `ESC` or an unrecognised `ESC` digraph is stored as a plain rune; an unterminated CSI deposits its parameter text into cells). Do not feed it untrusted input expecting a parse.
- **`Buffer::fromGrid()` is unvalidated** (see Wide characters) — the pair law is yours there.
- **Serialize hooks re-validate**: `__unserialize` on Buffer/Cell/Style/Hyperlink/Region re-runs every constructor invariant, so a forged payload fails closed instead of minting a structurally invalid buffer.
- **`applyDiff()` refuses negative cursor coordinates**; the wire encoder clamps them fail-soft to the grid edge.

## Memory model

Buffers are full-grid copy-on-write: a one-cell `with*()` copies the `width × height` cell list. Serialisation briefly holds both the old and new structures (~2-3× transient). Dimensions are caller-trusted at construction — size the grid to the pane, not the content. Region costs are bounded: `withRegion()`/`fill()` iterate only the clipped intersection, and `copy()` refuses regions above `MAX_COPY_CELLS` (1,000,000 cells).

## Where next

- Full repaint: `Buffer::toAnsi()`.
- Delta frames: `Buffer::diff()` → `DiffEncoder::encode()`; replay state with `applyDiff()`.
- Text extraction (search/clipboard): `Buffer::text()`.
- Building styled grids programmatically: the `Style` slots keep palette colours theme-following across delta round-trips.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

The Buffer/Cell data model follows the cell/style design of [charmbracelet/lipgloss](https://github.com/charmbracelet/lipgloss) and the terminal cell representation in [charmbracelet/x](https://github.com/charmbracelet/x) (`x/vt`).
