# candy-vt Caliber Learnings

Accumulated patterns and gotchas for this library.

---

## Parser table

The VT500 state machine transition table is generated in PHP at first
use as a 4096-byte string (15 states × 256 codes), packed one byte per
entry as `(action << 4) | nextState`. State and Action enums hold the
canonical numeric values; their order is load-bearing because the
transition table is keyed by `state.value << 8 | byte`. The build
function in `Transitions::build()` is a direct port of upstream
`charmbracelet/x/ansi/parser.GenerateTransitionTable` — keep them in
sync if upstream changes the table.

## ESC dispatch is separate from CSI dispatch

Two-byte ESC sequences (e.g. ESC D = IND, ESC 7 = DECSC) emit
`Handler::escDispatch($final, $intermediate)`, not `csiDispatch` or
`execute`. Downstream handlers translate ESC + 0x40-0x5F to the
equivalent C1 control if they want; the parser stays neutral.

## ESC \ (ST) emits a trailing escDispatch

When OSC/DCS/SOS/PM/APC are terminated by `ESC \` (ST in 7-bit form),
the parser dispatches the string sequence on the ESC, transitions to
Escape state, then dispatches the trailing `\` as `escDispatch(0x5C)`.
Most consumers ignore it. BEL and 8-bit ST (0x9C) terminators don't
have this trailing event.

## UTF-8 multi-byte handling

Lead bytes (0xC2-0xF4) transition to a synthetic `State::Utf8` and the
parser accumulates continuation bytes (0x80-0xBF) internally. The full
rune arrives at the handler as a single `printChar(string $rune)` call
— callers don't need to reassemble bytes themselves. An ASCII byte
arriving mid-rune drops the partial sequence and processes the byte
fresh from Ground.

## CSI default params surface as -1

`\x1b[;H` produces `params = [-1, -1]` (two slots, both default).
`\x1b[1;H` produces `[1, -1]`. Sentinel `-1` means "missing/default";
handlers downstream should resolve to spec-defined defaults
(`?: 1` for cursor moves, `?: 0` for SGR, etc.).

## CSI subparameters — ':' as sub-param separator (step 07.03)

VT500 sequences use `:` as a sub-parameter separator (e.g. `CSI 38:2:R:G:B`
for truecolor with subparams). In `Parser::param()`, both `;` (0x3B) and
`:` (0x3A) start a new param slot. Subparams surface as additional entries
in `$this->params` — handlers that need them consume them from the param
array directly. No separate subparam storage is maintained; the flat
`params` array is passed as-is to `csiDispatch`.

## ScreenHandler holds mutable state; Terminal delegates

`ScreenHandler` owns `Buffer` (mutable in place), `Cursor` / `Sgr` /
`Mode` (immutable values, reassigned on update), and `?string $windowTitle`.
`Terminal::feed()` drives bytes through the Parser; accessors delegate
to the handler. `Terminal::__clone()` deep-clones the handler so that
`withCursor()` / `withMode()` etc. don't share mutable state with the
original instance — without that, mutating one Terminal's cursor
would silently affect every Terminal cloned from it.

## SGR sub-handlers are stateless

`SgrHandler::apply($params, $current)` and
`CursorHandler::apply($final, $params, $cursor, $buffer)` take state
as input and return the new state. ScreenHandler owns the only stateful
copy; sub-handlers are reusable across handlers and tests. New CSI
final-byte routes (erase, scroll, mode, OSC) should follow the same
shape so they unit-test in isolation.

## Auto-wrap is deferred; scroll lives in ScrollHandler (PR4)

> **DEPRECATED — DECAWM is now implemented (step 07.02).**
> This entry is retained for historical context only.

`ScreenHandler::printChar()` used to clamp the cursor at the right edge
rather than auto-wrapping. Vertical scroll lived in PR4: `LF` (and
`IND`, `NEL`, `ESC D`, `ESC E`) at the bottom row scrolled the buffer
up by one and kept the cursor at the bottom. `RI` / `ESC M` at the top
scrolled the buffer down. Scrolled-off rows were dropped; scrollback
comes later.

## DECAWM auto-wrap implementation (step 07.02)

DECAWM (`CSI ? 7 h` / `CSI ? 7 l`) is implemented as:

- `Mode::$autoWrap` — public readonly property on `Mode`
- `Mode::withAutoWrap(bool)` — fluent setter returning new `Mode`
- `ModeHandler` CSI `?7` dispatch — maps 7 → `withAutoWrap($set)`
- `ScreenHandler::printChar()` — when `$mode->autoWrap && $nextCol >= $cols`:
  wrap cursor to `(row + 1, 0)` then write; if the cursor is at
  `$scrollRegionBottom`, a scroll is triggered before the write so
  the new content appears on the newly scrolled-in line.

The scroll-region-aware path is: `printChar` detects wrap → calls
`ScrollHandler::index()` to scroll the region if needed → moves cursor
to column 0 of the next row → writes. This ensures wrapping at the
bottom of a scroll region scrolls that region, not the whole buffer —
matching VT100/xterm behavior.

## EraseHandler erase fills with BCE behavior (step 07.07)

`CSI K`, `CSI J`, `CSI X` erase operations fill cells via `fillRow()`:

- When `$sgr?->background !== null` (BCE mode, CSI ?12 h was sent):
  creates `Cell(grapheme: ' ', sgr: $sgr)` — blank cell carrying the
  current SGR so the erased region inherits the background color.
- When no SGR background is set: creates `Cell::empty()` — plain blank
  cell with no styling.

`CSI P` (DCH) and `CSI @` (ICH) always use `Cell::empty()` since they
shift cells rather than erasing a region.

## Mode field ↔ DEC private mode mapping (PR5)

`Mode`'s field names predate the DEC mode numbering and don't align
1:1. The canonical mapping `ModeHandler` uses:

| DEC mode | Mode field         | Notes                              |
|----------|--------------------|------------------------------------|
| 25       | `cursorVisible`    | also mirrored on `Cursor::visible` |
| 1000     | `mouseAny`         | X11 button-only                    |
| 1002     | `mouseCellMotion`  | button + drag motion               |
| 1003     | `mouseExtended`    | any motion (button or not)         |
| 1006     | `mouseSgr`         | SGR coordinate format              |
| 1049     | `altScreen`        | + buffer/cursor/sgr swap           |
| 2004     | `bracketedPaste`   |                                    |
| 2026     | `syncUpdate`       | batch queue + flush on disable    |

`Mode::$mouseHighlights` is reserved for 1001 (highlight tracking)
but no handler currently sets it. Don't read it as "highlights mode is
on"; read it as "1001 was sent to ModeHandler if/when wired."

## Alt-screen swap (DEC 1049) is held on ScreenHandler

Entering alt mode saves the current `Buffer`/`Cursor`/`Sgr` into
`ScreenHandler` private fields and replaces the active buffer with a
fresh blank one. Leaving restores them. Mutations on the alt buffer
don't bleed to the saved main state (it's a different `Buffer`
instance). Re-entering while already in alt mode is a no-op so it
won't clobber alt content; leaving without entering is a no-op too.

Resize while in alt mode currently resizes only the active (alt)
buffer; the saved main buffer keeps its old dimensions. Real terminals
typically resize both — revisit if a downstream TUI exercises this.

## OSC dispatch lives in OscHandler; ScreenHandler holds the slots (PR6)

`OscHandler::apply($data, ScreenHandler)` parses the OSC payload (the
text between `ESC ]` and the terminator) and writes into specific
public slots on the handler:

| OSC cmd  | Slot                              |
|----------|-----------------------------------|
| 0/1/2    | `windowTitle`                     |
| 4        | `palette[idx]` (`array<int, Color>`) |
| 8        | `currentHyperlink`                |
| 52       | `clipboardEvents[]` log            |

Unknown OSC commands and malformed payloads silently no-op — matches
xterm behavior. Read responses for OSC 52 (`?` payload) are recorded
as `kind=read` events; the parser doesn't synthesize the reply, so a
real PTY consumer needs to walk `Terminal::clipboardEvents()` and
emit a response itself.

## Hyperlink span attribution

`OscHandler` flips `ScreenHandler::$currentHyperlink` between a
`Hyperlink` instance and `null`. Every `printChar()` while non-null
attaches that exact reference to the new `Cell`. Cells written before
the hyperlink opened keep their previous hyperlink (typically null);
cells written after it closes get null again. There's no "hyperlink
ranges" structure — attribution is per-cell.

OSC 8 form is `OSC 8 ; params ; URI`. An empty URI (`OSC 8;;`) closes
the link. The `id=` param is parsed out of `params` if present;
other key=value pairs (separated by `:`) are ignored for now.

## OSC 4 color spec parsing

xterm's `rgb:RRRR/GGGG/BBBB` form allows 1-4 hex digits per component;
they're MSB-aligned. `OscHandler::scaleHexComponent()` scales to 8
bits by either left-padding (1 digit → high nibble) or right-shifting
to keep the most-significant byte. We also accept the CSS-style
`#RRGGBB` shorthand. Other formats (named colors, hsl, etc.) are
rejected — the entry is silently dropped.

## Width-aware printChar: wide chars take 2 cells, combining marks attach (step 07.07)

`ScreenHandler::printChar()` consults `SugarCraft\Core\Util\Width::string`
for each rune the parser emits.

- `width >= 2`: write the cell at `(row, col)` + `Cell::continuation()`
  cells at `col+1..col+width-1`, advance cursor by `width`. CJK and
  most emoji land here. The continuation inherits the original cell's
  SGR + hyperlink so style spans look right.
- `width == 1`: usual ASCII path.
- `width == 0`: combining marks (U+0300–U+036F) and certain ZWJ sequences
  that don't contribute width but should still render as part of the
  preceding character. These call `attachCombiningChar()` to modify the
  previous cell's grapheme rather than being silently discarded.
- Wide char that doesn't fit at the right edge: clamp cursor at
  `cols - 1` without writing. Auto-wrap lands when DECSTBM margins do.

## Fixtures are committed as binary `.ansi` files (PR8)

`candy-vt/tests/fixtures/*.ansi` are raw byte captures of small
realistic ANSI streams. They MUST be LF-only and MUST NOT be
git-normalised. The dir-local `.gitattributes` (`*.ansi binary`)
prevents CRLF rewriting on checkout.

To regenerate or add fixtures, write the bytes via `printf` rather
than an editor — most editors will silently insert/strip BOMs or
normalise newlines. Example:

```sh
printf '\x1b[31mR\x1b[0m' > tests/fixtures/example.ansi
```

`SnapshotTest` reads each fixture with `file_get_contents()` and
asserts specific cells/cursor/mode. Keep fixtures small (≤100 bytes
each) — they're for the Parser/handler integration sanity check, not
for full-frame golden images.

## Fuzzer: feed() must never throw

`FuzzerTest` runs a deterministic-seed RNG and pumps 100 B – 100 KB of
random bytes through `Terminal::feed()` and `Terminal::flush()`. The
contract is that no input — even pathologically malformed — causes an
exception. Cursor must stay in bounds throughout. If a real-world
input ever crashes, add a regression case using its bytes verbatim
(or an isolated reduction) before fixing.

## Tab stops live on ScreenHandler as `array<int, bool>` (PR7)

`ScreenHandler::$tabStops` is a sparse map keyed by column index.
Defaults are every 8 columns starting at column 8 (so column 0 is
never a stop). Operations:

- HT (`0x09`)        → `TabHandler::forward(col, stops, cols)`; clamps at `cols-1`
- HTS (`ESC H` / C1 `0x88`) → set stop at current cursor column
- TBC (`CSI g`)      → mode 0 clears stop at cursor; mode 3 clears all
- CHT (`CSI I`)      → repeat forward N times (default 1)
- CBT (`CSI Z`)      → repeat backward N times; clamps at 0
- `Terminal::withTabStops($cols)` → replace the map with explicit columns

Tab stops are NOT regenerated on `Terminal::resize()` — custom stops
survive resize, and stops past a shrunken width are simply unreachable
(harmless; HT would clamp at the new right edge before reaching them).
If a downstream consumer needs default stops to fill in when growing,
explicitly call `withTabStops` after resize.

## Wide-character handling

CJK and emoji graphemes occupy 2 cells. The second cell is marked with
`Cell::continuation()`. Width queries delegate to `SugarCraft\Core\Util\Width`
to stay consistent with the rest of the stack.

## Scroll region lives on ScreenHandler, not Screen (DECSTBM pattern)

`ScreenHandler` holds two public properties tracking the DECSTBM scroll region:

| Property              | Type   | Meaning                                    |
|-----------------------|--------|--------------------------------------------|
| `$scrollRegionTop`    | `int`  | Top row of scroll region (0-indexed incl.)|
| `$scrollRegionBottom`| `int`  | Bottom row of scroll region (0-indexed incl.)|

`Screen` is a **readonly immutable snapshot** constructed from a `Buffer` copy
— it has no scroll region state and no setter. All mutable scroll region
state lives on `ScreenHandler`, which is the correct place for it.

The scroll region is set via CSI `r` dispatch → `ScreenHandler::setScrollRegion()`:

- Params: `[top;bottom]` (1-indexed, VT100 spec defaults top=1, bottom=rows)
- Reset when top == bottom or either is out of range
- Converted to 0-indexed inclusive bounds and stored on the handler

Scroll-region-aware movement primitives (all in `ScrollHandler`):

- `index()`        — IND / `ESC D` / LF / `CSI S`: move down; at bottom of region → scroll up
- `reverseIndex()` — RI / `ESC M`: move up; at top of region → scroll down
- `nextLine()`     — NEL / `CSI E`: CR + IND
- `applyCsi()`     — SU (`CSI S`) / SD (`CSI T`): scroll region by N rows

All four operations pass `scrollRegionTop` / `scrollRegionBottom` explicitly so
`ScreenHandler` doesn't need to maintain a separate scroll-mode flag. The
handler initializes to the full screen (`rows - 1`) on construction.

## Scrollback ring buffer (step 07.04)

`ScreenHandler::$scrollback` is a `Scrollback` ring buffer that stores rows
scrolled off the top of the screen. The ring is fixed-capacity (default
1000 rows, configurable via `Terminal::withScrollbackSize()` or the
`scrollbackSize` constructor param). Once full, new pushes silently
overwrite the oldest entry (tail advances). Iteration yields rows from
oldest to newest.

`Screen::fromBuffer()` receives the current handler's `Scrollback` reference,
so `Screen::scrollback()` exposes it as a read-only accessor on the
immutable snapshot.

The scrollback is pushed by `ScrollHandler` operations:

- `index()` / `reverseIndex()` — when LF at bottom or RI at top would
  scroll the region
- `nextLine()` — NEL (CR + IND)
- `applyCsi()` — SU (`CSI S`) / SD (`CSI T`)

And cleared by `EraseHandler` when it handles `CSI 3 J` (erase
scrollback). The ring is replaced wholesale on `Terminal::withScrollbackSize()`
— existing content is dropped, not preserved at a different capacity.

## SGR underline styles CSI 4:N mapping (step 07.05)

`Sgr::$underlineStyle` is an `UnderlineStyle` enum. `SgrHandler::underlineStyle()`
handles the CSI `4:N` dispatch:

- `4` (no subparam) or `4:1` → `UnderlineStyle::Single`
- `4:0` → `UnderlineStyle::None`
- `4:2` → `UnderlineStyle::Double`
- `4:3` → `UnderlineStyle::Curly`
- `4:4` → `UnderlineStyle::Dotted`
- `4:5` → `UnderlineStyle::Dashed`
- `24` → `withUnderline(false)->withUnderlineStyle(UnderlineStyle::None)` (clears any underline style)

`Sgr::withUnderlineStyle()` automatically sets `$underline = ($style !== UnderlineStyle::None)`
so the legacy boolean `$underline` flag stays consistent with the enum. `equals()` compares
both fields.

## DECOM origin mode implementation (step 07.06)

DECOM (`CSI ?6 h` / `CSI ?6 l`) is implemented as:

- `Mode::$originMode` — public readonly bool on `Mode`
- `Mode::withOriginMode(bool)` — fluent setter returning new `Mode`
- `ModeHandler` CSI `?6` dispatch — maps 6 → `withOriginMode($set)`
- When `$originMode` is true, cursor-addressing commands (CUP, HVP)
  treat `(1,1)` as the top-left of the DECSTBM scroll region rather
  than the absolute screen origin.

DECOM and DECSTBM interact: setting a scroll region with DECSTBM, then
enabling DECOM, resets the cursor to the top-left of that region. The
cursor is confined within the region while origin mode is active.
Leaving origin mode (`CSI ?6 l`) does NOT reset the scroll region —
the two must be handled explicitly by the consumer if both are active.

## DECSCUSR cursor shape implementation (step 07.06)

DECSCUSR (`CSI Ps SP q`) sets the cursor shape. Implemented as:

- `Mode::$cursorShape` — public readonly int (0–6) on `Mode`
- `Mode::withCursorShape(int)` — fluent setter returning new `Mode`
- `CursorShape` enum at root namespace (`SugarCraft\Vt\CursorShape`)
  with values: `BlinkingBlock=0/1`, `SteadyBlock=2`, `BlinkingUnderline=3`,
  `SteadyUnderline=4`, `BlinkingBar=5`, `SteadyBar=6`
- `CursorShape::fromInt(int)` — normalises `0` and `1` to `BlinkingBlock`
  per VT spec; unknown values fall back to `BlinkingBlock`
- `CursorShape::toInt()` — returns `$this->value`

`ModeHandler` handles CSI Ps SP q dispatch by calling
`withCursorShape($ps)` where Ps is the raw parameter. The shape is stored
in `Mode`; consumers that need the `CursorShape` enum call
`CursorShape::fromInt($mode->cursorShape)`.

## Focus event reporting implementation (step 07.06)

Focus event reporting (`CSI ?1004 h` / `CSI ?1004 l`) is implemented as:

- `Mode::$reportFocusEvents` — public readonly bool on `Mode`
- `Mode::withReportFocusEvents(bool)` — fluent setter returning new `Mode`
- `ModeHandler` CSI `?1004` dispatch — maps 1004 → `withReportFocusEvents($set)`
- `ScreenHandler::$focusEvents` — public `array` accumulating `FocusInMsg` /
  `FocusOutMsg` value objects
- `FocusInMsg` / `FocusOutMsg` — `final readonly` value objects in the
  `Msg` namespace (`SugarCraft\Vt\Msg\FocusInMsg`), both `implement Msg`
  for VCR serialization compatibility (step 07.22)
- `ScreenHandler::focusIn()` — appends `FocusInMsg` to `$focusEvents`
  when `$mode->reportFocusEvents` is true
- `ScreenHandler::focusOut()` — same for `FocusOutMsg`

No `Terminal` accessor is wired for `$focusEvents` in this step; the array
is accessible via `$vt->{internal handler reference}->focusEvents` or can
be exposed via a future `Terminal::focusEvents()` accessor.

## BCE background-color erase in EraseHandler (step 07.07)

When CSI ?12 h (BCE mode) is active, `EraseHandler::fillRow()` creates
`Cell(grapheme: ' ', sgr: $sgr)` — a blank cell carrying the full
current SGR, so the erased region visually inherits the background
color instead of going dark. When BCE is off or no background is set,
`Cell::empty()` is used. The `$sgr` is passed into `apply()` from
`ScreenHandler::csiDispatch()`.

## Combining-character composition via attachCombiningChar (step 07.07)

`ScreenHandler::attachCombiningChar()` appends a zero-width combining mark
(U+0300–U+036F) to the cell at `(row, col-1)` — the previous column:

- Silently dropped if `col == 0` (nothing before cursor to attach to).
- Silently dropped if the previous cell is a wide-char continuation cell.
- When DEC 2026 synchronized-output mode is active, the mutation is
  queued in `$pendingMutations[]` instead of written directly.
- Otherwise applied immediately via `$buffer->put()`.

## Stream writes

Never `ftruncate; rewind;` between writes — slice deltas with
`ftell` / `fseek` / `stream_get_contents`.

## Fixture encoding

Fixtures must be LF-only (`\n`). Add `*.ansi binary` to `.gitattributes`
to prevent CRLF normalization on checkout.

## Renderer value objects — Phase 1a patterns

The `SugarCraft\Vt` root namespace holds simplified value objects
(`Cell`, `CellGrid`, `Cursor`) for the vcr renderer path, distinct from
the full VT parser stack in subdirectories.

### Private mutate() helper for fluent with*() methods

Each root-namespace value object uses a private `mutate()` helper to
avoid repeating all fields in every `with*()` method:

```php
private function mutate(array $changes): self
{
    return new self(
        char: $changes['char'] ?? $this->char,
        fg: $changes['fg'] ?? $this->fg,
        ...
    );
}

public function withFg(int $c): self
{
    return $this->mutate(['fg' => $c]);
}
```

This keeps `with*()` methods to a single line while remaining explicit
about which field changes.

### Cell attribute bitfield

`Cell::$attrs` is an int bitfield (not booleans) to match the raw SGR
model upstream. Constants are defined on `Cell`:

```php
Cell::ATTR_BOLD | Cell::ATTR_ITALIC | Cell::ATTR_UNDERLINE ...
```

### CellGrid dirty-region tracking

`CellGrid` tracks a minimal bounding box of dirty cells
(`minRow`, `maxRow`, `minCol`, `maxCol`) as **private** properties,
not constructor parameters. Constructor params are `cols` + `rows` only.
The `dirtyRegion()` method exposes the bounds as a typed array return.

`clear()` creates a fresh grid (dirty region resets to zero/empty).
`resize()` creates a fresh grid with content copied, also resetting
dirty region. `set()` expands the bounding box to include the
written cell and returns a new `CellGrid` instance.

### Cursor shape values

Cursor `shape` uses raw int values matching the VT spec:
`0` = block (blinking), `1` = underline, `2` = pipe (bar).
`CursorShape` enum (in root namespace) maps symbolic names to these ints.

## Parser::reset() flushes then clears

`Parser::reset()` calls `flush()` first (dispatching any in-flight
string sequences) then `clear()` (resetting params/cmd/stringBuffer).
Calling reset() mid-sequence flushes that sequence rather than
discarding it silently — use this when repositioning the parser at a
known sync point rather than waiting for proper termination.

## CsiHandler / OscHandler interfaces (PR11)

`Parser\CsiHandler` and `Parser\OscHandler` are the handler contracts
for Phase 1c. They are currently empty shells; the implementation
fills in during Phase 1c. The Parser itself uses a separate `Handler`
interface internally — CsiHandler/OscHandler are for external consumers
who only need CSI or OSC handling without the full ScreenHandler
complexity.

## 256-color palette structure

The 256-color palette is structured as:
- Indexes 0-15: standard ANSI 16 colors
- Indexes 16-231: 6×6×6 RGB color cube (216 colors)
- Indexes 232-255: 24-step grayscale (dark to light)

The `cubePalette()` method generates the color cube:
```php
for ($r = 0; $r < 6; $r++) {
    for ($g = 0; $g < 6; $g++) {
        for ($b = 0; $b < 6; $b++) {
            $cube[] = (($r ? $r * 40 + 55 : 0) << 16)
                     | (($g ? $g * 40 + 55 : 0) << 8)
                     | ($b ? $b * 40 + 55 : 0);
        }
    }
}
```

The `rgb()` method reverses this: for the cube range it extracts r/g/b
components; for grayscale it computes `floor($i * 10 + 8)`.

## Theme attribute constants match Cell

`Theme::ATTR_BOLD`, `ATTR_ITALIC`, `ATTR_UNDERLINE`, `ATTR_INVERSE`,
and `ATTR_STRIKETHROUGH` have the same bitfield values as `Cell::ATTR_*`
so they can be used interchangeably when building SGR state.

## TokyoNight palette specifics

TokyoNight uses `defaultFg: 7` (Ansi7 White) and `defaultBg: 0`
(Ansi0 Black). The 16 ANSI slots (Ansi0–Ansi15) in the TokyoNight
palette map to:
- 0: #15161e (Black), 1: #f7768e (Red), 2: #9ece6a (Green), 3: #e0af68 (Yellow)
- 4: #7aa2f7 (Blue), 5: #bb9af7 (Magenta), 6: #7dcfff (Cyan), 7: #a9b1d6 (White)
- 8: #414868 (BrightBlack), 9–14: bright variants, 15: #c0caf5 (BrightWhite)

The `Theme::color($index)` method handles out-of-range indices by
returning 0 (black), matching the expected safe-default behavior.

## Themes catalog

`Themes::all()` returns all named themes including deferred stubs.
`Themes::v1()` returns only the v1-ready themes (TokyoNight family) with
TokyoNight first in the array to match its priority as the monorepo
standard. Both methods return `array<string, Theme>`.

## Golden-file snapshot tests

Use `assertGoldenAnsi` for any new `render()` test. Fixture files live
in `tests/fixtures/` with a `.golden` extension. Re-record goldens with
`UPDATE_GOLDENS=1 vendor/bin/phpunit` after intentional output changes.
Mirrors: `docs/repo_map_step_28.md`.

