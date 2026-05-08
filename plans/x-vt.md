# Plan: virtual terminal emulator lib (`x/vt` → `candy-vt`)

## Goal

New foundation lib that takes an ANSI byte stream as input, parses it
through a Paul-Williams-style state machine, mutates an in-memory
cell grid, and exposes the grid + cursor + mode state for inspection.

Highest-leverage item in the wave. Unlocks:

- **Snapshot tests** for any TUI: `feed(view()); assertSame($expected, screen()->cell(...))`
- **Recording / replay** ([x-vcr](./x-vcr.md))
- **Multiplexing** — multiple vt instances tiled in one renderer (future)
- **Headless SSH sessions** in candy-wish without real PTYs (future)
- **Browser-side rendering** of cassettes via XTerm.js or pure JS, if we ever ship a web demo viewer

## Scope

**In**

- VT500 ANSI parser state machine (ground / esc / csi / osc / dcs / apc / sos / pm)
- 80×24+ cell grid with arbitrary resize
- SGR (full set: fg/bg 16/256/truecolor + bold/italic/underline/strikethrough/blink/reverse/dim/hidden)
- Cursor positioning (CUP, CUF, CUB, CUU, CUD, CHA, VPA, save/restore)
- Erase (EL, ED variants)
- Scroll (SU, SD, IND, RI)
- DEC private modes: alt screen (1049), cursor visible (25), bracketed paste (2004), mouse on/off (1000/1002/1003/1006), synchronized output (2026)
- OSC 0/1/2 (titles), OSC 4 (palette set), OSC 8 (hyperlink), OSC 52 (clipboard)
- Tab stops + tab move
- Double-buffer for alt screen
- Unicode + grapheme width (delegate to candy-core `Util\Width`)

**Out (deferred)**

- Scrollback buffer (initial cut: scrolled-off lines lost; can add later)
- DEC double-width / double-height lines (DECDWL/DECDHL)
- Sixel / Kitty graphics in-grid rendering (vt records the bytes but doesn't render them; consumer can extract via `screen()->images()`)
- True color → 256-color downsampling (candy-palette's job, not vt's)
- Status line (DECSSDT)
- Charset switching (G0/G1/SS2/SS3) — most modern TUIs don't use this; document as known gap

## Naming + placement

- Composer pkg: `sugarcraft/candy-vt`
- Subdir: `candy-vt/`
- Namespace: `SugarCraft\Vt`
- Prefix: **Candy-** (foundation/system)

## Layout

```
candy-vt/
  composer.json
  phpunit.xml
  README.md
  CALIBER_LEARNINGS.md
  src/
    Terminal.php                       # facade — feed bytes, query state
    Screen.php                         # readonly snapshot of grid
    Cell.php                           # readonly: grapheme + fg + bg + sgr flags
    Cursor.php                         # readonly: row, col, visible, shape, saved
    Mode.php                           # readonly: dec mode flags
    Hyperlink.php                      # readonly: id + uri
    Color.php                          # union/value: Default | Indexed16 | Indexed256 | Truecolor
    Sgr.php                            # readonly attr flags (bold, italic, …)
    Parser/
      Parser.php                       # state machine driver
      State.php                        # enum
      Action.php                       # enum
      Class_.php                       # char-class lookup table
      Transitions.php                  # const table state×class → (action, next-state)
    Handler/
      Handler.php                      # interface — receives parser actions
      ScreenHandler.php                # default impl — mutates Screen
      SgrHandler.php                   # CSI m
      CursorHandler.php                # CSI A/B/C/D/H/f, save/restore, DEC modes 25
      EraseHandler.php                 # CSI K/J/X/P
      ScrollHandler.php                # CSI S/T, IND, RI, NEL
      ModeHandler.php                  # CSI ? h/l, CSI h/l
      OscHandler.php                   # OSC 0/1/2/4/8/52/110/111/112
      TabHandler.php                   # HT, BS, CR, LF, TBC
    Buffer.php                         # internal: 2D Cell array with row-of-vec optimization
    AltBuffer.php                      # parallel buffer for 1049 alt-screen toggle
    Lang.php
  examples/
    feed-and-screenshot.php
    diff-frames.php
  tests/
    fixtures/                          # captured byte-streams from real TUIs
      bubbletea-counter.ansi
      lipgloss-table.ansi
      glamour-readme.ansi
    ParserTest.php
    SgrTest.php
    CursorTest.php
    EraseTest.php
    ScrollTest.php
    AltScreenTest.php
    ModeTest.php
    OscTest.php
    HyperlinkTest.php
    SnapshotTest.php
```

## composer.json

- PHP `^8.1`
- Deps: `sugarcraft/candy-core: @dev` (for `Util\Width`), `sugarcraft/candy-sprinkles: @dev` (for ColorProfile types)
- Path-repos: full transitive closure

## Public API

```php
use SugarCraft\Vt\Terminal;

$term = Terminal::create(cols: 80, rows: 24);
$term->feed("\x1b[H\x1b[2J\x1b[31mHello\x1b[0m");

$screen = $term->screen();
$screen->cell(row: 0, col: 0)->grapheme;        # 'H'
$screen->cell(row: 0, col: 0)->fg;              # Color::indexed16(1)  (red)
$term->cursor()->row;                           # 0
$term->cursor()->col;                           # 5

$term->resize(cols: 120, rows: 40);
$term->cursor()->visible;                       # true (default)

# Diff helpers — useful for cassette assertions
$snap1 = $term->screen();
$term->feed("\x1b[2;5HX");
$snap2 = $term->screen();
$diff = $snap1->diff($snap2);                   # array of changed cells

# Mode tracking
$term->mode()->altScreen;                       # bool
$term->mode()->bracketedPaste;                  # bool
$term->mode()->mouseSgr;                        # bool

# Window title (OSC 0/2)
$term->windowTitle();                            # string|null

# OSC 8 hyperlinks discovered during parse
$screen->cell(0, 0)->hyperlink;                  # ?Hyperlink
```

## Parser approach

[Paul Williams' VT500 state machine](https://www.vt100.net/emu/dec_ansi_parser)
is the canonical reference. Roughly:

- 8 parser states (ground, escape, escape_intermediate, csi_entry, csi_param, csi_intermediate, csi_ignore, osc_string, dcs_*)
- ~16 character classes (printable, controls, ESC, ;, etc.)
- Transition table: state × class → (action, next-state)

We port this state-by-state, **not** by parsing CSI strings with regex.
Regex parsers fail on partial input (we may receive half a CSI across
two `feed()` calls). State machine handles partial input naturally.

### Implementation skeleton

```php
final class Parser
{
    private State $state = State::Ground;
    private array $params = [];          # CSI params
    private string $intermediates = '';
    private string $oscBuffer = '';

    public function __construct(private readonly Handler $handler) {}

    public function feed(string $bytes): void
    {
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($bytes[$i]);
            $class = self::classify($byte);
            $entry = Transitions::TABLE[$this->state->value][$class];
            $this->dispatch($entry, $byte);
        }
    }

    private function dispatch(int $entry, int $byte): void
    {
        $action = $entry & 0xFF;
        $next   = ($entry >> 8) & 0xFF;
        match ($action) {
            Action::Print->value     => $this->handler->print(chr($byte)),
            Action::Execute->value   => $this->handler->execute($byte),
            Action::CsiDispatch->value => $this->handler->csiDispatch($byte, $this->params, $this->intermediates),
            Action::OscEnd->value    => $this->handler->osc($this->oscBuffer),
            ...
        };
        $this->state = State::from($next);
    }
}
```

`Transitions::TABLE` is a precomputed `int[state][class]` array (8 × 16 = 128 entries) packed as `(next_state << 8) | action`.

## Handler split

`ScreenHandler` is the default — it mutates `Buffer` per action.
Each major dispatch type has its own sub-handler (Sgr, Cursor, Erase,
Scroll, Mode, Osc, Tab) so they can be unit-tested in isolation by
constructing a `ScreenHandler` with a mock buffer and calling the
sub-handler directly with synthetic params.

This keeps `ScreenHandler::csiDispatch` small — it switches on the
final byte and delegates:

```php
public function csiDispatch(int $final, array $params, string $intermediates): void
{
    match ($final) {
        ord('m') => $this->sgr->apply($params, $this->state),
        ord('A'), ord('B'), ord('C'), ord('D'), ord('H'), ord('f') => $this->cursor->move($final, $params),
        ord('K'), ord('J'), ord('X'), ord('P') => $this->erase->apply($final, $params, $intermediates),
        ord('S'), ord('T') => $this->scroll->apply($final, $params),
        ord('h'), ord('l') => $this->mode->apply($final, $params, $intermediates),
        default => null,  # ignored gracefully
    };
}
```

## Implementation slices

### PR1 — Cell + Buffer + Screen + Terminal facade (~half day)

- All readonly value types
- `Buffer` 2D init + resize (preserve content; pad / truncate)
- `Screen::cell()`, `Screen::diff()`, `Screen::lines()`
- `Terminal::create($cols, $rows)`, `feed()` is a no-op stub
- Tests: construct + resize round-trip; cell access bounds

### PR2 — Parser state machine (~2 days)

- `Parser`, `State`, `Action`, `Class_`, `Transitions`
- `Handler` interface
- A debug handler that records every action call, used for testing
- Tests: feed canonical byte-streams (`\x1b[31mhello`, `\x1bP...\x1b\\` DCS, `\x1b]2;title\x07` OSC) and assert action sequence

### PR3 — SgrHandler + CursorHandler + ScreenHandler default (~1 day)

- Wire `Terminal` to construct a real `ScreenHandler` and `Parser`
- SGR: 16-color, 256-color, truecolor; all attrs
- Cursor: CUU/CUD/CUF/CUB/CUP/CHA/VPA, save/restore (DECSC/DECRC), DEC mode 25 (cursor visibility)
- Tests: drive ANSI fixture, assert grid + cursor state

### PR4 — EraseHandler + ScrollHandler (~1 day)

- EL (CSI K) modes 0/1/2; ED (CSI J) modes 0/1/2/3
- DCH (delete chars), ICH (insert chars)
- SU/SD (scroll up/down); IND/RI (linefeed)
- Tests: line erase + screen scroll fixtures

### PR5 — ModeHandler + alt screen (~1 day)

- DEC private modes: 1049 (alt screen + save cursor), 25 (cursor visible), 1000/1002/1003/1006 (mouse), 2004 (bracketed paste), 2026 (synchronized output)
- `AltBuffer` — parallel grid swapped on 1049 toggle
- `Mode` value object exposed via `Terminal::mode()`
- Tests: alt-screen toggle preserves main buffer + cursor

### PR6 — OscHandler + Hyperlink (~half day)

- OSC 0/1/2 → window title (stored on Terminal)
- OSC 4 → palette set (stored on Terminal as int → Color map)
- OSC 8 → hyperlink open/close; cells emitted while a hyperlink is open get `Hyperlink` attached
- OSC 52 → clipboard read/write events (exposed via callback)
- Tests: title round-trip, hyperlink span attribution

### PR7 — TabHandler + control chars (~half day)

- HT (horizontal tab) → next tab stop
- BS, CR, LF → cursor moves
- TBC (tab clear), HTS (tab set)
- Default tab stops every 8 columns; configurable via `Terminal::withTabStops()`
- Tests: tab + clear cycle

### PR8 — fuzzing + real-TUI fixtures (~1 day)

- `tests/fixtures/*.ansi` — capture stdout from real bubbletea / lipgloss / glamour runs (record once, commit bytes)
- Snapshot tests: feed each fixture, assert specific cells/cursor at known positions
- Fuzzer: random byte sequences (length 1KB-100KB) → assert no exceptions thrown
- Validate `Width` integration: feed CJK + emoji + ZWJ sequences, assert cells consumed correctly

### PR9 — examples + .vhs + matrix entries (~half day)

- `examples/feed-and-screenshot.php`
- `.vhs/snapshot-demo.tape`
- All cross-cutting touch-ups (MATCHUPS, PROJECT_NAMES, CONVERSION, README, docs, icon)

## Test strategy

Three-tier:

1. **Unit** — each handler tested with synthetic param arrays
2. **Integration** — small ANSI byte sequences feed through full Parser → ScreenHandler, assert grid state
3. **Snapshot** — captured fixtures from real TUIs assert known-good cells

Commit fixture bytes; they're tiny (kilobytes) and language-portable.

## Caveats / open questions

1. **Scrollback** — initial cut has none. Lines scrolled off the top are
   lost. Adding scrollback later is a 100-line patch in `Buffer`. OK to defer.
2. **Charset switching (G0/G1, SS2/SS3, DEC line drawing)** — modern TUIs
   emit Unicode line-drawing chars (`─` `│` `┌`) instead of falling back
   to the DEC special graphics character set. Document as known gap. If
   we hit a TUI that uses it, port `vt100.charset` from upstream — small
   addition.
3. **Sync output (DEC 2026)** — set/reset toggles a "buffer until end" mode.
   Modern terminals use it to avoid mid-frame tearing. We don't *render*
   so it's effectively a no-op for us, but track the flag so consumers
   can know. (Cassettes might want to keep "sync ON" frames coalesced.)
4. **Performance** — feed() per-byte loop is ~100M ops/sec in pure PHP
   on a modern CPU. For typical TUI output (10-100 KB per second) this is
   trivial. Tight inner loop: classify byte, table lookup, dispatch via
   match. No regex.
5. **Windows line endings** — fixtures must be LF-only. Add a git
   `.gitattributes` entry: `*.ansi binary` to prevent CRLF normalization.
6. **Sixel / Kitty in-band data** — vt parses the DCS / APC envelope
   correctly (state machine handles it) and *records* the payload as a
   side-channel `Image[]` array on `Screen`. We don't decode the image.
   Consumers (e.g. cassette diff tools) can.
7. **Bidi / RTL** — out of scope. Cells are stored in logical order; the
   renderer (lipgloss / glamour) handles visual ordering before
   rendering. vt is logical-only.
8. **Cell width** — wide chars (CJK) occupy 2 cells; we mark the second
   cell as `Cell::continuation()`. Width queries delegate to candy-core's
   `Util\Width::string()` to stay consistent across the stack.

## Effort

| Slice | Effort |
|---|---|
| PR1 scaffold | half day |
| PR2 parser | 2 days |
| PR3 SGR + cursor | 1 day |
| PR4 erase + scroll | 1 day |
| PR5 modes + alt screen | 1 day |
| PR6 OSC + hyperlink | half day |
| PR7 tabs + control | half day |
| PR8 fuzz + fixtures | 1 day |
| PR9 examples + matrix | half day |
| **Total** | **~8 days (1.5-2 weeks calendar)** |

## Dependencies

- [x-ansi](./x-ansi.md) items A4 (DEC private modes), A5 (DCS/APC) — needed for parser dispatch identity
- candy-core `Util/Width` (already exists)
- candy-sprinkles color types (already exists)

## Tracking

- `MATCHUPS.md` — new row: `[charmbracelet/x/vt] | candy-vt | candy-vt/ | sugarcraft/candy-vt | SugarCraft\Vt | 🟡 (until v1) | In-memory virtual terminal emulator`
- `PROJECT_NAMES.md` — naming entry for CandyVt
- `CONVERSION.md` — phase row
- `UPSTREAM_OPPORTUNITIES.md` — flip `x/vt` to 🟡 on PR1, 🟢 on PR9
- `docs/index.html` — homepage tile
- `media/candy-vt.png` — 256² icon
- `candy-vt/CALIBER_LEARNINGS.md` — capture parser-table caveat (item 5), wide-char handling (item 8)
- Update candy-core's snapshot-test conventions doc to recommend candy-vt for cell-grid assertions instead of raw byte snapshots
