<img src=".assets/icon.png" alt="candy-vt" width="160" align="right">

# CandyVt

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-vt)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-vt)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-vt?label=packagist)](https://packagist.org/packages/sugarcraft/candy-vt)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

In-memory virtual terminal emulator — parses an ANSI byte stream into a
cell grid with cursor, mode, SGR style, and hyperlink state.

Mirrors [charmbracelet/x/vt](https://github.com/charmbracelet/x/tree/main/vt).

## Install

```sh
composer require sugarcraft/candy-vt
```

## Quickstart

```php
use SugarCraft\Vt\Terminal\Terminal;

$vt = Terminal::create(cols: 80, rows: 24);

// Feed raw ANSI bytes from a terminal program.
$vt->feed("\x1b[1;1H\x1b[31mmhello\x1b[0m");

// Inspect the rendered screen.
$screen = $vt->screen();
foreach ($screen->lines() as $row => $line) {
    echo "$row: $line\n";
}

// Current cursor position.
$cursor = $vt->cursor();
echo "cursor at {$cursor->row},{$cursor->col}\n";
```

## Architecture

| Layer | Class | Role |
|---|---|---|
| Facade | `Terminal\Terminal` | Owns a Parser + ScreenHandler; `feed()` drives bytes in |
| Parser | `Parser\Parser` | VT500 state machine — Paul-Williams algorithm, handles partial input, parses subparameters (`:) |
| Handler | `Handler\ScreenHandler` | Dispatches parser actions to Buffer / Cursor / Sgr / Mode |
| Screen | `Screen\Screen` | Immutable snapshot — read current grid after feeding bytes |
| Buffer | `Buffer\Buffer` | Cell grid — `rows × cols` of styled grapheme cells |
| Cursor | `Cursor\Cursor` | Position + visibility + origin mode tracking |
| SGR | `Sgr\Sgr` | Current graphics rendition: foreground / background / attributes |
| Mode | `Mode\Mode` | DEC private mode flags (`DECSET`/`DECRST`) including DECAWM auto-wrap |
| Hyperlink | `Hyperlink\Hyperlink` | OSC 8 URL + id tracker |
| Scrollback | `Screen\Scrollback` | Ring buffer — stores rows that scroll off the top (default 1000) |
| Msg | `Msg\FocusInMsg / FocusOutMsg` | Focus-in / focus-out event records (CSI I/O, mode 1004) |

### Parser handlers

Each handler translates parser actions into handler state mutations:

- `CursorHandler` — CUP, HVP, CUU/CUD/CUF/CUB, CR, LF/VT/FF, BS, RI, HOME, DECSC/USC
- `SgrHandler` — SGR sequences (colour + attributes)
- `EraseHandler` — ED, EL, ECH, DECSCA
- `ScrollHandler` — SU, SD, DECSTBM
- `ModeHandler` — DECSET/DECRST/DECMODESET/DECMODERST (includes DECAWM mode 7)
- `TabHandler` — TBC, HTS
- `OscHandler` — OSC window title, hyperlink, colour palette
- `ScreenHandler` — orchestrates all of the above; owns the Buffer

## Background Color Erase (BCE)

When BCE mode is enabled (`CSI ?12 h`), erase operations (`CSI K`, `CSI J`,
`CSI X`) fill cleared cells with the current SGR background color instead of
a plain blank cell. This prevents "ghosting" in terminals with colored
backgrounds — the erased area retains its visual background rather than going
black.

```php
$vt = Terminal::create(cols: 80, rows: 24);
$vt->feed("\x1b[48;5;196m\x1b[?12h");  // red background + BCE on
$vt->feed("\x1b[2J");                   // erase screen — cells carry red bg
echo $vt->screen()->cell(0, 0)->background()?->toInt(); // 196
```

BCE state is tracked via the `Sgr` object's background color — the pen
must be set before the erase sequence for the background to carry through.

## Diff API

After feeding bytes, snapshot the screen and compare:

```php
$before = $vt->screen();
$vt->feed($moreBytes);
$after  = $vt->screen();

foreach ($before->diff($after) as $change) {
    [$row, $col, $prev, $next] = $change;
    echo "{$row},{$col}: '{$prev->grapheme}' → '{$next->grapheme}'\n";
}
```

## Auto-wrap (DECAWM)

DECAWM (`CSI ? 7 h` / `CSI ? 7 l`) controls whether the cursor
automatically wraps to the next line when a character is printed at the
rightmost column. The `Mode` object exposes this as:

```php
// Query the current state.
$mode = $vt->mode();
var_dump($mode->autoWrap);  // bool

// Build a new mode with auto-wrap forced on/off.
$wrapped = $mode->withAutoWrap(true);
```

When auto-wrap is **off** (the default), characters written at the
rightmost column are silently discarded. When **on**, the cursor moves
to column 0 of the next row before writing — and if that row is within a
scroll region the region scrolls up by one line, matching VT100 behavior.

Scroll regions (DECSTBM, `CSI r`) and auto-wrap interact correctly:
wrapping at the bottom of a scroll region triggers a scroll within that
region, not on the whole buffer.

## SGR underline styles

SGR `CSI 4:N` controls the underline style. The `Sgr` object exposes
this as an `UnderlineStyle` enum and a fluent setter:

```php
use SugarCraft\Vt\Sgr\Sgr;
use SugarCraft\Vt\Sgr\UnderlineStyle;

// Build an Sgr state with a specific underline style.
$sgr = Sgr::empty()->withUnderlineStyle(UnderlineStyle::Double);
echo $sgr->underline;           // true
echo $sgr->underlineStyle->value; // 2 (Double)
```

`UnderlineStyle` values map to CSI sequences as follows:

| Enum value | CSI sequence | Description         |
|------------|--------------|---------------------|
| `None`     | `CSI 4:0 m`  | No underline        |
| `Single`   | `CSI 4:1 m`  | Single underline   |
| `Double`  | `CSI 4:2 m`  | Double underline   |
| `Curly`   | `CSI 4:3 m`  | Curly underline    |
| `Dotted`  | `CSI 4:4 m`  | Dotted underline   |
| `Dashed`  | `CSI 4:5 m`  | Dashed underline   |

`CSI 24 m` clears underline (any style). Plain `CSI 4 m` (no subparam)
is equivalent to `4:1` (single).

## Scrollback buffer

When the screen scrolls (LF at the bottom, DECSTBM scroll region, or
auto-wrap at the bottom of a region), rows pushed off the top are stored
in a ring-buffer `Scrollback`. Access it via:

```php
$screen = $vt->screen();
$scrollback = $screen->scrollback();

foreach ($scrollback->all() as $rowIndex => $row) {
    // $row is array<int, Cell>
    echo "scrollback row $rowIndex\n";
}
```

The default capacity is 1000 rows. Configure it at construction or
retrospectively:

```php
// Set at construction.
$vt = Terminal::create(cols: 80, rows: 24, scrollbackSize: 5000);

// Retrospective change — existing scrollback is replaced.
$vt = $vt->withScrollbackSize(5000);
```

Available `Scrollback` accessors:

```php
$scrollback->count()    // int — rows currently stored
$scrollback->maxSize()  // int — configured capacity
$scrollback->at($n)     // array<int, Cell>|null — row offset N from oldest
$scrollback->all()       // array<int, array<int, Cell>> — all rows oldest→newest
```

The scrollback is consumed by `ScreenHandler`'s scroll-up and scroll-down
operations (SU/SD/IND/RI/NEL). Erase-of-scrollback (`CSI 3 J`) clears the
ring buffer.

## Origin mode (DECOM)

DECOM (`CSI ?6 h` / `CSI ?6 l`) controls whether cursor addressing is
relative to the absolute screen origin or to the scroll region defined by
DECSTBM. The `Mode` object exposes this as:

```php
$mode = $vt->mode();
var_dump($mode->originMode);  // bool

$origin = $mode->withOriginMode(true);
```

When origin mode is **on**, the cursor is constrained to the scroll region
and cursor-addressing commands (`CSI row;col H`, `CSI row;col f`) treat
`(1,1)` as the top-left of the DECSTBM region. When **off** (the default),
`(1,1)` is the absolute top-left of the screen. DECSTBM must be reset
when leaving origin mode — both happen together via `CSI ?6 l`.

## Cursor shape (DECSCUSR)

DECSCUSR (`CSI Ps SP q`) sets the terminal cursor shape. The `Mode` object
stores this as an integer and exposes it via the `CursorShape` enum:

```php
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\CursorShape;

$mode = $vt->mode();
var_dump($mode->cursorShape);  // int (0–6)

// Build a new mode with a specific cursor shape.
$bar = $mode->withCursorShape(CursorShape::SteadyBar->toInt());
```

`CursorShape` values map to VT sequences:

| Enum value      | Value | CSI sequence   | Description            |
|-----------------|-------|----------------|------------------------|
| `BlinkingBlock`  | 0/1   | `CSI 0 SP q`   | Blinking block         |
| `SteadyBlock`    | 2     | `CSI 2 SP q`   | Steady block           |
| `BlinkingUnderline` | 3 | `CSI 3 SP q`   | Blinking underline    |
| `SteadyUnderline`  | 4  | `CSI 4 SP q`   | Steady underline       |
| `BlinkingBar`    | 5     | `CSI 5 SP q`   | Blinking vertical bar  |
| `SteadyBar`       | 6     | `CSI 6 SP q`   | Steady vertical bar    |

`CursorShape::fromInt()` normalises both `0` and `1` to `BlinkingBlock`
to match the VT spec.

## Focus events (mode 1004)

When focus event reporting is enabled (`CSI ?1004 h` / `CSI ?1004 l`),
the terminal records focus-in (`CSI I`) and focus-out (`CSI O`) events.
The `Mode` object tracks the reporting flag:

```php
$mode = $vt->mode();
var_dump($mode->reportFocusEvents);  // bool

$tracking = $mode->withReportFocusEvents(true);
```

Events are accumulated on the internal `ScreenHandler::$focusEvents`
array as `FocusInMsg` / `FocusOutMsg` value objects. A consumer can
inspect the array directly or wire the handler to dispatch events to
an event loop.

## Combining characters and wide characters

`ScreenHandler::printChar()` categorises every incoming rune by width:

- **Wide (width ≥ 2):** CJK and most emoji occupy 2 cells. A `Cell::continuation()`
  marker is written to the second cell and inherits SGR + hyperlink from the
  base cell. The cursor advances by the full width.
- **Normal (width 1):** Written to a single cell.
- **Combining mark (width 0):** Zero-width Unicode combining diacritical marks
  (U+0300–U+036F) are not silently discarded. Instead they are attached to
  the preceding cell via `Cell::withCombining()` so the base character and
  its combining marks render as a single composed grapheme in one column:

```php
$vt->feed("\x1b[31me\xcc\x81");  // 'e' + combining acute accent → 'é'
$cell = $vt->screen()->cell(0, 0);
echo $cell->grapheme;   // 'e'
echo $cell->combining;  // "\xcc\x81" — attach to grapheme for full cluster
```

`Cell::$combining` is a plain string; `$cell->withCombining($mark)` appends
to it. The `$combining` field is compared in `Cell::equals()` so snapshots
that include composed characters compare correctly.

Combining marks that arrive at column 0 or immediately after a wide-char
continuation cell are silently dropped — nothing in the preceding column
to attach to.

## Synchronized output (DEC 2026)

DEC 2026 (`CSI ?2026 h` / `CSI ?2026 l`) enables **synchronized output
mode**. While active, all buffer mutations (character writes, combining
attachments, erase fills) are held in a queue on `ScreenHandler` instead
of being applied immediately. When the mode is disabled (`CSI ?2026 l`),
all queued mutations are replayed atomically. This prevents mid-sequence
screen updates from being visible to the user, matching xterm's batched
update behavior.

```php
$mode = $vt->mode();
var_dump($mode->syncUpdate);  // bool

$vt->feed("\x1b[?2026h");    // enter sync-update mode
$vt->feed("\x1b[31mA\x1b[0m"); // queued, not yet visible
$vt->feed("\x1b[?2026l");    // flush → both cells appear together
```

Mutations are queued in `ScreenHandler::$pendingMutations` and flushed by
`flushPendingMutations()` when sync mode is exited. BCE erase (CSI ?12 h)
and combining-char attachments are both eligible for queued writes.

## Test

```sh
cd candy-vt && composer install && vendor/bin/phpunit
```

## Related

- [SugarCraft monorepo](https://github.com/detain/sugarcraft)
- Upstream: [charmbracelet/x/vt](https://github.com/charmbracelet/x/tree/main/vt)
