# candy-vt Library Research: Virtual Terminal Emulator Comparison

**Date:** 2026-05-13
**Library:** candy-vt (SugarCraft monorepo PHP 8.3+ virtual terminal emulator)
**Upstream:** charmbracelet/x/vt (Go)
**Context:** Research for feature parity and potential improvements

---

## Executive Summary

candy-vt implements a VT500-series ANSI parser with cell grid management, cursor handling, SGR styling, and OSC support. The current implementation is solid but missing several features that exist in upstream and other terminal emulator libraries. This document compares approaches across Go (charmbracelet/x/vt, termbox, tcell), Rust (vte, alacritty), and provides prioritized recommendations for candy-vt enhancements.

---

## 1. Current Implementation Overview

### Source Structure
```
candy-vt/src/
├── Terminal/Terminal.php      — Facade, owns Parser + ScreenHandler
├── Parser/
│   ├── Parser.php             — VT500 state machine (Paul-Williams)
│   ├── State.php              — 15 states (Ground, CsiEntry, Utf8, etc.)
│   ├── Transitions.php        — 4096-byte packed transition table
│   ├── Action.php             — 8 actions (Print, Execute, Clear, etc.)
│   ├── Handler.php            — Interface for parser callbacks
│   └── DebugHandler.php       — Debug tracing
├── Handler/
│   ├── ScreenHandler.php      — Orchestrates Buffer/Cursor/Sgr/Mode
│   ├── CursorHandler.php      — CUP, HVP, CUU/CUD/CUF/CUB
│   ├── SgrHandler.php         — SGR color and attributes
│   ├── EraseHandler.php       — EL, ED, ECH, DCH, ICH
│   ├── ScrollHandler.php      — SU, SD, DECSTBM (vertical scroll)
│   ├── ModeHandler.php        — DECSET/DECRST
│   ├── OscHandler.php         — Window title, palette, hyperlink, clipboard
│   └── TabHandler.php         — TBC, HTS, CHT, CBT
├── Buffer/Buffer.php          — Mutable 2D cell grid (rows × cols)
├── Cell/Cell.php              — Single cell: grapheme, SGR, hyperlink, continuation
├── Cursor/Cursor.php          — Position, visibility, shape, saved position
├── Screen/Screen.php          — Immutable snapshot via copy-on-write
├── Sgr/Sgr.php                — Attributes: bold, italic, underline, colors
├── Color/Color.php            — 3 kinds: Default, Indexed16, Indexed256, Truecolor
├── Mode/Mode.php              — DEC mode flags
└── Hyperlink/Hyperlink.php    — OSC 8 URL + id
```

### Current Coverage (from CALIBER_LEARNINGS.md)
- ✅ VT500 state machine with UTF-8 accumulation
- ✅ SGR (38;2;R;G;B, 38;5;N, 48;2;R;G;B, 48;5;N)
- ✅ Cursor movement (CUU/CUD/CUF/CUB, CUP/HVP, CHA, VPA)
- ✅ Erase (EL, ED, ECH, DCH, ICH)
- ✅ Vertical scroll (SU/SD at bottom margin)
- ✅ Tab stops (HTS, TBC, CHT, CBT)
- ✅ DEC modes (25, 1000, 1002, 1003, 1006, 1049, 2004, 2026)
- ✅ OSC 0/1/2 (window title), OSC 4 (palette), OSC 8 (hyperlink), OSC 52 (clipboard)
- ✅ Alt screen (DEC 1049 with buffer/cursor/SGR save; DEC 1047/47 without save)
- ✅ Wide char handling (CJK, emoji — 2 cells with continuation)

### Known Gaps (from CALIBER_LEARNINGS.md)
- ❌ **No scrollback** — scrolled-off rows are dropped
- ❌ **No auto-wrap** — cursor clamps at right edge; auto-wrap deferred until DECSTBM margins
- ❌ **No DECSTBM scroll margins** — vertical scroll only at buffer bottom
- ❌ **No BCE (Background Color Erase)** — erased cells are empty, not background-colored
- ❌ **No true combining character composition** — zero-width chars skip silently
- ❌ **No sixel graphics**
- ❌ **No Kitty graphics protocol**
- ❌ **No DECRQSS (Request for Presentation State)**
- ❌ **No synchronized output (DEC绍)**

---

## 2. ANSI Parsing Comparison

### 2.1 Paul-Williams State Machine

All modern terminal emulators use the Paul-Williams state machine from the DEC ANSI parser specification (vt100.net/emu/dec_ansi_parser).

#### charmbracelet/x/ansi/parser (Go)

**Source:** [github.com/charmbracelet/x/ansi/parser.go](https://github.com/charmbracelet/x/blob/main/ansi/parser/parser.go)

```go
// Uses a transition table generated at compile time via go:generate
// Table is []uint8 with packed entries: action<<4 | nextState
// 15 states × 256 bytes = 3840 bytes

type Parser struct {
    state     parser.State
    params    []int
    paramsLen int
    cmd       int
    data      []byte
    dataLen   int
}

func (p *Parser) advance(b byte) parser.Action {
    state, action := parser.Table.Transition(p.state, b)
    // ...
}
```

**Key features:**
- Compile-time generated transition table (Go generate)
- Params stored as `[]int` with `paramsLen` cursor
- `data` buffer for OSC/DCS strings with `dataLen` tracking
- Context-aware OSC parsing (`;` separator detection within string)
- Subparameter support via `:` separator (CSI 38:2:...)

#### candy-vt Parser (PHP) — Current Implementation

```php
// Transitions.php generates 4096-byte string at first use
// Same packing scheme: action<<4 | nextState
// State enum order matches upstream exactly (load-bearing for table index)

private static function build(): string {
    $g = State::Ground->value;
    $t = str_repeat(self::pack(Action::None->value, $g), self::SIZE);
    // ... range helpers for setting entries
    // Anywhere transitions applied to all states first
    // Then state-specific transitions
    return $t;
}
```

**Differences from upstream:**
| Aspect | Upstream (Go) | candy-vt (PHP) |
|--------|---------------|----------------|
| Table generation | Compile time (go:generate) | Runtime (first use) |
| Subparameters | Full support with `:` separator | Not yet implemented |
| Params storage | `[]int` with `paramsLen` | `array` with `-1` sentinel for missing |
| String buffer | `[]byte` with `dataLen` | `string` with append |

#### Rust vte

**Source:** [alacritty/vte](https://docs.rs/vte/latest/vte/)

```rust
// Generic over OSC_RAW_BUF_SIZE for no_std compatibility
pub struct Parser<const OSC_RAW_BUF_SIZE: usize = MAX_OSC_RAW> { /* private */ }

pub trait Perform {
    fn print(&mut self, c: char);
    fn execute(&mut self, byte: u8);
    fn put(&mut self, byte: u8);
    fn oscill(&mut self, params: &mut Params, byte: u8);
    fn csi_dispatch(&mut self, params: &Params, intermediates: &[u8], final_byte: u8);
    fn esc_dispatch(&mut self, intermediates: &[u8], final_byte: u8);
    fn dcs_dispatch(&mut self, params: &Params, data: &[u8]);
    fn get_param(&mut self,byte: u8) -> Param;
    // ...
}
```

**Key design:** `Perform` trait allows zero-cost abstraction — the parser doesn't know about terminal semantics, it just dispatches to a performer.

### 2.2 Subparameter Handling (CSI `:` separator)

**Source:** [alacritty/vte#22](https://github.com/jwilm/vte/issues/22) — colon-separated CSI parameters

The `:` subparameter separator allows sequences like:
```
CSI 38:2::186:93:0m    → 38;2;186;93;0 (extended color with subparams)
CSI 4:0:1m             → 4;0;1 (underline with style subparam)
```

**xterm.js implementation insight:**
- Stores up to 32 params, 32 subparams total
- Uses a flat structure: `params[]` where first value of subparam group goes in params array, excess subparams in `subparams[]`
- Performance optimization: for common case (no subparams), zero overhead

**Recommendation for candy-vt:** Implement subparameter parsing in `Transitions.php` and `Parser.php`. The current `Action::Param` handling treats `:` as an invalid digit (skipped). Need to either:
1. Add new action `Subparam` to the transition table
2. Handle `:` within existing `Action::Param` logic (more complex state machine)

**Effort:** Medium — requires transition table modification and param state machine update.

---

## 3. Cell Grid Management Comparison

### 3.1 charmbracelet/x/vt — Buffer Approach

**Source:** Uses `ultraviolet` for rendering buffer

```go
// https://github.com/charmbracelet/ultraviolet/blob/main/buffer.go
type Buffer struct {
    cells []Cell  // flat array: row * width + col
    width int
    height int
}

// Cell is narrow (8 bytes) for cache efficiency:
// 4 bytes content (rune as uint32)
// 2 bytes style (flags)
// 1 byte foreground
// 1 byte background
```

**Design:** Flat array indexed by `y * width + x` for cache-friendly iteration. Colors stored as indices into a 256-color palette + 24-bit RGB fallback.

### 3.2 candy-vt Buffer Approach

```php
final class Buffer {
    /** @var array<int, array<int, Cell>> */
    private array $grid;  // Nested arrays: $grid[row][col]

    public function cell(int $row, int $col): Cell { /* ... */ }
    public function put(int $row, int $col, Cell $cell): void { /* ... */ }
    public function resize(int $cols, int $rows): self { /* ... */ }
}
```

**Current Cell structure:**
```php
final readonly class Cell {
    public function __construct(
        public string $grapheme = ' ',      // UTF-8 string
        public ?Sgr $sgr = null,            // Embedded SGR
        public bool $continuation = false,  // Wide char trailing cell
        public ?Hyperlink $hyperlink = null,
    ) {}
}
```

### 3.3 Performance Comparison

| Aspect | Go (ultraviolet) | PHP (candy-vt) |
|--------|------------------|----------------|
| Grid storage | Flat `[]Cell` (contiguous) | Nested `array[row][col]` (hash tables) |
| Memory layout | Cache-friendly | Cache-poor for full-grid iteration |
| Cell size | ~8 bytes fixed | ~40+ bytes (string + objects) |
| Width lookup | O(1) | O(1) |
| Row iteration | Fast memcopy | Hash traversal |

### 3.4 Potential Improvement: Flat Cell Array

**Recommendation:** Add a parallel `BufferLinear` class that stores cells in a flat array for performance-critical use cases:

```php
final class BufferLinear {
    /** @var array<int, Cell> Flat array: index = row * cols + col */
    private array $cells;

    public function __construct(
        public readonly int $cols,
        public readonly int $rows,
    ) {
        $this->cells = array_fill(0, $cols * $rows, Cell::empty());
    }

    public function cell(int $row, int $col): Cell {
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
            return Cell::empty();
        }
        return $this->cells[$row * $this->cols + $col];
    }
}
```

**Effort:** Low. Can coexist with existing `Buffer`. Used internally for diff operations or as an optional optimization.

---

## 4. Scrollback Buffer Comparison

### 4.1 charmbracelet/x/vt — Recent Addition (March 2026)

**Source:** [commit 2969fce](https://github.com/charmbracelet/x/commit/2969fcee80035444c85f98baa13389201d6edd0c)

```go
// DefaultScrollbackSize = 10000 lines
type Scrollback struct {
    lines    []uv.Line  // uv.Line is []Cell
    maxLines int
}

func NewScrollback(maxLines int) *Scrollback {
    return &Scrollback{
        lines:    make([]uv.Line, 0, min(maxLines, 1000)),
        maxLines: maxLines,
    }
}

func (s *Scrollback) Push(line uv.Line) {
    if s == nil || s.maxLines <= 0 { return }

    // Find last non-empty cell to trim trailing empty cells
    lastNonEmpty := -1
    for i := len(line) - 1; i >= 0; i-- {
        if !line[i].IsZero() && !line[i].Equal(&uv.EmptyCell) {
            lastNonEmpty = i
            break
        }
    }
    cloned := slices.Clone(line[:lastNonEmpty+1])

    if len(s.lines) >= s.maxLines {
        s.lines = slices.Delete(s.lines, 0, 1)  // Evict oldest
    }
    s.lines = append(s.lines, cloned)
}
```

**Design notes:**
- Simple slice-based with eviction via `slices.Delete`
- Lines are cloned (not referenced) to prevent mutation
- Trim trailing empty cells on push
- Max 1000 pre-allocated capacity initially

### 4.2 Alacritty — Ring Buffer with View Offset

**Source:** [alacritty/alacritty — storage.rs](https://github.com/alacritty/alacritty/blob/master/alacritty_terminal/src/grid/storage.rs)

```rust
/// A ring buffer for optimizing indexing and rotation.
///
/// The [`Storage::rotate`] and [`Storage::rotate_down`] functions are fast
/// modular additions on the internal `zero` field.
pub struct Storage<T> {
    inner: Vec<Row<T>>,
    zero: usize,         // Starting line offset (bottom of terminal)
    visible_lines: usize,
    len: usize,          // Total lines (scrollback + visible)
}

impl<T> Storage<T> {
    /// Compute actual index with modular arithmetic
    #[inline]
    fn compute_index(&self, requested: Line) -> usize {
        let positive = -(requested - self.visible_lines).0 as usize - 1;
        let zeroed = self.zero + positive;
        if zeroed >= self.inner.len() { zeroed - self.inner.len() } else { zeroed }
    }
}
```

**Design notes:**
- Ring buffer where `zero` is the bottommost line offset
- Scroll = increment/decrement `zero` (O(1), no data movement)
- Rotation only needed when buffer grows
- View offset translation happens in `compute_index`

### 4.3 st (simple terminal) — Ring Buffer Patch

**Source:** [st scrollback ringbuffer patch](http://st.suckless.org/patches/scrollback)

```c
typedef struct {
    Line *buf;  /* ring of Line pointers */
    int cap;    /* max number of lines */
    int len;    /* current number of valid lines (<= cap) */
    int head;   /* physical index of logical oldest */
} Scrollback;

static Scrollback sb;

static inline int sb_phys_index(int logical_idx) {
    return (sb.head + logical_idx) % sb.cap;
}
```

**Design notes:**
- Ring buffer of Line pointers
- `head` points to oldest line
- Physical index = `(head + logical_index) % capacity`
- Simple and efficient

### 4.4 Recommendation for candy-vt

**Implement scrollback as a ring buffer with PHP arrays:**

```php
final class Scrollback {
    /** @var array<int, array<int, Cell>> Lines stored as grid copies */
    private array $lines = [];

    public function __construct(
        private int $maxLines = 10000,
    ) {}

    public function push(array $line): void {
        if ($this->maxLines <= 0) { return; }

        // Clone the line
        $cloned = array_map(fn(Cell $c) => $c, $line);

        if (count($this->lines) >= $this->maxLines) {
            array_shift($this->lines);  // Evict oldest
        }
        $this->lines[] = $cloned;
    }

    public function line(int $index): ?array {
        return $this->lines[$index] ?? null;
    }

    public function len(): int {
        return count($this->lines);
    }
}
```

**Integration with ScreenHandler:**
- `ScrollHandler` calls `$scrollback->push($buffer->copyRow($row))` when scrolling
- Add `Terminal::scrollback(): ?Scrollback` accessor
- Add `Terminal::scrollbackLen(): int`
- Decouple scrollback from alt screen (alt screen typically doesn't scroll)

**Effort:** Medium — requires new class + integration with existing scroll logic.

---

## 5. Cursor Handling Comparison

### 5.1 Cursor State

#### charmbracelet/x/vt Cursor

```go
type Cursor struct {
    x, y    int
    visible bool
    shape   int  // 0=block, 1=underline, 2=pipe
    hidden  bool
}
```

#### candy-vt Cursor

```php
final readonly class Cursor {
    public function __construct(
        public int $row = 0,
        public int $col = 0,
        public bool $visible = true,
        public int $shape = 0,
        public ?int $savedRow = null,  // DECSC save position
        public ?int $savedCol = null,
    ) {}
}
```

### 5.2 Missing Cursor Features

| Feature | charmbracelet/x/vt | candy-vt |
|---------|-------------------|----------|
| Origin mode (DECOM) | ✅ Full support | ❌ Not implemented |
| Cursor blink state | ✅ | ❌ Not tracked |
| DECSC/USC save/restore | ✅ | ✅ (partial — ignores origin mode) |
| Cursor shape (DECSCUSR) | ✅ | ❌ CSI q not handled |
| Jump (smooth scroll) | ✅ | N/A (no smooth scroll) |

### 5.3 DECOM (Origin Mode) Implementation

When DECOM is set, cursor addressing is relative to the scroll region (top-left of scroll margins) instead of the full buffer.

**Required change in CursorHandler:**
```php
public function apply(int $final, array $params, Cursor $cursor, Buffer $buffer, Mode $mode): Cursor {
    // If DECOM is set, row/col are 1-based relative to scroll top margin
    $origin = $mode->originMode ? $buffer->scrollTop : 0;
    // ... existing movement logic with origin offset
}
```

**Effort:** Low-Medium — requires passing Mode to CursorHandler, adding origin offset calculation.

---

## 6. SGR Color Handling Comparison

### 6.1 Truecolor and Palette

#### charmbracelet/x/vt Color

Uses `color.Color` from `image/color` with direct RGB values:

```go
type Color struct {
    r, g, b float64  // 0.0-1.0 range
}
```

#### candy-vt Color

```php
final readonly class Color {
    private function __construct(
        public int $kind,   // 0=Default, 1=Indexed16, 2=Indexed256, 3=Truecolor
        public int $value,  // index or 0xRRGGBB
    ) {}

    public static function truecolor(int $r, int $g, int $b): self {
        return new self(3, ($r << 16) | ($g << 8) | $b);
    }

    public function red(): int { return ($this->value >> 16) & 0xFF; }
}
```

### 6.2 Missing SGR Features

| Feature | Notes |
|---------|-------|
| SGR 4:subparameter (underline style) | 0=none, 1=single, 2=double, 3=curly, 4=dotted, 5=dashed |
| SGR 28 (conceal/secrecy) | Hidden text (used by password managers) |
| SGR 29 (reveal) | Counterpart to 28 |
| SGR 90-97 / 100-107 | Bright foreground/background (already implemented) |
| Italic (SGR 3, 23) | ✅ Implemented |
| Dim (SGR 2, 22) | ✅ Implemented |
| Framed/Encircled (SGR 51-52) | ❌ Not implemented |
| Overlined (SGR 53, 55) | ❌ Not implemented |

### 6.3 SGR Underline Subparameter

**Source:** [charmbracelet/x/ansi/sgr.go](https://github.com/charmbracelet/x/blob/main/ansi/sgr.go)

```go
// Underline styles via SGR 4:n
const (
    UnderlineStyleNone     UnderlineStyle = 0
    UnderlineStyleSingle   UnderlineStyle = 1
    UnderlineStyleDouble   UnderlineStyle = 2
    UnderlineStyleCurly    UnderlineStyle = 3
    UnderlineStyleDotted   UnderlineStyle = 4
    UnderlineStyleDashed   UnderlineStyle = 5
)
```

**Recommendation for candy-vt Sgr class:**
```php
final readonly class Sgr {
    public function __construct(
        public bool $bold = false,
        public bool $italic = false,
        public int $underlineStyle = 0,  // 0=none, 1=single, 2=double, 3=curly, 4=dotted, 5=dashed
        // ...
    ) {}
}
```

**Effort:** Low — add new field and update handler parsing.

---

## 7. Synchronized Output (DECSET 2026)

### 7.1 Purpose

When bracketed paste mode (DECSET 2004) tells the terminal to wrap pasted text in markers, synchronized output (DECSET 2026) tells the terminal that the application will send output in discrete chunks, and the terminal should wait for a flush sequence before rendering.

**Flow:**
1. App sends `ESC[?2026h` to enable
2. App sends rendering commands
3. App sends `ESC[202S` (sync updates) or waits for `flush` callback
4. Terminal batches renders to avoid visual tearing

### 7.1 Implementation in charmbracelet/x/vt

**Source:** [charmbracelet/x/vt — sync.go](https://github.com/charmbracelet/x/blob/main/ansi/sync.go) (likely)

The upstream recently added `syncUpdate` flag to Mode and a corresponding callback.

**Recommendation for candy-vt:**
Add `syncUpdate` handling (already in Mode) + callback mechanism:
```php
public function __construct(
    // ...
    public ?callable $onSyncFlush = null,  // Called when sync flush needed
) {}
```

**Effort:** Low-Medium — Mode flag already exists, need callback integration.

---

## 8. Kitty Graphics Protocol (Sixel Alternative)

### 8.1 Overview

Kitty graphics protocol (0x47 escape sequence) provides:
- 24-bit color
- Vector graphics
- Image transmission (base64, direct)
- Query support

**Upstream status in charmbracelet/x/vt:**
- `vt/graphics.go` — partial implementation
- Supports transmission, query, removal

**Recommendation:** Defer for now — complex protocol, needs dedicated handler.

---

## 9. DECRQSS (Request for Presentation State)

### 9.1 Purpose

Allows applications to query the current terminal state (cursor position, tab stops, mode settings, etc.).

**Format:** `ESC[$\p` (DCS) with response via `ESC[$\p` response

**Recommendation:** Not needed pre-1.0 — rare sequence, only used by specialized terminal tools.

---

## 10. Mouse Protocol Comparison

### 10.1 Current Implementation

candy-vt tracks mouse modes:
- `mouseAny` (1000) — button events
- `mouseCellMotion` (1002) — button + drag
- `mouseExtended` (1003) — any motion
- `mouseSgr` (1006) — SGR coordinate format
- `mouseHighlights` (1001) — highlight tracking (reserved)

### 10.2 Missing

| Feature | Notes |
|---------|-------|
| DECSET 1004 (FocusIn/FocusOut) | Focus event reporting |
| SGR Mouse extended (1015) | urxvt-style coordinate encoding |
| Mouse drag threshold | Prevent flood of events during drag |
| Mouse encoding selection | Legacy vs. UTF-8 vs. SGR |

**Recommendation:** Add `focusEvent` tracking to Mode + handler for `CSI I`/`CSI O`.

---

## 11. Prioritized Recommendations

### P0 — Must Have (Bug Fixes / Correctness)

| # | Item | Description | Effort |
|---|------|-------------|--------|
| P0.1 | **DECSTBM scroll margins** | Vertical scroll within margins, not just buffer bottom. Needed for `tmux` panes and vim status bar. | Medium |
| P0.2 | **Auto-wrap with DECAWM** | Line wrap when cursor at right edge and character printed. Currently clamps. | Medium |
| P0.3 | **Subparameter parsing (`:)`** | CSI 38:2:... and other subparam sequences. Fixes some SGR edge cases. | Medium |

### P1 — Should Have (Feature Parity)

| # | Item | Description | Effort |
|---|------|-------------|--------|
| P1.1 | **Scrollback buffer** | Ring buffer storing scrolled-off lines. Default 10000 lines. | Medium |
| P1.2 | **SGR underline styles** | SGR 4:n for single/double/curly/dotted/dashed underline. | Low |
| P1.3 | **DECOM (Origin Mode)** | Cursor addressing relative to scroll region when set. | Low-Medium |
| P1.4 | **Cursor shape (DECSCUSR)** | CSI q for block/underline/pipe cursor shape. | Low |
| P1.5 | **Focus events (DECSET 1004)** | FocusIn/FocusOut via CSI I/O. | Low |

### P2 — Nice to Have (Better UX)

| # | Item | Description | Effort |
|---|------|-------------|--------|
| P2.1 | **Flat grid storage** | Parallel `BufferLinear` with flat array for faster diff iteration. | Low |
| P2.2 | **BCE (Background Color Erase)** | Erased cells inherit current background color, not empty. | Low-Medium |
| P2.3 | **Combining character composition** | ZWJ and combining marks compose onto previous cell. | High |
| P2.4 | **Synchronized output callback** | Add `onSyncFlush` callback for batched rendering. | Low |

### P3 — Future (Post 1.0)

| # | Item | Description | Effort |
|---|------|-------------|--------|
| P3.1 | **Kitty graphics protocol** | Image support via sixel alternative. | High |
| P3.2 | **Sixel decoder** | DECSixel rendering to grid. | High |
| P3.3 | **DECRQSS** | Query terminal state. | Medium |

---

## 12. Effort Estimates

| Priority | Total Items | Estimated Total Effort |
|----------|-------------|------------------------|
| P0 (Must Have) | 3 | ~9 person-days |
| P1 (Should Have) | 5 | ~6 person-days |
| P2 (Nice to Have) | 4 | ~5 person-days |
| P3 (Future) | 3 | ~15+ person-days |

**Note:** These are rough estimates assuming familiarity with VT100 spec and existing codebase.

---

## 13. Key Files to Modify for P0

### P0.1 DECSTBM Scroll Margins

**Files:**
- `src/Handler/ScrollHandler.php` — Add margin awareness to `index()`, `reverseIndex()`, `nextLine()`
- `src/Mode/Mode.php` — Add `scrollTop`/`scrollBottom` margin fields
- `src/Handler/ModeHandler.php` — Handle DECSTBM (CSI r)

### P0.2 Auto-wrap

**Files:**
- `src/Handler/ScreenHandler.php` — In `printChar()`, wrap instead of clamp when at right edge and DECAWM is set
- `src/Mode/Mode.php` — Add `autoWrap` boolean field

### P0.3 Subparameter Parsing

**Files:**
- `src/Parser/Transitions.php` — Modify OSC/DCS string state ranges to handle `:`
- `src/Parser/Parser.php` — Update `param()` to recognize `:` subparam separator
- `src/Handler/SgrHandler.php` — Parse subparams for SGR 38:2 etc.

---

## 14. References

### Upstream
- [charmbracelet/x/ansi/parser](https://github.com/charmbracelet/x/tree/main/ansi/parser) — Go VT500 parser
- [charmbracelet/x/vt](https://github.com/charmbracelet/x/tree/main/vt) — Virtual terminal emulator
- [charmbracelet/x/vt/commit/2969fce](https://github.com/charmbracelet/x/commit/2969fcee80035444c85f98baa13389201d6edd0c) — Scrollback addition (March 2026)
- [charmbracelet/ultraviolet](https://github.com/charmbracelet/ultraviolet) — Terminal UI primitives

### Rust
- [alacritty/vte](https://github.com/alacritty/vte) — Rust VTE parser (310 stars)
- [alacritty/vte/issues/22](https://github.com/jwilm/vte/issues/22) — Subparameter discussion

### Other Terminal Emulators
- [nsf/termbox-go](https://github.com/nsf/termbox-go) — Minimalist cell-based terminal API
- [gdamore/tcell](https://github.com/gdamore/tcell) — Enhanced terminal library (successor to termbox)
- [alacritty/alacritty](https://github.com/alacritty/alacritty) — GPU-accelerated terminal (scrollback ring buffer)
- [st suckless scrollback](http://st.suckless.org/patches/scrollback) — Ring buffer implementation

### Specifications
- [VT100.net — DEC ANSI Parser](https://vt100.net/emu/dec_ansi_parser) — Paul-Williams state machine
- [ECMA-48](https://www.ecma-international.org/publications-and-standards/standards/ecma-48/) — Control functions for Coded Character Sets
- [xterm sequence definitions](https://invisible-island.net/xterm/ctlseqs/ctlseqs.html) — CSI, OSC, DCS sequences

---

## 15. Summary

candy-vt is a well-structured port of charmbracelet/x/vt with clean separation between parser, handlers, and state. The most impactful improvements would be:

1. **DECSTBM scroll margins** (P0) — Required for real terminal emulation (tmux, vim, etc.)
2. **Auto-wrap** (P0) — Fundamental terminal behavior
3. **Scrollback buffer** (P1) — Essential for interactive terminal applications
4. **Subparameter parsing** (P0) — Correctness fix for SGR and other sequences

The cell grid management using nested PHP arrays is less cache-friendly than Go's flat arrays, but acceptable for most use cases. If performance becomes critical, a parallel flat-array implementation could be added.

The codebase is well-tested and follows immutable/fluent patterns correctly. Adding new features should follow the existing handler decomposition (CursorHandler, SgrHandler, etc.) for testability.
