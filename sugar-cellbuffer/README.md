# sugar-cellbuffer

> Formerly published as `sugarcraft/candy-buffer` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Buffer\` → `SugarCraft\CellBuffer\`. <!-- @rename-keep -->

Cell-grid value objects for terminal rendering — immutable `Buffer` and `Cell` types that form the shared render data model of the SugarCraft ecosystem.

## Overview

`sugar-cellbuffer` provides the core data model for terminal rendering:

- **`Buffer`** — a 2-D cell grid. Every mutation returns a new instance: the fluent `with*()` family plus `fill()`, `copy()` and `applyDiff()` (not named `with*`, equally immutable). Access is bounds-checked.
- **`Cell`** — a single terminal cell: rune, style, hyperlink, and column width. Cells are plain value objects — rebuild via `new` rather than `with*()`.
- **`Position`** / **`Region`** — geometric primitives for buffer navigation and sub-region blitting.
- **`Style`** — per-cell pen: foreground/background (RGB *or* palette-slot identity), underline colour, and an attribute bitmask.
- **`Hyperlink`** — OSC 8 anchor (url + id), validated at construction.

`Buffer::diff()` produces minimal delta ops — see [Diffing & delta ANSI](#diffing--delta-ansi) below.

The model is consumed by sugar-overlay, sugar-toast, sugar-panels, sugar-table, sugar-forms, sugar-style, sugar-charts, sugar-image, sugar-dashboard, sugar-testing, sugar-video, sugar-datepicker, sugar-flexbox, candy-minesweeper and candy-tetris. Some renderers keep independent engines by design (sugar-core's line-diff Renderer, sugar-vterm's mutable grid) and candy-code does not consume this library; the package's role is the shared *data model* and its byte encoders.

`src/Lang.php` and `lang/` are the per-package i18n facade scaffolded for failure messages, currently DORMANT: zero `Lang::` call sites in `src/` — every exception message is a literal English string, and there is no translation API to expect yet.

## Install

```sh
composer require sugarcraft/sugar-cellbuffer
```

Requires PHP `^8.3`.

Packaged from the monorepo, this lib ships only a `dev-master` line on Packagist — consumers without `minimum-stability: dev` must pin explicitly (`composer require sugarcraft/sugar-cellbuffer:@dev`). The 2026-10 rename left the old `sugarcraft/candy-buffer` provider registered and frozen at its last old-name release; depend on the new name only. Inside the monorepo or a vendored checkout, a path repository on the `sugar-cellbuffer/` directory is the alternative.

## Quickstart

Coordinates are zero-based (`col`, `row`); the CUP sequences the encoders emit are one-based.

```php
<?php

require 'vendor/autoload.php';

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Position;
use SugarCraft\CellBuffer\Region;

// Create a 20x3 buffer filled with blank cells.
$buf = Buffer::new(20, 3);

// Set a cell at (col=5, row=1).
$buf = $buf->withCellAt(5, 1, Cell::new('H'));

// Blit a seeded 2x2 sub-buffer at origin — seeding matters: blitting an
// all-blank sub-buffer over blank cells is a visible no-op.
$sub = Buffer::new(2, 2);
$sub = $sub->withCellAt(0, 0, Cell::new('A'))->withCellAt(1, 0, Cell::new('B'));
$sub = $sub->withCellAt(0, 1, Cell::new('C'))->withCellAt(1, 1, Cell::new('D'));
$buf = $buf->withRegion(Region::new(Position::new(0, 0), 2, 2), $sub);

// Read a cell back.
$cell = $buf->cellAt(5, 1);
echo $cell->rune(); // 'H'

// Paint the whole grid and show it. toAnsi() joins rows with a bare LF,
// which a canonical (ONLCR) line turns into CRLF; raw-mode callers must
// write "\r\n" or interpose CUP themselves — see the toAnsi() contract.
echo $buf->toAnsi();
```

Run end-to-end (`php examples/basic.php`) the echoed `H` and the first grid row share one line: `toAnsi()` ends rows with a bare `\n` and no carriage return, so the `A B` row continues directly after the `H`, the second row carries the `C D` pair plus the grid's `H` at column 5, the third row is space-padded, and the shell prompt lands at the end of that third row rather than on a fresh line.

No `.vhs` demo — this is a pure data model; `examples/basic.php` + `examples/diff_frames.php` plus `tests/BufferTest.php` and `tests/WideCellPairingTest.php` are the executable spec.

## Wide characters

Wide cells (CJK, many emoji) declare `width: 2` — the pair law is exactly a width-2 contract; larger widths (3+) are run-length spans: the lead paints all N columns from its own rune, the **span law** requires that rune to measure exactly N display columns (a short rune behind a big declaration is refused by the constructor and by unserialization), and the covered columns carry width-0 mask cells — sugar-table's own border-fill shape. The writing API enforces the pair for you: `withCellAt()`, `fill()` and `withRegion()` automatically write the width-0 continuation partner to the right (a width-2 lead straddling the last column DEGRADES to a blank there — a two-column rune never paints past the frame edge, and `applyDiff()` mirrors the degrade), blank a left-behind continuation when a lead is replaced, and blank the displaced partner when a new lead lands on an existing pair's slot — no orphaned half-pair can survive a public mutation. Encoded streams skip continuations while the cursor advance trusts each lead's declared width, keeping wire and grid in contract. Sweeping a region with width-2 leads applies that pair law per written cell — consecutive leads displace each other and a uniform wide `fill()` ends as a single lead at the region's right edge; write leads at the stride for wide glyph runs (larger-width spans carry no partners).

```php
// Width is declared, never measured: pass width 2 explicitly.
$buf = Buffer::new(4, 1)->withCellAt(1, 0, Cell::new('中', null, null, 2));
// (1,0) carries '中' (width 2); (2,0) is now a continuation (rune '', width 0).

$cont = Cell::continuation(); // only needed for hand-built grids
$manual = Buffer::fromGrid(2, 1, [Cell::new('中', null, null, 2), $cont]);
```

`fromGrid()` is the escape hatch that skips the pair law: it demands a flat list of exactly `width*height` cells (dimensions and the count are validated), but the cells are taken as given — pair maintenance there is the caller's duty.

## API

**Alias convention.** When a consumer file imports both this package's value object and a same-named class from elsewhere (typically sugar-style's `Style`), the canonical alias spellings are `BufferStyle`, `BufferRegion` and `BufferPosition` — e.g. `use SugarCraft\CellBuffer\Style as BufferStyle;`. Every aliased cellbuffer import in the fleet already skews to exactly these three names (seven imports across five consumers); no per-package `CellBufferCell` variants are in use. Keep aliased imports over inline fully-qualified names so a glance at the `use` block says which `Style` is in scope. A direct `Style ↔ sugar-style` converter bridge is deliberately **not** shipped in this direction: sugar-style already requires this package (its `RegionBridge` converts cellbuffer regions), so a sugar-cellbuffer → sugar-style require would close a package cycle — any bridge belongs on the sugar-style side of the seam.

**Canonical read surface.** The bare accessor methods (`Cell::rune()`, `Style::attrs()`, `Region::origin()`, `Position::col()`, `Hyperlink::url()`, `MoveCursorOp::col()` …) are the **canonical** way to read these value objects, per the house bare-accessor law. The `public readonly` properties remain published and fully functional — the byte shape is frozen and consumers already skew property-style — but they carry `@deprecated` docblocks as a read surface: write new reads through the accessors. Inside each class, `$this->prop` stays the storage idiom (equality, serialization and arithmetic read own state directly); cross-object reads in this package's own sources are accessor-only.

### Buffer

- `Buffer::new(int $width, int $height): self` — factory, creates a grid filled with blank cells.
- `Buffer::cellAt(int $col, int $row): Cell` — bounds-checked accessor; throws `\OutOfRangeException` on miss.
- `Buffer::withCellAt(int $col, int $row, Cell $cell): self` — immutable cell setter (enforces the wide-pair law).
- `Buffer::withRegion(Region $region, Buffer $source): self` — blit `$source` into `$region`, clipped to the intersection.
- `Buffer::fill(Region $region, Cell $cell): self` — paint a region.
- `Buffer::copy(Region $region): self` — extract a buffer that is always region-sized: in-grid cells are copied verbatim, out-of-grid area is padded with blanks (unlike `withRegion()`/`fill()`, which clip to the intersection). Capped by `MAX_COPY_CELLS`.
- `Buffer::diff(Buffer $previous): list<DiffOp>` — delta ops that turn `$previous` into this buffer (dimensions must match); see [Diffing](#diffing--delta-ansi).
- `Buffer::applyDiff(array $ops): self` — replay a diff stream onto a buffer; refuses negative cursor ops.
- `Buffer::toAnsi(): string` — full-repaint ANSI for the whole grid. Rows are joined with a **bare LF only** — no CR, no cursor addressing — so the stream lands on the right rows only where the terminal maps newlines to CRLF (canonical cooked mode, ONLCR on) or the grid is exactly as wide as the terminal (auto-wrap returns the cursor). Raw-mode callers post-process `"\r\n"` themselves or interpose CUP moves between rows; `text()` shares the bare-LF join contract.
- `Buffer::text(?Region $region = null): string` — plain-text extraction, rows joined with bare `\n` (same contract as `toAnsi()`); width-0 continuations are skipped and every other cell's rune is emitted whatever its style — invisible cells included. Style-hidden content therefore survives copy/search/screen-reader extraction; do not use `ATTR_INVISIBLE` to mask values from `text()` consumers.
- `Buffer::fromString(?string $ansi, int $width, int $height): self` — **test-support** ANSI parser (lossy; see Security); all three arguments are required and non-positive dimensions are refused.
- `Buffer::fromGrid(int $width, int $height, array $grid): self` — wrap a flat cell list (index = `row*width + col`); positive dimensions and exactly `width*height` cells are enforced, pair law is not.
- `Buffer::width(): int`, `Buffer::height(): int`, `Buffer::region(): Region`.

### Cell

- `Cell::new(string $rune = ' ', ?Style $style = null, ?Hyperlink $link = null, int $width = 1): self` — negative widths are refused; `0` marks a continuation, `2` a wide lead (the only width that carries a continuation partner), larger values are single run-length spans (see sugar-table's border fills). Spans must MEASURE UP: a width `N > 2` cell requires a rune exactly `N` display columns wide (the span law, checked with sugar-core's `Width::of`) — a short rune behind a big declared width under-paints every frame it touches, so the pairing is refused at construction (and again on unserialization).
- `Cell::continuation(): self` — blank width-0 partner cell.
- `Cell::blank(): self` — the named padding preset (one space, default style, width 1); same value as `Cell::new()`, so erase paths read as intent.
- `Cell::rune(): string`, `Cell::style(): ?Style`, `Cell::link(): ?Hyperlink`, `Cell::width(): int`, `Cell::equals(Cell $other): bool`.
- Immutable derivations mirroring the `Style::with*()` law — each returns a new cell keeping every untouched field: `Cell::withRune(string)`, `Cell::withStyle(?Style)` (null clears), `Cell::withLink(?Hyperlink)` (null clears), `Cell::withWidth(int)`. The span law re-fires through the constructor on `withRune`/`withWidth`, so a derivation can never mint an under-painted pairing the direct constructor would refuse.

### Style

- `Style::new(?int $fg = null, ?int $bg = null, int $attrs = 0): self` — convenience factory (RGB colours and the attrs bitmask only). The full slot-aware form is the constructor: `new Style($fg, $bg, $attrs, $fgSlot, $bgSlot, $ul, $ulSlot)`, where slots are palette identities `0-255` validated at construction. Colours are `0xRRGGBB`. Slot and RGB are mutually exclusive per channel by the `with*()` contract; a channel may also carry BOTH when the RGB is the slot's canonical hex belt (what `Style::fromSgr()` records for palette colours); any other pairing is refused at construction. Emission prefers the slot over the belt, which keeps theme-following colours symbolic across delta round-trips instead of baking hex values.
- `withFg()/withBg()/withUl()` set RGB and discard slot identity; `withFgSlot()/withBgSlot()/withUlSlot()` set the slot and clear the RGB twin. The underline colour is stored independently of the attribute and EMITTED whenever the pen is set (`58;2`/`58;5` bytes ride any encoding of the style, underline attribute or not) — mirroring `fromSgr`'s acceptance of `58;…` without a `4`, so parse→emit→parse never silently forgets the colour.
- Attribute bits: `ATTR_BOLD`, `ATTR_ITALIC`, `ATTR_UNDERLINE`, `ATTR_STRIKE`, `ATTR_FAINT`, `ATTR_BLINK`, `ATTR_REVERSE`, `ATTR_OVERLINE`, `ATTR_INVISIBLE` (bold=1, italic=2, underline=4, …). The layout is this package's own and deliberately does not match sugar-vterm's independent bits. `ATTR_BLINK` caveat: the bit rides the wire verbatim (`5`/`25`) and the terminal decides what that means — real flashing (a WCAG 2.2.2 flashing-adjacent concern), a theme-brightened rendition, or nothing. This package cannot rate it and does not try to; TUIs honouring reduced motion substitute static emphasis upstream. Carrying the bit verbatim *is* the contract.
- `withAttrs(int $attrs): self` replaces the whole bitmask; nine single-bit toggles `withBold()/withItalic()/withUnderline()/withStrike()/withFaint()/withBlink()/withReverse()/withOverline()/withInvisible()` (each `bool $on = true`); factories `Style::bold()` / `Style::reverse()`; `hasBold()`-style predicates for all nine; `equals(self $other)` compares all seven fields and the static `Style::valuesEqual()` extends it null-safe — the equality the diff/encode paths use to decide style transitions.
- `Style::toAnsi(): string` — one-shot full SGR emission for the style, byte-identical to what the diff encoder writes on a pen transition onto it (same canonical `Support\SgrEmitter` home, so parse→emit is an identity surface outside a Buffer stream). Emission is assertive: it opens with the reset term and is self-contained on any terminal state.
- `Style::fromSgr(string $params, ?Style $carry = null): ?Style` — public SGR-parameter parser: reset, base/bright/256 palette colours (recording slots with an RGB fallback belt), RGB colours, underline colour (`58;2`/`58;5`), attribute on-codes `1`-`5`, `7`-`9`, `53` and off-codes `22` (clears bold AND faint), `23`-`25`, `27`-`29`, `55` — `6`, `26` and `21` are unhandled (`21`'s doubly-underlined reading needs a bit the nine-bit model does not carry; its legacy bold-off reading is already served by `22`), and any other code outside the enumerated vocabulary is ignored like those — plus clearing codes `39`/`49`/`59`; colon sub-parameters are INTERPRETED, not flattened — `38:2:r:g:b`, the T.416 colour-space variants `38:2:cs:r:g:b` / `38:2::r:g:b`, and `38:5:n` all parse to their true operands, an out-of-vocabulary colour mode fail-stops like its truncated semicolon twin, and an unrecognised colon extension on a non-colour code (`4:0`) is dropped whole rather than reinterpreted. Malformed (non-numeric) parameters throw `InvalidArgumentException`; a truncated extended colour (`38`/`48`/`58` missing operands) ENDS THE PARSE keeping the pen built so far, so `null` is returned only when nothing was set — the `$carry`, if any, survives as the partial pen in that case.

### Position / Region / Hyperlink

- `Position::new(int $col, int $row): self` with `col()`/`row()`.
- `Region::new(Position $origin, int $width, int $height): self` — promoted readonly `$origin`/`$width`/`$height` with accessors `origin()`, `width()`, `height()`, inclusive-edge `right()`/`bottom()`, and `contains(int $col, int $row): bool`. Zero-area regions no-op in `fill()`/`withRegion()`; `copy()` refuses them (its result is region-sized by contract).
- `Hyperlink::new(string $url, string $id = '')` — rejects C0 controls, DEL and the C1 escape introducers in both fields (lone raw 0x80-0x9F bytes and their `C2 xx` spellings; valid multibyte IRI payloads pass), and semicolons in the id. On the wire the open frame is spelled once, by `Hyperlink::toAnsi()`, per the spec grammar `OSC 8 ; params ; URI ST`: the id rides the `id=` attribute, and the id-less form keeps the empty params field (double semicolon — a single-semicolon slide puts the URL in params and reads as a CLOSE on conforming decoders). The close frame is the `Hyperlink::CLOSE` constant; both `toAnsi()` and the delta encoder route through the same pair.
- Every value object here (`Buffer`, `Cell`, `Position`, `Region`, `Style`, `Hyperlink`) implements `JsonSerializable`. `Position` deliberately admits negative coordinates (relative offsets); `Region` refuses negative dimensions at construction.

## Diffing & delta ANSI

`Buffer::diff(Buffer $previous)` compares two frames and returns a minimal list of `DiffOp` objects. Passing those ops through `DiffEncoder::encode()` produces a tight ANSI byte stream — no full repaint, just the cells that changed; `Buffer::diffToAnsi($previous)` collapses the diff-and-encode pair into one call. `encode()` resets its cursor, style and hyperlink state to home on every call and opens every frame with an unconditional home `CUP` (`\x1b[1;1H`), so one encoder instance reused across frames is safe: each emitted frame is self-contained — anchored at home before the first paint, no dependence on what a previous frame left on screen.

**DiffOp types**

| Op | ANSI | When used |
|---|---|---|
| `MoveCursorOp` | CUP `\x1b[row;colH` | Reposition before a run of changes (skipped when the encoder cursor is already there) |
| `SetStyleOp` | SGR `\x1b[...m` | Style transition (only when style differs by value); a full reset + restatement, not a minimal delta |
| `SetCellOp` | raw rune bytes | One or more consecutive changed cells |
| `RepeatRunOp` | REP `\x1b[N b` | Tail of a run of identical cells where `RepeatGate` byte-math wins — REP bytes appear for whole ASCII runs of 6+ cells (the gate sees `runLen−1`; the lead glyph rides a literal `SetCellOp`) and for shorter multi-byte runs; below that the literal bytes are emitted. `Buffer::diff()` emits a `RepeatRunOp` for every ≥2-cell width-1 identical run; the REP-vs-literal choice is made later in `DiffEncoder::encodeRepeatRun()` |
| `EraseRunOp` | ECH `\x1b[N X` | Runs of 3+ blank default cells |
| `SetHyperlinkOp` | OSC 8 | Open/close OSC 8 hyperlink anchors |

**Constructor shapes** (all promoted readonly; hand-built op lists are first-class `encode()` input): `MoveCursorOp(int $col, int $row)`, `SetCellOp(list<Cell> $cells)`, `SetStyleOp(?Style $style)`, `SetHyperlinkOp(?Hyperlink $hyperlink)`, `RepeatRunOp(string $rune, int $count, int $width = 1)`, `EraseRunOp(int $count)`.

Footnotes: `REP` and `ECH` are not universally implemented. The gate above is pure byte arithmetic — `RepeatGate` answers `false` on ties, so runs that could go either way stay literal (plain glyphs survive every terminal; REP is an extra capability assumption). `DiffEncoder::encode()` carries no opt-out flag for the escape sequences; on a terminal that mishandles them, `toAnsi()` is the conservative full-repaint fallback (see [Degraded terminals & non-visual output](#degraded-terminals--non-visual-output)).

**The `SugarCraft\CellBuffer\Diff\` machinery** — besides the op types in the table and the `DiffOp` base, four public classes carry the pipeline: `DiffEncoder` is the byte writer (op list → ANSI stream, absolute-CUP prologue); `DiffOptimiser` runs at the end of every `Buffer::diff()` and shapes the op list (style last-wins, adjacent same-state cell runs coalesced); `SgrEmitter` turns a `Style` into SGR parameter codes, preferring palette-slot identity over the RGB twin — the emission preference the Style section above relies on; `RepeatGate` is the stateless cost policy answering `worth(count, runeBytes)` for every candidate repeat.

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
\x1b[1;1H             # home anchor — the unconditional prologue every frame opens with
\x1b[0;38;2;255;0;0m  # SGR → red fg            (first MoveCursorOp skipped: the prologue parked the cursor at home)
A                   # SetCellOp 'A'
\x1b[0;38;2;0;0;255;1m  # SGR → blue fg + bold  (second MoveCursorOp skipped: cursor advanced past 'A')
BBB                 # 'B' + RepeatRunOp encoded LITERAL: RepeatGate::worth(2,1)
                    # says the REP frame would cost more than the remaining glyphs
\x1b[0m             # closing SGR reset — the frame ends styled; the encoder
                    # always closes a styled stream, so stale colour never bleeds into the next frame
```

Total: 50 bytes vs 51 bytes for the same 5×2 frame repainted via `toAnsi()` (which pays the reset too, walks every cell including the blanks row-by-row, and joins rows with bare `\n`). Cell styles in the example are equal-by-value instances; the stream is instance-independent — sharing one `Style` object across the three `B` cells yields the identical bytes. The diff is round-trip verified: `$prev->applyDiff($curr->diff($prev))` renders byte-identically to `$curr` via `toAnsi()`, hyperlinks (ids included) surviving intact.

## Security & trust boundaries

- **Cell runes are stored and emitted VERBATIM.** `toAnsi()` and the diff encoder write whatever rune you put in a cell. Stripping control bytes is the CALLER's duty: C0 controls and DEL (and the hostile 8-bit C1 range) must not reach a cell from untrusted input — pass external text through sugar-core's `SugarCraft\Core\Util\Sanitize::untrusted()` first (sugar-core/src/Util/Sanitize.php). Plain terminal-bound output uses `untrusted()`; when the string is destined for a frame whose bytes carry PUA zone/image-overlay markers, use `Sanitize::untrustedForMarkedFrames()` instead — the same sweep plus removal of the U+E000/U+E001 zone sentinels, so a forged marker cannot reach the host's zone parser. Raw escapes in runes desync the cursor and inject live SGR onto the wire.
- **`text()` does not respect styles**: runes in invisible (or any styled) cells still come out — invisibility is a display attribute, not a redaction boundary.
- **`Hyperlink` hard-rejects** C0 controls, DEL, and the C1 escape introducers — lone raw 0x80-0x9F bytes that do not continue a well-formed UTF-8 sequence, and their UTF-8 `C2 xx` spellings (C2 80-C2 9F) — in url and id, and semicolons in the id, at construction and again on unserialization — the OSC 8 fields cannot be forged through those types. Well-formed multibyte payloads (arrows, emoji, Cyrillic: an IRI) are accepted even though their continuation bytes sit in the C1 window.
- **`Buffer::fromString()` is test-support**, not a terminal emulator: it walks bytes, skips OSC frames, and is lossy by contract (hyperlinks never re-attached). CSI frames are consumed exactly as a terminal consumes them — parameter/intermediate bytes up to a final byte (0x40–0x7E); only SGR-shaped frames (final `m`, digits with `;`/`:` separators; an empty frame means reset) are interpreted onto the live pen. Every other CSI — `CUP`/`ECH`/`REP` frames this package itself emits, DEC private modes like `\x1b[?25l`/`\x1b[?1049h` — is dropped without depositing runes (`fromString("x\x1b[2Jy",10,1)->text()` is `"xy        "`; `fromString("\x1b[?25lAB\x1b[0m",4,1)` is `"AB  "`, not a throw). An `m`-terminated frame whose parameters carry private/intermediate bytes is likewise dropped, while a direct `Style::fromSgr()` call with such parameters still throws (`SGR parameters must be semicolon-separated integers`). An unterminated CSI swallows the rest of the stream. A lone trailing `ESC` or unrecognised `ESC` digraph is stored as a plain rune. Do not feed it untrusted input expecting a parse.
- **`Buffer::fromGrid()` skips pair enforcement** (see Wide characters) — it validates the dimensions and the exact `width*height` cell count, but takes the cells as given; the pair law is yours there.
- **Serialize hooks re-validate**: `__unserialize` on Buffer/Cell/Style/Hyperlink/Region re-runs every constructor invariant, so a forged payload fails closed instead of minting a structurally invalid buffer.
- **`applyDiff()` refuses negative cursor coordinates**; the wire encoder clamps them fail-soft to the origin (first row/column). Out-of-grid POSITIVE writes are the mirror half of the same policy: `applyDiff()` skips them fail-soft on both axes (a row below the last row, a column past the last column) — the grid can never be extended by a diff replay — while the encoder, which holds no grid dimensions, lets positive overflow ride through untouched (bounding the grid is the caller's job).

## Memory model

Every mutation clones the full grid: a one-cell `with*()` copies the `width × height` cell list, so the old and new structures briefly coexist (~2-3× memory, momentarily). Dimensions are caller-trusted at construction — size the grid to the pane, not the content. Region costs are bounded: `withRegion()`/`fill()` iterate only the clipped intersection, and `copy()` refuses regions above `MAX_COPY_CELLS` (1,000,000 cells).

## Degraded terminals & non-visual output

- **No capability gating.** `toAnsi()`, the diff encoder and `applyDiff()` emit SGR, ECH, REP and OSC 8 unconditionally — there is no `NO_COLOR`/`CLICOLOR`/`isatty`/terminfo check anywhere in `src/`. Adapting output to degraded terminals is the caller's job; `toAnsi()` (full repaint, no REP/ECH) is the conservative fallback.
- **`Buffer::text()` is the non-visual projection.** Style-free, one row per line, continuation cells skipped — the right source for accessibility trees, clipboard and search.
- **Palette slots carry identity, not just colour.** `fgSlot`/`bgSlot`/`ulSlot` keep the 0-255 terminal-palette identity of themed colours across delta round-trips, which doubles as the hook for monochrome/high-contrast remapping: remap the slot, not the hex.

## Where next

- Full repaint: `Buffer::toAnsi()`.
- Delta frames: `Buffer::diff()` → `DiffEncoder::encode()`; replay state with `applyDiff()`.
- Text extraction (search/clipboard): `Buffer::text()`.
- Building styled grids programmatically: the `Style` slots keep palette colours theme-following across delta round-trips.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

The Buffer/Cell data model follows the terminal cell buffer of [charmbracelet/x/cellbuf](https://github.com/charmbracelet/x/tree/main/cellbuf) — the Go module `charmbracelet/x/cellbuf`, whose `cellbuf/` (and the old `x/vt`) packages now live consolidated in the [charmbracelet/x](https://github.com/charmbracelet/x) monorepo. The pen/style vocabulary and SGR emission echo [charmbracelet/lipgloss](https://github.com/charmbracelet/lipgloss). The diff framing follows [ratatui](https://github.com/ratatui/ratatui)'s `Buffer::diff`, cited in `DiffOp`/`DiffEncoder`'s upstream notes.

## Divergences from charmbracelet/x/cellbuf

The antecedent is named in [Credits](#credits--inspiration); the shape this port took from it is deliberately narrower. Upstream method names below were read off `cellbuf/buffer.go`, `cellbuf/hardscroll.go` and `cellbuf/hashmap.go` — not from memory.

- **Immutable value objects, no live mutation.** Upstream `Buffer` is edited in place: `Set`/`SetCell`, `Clear`/`ClearRect`, `Fill`/`FillRect`, structural edits `InsertCell`/`InsertCellRect`/`InsertLine`/`InsertLineRect` and `DeleteCell`/`DeleteCellRect`/`DeleteLine`/`DeleteLineRect`, and geometry changes via `Resize`. None of that exists here: every change returns a new `Buffer` (copy-on-write `with*()`), and there is **no resize, no scroll, no `SetSize`**. Structural recomposition is a blit — extract with `copy()`, paste with `withRegion()`:

  ```php
  $window = $src->copy(Region::new(Position::new(2, 1), 4, 2));                    // region-sized extract
  $dst   = $dst->withRegion(Region::new(Position::new(0, 0), 4, 2), $window);      // clipped paste
  ```

- **Dense only.** One flat `width*height` cell grid behind the API. Upstream also carries a `Screen` with scroll optimisation (`hardscroll.go`: `scrollOptimize`, `scrollUp`/`scrollDown`, `scrollIdl`) and a line-hash map (`hashmap.go`) feeding it; there is no counterpart, and no sparse cell-buffer twin here — a mutable live grid that absorbs terminal I/O is sugar-vterm's job, not this package's.
- **No capability gating.** The encoders emit the byte forms they decided to emit (REP/ECH/OSC 8/CUP) without probing what the attached terminal supports — the honest statement of that trade is in [Degraded terminals & non-visual output](#degraded-terminals--non-visual-output).
