# SugarVterm

> Formerly published as `sugarcraft/candy-vt` with namespace `SugarCraft\Vt` (renamed 2026-10). <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-vterm)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-vterm)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-vterm?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-vterm)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

In-memory virtual terminal emulator — parses an ANSI byte stream into a
cell grid with cursor, mode, SGR style, and hyperlink state.

Used as the terminal emulator behind
[candy-vcr](../candy-vcr/)'s `render-tape` pipeline — every frame produced
for a GIF is a `Snapshot::of($terminal, $time)` after the renderer feeds
output bytes into a `Terminal` instance.

## Contents

- [Text units](#text-units)
- [Install](#install)
- [Quickstart](#quickstart)
- [Architecture](#architecture)
- [Two Terminal classes](#two-terminal-classes)
  - [Renderer value objects (vcr path)](#renderer-value-objects-vcr-path)
- [Parser state machine](#parser-state-machine)
- [CSI coverage table](#csi-coverage-table)
- [OSC coverage](#osc-coverage)
- [SGR attributes](#sgr-attributes)
- [Background Color Erase (BCE)](#background-color-erase-bce)
- [Diff API](#diff-api)
- [Auto-wrap (DECAWM)](#auto-wrap-decawm)
- [SGR underline styles](#sgr-underline-styles)
- [Scrollback buffer](#scrollback-buffer)
- [Origin mode (DECOM)](#origin-mode-decom)
- [Cursor shape (DECSCUSR)](#cursor-shape-decscusr)
- [Focus events (mode 1004)](#focus-events-mode-1004)
- [Combining characters and wide characters](#combining-characters-and-wide-characters)
- [Synchronized output (DEC 2026)](#synchronized-output-dec-2026)
- [Theme catalog](#theme-catalog)
- [Subsystems](#subsystems)
- [Trust boundary and hostile input](#trust-boundary-and-hostile-input)
- [Development](#development)
- [Snapshot tests](#snapshot-tests)
- [Related](#related)
- [Credits & inspiration](#credits--inspiration)

## Text units

Text moves through three levels, and this README uses one fixed term for
each:

- **code point** — a single Unicode scalar, the unit the parser decodes from
  UTF-8 bytes. `printChar()` classifies every incoming code point by width,
  and charset designation translates only 7-bit code points.
- **grapheme** — a user-perceived character: a base code point plus the
  zero-width marks that attach to it. The grid stores the base cluster in
  `Cell::$char` (whose emulator-facing alias is `Cell::$grapheme`), with any
  attached marks in `Cell::$combining`.
- **cell** — one grid slot. A grapheme occupies one cell, or two when it is
  wide; the second slot is a continuation cell.

"Rune" and "graphic" are retired as unit names. Two spellings survive on
purpose: `SGR` keeps its ECMA-48 expansion, "Select Graphic Rendition",
because that names the sequence rather than a text unit, and code
identifiers such as the `translate($charset, $rune)` parameter keep their
own spelling.

## Install

```sh
composer require sugarcraft/sugar-vterm
```

## Quickstart

```php
use SugarCraft\Vterm\Terminal\Terminal;

$vt = Terminal::new(cols: 80, rows: 24);

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

`lines()` echoes the raw grid contents — on bytes from anywhere but your own
program, read §Trust boundary and hostile input (below) before echoing that
output anywhere.

> **Captured-stream gotcha (ONLCR).** A real terminal writes line breaks as
> `\r\n` because the tty layer (ONLCR) expands each `\n` on output. Feeding
> the emulator a raw `\n` reproduces exact VT semantics — the cursor moves
> down one row *without* resetting the column — so text captured with bare
> LFs (files, pipes, hand-written fixtures) stacks diagonally and the cell
> grid false-diffs against what a human saw on screen. Normalise `\n` to
> `\r\n` (collapsing existing `\r\n` first) before `feed()` when the source
> is not a tty capture.

## Architecture

| Layer | Class | Role |
|---|---|---|
| Facade | `Terminal\Terminal` | Owns a Parser + ScreenHandler; `feed()` drives bytes in |
| Parser | `AnsiParser\Parser\Parser` | VT500 state machine (owned by **sugar-ansi-parser**; consumed via both facades — `src/Terminal/Terminal.php` and `src/Terminal.php`) — Paul-Williams algorithm, handles partial input, parses subparameters (`:`) |
| Handler | `AnsiParser\Parser\CsiHandler` | CSI sequence handler contract (owned by sugar-ansi-parser) — vterm's `Parser\CsiHandlerImpl` implements it |
| Handler | `AnsiParser\Parser\OscHandler` | OSC sequence handler contract (owned by sugar-ansi-parser) — vterm's `Parser\OscHandlerImpl` implements it |
| Handler | `Handler\ScreenHandler` | Dispatches parser actions to Buffer / Cursor / Sgr / Mode |
| Screen | `Screen\Screen` | Immutable snapshot — read current grid after feeding bytes |
| Buffer | `Buffer\Buffer` | Cell grid — `rows × cols` of styled grapheme cells |
| Cursor | `Cursor\Cursor` | Position + visibility + shape (the emulator-path class also carries the saved row/column pair) |
| SGR | `Sgr\Sgr` | Current graphics rendition: foreground / background / attributes |
| Rendition | `Rendition` | Double-height / double-width line rendition (`ESC # 3`–`ESC # 6`), stamped on cells |
| Charset | `Charset\Charsets` | SCS character-set tables — G0–G3 designations, SO/SI locking shifts, SS2/SS3 single shifts |
| Mode | `Mode\Mode` | DEC private mode flags (`DECSET`/`DECRST`) including DECAWM auto-wrap and DECOM origin mode (`$originMode` — origin is mode state, not cursor state; the emulator keeps a separate saved-origin slot per DECSC); `altScreenVariant` separates the four-way `47`/`1047`/`1048`/`1049` alt-screen semantics |
| Hyperlink | `Hyperlink\Hyperlink` | OSC 8 URL + id tracker |
| Scrollback | `Screen\Scrollback` | Ring buffer — stores rows that scroll off the top (default 1000) |
| Msg | `Msg\FocusInMsg / FocusOutMsg` | Focus-in / focus-out event records (CSI I and CSI O, mode 1004) |
| Theme | `Theme` | 256-color palette + default fg/bg + factory methods for named themes |
| Catalog | `Themes` | Bundled theme catalog: `all()` and `v1()` accessors |

## Two Terminal classes

sugar-vterm ships **two** Terminal entry-points for distinct use cases —
both share the short class name `Terminal`, so import one per file or alias
the other (`use SugarCraft\Vterm\Terminal\Terminal as FullTerminal;`):

| Class | Use case | Methods |
|-------|----------|---------|
| `SugarCraft\Vterm\Terminal\Terminal` | Full VT500 emulator — parses CSI/OSC/DCS, maintains Sgr/Mode/Hyperlink/Scrollback. | `new(cols, rows, ?scrollbackSize)` factory (named args preferred: `Terminal::new(cols: 80, rows: 24, scrollbackSize: 5000)`; the constructor behind it is `__construct(cols, rows, ?Buffer, ?Cursor, ?Mode, scrollbackSize = 1000, ?onFocusEvent)`, so direct `new Terminal(80, 24, 5000)` TypeErrors on `?Buffer`, not `?int`), `feed(bytes, ?respond)`, `feedAsync(bytes): PromiseInterface<string>`, `feedStream(ReadableStreamInterface, ?respond, ?cancellation): PromiseInterface<string>`, `replies()`, `flush()`, `screen()`, `cursor()`, `mode()`, `windowTitle()` (null until the first title), `palette()`, `clipboardEvents()` and `focusEvents()` (both drain-on-read), `resize(cols, rows)`, `isWrapPending()`, `bells(): int` (emulator-only, monotonic across RIS — see the §BEL note), `enableAltScreen()`/`disableAltScreen()`/`isAltScreen()`, plus `with*()` builders — `withTabStops`, `withScrollbackSize` and `withCellPixels` are supported API (`withCellPixels($widthPx, $heightPx)` sizes the advisory XTWINOPS 14t/16t replies), while `withBuffer`, `withCursor`, `withMode` and `withWindowTitle` carry an `@internal` mark in `src/Terminal/Terminal.php`: they are composition seams for tests and the parser path, not supported entry points. `create()` is deprecated — use `new()`. |
| `SugarCraft\Vterm\Terminal` (root) | Lightweight emulator used by candy-vcr's renderer — produces `Snapshot` value objects directly. | `new(cols, rows, ?Theme)` factory (e.g. `Terminal::new(80, 24, $theme)` or named `theme:`; the constructor behind it is `__construct(cols, rows, Buffer, Cursor, Parser, CsiHandlerImpl, OscHandlerImpl, ?Theme)` — five required injected collaborators, so a positional scrollback int like the full-path idiom `new(80, 24, 5000)` TypeErrors on `?Theme` here), `theme()`, `feed(bytes): self` (mutates in place, returns `$this`), `snapshot(float $time = 0.0): Snapshot` (deep-copies the grid), `revision(): int`, `cursor(): Cursor`, `grid(): Buffer`, `windowTitle(): string` (empty until the first title), `isWrapPending(): bool`, `flush(): void` (drains the parser so trailing OSC side effects land before the next `snapshot()`). `enableAltScreen()`/`disableAltScreen()` exist for interface parity but ALWAYS throw `\LogicException` — alt-screen machinery lives only on the full `Terminal\Terminal`. |

```php
use SugarCraft\Vterm\Terminal;       // root — renderer path
use SugarCraft\Vterm\Theme;

$vt = Terminal::new(cols: 80, rows: 24, theme: Theme::tokyoNight());
$vt = $vt->feed("\x1b[1;1H\x1b[31mhello\x1b[0m");
$snapshot = $vt->snapshot(time: 1.234);
```

The root `Terminal` MUTATES in place — `feed()` writes into the terminal's
own grid and returns `$this` purely for chaining (it is not an immutable
fluent builder). Frames are frozen explicitly: `snapshot()` deep-copies the
grid, so a captured `Snapshot` never retro-mutates as later feeds land. The
root path uses the `HandlerAdapter` + `CsiHandlerImpl` + `OscHandlerImpl`
triple internally. The full `Terminal\Terminal` also mutates in place
(`feed(): void`) — it's optimised for byte-stream ingest rather than
per-frame snapshots.

### Renderer value objects (vcr path)

The `SugarCraft\Vterm` root namespace provides simplified value objects for the
candy-vcr VHS renderer path — independent of the full VT parser stack:

```php
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Cell;
use SugarCraft\Vterm\Cursor;

// Cell — char + fg (0-255) + bg (0-255) + attrs bitfield
$cell = new Cell(char: 'X', fg: 196, bg: 21, attrs: Cell::ATTR_BOLD);
$cell = $cell->withFg(34);            // green foreground
$cell = $cell->withBg(226);           // yellow background
$cell = $cell->withAttrs(Cell::ATTR_ITALIC | Cell::ATTR_UNDERLINE);

// Buffer — the one 2D cell grid (emulator + renderer), mutable put(), dirty-region tracking
$grid = new Buffer(cols: 80, rows: 24);
$grid->put(0, 0, new Cell(char: 'H')); // in place, returns void
$grid->put(0, 1, new Cell(char: 'i'));
echo $grid->cell(0, 0)->char;         // 'H'
echo implode(',', $grid->dirtyRegion()); // minRow, maxRow, minCol, maxCol
// On a never-dirtied grid those four are the sentinels (minRow/minCol =
// PHP_INT_MAX, maxRow/maxCol = -1 — Buffer.php), so test emptiness with
// maxRow < minRow, not by arithmetic on the corners.
$grid = $grid->clear();                // NEW empty grid, same size; dirty box resets
$grid = $grid->resize(100, 40);        // NEW grid; content within bounds kept,
                                       // cuts discarded, edge wide-base erased,
                                       // whole viewport marked dirty for repaint

// Cursor — row + col + shape + visibility
$cursor = new Cursor(row: 0, col: 0, shape: 0, visible: true);
$cursor = $cursor->at(5, 10);        // move to row 5, col 10
$cursor = $cursor->withShape(2);      // shape 2 = steady block
$cursor = $cursor->hidden();           // hide cursor
$cursor = $cursor->shown();          // show cursor
```

### Parser handlers

Each handler translates parser actions into handler state mutations:

- `CursorHandler` — CUP, HVP, CUU/CUD/CUF/CUB, CR, LF/VT/FF, BS, RI, HOME, DECSC/USC
- `SgrHandler` — SGR sequences (colour + attributes)
- `EraseHandler` — ED, EL, ECH
- `ScrollHandler` — SU, SD, DECSTBM
- `ModeHandler` — DECSET/DECRST private modes only (`?`-prefixed; the
  extended SM/RM mode numbers 10/19/20/29 are not implemented — bare forms
  stay inert on both engines; includes DECAWM mode 7)
- `TabHandler` — TBC, HTS
- `OscHandler` — OSC window title, hyperlink, colour palette
- `ScreenHandler` — orchestrates all of the above; owns the Buffer

### Snapshot

`SugarCraft\Vterm\Snapshot` is an immutable point-in-time grid + cursor +
feed revision. Captures through `Snapshot::of()` or `Terminal::snapshot()`
deep-copy the grid, so a snapshot keeps showing the frame as it was even
after the terminal consumes more bytes:

```php
use SugarCraft\Vterm\Snapshot;
use SugarCraft\Vterm\Terminal;

$terminal = Terminal::new(cols: 80, rows: 24)->feed("hello\r\n");

$snap = Snapshot::of($terminal, time: 1.234);  // deep-copies grid + cursor
$snap->grid;       // Buffer — frozen copy, unaffected by later feeds
$snap->cursor;     // Cursor
$snap->time;       // float — virtual playback time
$snap->revision;   // int — terminal's feed counter at capture time

$terminal->feed("world\r\n");                  // live grid moves on...
$snap->grid->cell(1, 0)->grapheme;             // ' ' — snapshot's row 1 stayed blank

$replay = Snapshot::of($terminal, time: 1.234);

$snap->equals($replay);          // false — the second feed changed the grid
$snap->equalsWithTime($replay);  // false — grid + cursor + time strict check
```

Build a `Snapshot` directly from its constructor only when you already own
the Buffer — the constructor stores what it is given without copying.

`Snapshot::equals()` is the dedup signal used by candy-vcr's
`FrameDedup` to collapse visually identical adjacent frames before GIF
encoding. `Terminal::revision()` gives the cheaper first pass: two captures
with the same revision saw no intervening write path at all.

## Parser state machine

The VT500 state machine is **not** in this package — it is owned by
[sugar-ansi-parser](../sugar-ansi-parser/) as
`SugarCraft\AnsiParser\Parser\Parser`, a Paul Williams machine
([reference](https://vt100.net/emu/dec_ansi_parser)) that drives a
`AnsiParser\Parser\Handler` interface with parsed actions. sugar-vterm
consumes it directly — both `Terminal\Terminal` and the root `Terminal`
import `SugarCraft\AnsiParser\Parser\Parser` by `use` statement
(`src/Terminal/Terminal.php`, `src/Terminal.php`) and feed it the same
byte chunks; what lives here is the screen-side
handler layer that the parser drives.

| Component | Owner | Role |
|-----------|-------|------|
| `AnsiParser\Parser\State` | sugar-ansi-parser | Enum of the 15 parser states: `Ground`, `Escape`, `EscapeIntermediate`, `CsiEntry`, `CsiParam`, `CsiIntermediate`, `DcsEntry`, `DcsParam`, `DcsIntermediate`, `DcsString`, `OscString`, `SosString`, `PmString`, `ApcString`, `Utf8`. No `CsiIgnore` state exists — ignored CSI bytes are handled inside the transition table. |
| `AnsiParser\Parser\Action` | sugar-ansi-parser | Enum of dispatch actions: `Print`, `Execute`, `CsiDispatch`, `EscDispatch`, `OscDispatch`, `DcsDispatch`, `Hook`, `Put`, `Unhook`. |
| `AnsiParser\Parser\Transitions` | sugar-ansi-parser | Static transition table — `(state, byte) → (action, next_state)`. |
| `AnsiParser\Parser\Handler` | sugar-ansi-parser | The contract the parser drives. |
| `AnsiParser\Parser\CsiHandler` | sugar-ansi-parser | CSI dispatch contract — `printable`, `cuu`/`cud`/`cuf`/`cub`, `cup`/`hvp`, `sgr`, `ed`/`el`, `decset`/`decrst`, `decstbm`, `tbc`, `cht`/`cbt`, `cr`/`lf`, `su`/`sd`, `il`/`dl`, `ich`/`dch`, `rep`, `scosc`/`scorc`, `gridRows`/`gridCols` — 29 members, matching `sugar-ansi-parser/src/Parser/CsiHandler.php`. |
| `AnsiParser\Parser\OscHandler` | sugar-ansi-parser | OSC dispatch contract — `title`, `hyperlink`. |
| `AnsiParser\Parser\HandlerAdapter` | sugar-ansi-parser | Glue that wires `CsiHandler` + `OscHandler` into a single `Handler` for the parser. |
| `Parser\CsiHandlerImpl` | sugar-vterm | Default CSI handler used by the root `Terminal` renderer path. |
| `Parser\OscHandlerImpl` | sugar-vterm | Default OSC handler — tracks last window title. |
| `Parser\DebugHandler` | sugar-vterm | Local debug sink that dumps every parsed action. |
| `Parser\RendererHandler` | sugar-vterm | Screen-writing handler for the renderer path. |

Partial input is supported — feed any byte boundary and the parser
state persists across `feed()` calls. Subparameter colons
(`CSI 4:2 m` → double underline) are parsed natively.

The full emulator also speaks ReactPHP: `feedAsync()` parses a chunk and
resolves with the terminal→host reply bytes (DA1/CPR/XTWINOPS …) instead of
requiring the `feed($bytes, $respond)` callback, and `feedStream()` pumps a
`ReadableStreamInterface` (PTY output, socket) through the same parser —
incrementally per `data` event, flushed at `end`, rejecting on `error` or a
close that never signalled end-of-stream. The synchronous `feed()` surface is
unchanged; these are additive.

## CSI coverage table

The default `CsiHandlerImpl` (used by `SugarCraft\Vterm\Terminal`) implements
the renderer-relevant subset of CSI dispatches. The full
`Handler\ScreenHandler` (used by `SugarCraft\Vterm\Terminal\Terminal`)
implements the superset.

| Final byte | Name | Method | Behaviour |
|------------|------|--------|----------|
| `@` | ICH | `ich($n=1)` | Insert N blank characters at cursor. |
| `A` | CUU | `cuu($n=1)` | Cursor up N, clamped to scroll region top. |
| `B` | CUD | `cud($n=1)` | Cursor down N, clamped to scroll region bottom. |
| `b` | REP | `rep($n=1)` | Repeat the preceding printable N times (renderer only; the emulator CSI dispatcher has no `b` arm and discards it). |
| `C` | CUF | `cuf($n=1)` | Cursor right N, clamped to right margin. |
| `D` | CUB | `cub($n=1)` | Cursor left N, clamped to column 0. |
| `E` | CNL | (Handler) | Cursor down N + column 0. |
| `F` | CPL | (Handler) | Cursor up N + column 0. |
| `G` | CHA | (Handler) | Cursor to column N. |
| `H` | CUP | `cup($row, $col)` | Move cursor to (row, col) 1-indexed. |
| `I` | CHT | `cht($n=1)` | Cursor forward N tab stops (emulator honours the tab-stop table; the renderer offsets the column forward by N, clamped to the last column, ignoring tab stops entirely). Shadowed while `?1004 h` is set — the focus intercept consumes any `CSI Ps I` as focus-in before tab handling runs. |
| `J` | ED | `ed($mode=0)` | Erase display: 0=cursor→end, 1=begin→cursor, 2=all, 3=all+scrollback (emulator clears the ring; the renderer has no ring, so 3 blanks the grid). |
| `K` | EL | `el($mode=0)` | Erase line: 0=cursor→eol, 1=bol→cursor, 2=full line. |
| `L` | IL | `il($n=1)` | Insert N blank lines at cursor. |
| `M` | DL | `dl($n=1)` | Delete N lines at cursor. |
| `P` | DCH | `dch($n=1)` | Delete N characters at cursor. |
| `S` | SU | `su($n=1)` | Scroll up N lines. |
| `T` | SD | `sd($n=1)` | Scroll down N lines. |
| `X` | ECH | (Handler) | Erase N characters from cursor (preserves BCE bg). |
| `Z` | CBT | `cbt($n=1)` | Cursor backward N tab stops (emulator honours the tab-stop table; the renderer offsets the column back by N, clamped to column 0, ignoring tab stops entirely). |
| `d` | VPA | (Handler) | Cursor to row N. |
| `f` | HVP | `hvp($row, $col)` | Same as CUP — move cursor to (row, col). |
| `g` | TBC | `tbc($mode=0)` | Tab clear: 0=column, 3=all (renderer: no-op — tab stops unmodelled). |
| `h` | DECSET (private, `?`-prefixed only) | `decset($mode, $prefix)` | Set mode; bare SM without the `?` prefix is inert on both engines (the emulator gate and `CsiHandlerImpl::decset` refuse it). `?7 h` enables DECAWM, `?12 h` BCE, `?25 h` cursor visible, `?47/?1047/?1049 h` alt screen, `?1004 h` focus events, `?2026 h` synchronized output, `?6 h` DECOM, `?1000/?1001/?1002/?1003 h` mouse tracking, `?1005/?1006/?1015 h` mouse encoding, `?1048 h` cursor save/restore, `?2004 h` bracketed paste — full roster below. |
| `l` | DECRST (private, `?`-prefixed only) | `decrst($mode, $prefix)` | Reset mode (mirror of `h`; bare RM without the prefix is inert). |
| `m` | SGR | `sgr($params)` | Set graphic rendition. Subparam colons (`4:1`..`4:5`) parsed. |
| `r` | DECSTBM | `decstbm($top, $bottom)` | Set scroll region rows (1-indexed). |
| `s` | DECSC | `scosc()` | Save cursor + Sgr + origin mode (emulator); the renderer saves the cursor position only. |
| `u` | DECRC | `scorc()` | Restore cursor + Sgr + origin mode (emulator); the renderer restores the position only. |
| `<n> SP q` | DECSCUSR | (Handler) | Cursor shape; the 0–6 vocabulary maps to block/underline/bar × blink, out-of-vocabulary values are stored verbatim (unclamped). |
| `I` / `O` | Focus | (Handler) | Focus-in / focus-out recorded on the params-less `CSI I` / `CSI O` form real terminals emit under `?1004 h`. While that mode is set the intercept runs before tab handling, so a parameterised `CSI Ps I` is consumed as focus-in and CHT is unreachable on the emulator path. |

`(Handler)` rows live in the full `Handler/ScreenHandler` dispatcher used
by `SugarCraft\Vterm\Terminal\Terminal`; the renderer's upstream
`HandlerAdapter` does not route those final bytes, so the renderer path
silently discards them. Rows with an explicit `Method` are implemented by
`Parser/CsiHandlerImpl` — the emulator implements a superset of those, save
the three asymmetries named below (one of them, `b` REP, is renderer-only).
Three asymmetries are deliberate and pinned out of
`tests/EngineDifferentialTest.php`'s corpus: tab handling (`I`/`Z`/`g` —
the emulator walks a tab-stop table, the renderer merely offsets the column
by N and ignores tab stops entirely, and `TBC` no-ops), `b` REP (renderer
only, the emulator discards it), and BCE
background-colour erase (`X` ECH and mode-3 scrollback clearing are
emulator-only). The renderer honours the same DECAWM/DECTCEM private
modes, origin-mode clamps, and SGR set as the emulator.

### DECSET/DECRST mode roster (emulator `ModeHandler`)

The full emulator's `Handler\ModeHandler` tracks these private modes (all
`?`-prefixed; bare SM/RM stays inert on both engines). Mouse and paste state
lands on `Mode` fields the library exposes for consumers to read — decoded,
never encoded:

| Mode | Effect on `set` (`h`)                                                    |
|------|--------------------------------------------------------------------------|
| 6    | `Mode::$originMode` — DECOM, cursor rows relative to the scroll region.  |
| 7    | `Mode::$autoWrap` — DECAWM wrap at the right margin.                     |
| 25   | DECTCEM cursor visibility (`setCursorVisible`).                          |
| 47   | Alt-buffer swap without save (`enterAltScreenNoSave`).                   |
| 1000 | `Mode::$mouseAny` — button press/release tracking flag.                  |
| 1001 | `Mode::$mouseHighlights` — highlight tracking flag.                      |
| 1002 | `Mode::$mouseCellMotion` — cell-motion tracking flag.                    |
| 1003 | `Mode::$mouseExtended` — any-event tracking flag.                        |
| 1004 | `Mode::$reportFocusEvents` — arms the `CSI I`/`O` focus intercept.       |
| 1005 | `Mode::$mouseEncoding = MouseEncoding::Utf8`; reset returns `Default`.   |
| 1006 | `Mode::$mouseEncoding = MouseEncoding::Sgr`; reset returns `Default`.    |
| 1015 | `Mode::$mouseEncoding = MouseEncoding::Urxvt`; reset returns `Default`.  |
| 1047 | Alt-buffer swap without save, same arm as 47.                            |
| 1048 | Cursor save/restore only (`enterAltScreenCursorOnly`) — no buffer swap.  |
| 1049 | Full alt screen (`enterAltScreen`): save cursor, switch, clear.          |
| 2004 | `Mode::$bracketedPaste` — flags that paste arrives bracketed.            |
| 2026 | `Mode::$syncUpdate` — synchronized output queue (16384-cell budget).     |

Modes 1005/1006/1015 share the one `MouseEncoding` slot: it is the decoder's
note of which reporting dialect a real terminal would use — the last setter
wins, any reset flips back to `Default`, and none of them change which
tracking modes (1000–1003) are armed. The library emits nothing for any mouse,
focus or paste mode; consumers read the `Mode` fields and build report bytes
themselves.

The `printable` and `execute` paths also route through the same
adapter — printable bytes call `CsiHandlerImpl::printable()` (writes
the cell and defers the wrap at the right margin, exactly like the
emulator's `ScreenHandler`), and C0 controls (`\b \t \n
\r`) call the corresponding cursor handler.

### Line rendition and character sets (`ESC` forms)

CSI sequences are only half the picture; two `ESC` families change how
glyphs are drawn and which glyphs a byte means:

| Sequence | Name | Effect. |
|---|---|---|
| `ESC # 3` | DECDHL top | Line renders as the top half of a double-height line. |
| `ESC # 4` | DECDHL bottom | Line renders as the bottom half of a double-height line. |
| `ESC # 5` | DECSWL | Line returns to single width/height (rendition `None`). |
| `ESC # 6` | DECDWL | Line renders double-width (a continuation cell follows each printed grapheme, when there is room). |
| `ESC ( <b>` / `ESC ) <b>` / `ESC * <b>` / `ESC + <b>` | SCS | Designate charset `<b>` into slot G0/G1/G2/G3. |
| `SO` (0x0E) / `SI` (0x0F) | LS1 / LS0 | Lock G1 (or back to G0) into the GL position for subsequent graphemes. |
| `SS2` / `SS3` (C1 0x8E/0x8F) | Single shift | Use G2/G3 for exactly the next grapheme. |

Line rendition is not a pen state — it is stamped on the cells
(`Cell::$rendition`), so it scrolls with the content and rides every
diff payload. Both engines implement `ESC #` (the emulator in
`Handler/ScreenHandler`, the renderer path via `RendererHandler` into
`CsiHandlerImpl::escLineRendition`); the designation and shift
machinery is emulator-side only — `ScreenHandler` holds the four
designatable slots (`$charsets`) plus the GL selection and pending
SS2/SS3, and restores all four slots to ASCII on `RIS`, `DECSTR`, and
`DECALN`. Only the GL-reachable designation translates 7-bit code points;
multi-byte UTF-8 always passes through untranslated.
`Charset\Charsets` carries the tables (ASCII `B`, DEC Special Graphics
`0`, UK `A`, No-Break Space `U`) and the `translate()` entry point.
`Mode::$altScreenVariant` (see Subsystems) is the third
previously-undocumented surface: DECSET `47`/`1047` swap buffers
without saving, `1048` saves only the cursor, and `1049` saves buffer +
cursor + SGR with a full restore.

## OSC coverage

The default `OscHandlerImpl` (renderer path) implements:

| Sequence | Method | Behaviour |
|----------|--------|----------|
| `OSC 0;<title> BEL/ST` | `title($title)` | Set window title. |
| `OSC 1;<name> BEL/ST` | `title($name)` | Set icon name — folded onto the same title channel; both engines route 0/1/2 into one slot and store no separate icon name. |
| `OSC 2;<title> BEL/ST` | `title($title)` | Set window title. |
| `OSC 8;<id>;<uri> BEL/ST` | `hyperlink($uri, $id)` | Begin/end hyperlink (OSC 8). Empty URI ends the link. |
| `lastTitle()` | accessor | Most recent title set during the stream. |

The full `Handler\OscHandler` (used by `SugarCraft\Vterm\Terminal\Terminal`)
also handles palette **set** (`OSC 4` — writes only, no query reply) and
clipboard get/set requests (`OSC 52`). The OSC 52 log is a bounded ring:
a remote program can arm OSC 52 itself, so past 1024 entries the oldest
event is dropped. Drain it with `Terminal::clipboardEvents()`, which
returns and clears the backlog (like `replies()`). `OSC 10`/`OSC 11`
(default foreground/background query and set) are discarded — the
emulator keeps no host-window or default-colour state. Focus and
mode-query events are CSI-driven, not OSC: see the focus-reporting and
DEC 2026 sections below.

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
| `5` / `6` | Blink (slow and rapid fold onto one bit). |
| `7` | Inverse (swap fg/bg). |
| `8` | Hidden (conceal) — emulator tracks it, but `Screen::lines()` is a TEXT channel: concealed text still appears in extracted content, so spoiler/secrets hygiene is the consuming host's job. |
| `9` | Strikethrough. |
| `21` | Accepted and ignored — the SGR handler has no arm for it. |
| `22` | Reset bold + faint. |
| `23` / `24` / `25` / `27` / `28` / `29` | Reset italic / underline / blink / inverse / hidden / strikethrough. |
| `30..37` / `90..97` | Set 8 + 8 bright ANSI foreground. |
| `38;5;<n>` / `38;2;<r>;<g>;<b>` | 256-color / truecolor foreground. |
| `40..47` / `100..107` | Set 8 + 8 bright ANSI background. |
| `48;5;<n>` / `48;2;<r>;<g>;<b>` | 256-color / truecolor background. |
| `39` / `49` | Reset fg / bg to default. |
| `58;5`/`58;2` / `59` | Underline-colour specifiers consumed and discarded (no per-style colour state). |

Attribute bitfield exposed as `Cell::ATTR_*` constants:
`ATTR_BOLD`, `ATTR_ITALIC`, `ATTR_UNDERLINE`, `ATTR_INVERSE`,
`ATTR_STRIKETHROUGH`.

### Accessibility (blink and motion)

SGR `5` and `6` (slow/rapid blink) fold onto ONE `Sgr::$blink` bit, and
only the emulator tracks it — the renderer path's cell attribute model
carries no blink bit at all. DECSCUSR (`CSI <n> SP q`) records the
blinking-versus-steady cursor shapes verbatim in the `CursorShape`
enum. Nothing in vterm animates; it only records. A host that lights a
blink or a blinking cursor from that record should honour the user's
no-blink preference and `prefers-reduced-motion` (WCAG 2.2.2 Pause,
Stop, Hide): suppress the animation and render the steady form. The
sugarcraft site's reduced-motion chrome covers page transitions only —
the VHS demo GIF loops regardless of viewer settings.

## Background Color Erase (BCE)

When BCE mode is enabled (`CSI ?12 h`), erase operations (`CSI K`, `CSI J`,
`CSI X`) fill cleared cells with the current SGR background color instead of
a plain blank cell. This prevents "ghosting" in terminals with coloured
backgrounds — the erased area retains its visual background rather than going
black.

```php
use SugarCraft\Vterm\Color\Color;  // color value object
use SugarCraft\Vterm\Terminal\Terminal;

$vt = Terminal::new(cols: 80, rows: 24);
$vt->feed("\x1b[48;5;196m\x1b[?12h");  // red background + BCE on
$vt->feed("\x1b[2J");                   // erase screen — cells carry red bg

$bg = $vt->screen()->cell(0, 0)->background();  // ?Color on the erased cell
echo $bg->value, "\n";                  // 196 — palette slot ($bg->kind is 2 = indexed-256)
echo Color::truecolor(255, 0, 0)->red(), "\n"; // 255 — truecolor splits via red()/green()/blue()
```

BCE state is tracked via the `Sgr` object's background color — the pen
must be set before the erase sequence for the background to carry through.

## Diff API

After feeding bytes, snapshot the screen and compare:

```php
use SugarCraft\Vterm\Terminal\Terminal;

$vt = Terminal::new(cols: 80, rows: 24);
$vt->feed("before\r\n");
$before = $vt->screen();

$vt->feed("after\r\n");
$after  = $vt->screen();

foreach ($before->diff($after) as $change) {
    // Each change is ['row' => int, 'col' => int, 'prev' => Cell, 'next' => Cell].
    echo "{$change['row']},{$change['col']}: '{$change['prev']->grapheme}' → '{$change['next']->grapheme}'\n";
}
```

## Auto-wrap (DECAWM)

DECAWM (`CSI ? 7 h` / `CSI ? 7 l`) controls whether the cursor
automatically wraps to the next line when a character is printed at the
rightmost column. The `Mode` object exposes this as:

```php
use SugarCraft\Vterm\Terminal\Terminal;

$vt = Terminal::new(cols: 80, rows: 24);

// Query the current state.
$mode = $vt->mode();
var_dump($mode->autoWrap);  // bool

// Build a new mode with auto-wrap forced on/off.
$wrapped = $mode->withAutoWrap(true);
```

When auto-wrap is **on** (the default, matching xterm/VT/ANSI.SYS), a grapheme landing in the
rightmost column parks the cursor there arming a deferred (phantom)
wrap; the next grapheme consumes it, moving to column 0 of the next row
before writing — and if that row is within a scroll region the region
scrolls up by one line, matching VT100 behaviour. When **off**, the
cursor clamps at the rightmost column and subsequent graphemes overwrite
it (the phantom flag is set by geometry alone, so a mid-stream `?7l` /
`?7h` toggles like xterm).

Scroll regions (DECSTBM, `CSI r`) and auto-wrap interact correctly:
wrapping at the bottom of a scroll region triggers a scroll within that
region, not on the whole buffer.

## SGR underline styles

SGR `CSI 4:N` controls the underline style. The `Sgr` object exposes
this as an `UnderlineStyle` enum and a fluent setter:

```php
use SugarCraft\Vterm\Sgr\Sgr;
use SugarCraft\Vterm\Sgr\UnderlineStyle;

// Build an Sgr state with a specific underline style.
$sgr = Sgr::empty()->withUnderlineStyle(UnderlineStyle::Double);
echo $sgr->underline;           // true
echo $sgr->underlineStyle->value; // 2 (Double)
```

`UnderlineStyle` values map to CSI sequences as follows:

| Enum value | CSI sequence | Description      |
|------------|--------------|------------------|
| `None`     | `CSI 4:0 m`  | No underline     |
| `Single`   | `CSI 4:1 m`  | Single underline |
| `Double`   | `CSI 4:2 m`  | Double underline |
| `Curly`    | `CSI 4:3 m`  | Curly underline  |
| `Dotted`   | `CSI 4:4 m`  | Dotted underline |
| `Dashed`   | `CSI 4:5 m`  | Dashed underline |

`CSI 24 m` clears underline (any style). Plain `CSI 4 m` (no subparam)
is equivalent to `4:1` (single).

## Scrollback buffer

When the screen scrolls (LF at the bottom, DECSTBM scroll region, or
auto-wrap at the bottom of a region), rows pushed off the top are stored
in a ring-buffer `Scrollback`. Access it via:

```php
use SugarCraft\Vterm\Terminal\Terminal;

$vt = Terminal::new(cols: 80, rows: 4);
foreach (range(1, 6) as $n) {
    $vt->feed("line $n\r\n");  // each LF at the bottom row scrolls one off: lines 1-3 land in the ring
}

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
use SugarCraft\Vterm\Terminal\Terminal;

// Set at construction.
$vt = Terminal::new(cols: 80, rows: 24, scrollbackSize: 5000);

// Retrospective change — existing scrollback is replaced.
$vt = $vt->withScrollbackSize(5000);
```

Available `Scrollback` accessors:

```php
use SugarCraft\Vterm\Terminal\Terminal;

$scrollback = Terminal::new(cols: 80, rows: 24)->screen()->scrollback();

$scrollback->count();     // int — rows currently stored
$scrollback->maxSize();   // int — configured capacity
$scrollback->at(0);       // array<int, Cell>|null — row offset N from oldest
$scrollback->all();       // array<int, array<int, Cell>> — all rows oldest→newest
$scrollback->clear();     // void — drop every stored row (ED mode 3, `CSI 3 J`)
```

The scrollback is consumed by `ScreenHandler`'s scroll-up and scroll-down
operations (SU/SD/IND/RI/NEL). Erase-of-scrollback (`CSI 3 J`) clears the
ring buffer.

## Origin mode (DECOM)

DECOM (`CSI ?6 h` / `CSI ?6 l`) controls whether cursor addressing is
relative to the absolute screen origin or to the scroll region defined by
DECSTBM. The `Mode` object exposes this as:

```php
use SugarCraft\Vterm\Terminal\Terminal;

$vt = Terminal::new(cols: 80, rows: 24);

$mode = $vt->mode();
var_dump($mode->originMode);  // bool

$origin = $mode->withOriginMode(true);
```

When origin mode is **on**, the cursor is constrained to the scroll region
and cursor-addressing commands (`CSI row;col H`, `CSI row;col f`) treat
`(1,1)` as the top-left of the DECSTBM region. When **off** (the default),
`(1,1)` is the absolute top-left of the screen. Leaving origin mode
(`CSI ?6 l`) flips only the mode: the DECSTBM region stays armed and is
re-adopted when origin mode is re-enabled.

## Cursor shape (DECSCUSR)

DECSCUSR (`CSI Ps SP q`) sets the terminal cursor shape. The handler records it
on **two** fields — `Cursor::$shape`, the copy a renderer would read, and
`Mode::$cursorShape`, the copy mode state carries — which the handler writes
together and keeps equal across every rebuild of the cursor. `Mode` exposes the
value as an integer:

```php
use SugarCraft\Vterm\CursorShape;
use SugarCraft\Vterm\Terminal\Terminal;

$vt = Terminal::new(cols: 80, rows: 24);

$mode = $vt->mode();
var_dump($mode->cursorShape);  // int — 0–6 for well-formed input, stored verbatim otherwise

// Build a new mode with a specific cursor shape.
$bar = $mode->withCursorShape(CursorShape::SteadyBar->toInt());
```

The DECSCUSR parameter (`CSI <n> SP q`) maps to `CursorShape` cases. In VT
vocabulary `0` means "default appearance" and is a blinking block, and
`CursorShape::fromInt()` folds both `0` and `1` onto `BlinkingBlock`:

| Enum value          | CSI param    | Sequence        | Description           |
|---------------------|--------------|-----------------|-----------------------|
| `BlinkingBlock`     | `0` or `1`   | `CSI 0/1 SP q`  | Blinking block        |
| `SteadyBlock`       | `2`          | `CSI 2 SP q`    | Steady block          |
| `BlinkingUnderline` | `3`          | `CSI 3 SP q`    | Blinking underline    |
| `SteadyUnderline`   | `4`          | `CSI 4 SP q`    | Steady underline      |
| `BlinkingBar`       | `5`          | `CSI 5 SP q`    | Blinking vertical bar |
| `SteadyBar`         | `6`          | `CSI 6 SP q`    | Steady vertical bar   |

The 0–6 vocabulary is **not enforced** at parse time: the handler stores the
raw `CSI Ps` parameter verbatim on both fields, so `CSI 9 SP q` records the
integer `9`. Only readers that convert through `CursorShape::fromInt()` see an
enum, and its default arm silently folds any unknown value onto
`BlinkingBlock` — an adversarial shape parameter round-trips as a rewritten
block rather than an error.

Across the reset and screen-swap paths the shape follows xterm-411: RIS
(`ESC c`) and DECSTR (`CSI ! p`) reset it to `0` on both fields, while DECALN
(`ESC # 8`) and the alt-screen swaps (DECSET 1048/1049) preserve it. Injecting
both a `Cursor` and a `Mode` into `ScreenHandler` is the one documented case
where the pair is taken exactly as supplied. DECSTR
resets it because xterm's soft reset shares RIS's cursor block and
`InitCursorShape()` recomputes the style from resources, so a DECSCUSR setting
does not outlive a soft reset.

## Focus events (mode 1004)

When focus event reporting is enabled (`CSI ?1004 h` / `CSI ?1004 l`),
the terminal records focus-in (`CSI I`) and focus-out (`CSI O`) events.
The `Mode` object tracks the reporting flag:

```php
use SugarCraft\Vterm\Terminal\Terminal;

$vt = Terminal::new(cols: 80, rows: 24);
$vt->feed("\x1b[?1004h");  // start reporting focus in/out

$mode = $vt->mode();
var_dump($mode->reportFocusEvents);  // bool — true under mode 1004

$tracking = $mode->withReportFocusEvents(true);
```

Events land in a bounded focus ring inside the handler (drop-oldest past
1024 entries — a hostile stream can arm mode 1004 and flood `CSI I`/`O`),
as `FocusInMsg` / `FocusOutMsg` value objects. The emulator facade drains
it: `Terminal::focusEvents()` returns the backlog oldest-first and clears
it, so poll after each feed. For live notification instead of draining,
pass an `onFocusEvent` callback to the constructor.

## Combining characters and wide characters

`ScreenHandler::printChar()` categorises every incoming code point by width:

- **Wide (width ≥ 2):** CJK and most emoji occupy 2 cells. A `Cell::continuation()`
  marker is written to the second cell and inherits SGR + hyperlink from the
  base cell. The cursor advances by the full width.
- **Normal (width 1):** Written to a single cell.
- **Combining mark (width 0):** Zero-width code points are not silently discarded.
  Attaching is driven by the sugar-core `Width::string` classification, not by
  a fixed codepoint range: every zero-width code point (U+0300–U+036F plus the full
  U+1AB0–U+1AFF, U+1DC0–U+1DFF, U+20D0–U+20FF, U+FE00–U+FE0F, U+FE20–U+FE2F
  ranges, variation selectors, ZWJ/ZWNJ — anything `Width::string` scores 0)
  is attached to the preceding cell via `Cell::withCombining()` so the base
  character and its marks render as a single composed grapheme in one column:

```php
use SugarCraft\Vterm\Terminal\Terminal;

$vt = Terminal::new(cols: 80, rows: 24);
$vt->feed("\x1b[31me\xcc\x81");  // 'e' + combining acute accent → 'é'
$cell = $vt->screen()->cell(0, 0);
echo $cell->grapheme, "\n";   // 'e'
echo $cell->combining, "\n";  // "\xcc\x81" — attach to grapheme for full cluster
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
update behaviour.

```php
use SugarCraft\Vterm\Terminal\Terminal;

$vt = Terminal::new(cols: 80, rows: 24);

$mode = $vt->mode();
var_dump($mode->syncUpdate);  // bool — false until synchronized output is set

$vt->feed("\x1b[?2026h");       // enter synchronized output mode
$vt->feed("\x1b[31mA\x1b[0m");  // queued, not yet visible
$vt->feed("\x1b[?2026l");       // flush → both cells appear together
var_dump($vt->screen()->cell(0, 0)->grapheme);  // 'A'
```

Mutations are queued on the handler's internal synchronized-output queue and replayed
atomically when the window closes. BCE erase (CSI ?12 h)
and combining-char attachments are both eligible for queued writes.
The queue is bounded: past 16384 pending mutations a partial flush lands
to the grid, so an adversarial flood costs memory safety before it costs
content — all-or-nothing is surrendered only under hostile pressure.

## Theme catalog

`Theme` provides a 256-color palette with factory methods for named themes.
`Themes` is the catalog listing all available themes:

```php
use SugarCraft\Vterm\Theme;
use SugarCraft\Vterm\Themes;

// Tokyo Night — the standard theme of the monorepo's 305 .tape demo files
$theme = Theme::tokyoNight();

// Index into the 256-color palette
$red = $theme->color(1);       // 0xf7768e

// Resolve RGB components from a 256-color index
$rgb = Theme::rgb(196);        // [255, 0, 0]

// Map ANSI 0-15 slot to its 256-color index
$idx = Theme::fgIndex(1);        // 1 (Ansi1 → index 1)

// V1-ready themes — every bundled theme carries a complete palette,
// so v1() currently lists the same five themes as all()
foreach (Themes::v1() as $name => $theme) {
    echo "$name\n";
}
// TokyoNight, TokyoNightLight, TokyoNightStorm, Dracula, SolarizedDark

// All themes in the catalog
foreach (Themes::all() as $name => $theme) {
    echo "$name\n";
}
// TokyoNight, TokyoNightLight, TokyoNightStorm, Dracula, SolarizedDark
```

Available themes:

| Theme | Status | Description |
|---|---|---|
| `TokyoNight` | ✅ v1 | Dark theme — the standard of the 305 monorepo tapes |
| `TokyoNightLight` | ✅ v1 | Light variant of TokyoNight |
| `TokyoNightStorm` | ✅ v1 | Storm variant of TokyoNight |
| `Dracula` | ✅ v1 | Full Dracula palette |
| `SolarizedDark` | ✅ v1 | Full Solarized Dark palette |

Attribute constants on `Theme` match `Cell::ATTR_*` for SGR bitfield construction:
`Theme::ATTR_BOLD`, `Theme::ATTR_ITALIC`, `Theme::ATTR_UNDERLINE`, `Theme::ATTR_INVERSE`, `Theme::ATTR_STRIKETHROUGH`.

### Theme accessors

| Method | Returns |
|--------|---------|
| `$theme->color($index)` (instance) | `int` — packed 24-bit RGB for palette slot 0..255. When a slot is absent, falls back through `Theme::rgb()` for any index — the standard xterm mapping (cube 16..231 and grayscale 232..255), not only the grayscale ramp. |
| `Theme::rgb($index)` | `array{int,int,int}` — `[R,G,B]` resolution for any 256-color index, including the 6x6x6 color cube (16..231) and the 24-step grayscale ramp (232..255). |
| `Theme::fgIndex($slot)` | `int` — Map an ANSI 0..15 slot to its 256-color index (currently a no-op identity, kept for spec parity). |
| `Theme::bgIndex($slot)` | `int` — Same for background slot. |
| `Theme::defaultPalette()` | `array<int,int>` — Bootstrap palette (xterm defaults); the `Theme` constructor falls back to it when no palette is passed (`Themes::ansi()` does not exist). |
| `Theme::ANSI_OFFSET` / `Theme::CUBE_OFFSET` / `Theme::GRAYSCALE_OFFSET` | int constants 0 / 16 / 232 — palette region boundaries. |

## Subsystems

Each subdirectory of `src/` owns a small piece of VT state:

| Namespace | Purpose | Public surface |
|-----------|---------|----------------|
| `Buffer\Buffer` | Cell grid storage (`rows × cols` array of `Cell`). | `cell($row, $col)` (either coordinate out of bounds → `Cell::empty()`), `put($row, $col, $cell)` (out-of-grid writes silently ignored), `insertRows($count, $from, ?$top, ?$bottom)`, `deleteRows($count, $from, ?$top, ?$bottom)`, `dirtyRegion()`, `each(): Generator`, `copy(): array`, `clear(): self` (blank the grid in place), `equals(self $other): bool` (grid-for-grid comparison), `resize($cols, $rows): self` (refuses dims < 1, repairs wide pairs at the cut, marks the whole viewport dirty). |
| `Screen\Screen` | Immutable snapshot of Buffer + Scrollback; cell-level diff API. | `fromBuffer($buf, ?$scrollback)`, `lines()` (Generator: full-width rows, combining marks re-appended), `linesWithHistory()` (Generator: scrolled-off rows oldest-first, then visible), `cell($row, $col)` (out-of-bounds in EITHER coordinate returns `Cell::empty()`), `scrollback()` (`?Scrollback` — null when built via `fromBuffer($buf)` without a ring; the full `Terminal\Terminal::screen()` path always wires one), `diff($other)`. |
| `Screen\Scrollback` | Ring buffer (default 1000 rows) for rows scrolled off the top. | `push($row)` (record a scrolled-off line, evicting the oldest at capacity), `count()`, `maxSize()`, `at($n)`, `all()`, `clear()` (drop every saved row — what `ED 3` calls). |
| `Cell\Cell` | Cell-grid cell — grapheme + Sgr + Hyperlink + combining marks. Used by the full Terminal facade. | `empty()`, `continuation($prev)`, `withCombining($s)`, `sgr()`, `foreground()`, `background()`, `equals()`. |
| `Cursor\Cursor` | Position + visibility + shape + saved-state. | `withRow($r)`, `withCol($c)`, `withVisible($v)`, `withShape($s)`, `save()`, `restore()`, `equals()`. |
| `Sgr\Sgr` | Pen state (fg, bg, attrs bitfield, underline style). | `empty()`, `withBold/Italic/Underline/Strikethrough/Blink/Reverse/Dim/Hidden($v)`, `withUnderlineStyle($style)`, `withForeground(?$color)`, `withBackground(?$color)`, `equals()`. |
| `Sgr\UnderlineStyle` | Enum: `None`/`Single`/`Double`/`Curly`/`Dotted`/`Dashed`. | Backed int enum (`None`=0 … `Dashed`=5); `->value` is the CSI code (no `fromInt()`). |
| `Rendition` (root enum) | Double-height/width line rendition (`ESC # 3`–`ESC # 6`), stamped on every cell of the line so it scrolls with content and rides each diff payload. | `None` (0) / `DoubleTop` (1) / `DoubleBottom` (2) / `DoubleWidth` (3), `isDoubleHeight()`. |
| `Charset\Charsets` | SCS character-set tables — G0–G3 designations (`ESC ( ) * +`), locking SO/SI, single-shift SS2/SS3; emulator-side only, 7-bit code points translate through the GL-reachable set, UTF-8 never. | `ASCII` ('B'), `DEC_SPECIAL` ('0'), `UK` ('A'), `NO_BREAK_SPACE` ('U'), `translate($charset, $rune)`. |
| `Mode\Mode` | DEC private modes. `altScreenVariant` (consts `ALT_NONE`/`ALT_NO_SAVE`/`ALT_CURSOR_ONLY`/`ALT_FULL`) records which of the four alt-screen meanings (`47`/`1047` swap only, `1048` cursor save only, `1049` full save+restore) a switch came from; `withAltScreen(bool)` maps to `ALT_FULL`/`ALT_NONE`. | `withAltScreen/AltScreenVariant/CursorVisible/MouseEncoding/MouseHighlights/MouseAny/MouseCellMotion/MouseExtended/BracketedPaste/SyncUpdate/AutoWrap/OriginMode/CursorShape/ReportFocusEvents`, `isAltScreen()`, `isMouseReporting()`, `equals()`. |
| `Hyperlink\Hyperlink` | OSC 8 URI + id state. | `fromRaw($id, $uri)`, `equals()`. |
| `Msg\FocusInMsg` / `FocusOutMsg` | Focus event records — land in the emulator's bounded focus ring (DEC mode `1004`) and drain via `Terminal::focusEvents()`. | Plain DTOs. |
| `Color\Color` | Color value object — palette index or truecolor RGB. | `default()`, `indexed16($i)`, `indexed256($i)`, `truecolor($r,$g,$b)`, `fromInt($kind,$v)`, `red()`, `green()`, `blue()`, `equals()`, `equalsOrBothNull(?a, ?b): bool` (static null-safe comparison: two nulls are equal, one null never is). |
| `CursorShape` (root enum) | `BlinkingBlock` (0/1) / `SteadyBlock` (2) / `BlinkingUnderline` (3) / `SteadyUnderline` (4) / `BlinkingBar` (5) / `SteadyBar` (6). | `fromInt()`, `toInt()`. |

## Trust boundary and hostile input

Both facades exist to parse bytes emitted by UNTRUSTED programs, so every
unbounded channel is capped and nothing leaves the grid unsanitised:

- **Parser string buffer** — `Terminal::MAX_STRING_BUFFER` (64 KiB, shared by
  both facade parsers). OSC/DCS payloads past the cap are truncated, so no
  per-stream allocation grows with attacker-chosen length.
- **Replies** — the emulator's query-reply queue is a 1024-entry drop-oldest
  ring; under a DA/DSR flood an awaited reply can be evicted, so drain
  `replies()` (or pass the `?respond` callback) promptly.
- **Clipboard / focus events** — OSC 52 and DEC 1004 are *self-armed* by the
  remote program (no capability negotiation), so both logs are 1024-entry
  drop-oldest rings. `clipboardEvents()` and `focusEvents()` drain-on-read.
- **Synchronized output (DEC 2026) queue** — at most 16384 pending cell writes; past that the
  queue partially replays to the grid. Atomicity is surrendered only under
  adversarial pressure, never silently dropped.
- **Scrollback** — bounded ring (default 1000 rows); `withScrollbackSize()`
  drops oldest rows when shrinking. The renderer path keeps no scrollback.
- **Stored strings are attacker text** — window titles, OSC 8 hyperlinks and
  OSC 52 payloads are kept VERBATIM; consumers must sanitise before display
  or onward propagation (`Sanitize::untrusted()` upstream).
- **Discarded without parsing** — DCS, SOS, PM and APC payloads; malformed
  UTF-8 bytes (the upstream tokenizer emits no print event for them, so the
  grid only ever holds decoded graphemes).
- **BEL** — counted via `bells()` on the emulator path only; the renderer
  discards BEL upstream in the adapter before any sink exists.
- **One-way doors** — mouse, focus and bracketed-paste sequences are DECODED
  only; the DECSET/DECRST roster above names every mode bit the emulator
  tracks and none of them produce outbound bytes. `Mode\MouseEncoding`
  describes the decoder slot; the library ships
  no encoders, so programs emitting those sequences build their own bytes.
- **No I/O** — `src/` never writes streams, spawns processes, or touches the
  filesystem; `feedStream()` merely accepts one.
- **Renderer silences** — the CSI finals the parser adapter does not route
  (E F G d X c n t p q, SP q, and OSC 4/10/11/52) are discarded on the
  renderer path; the CSI/OSC coverage tables above are the per-sequence
  ledger for which engine implements what.

## Development

```sh
# from the monorepo root: wire siblings to the local tree (CI injects the same path-repo closure)
php scripts/refresh-deps.php --mode=linked --libs=sugar-vterm
cd sugar-vterm
vendor/bin/phpunit                                              # test suite
vendor/bin/phpstan analyze                                      # static analysis (level: max)
vendor/bin/php-cs-fixer fix --config=../.php-cs-fixer.dist.php  # lint + auto-fix style
```

## Snapshot tests

Render output is covered by golden-file snapshot tests. The fixture set is
exactly two files in `tests/fixtures/` — `terminal-cleared.golden` and
`terminal-parsed-text.golden` — both compared against actual ANSI byte
output by `tests/GoldenRenderTest.php` via
`SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi()`; no other
fixture files are read. To re-record a fixture after an intentional output
change, delete it and re-run the test — `assertGoldenAnsi()` writes the
file when it is missing (there is no env-var override):

```sh
rm tests/fixtures/<name>.golden
vendor/bin/phpunit --filter <TestName>
```

Code style is enforced by `php-cs-fixer` via the root `.php-cs-fixer.dist.php` (PSR-12 + `declare_strict_types` + `strict_param` + short array syntax). Append `--dry-run --diff` to preview without writing.

## Related

- **[candy-vcr](../candy-vcr/)** — VHS-compatible cassette recorder /
  GIF renderer. Uses this lib's root `Terminal` + `Snapshot` to drive
  every rendered frame.
- [SugarCraft monorepo](https://github.com/detain/sugarcraft)

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
