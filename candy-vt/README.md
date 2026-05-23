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
Used as the terminal emulator behind
[candy-vcr](../candy-vcr/)'s `render-tape` pipeline — every frame produced
for a GIF is a `Snapshot::of($terminal, $time)` after the renderer feeds
output bytes into a `Terminal` instance.

## Contents

- [Install](#install)
- [Quickstart](#quickstart)
- [Architecture](#architecture)
- [Two Terminal classes](#two-terminal-classes)
- [Renderer value objects (vcr path)](#renderer-value-objects-vcr-path)
- [Parser state machine](#parser-state-machine)
- [CSI coverage table](#csi-coverage-table)
- [OSC coverage](#osc-coverage)
- [SGR attributes](#sgr-attributes)
- [Theme catalog](#theme-catalog)
- [Subsystems](#subsystems)
- [Development](#development)
- [Related](#related)

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
| Handler | `Parser\CsiHandler` | CSI sequence handler contract — Phase 1c implementation fills in CUP/SGR/ED/EL/DECSET/DECRST/DECSTBM/TBC |
| Handler | `Parser\OscHandler` | OSC sequence handler contract — Phase 1c implementation fills in title/hyperlink |
| Handler | `Handler\ScreenHandler` | Dispatches parser actions to Buffer / Cursor / Sgr / Mode |
| Screen | `Screen\Screen` | Immutable snapshot — read current grid after feeding bytes |
| Buffer | `Buffer\Buffer` | Cell grid — `rows × cols` of styled grapheme cells |
| Cursor | `Cursor\Cursor` | Position + visibility + origin mode tracking |
| SGR | `Sgr\Sgr` | Current graphics rendition: foreground / background / attributes |
| Mode | `Mode\Mode` | DEC private mode flags (`DECSET`/`DECRST`) including DECAWM auto-wrap |
| Hyperlink | `Hyperlink\Hyperlink` | OSC 8 URL + id tracker |
| Scrollback | `Screen\Scrollback` | Ring buffer — stores rows that scroll off the top (default 1000) |
| Msg | `Msg\FocusInMsg / FocusOutMsg` | Focus-in / focus-out event records (CSI I/O, mode 1004) |
| Theme | `Theme` | 256-color palette + default fg/bg + factory methods for named themes |
| Catalog | `Themes` | Bundled theme catalog: `all()` and `v1()` accessors |

## Two Terminal classes

candy-vt ships **two** Terminal entry-points for distinct use cases:

| Class | Use case | Methods |
|-------|----------|---------|
| `SugarCraft\Vt\Terminal\Terminal` | Full VT500 emulator — parses CSI/OSC/DCS, maintains Sgr/Mode/Hyperlink/Scrollback. | `create(cols, rows, ?scrollbackSize)`, `feed(bytes)`, `flush()`, `screen()`, `cursor()`, `mode()`, `windowTitle()`, `palette()`, `clipboardEvents()`, `resize(cols, rows)`, `enableAltScreen()`/`disableAltScreen()`/`isAltScreen()`, plus `with*()` builders for Buffer/Cursor/Mode/WindowTitle/TabStops/ScrollbackSize. |
| `SugarCraft\Vt\Terminal` (root) | Lightweight emulator used by candy-vcr's renderer — produces `Snapshot` value objects directly. | `new(cols, rows, ?Theme)`, `theme()`, `feed(bytes): self`, `snapshot(?time): Snapshot`, `cursor(): Cursor`, `grid(): CellGrid`, `windowTitle(): string`. |

```php
use SugarCraft\Vt\Terminal;       // root — renderer path
use SugarCraft\Vt\Theme;

$vt = Terminal::new(cols: 80, rows: 24, theme: Theme::tokyoNight());
$vt = $vt->feed("\x1b[1;1H\x1b[31mhello\x1b[0m");
$snapshot = $vt->snapshot(time: 1.234);
```

The root `Terminal` returns a NEW instance from `feed()` (immutable
fluent style) and uses the `HandlerAdapter` + `CsiHandlerImpl` +
`OscHandlerImpl` triple internally. The full `Terminal\Terminal`
mutates in place (`feed(): void`) — it's optimised for byte-stream
ingest rather than per-frame snapshots.

### Renderer value objects (vcr path)

The `SugarCraft\Vt` root namespace provides simplified value objects for the
candy-vcr VHS renderer path — independent of the full VT parser stack:

```php
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;

// Cell — char + fg (0-255) + bg (0-255) + attrs bitfield
$cell = new Cell(char: 'X', fg: 196, bg: 21, attrs: Cell::ATTR_BOLD);
$cell = $cell->withFg(34);            // green foreground
$cell = $cell->withBg(226);           // yellow background
$cell = $cell->withAttrs(Cell::ATTR_ITALIC | Cell::ATTR_UNDERLINE);

// CellGrid — 2D grid with dirty-region tracking
$grid = new CellGrid(cols: 80, rows: 24);
$grid = $grid->set(0, 0, new Cell(char: 'H'));
$grid = $grid->set(0, 1, new Cell(char: 'i'));
echo $grid->get(0, 0)->char;          // 'H'
echo implode(',', $grid->dirtyRegion()); // minRow, maxRow, minCol, maxCol
$grid = $grid->clear();               // resets dirtyRegion
$grid = $grid->resize(100, 40);     // grow/shrink preserving content

// Cursor — row + col + shape + visibility
$cursor = new Cursor(row: 0, col: 0, shape: 0, visible: true);
$cursor = $cursor->at(5, 10);        // move to row 5, col 10
$cursor = $cursor->withShape(2);      // shape 2 = pipe
$cursor = $cursor->hidden();           // hide cursor
$cursor = $cursor->shown();          // show cursor
```

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

### Snapshot

`SugarCraft\Vt\Snapshot` is an immutable point-in-time grid + cursor:

```php
use SugarCraft\Vt\Snapshot;

$snap = Snapshot::of($terminal, time: 1.234);  // captures grid + cursor
$snap->grid;     // CellGrid
$snap->cursor;   // Cursor
$snap->time;     // float — virtual playback time

$snap->equals($other);          // grid + cursor only (used by FrameDedup)
$snap->equalsWithTime($other);  // grid + cursor + time (strict reproducibility)
```

`Snapshot::equals()` is the dedup signal used by candy-vcr's
`FrameDedup` to collapse visually identical adjacent frames before GIF
encoding.

## Parser state machine

`Parser\Parser` is a Paul Williams VT500 state machine
([reference](https://vt100.net/emu/dec_ansi_parser)) that drives a
`Parser\Handler` interface with parsed actions.

| Component | Role |
|-----------|------|
| `Parser\State` | Enum of parser states: `Ground`, `Escape`, `CsiEntry`, `CsiParam`, `CsiIntermediate`, `CsiIgnore`, `DcsEntry`, `OscString`, etc. |
| `Parser\Action` | Enum of dispatch actions: `Print`, `Execute`, `CsiDispatch`, `EscDispatch`, `OscDispatch`, `DcsDispatch`, `Hook`, `Put`, `Unhook`. |
| `Parser\Transitions` | Static transition table — `(state, byte) → (action, next_state)`. |
| `Parser\Handler` | The contract the parser drives — implementations: `HandlerAdapter` (wraps CSI + OSC sub-handlers), `DebugHandler` (dumps every action). |
| `Parser\CsiHandler` | CSI dispatch contract — `printable`, `cuu`/`cud`/`cuf`/`cub`, `cup`, `sgr`, `ed`/`el`, `decset`/`decrst`, `decstbm`, `tbc`, `cht`/`cbt`, `gridRows`. |
| `Parser\OscHandler` | OSC dispatch contract — `title`, `hyperlink`. |
| `Parser\CsiHandlerImpl` | Default CSI handler used by the root `Terminal` renderer path. |
| `Parser\OscHandlerImpl` | Default OSC handler — tracks last window title. |
| `Parser\HandlerAdapter` | Glue that wires `CsiHandler` + `OscHandler` into a single `Handler` for the parser. |

Partial input is supported — feed any byte boundary and the parser
state persists across `feed()` calls. Subparameter colons
(`CSI 4:2 m` → double underline) are parsed natively.

## CSI coverage table

The default `CsiHandlerImpl` (used by `SugarCraft\Vt\Terminal`) implements
the renderer-relevant subset of CSI dispatches. The full
`Handler\ScreenHandler` (used by `SugarCraft\Vt\Terminal\Terminal`)
implements the superset.

| Final byte | Name | Method | Behavior |
|------------|------|--------|----------|
| `@` | ICH | (Handler) | Insert N blank characters at cursor. |
| `A` | CUU | `cuu($n=1)` | Cursor up N, clamped to scroll region top. |
| `B` | CUD | `cud($n=1)` | Cursor down N, clamped to scroll region bottom. |
| `C` | CUF | `cuf($n=1)` | Cursor right N, clamped to right margin. |
| `D` | CUB | `cub($n=1)` | Cursor left N, clamped to column 0. |
| `E` | CNL | (Handler) | Cursor down N + column 0. |
| `F` | CPL | (Handler) | Cursor up N + column 0. |
| `G` | CHA | (Handler) | Cursor to column N. |
| `H` | CUP | `cup($row, $col)` | Move cursor to (row, col) 1-indexed. |
| `I` | CHT | `cht($n=1)` | Cursor forward N tab stops. |
| `J` | ED | `ed($mode=0)` | Erase display: 0=cursor→end, 1=begin→cursor, 2=all, 3=all+scrollback. |
| `K` | EL | `el($mode=0)` | Erase line: 0=cursor→eol, 1=bol→cursor, 2=full line. |
| `L` | IL | (Handler) | Insert N blank lines at cursor. |
| `M` | DL | (Handler) | Delete N lines at cursor. |
| `P` | DCH | (Handler) | Delete N characters at cursor. |
| `S` | SU | (Handler) | Scroll up N lines. |
| `T` | SD | (Handler) | Scroll down N lines. |
| `X` | ECH | (Handler) | Erase N characters from cursor (preserves BCE bg). |
| `Z` | CBT | `cbt($n=1)` | Cursor backward N tab stops. |
| `d` | VPA | (Handler) | Cursor to row N. |
| `f` | HVP | `hvp($row, $col)` | Same as CUP — move cursor to (row, col). |
| `g` | TBC | `tbc($mode=0)` | Tab clear: 0=column, 3=all. |
| `h` | SM/DECSET | `decset($mode, $prefix)` | Set mode. `?` prefix → private. `?7 h` enables DECAWM, `?12 h` BCE, `?25 h` cursor visible, `?47/?1047/?1049 h` alt screen, `?1004 h` focus events, `?2026 h` sync-update, `?6 h` DECOM. |
| `l` | RM/DECRST | `decrst($mode, $prefix)` | Reset mode (mirror of `h`). |
| `m` | SGR | `sgr($params)` | Set graphic rendition. Subparam colons (`4:1`..`4:5`) parsed. |
| `r` | DECSTBM | `decstbm($top, $bottom)` | Set scroll region rows (1-indexed). |
| `s` | DECSC | (Handler) | Save cursor + Sgr + origin mode. |
| `u` | DECRC | (Handler) | Restore cursor + Sgr + origin mode. |
| `<n> SP q` | DECSCUSR | (Handler) | Cursor shape 0–6 (block/underline/bar × blink). |
| `<n> I/O` | Focus | (Handler) | Focus-in / focus-out report (under `?1004 h`). |

`(Handler)` rows live in the full `Handler/ScreenHandler` dispatcher
used by `SugarCraft\Vt\Terminal\Terminal`. The renderer path
(`SugarCraft\Vt\Terminal`) implements the rows with an explicit
`Method` column — the rest of the CSI sequences are ignored when the
renderer encounters them (they're already simplified-away by upstream
applications writing rendered output).

The `printable` and `execute` paths also route through the same
adapter — printable bytes call `CsiHandlerImpl::printable()` (writes
the cell + advances cursor + auto-wraps), and C0 controls (`\b \t \n
\r`) call the corresponding cursor handler.

## OSC coverage

The default `OscHandlerImpl` (renderer path) implements:

| Sequence | Method | Behavior |
|----------|--------|----------|
| `OSC 0;<title> BEL/ST` | `title($title)` | Set window title (and icon name). |
| `OSC 2;<title> BEL/ST` | `title($title)` | Set window title only. |
| `OSC 8;<id>;<uri> BEL/ST` | `hyperlink($uri, $id)` | Begin/end hyperlink (OSC 8). Empty URI ends the link. |
| `lastTitle()` | accessor | Most recent title set during the stream. |

The full `Handler\OscHandler` (used by `SugarCraft\Vt\Terminal\Terminal`)
adds palette query/set (`OSC 4`, `OSC 10`, `OSC 11`), clipboard get/set
(`OSC 52`), and forwards focus / mode-query events to
`ScreenHandler::$clipboardEvents` / `$focusEvents`.

## SGR attributes

`Sgr\Sgr` tracks the current pen state. Supported attributes:

| SGR param | Effect |
|-----------|--------|
| `0` | Reset all attributes. |
| `1` | Bold. |
| `2` | Faint. |
| `3` | Italic. |
| `4` (or `4:1`) | Single underline. |
| `4:2` / `4:3` / `4:4` / `4:5` | Double / Curly / Dotted / Dashed underline. |
| `7` | Inverse (swap fg/bg). |
| `9` | Strikethrough. |
| `21` / `22` | Reset bold + faint. |
| `23` / `24` / `27` / `29` | Reset italic / underline / inverse / strikethrough. |
| `30..37` / `90..97` | Set 8 + 8 bright ANSI foreground. |
| `38;5;<n>` / `38;2;<r>;<g>;<b>` | 256-color / truecolor foreground. |
| `40..47` / `100..107` | Set 8 + 8 bright ANSI background. |
| `48;5;<n>` / `48;2;<r>;<g>;<b>` | 256-color / truecolor background. |
| `39` / `49` | Reset fg / bg to default. |

Attribute bitfield mirrored on `Cell::ATTR_*` constants:
`ATTR_BOLD`, `ATTR_ITALIC`, `ATTR_UNDERLINE`, `ATTR_INVERSE`,
`ATTR_STRIKETHROUGH`.

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

## Theme catalog

`Theme` provides a 256-color palette with factory methods for named themes.
`Themes` is the catalog listing all available themes:

```php
use SugarCraft\Vt\Theme;
use SugarCraft\Vt\Themes;

// Tokyo Night — the only theme used in the monorepo's 277 .tape files
$theme = Theme::tokyoNight();

// Index into the 256-color palette
$red = $theme->color(1);       // 0xf7768e

// Resolve RGB components from a 256-color index
$rgb = Theme::rgb(196);        // [255, 85, 85]

// Map ANSI 0-15 slot to its 256-color index
$idx = Theme::fgIndex(1);        // 1 (Ansi1 → index 1)

// V1-ready themes
foreach (Themes::v1() as $name => $theme) {
    echo "$name\n";
}
// TokyoNight, TokyoNightLight, TokyoNightStorm

// All themes (including deferred stubs)
foreach (Themes::all() as $name => $theme) {
    echo "$name\n";
}
// TokyoNight, TokyoNightLight, TokyoNightStorm, Dracula, SolarizedDark
```

Available themes:

| Theme | Status | Description |
|---|---|---|
| `TokyoNight` | ✅ v1 | Dark theme — used in all 277 monorepo tapes |
| `TokyoNightLight` | ✅ v1 | Light variant of TokyoNight |
| `TokyoNightStorm` | ✅ v1 | Storm variant of TokyoNight |
| `Dracula` | ✅ v1 | Full Dracula palette |
| `SolarizedDark` | ✅ v1 | Full Solarized Dark palette |

Attribute constants on `Theme` match `Cell::ATTR_*` for SGR bitfield construction:
`Theme::ATTR_BOLD`, `Theme::ATTR_ITALIC`, `Theme::ATTR_UNDERLINE`, `Theme::ATTR_INVERSE`, `Theme::ATTR_STRIKETHROUGH`.

### Theme accessors

| Method | Returns |
|--------|---------|
| `Theme::color($index)` | `int` — packed 24-bit RGB for palette slot 0..255. Falls back through `Theme::rgb()` for grayscale (232..255) when a slot is absent. |
| `Theme::rgb($index)` | `array{int,int,int}` — `[R,G,B]` resolution for any 256-color index, including the 6x6x6 color cube (16..231) and the 24-step grayscale ramp (232..255). |
| `Theme::fgIndex($slot)` | `int` — Map an ANSI 0..15 slot to its 256-color index (currently a no-op identity, kept for spec parity). |
| `Theme::bgIndex($slot)` | `int` — Same for background slot. |
| `Theme::defaultPalette()` | `array<int,int>` — Bootstrap palette (xterm defaults) used by `Themes::ansi()`. |
| `Theme::ANSI_OFFSET` / `Theme::CUBE_OFFSET` / `Theme::GRAYSCALE_OFFSET` | int constants 0 / 16 / 232 — palette region boundaries. |

## Subsystems

Each subdirectory of `src/` owns a small piece of VT state:

| Namespace | Purpose | Public surface |
|-----------|---------|----------------|
| `Buffer\Buffer` | Cell grid storage (`rows × cols` array of `Cell`). | `cell($row, $col)`, `put($row, $col, $cell)`, `each(): Generator`, `copy(): array`, `resize($cols, $rows): self`. |
| `Screen\Screen` | Immutable snapshot of Buffer + Scrollback; cell-level diff API. | `fromBuffer($buf, ?$scrollback)`, `lines()`, `cell($row, $col)`, `scrollback()`, `diff($other)`. |
| `Screen\Scrollback` | Ring buffer (default 1000 rows) for rows scrolled off the top. | `count()`, `maxSize()`, `at($n)`, `all()`. |
| `Cell\Cell` | Cell-grid cell — grapheme + Sgr + Hyperlink + combining marks. Used by the full Terminal facade. | `empty()`, `continuation($prev)`, `withCombining($s)`, `sgr()`, `foreground()`, `background()`, `equals()`. |
| `Cursor\Cursor` | Position + visibility + shape + saved-state. | `withRow($r)`, `withCol($c)`, `withVisible($v)`, `withShape($s)`, `save()`, `restore()`, `equals()`. |
| `Sgr\Sgr` | Pen state (fg, bg, attrs bitfield, underline style). | `empty()`, `withBold/Italic/Underline/Strikethrough/Blink/Reverse/Dim/Hidden($v)`, `withUnderlineStyle($style)`, `withForeground(?$color)`, `withBackground(?$color)`, `equals()`. |
| `Sgr\UnderlineStyle` | Enum: `None`/`Single`/`Double`/`Curly`/`Dotted`/`Dashed`. | `fromInt()`, `value`. |
| `Mode\Mode` | DEC private modes. | `withAltScreen/AltScreenVariant/CursorVisible/MouseSgr/MouseHighlights/MouseAny/MouseCellMotion/MouseExtended/BracketedPaste/SyncUpdate/AutoWrap/OriginMode/CursorShape/ReportFocusEvents`, `isAltScreen()`, `equals()`. |
| `Hyperlink\Hyperlink` | OSC 8 URI + id state. | `fromRaw($id, $uri)`, `equals()`. |
| `Msg\FocusInMsg` / `FocusOutMsg` | Focus event records — accumulated on `ScreenHandler::$focusEvents` when DEC mode `1004` is set. | Plain DTOs. |
| `Color\Color` | Color value object — palette index or truecolor RGB. | `default()`, `indexed16($i)`, `indexed256($i)`, `truecolor($r,$g,$b)`, `fromInt($kind,$v)`, `red()`, `green()`, `blue()`, `equals()`. |
| `CursorShape` (root enum) | `BlinkingBlock` (0/1) / `SteadyBlock` (2) / `BlinkingUnderline` (3) / `SteadyUnderline` (4) / `BlinkingBar` (5) / `SteadyBar` (6). | `fromInt()`, `toInt()`. |

## Development

```sh
cd candy-vt && composer install
vendor/bin/phpunit                                              # test suite
vendor/bin/phpstan analyze                                      # static analysis (level: max)
vendor/bin/php-cs-fixer fix --config=../.php-cs-fixer.dist.php  # lint + auto-fix style
```

Code style is enforced by `php-cs-fixer` via the root `.php-cs-fixer.dist.php` (PSR-12 + `declare_strict_types` + `strict_param` + short array syntax). Append `--dry-run --diff` to preview without writing.

## Related

- **[candy-vcr](../candy-vcr/)** — VHS-compatible cassette recorder /
  GIF renderer. Uses this lib's root `Terminal` + `Snapshot` to drive
  every rendered frame.
- [SugarCraft monorepo](https://github.com/detain/sugarcraft)
- Upstream: [charmbracelet/x/vt](https://github.com/charmbracelet/x/tree/main/vt)
