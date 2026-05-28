# charmbracelet/ultraviolet

## Metadata
- **URL**: https://github.com/charmbracelet/ultraviolet
- **Language**: Go
- **Stars**: ~2.5k+ (charmbracelet ecosystem repo; exact star count not exposed in clone)
- **License**: MIT (./LICENSE)
- **Description**: Ultraviolet is a set of primitives for building terminal user interfaces in Go. It provides cell-based rendering, cross-platform input handling, and a diffing renderer inspired by ncurses—without the need for `terminfo` or `termcap` databases. Powers Bubble Tea v2 and Lip Gloss v2.

## Feature List
- **Cell-based diffing renderer** — only redraws what changed; optimizes cursor movement; uses ECH/REP/ICH/DCH escape sequences when available; scroll optimizations; minimal bandwidth (critical for SSH)
- **Universal input handling** — unified keyboard and mouse events across platforms; supports legacy encodings, Kitty keyboard protocol (with disambiguation, event types, alternate keys), SGR mouse encoding (including pixel coordinates), Windows Console input
- **Cross-platform terminal support** — first-class Unix (termios + ANSI) and Windows (Console API) support; consistent behavior across terminal emulators; no terminfo/termcap dependency
- **Alternate screen and inline modes** — fullscreen alternate screen mode preserves terminal scrollback; inline TUIs coexist with terminal context
- **Suspend/resume cycles** — `Stop()`/`Start()` can be called repeatedly; `uv.Suspend()` shells out to editors or suspends via SIGTSTP
- **Cassowary constraint-based layout solver** — partitions screen space using `Len`, `Min`, `Max`, `Percent`, `Ratio`, `Fill` constraints with priority-based conflict resolution
- **Rich styling** — ANSI SGR sequences (bold, italic, underline, strikethrough, reverse, conceal, blink); foreground/background colors; underline color; hyperlink support
- **Border primitives** — Normal, Rounded, Block, Thick, Double, HalfBlock (inner/outer) border styles with Unicode box-drawing characters
- **Progress bar sequences** — Windows Terminal progress bar API support (default, error, indeterminate, warning states)
- **Window management** — parent/child window hierarchy; shared-buffer views (`NewView`); independent buffers (`NewWindow`)
- **Styled string rendering** — decomposes ANSI-styled text into cells; supports word wrapping and truncation
- **Event types** — `KeyPressEvent`, `KeyReleaseEvent`, `MouseClickEvent`, `MouseDragEvent`, `MouseScrollEvent`, `WindowSizeEvent`, `PixelSizeEvent`, `FocusEvent`, `PasteEvent`
- **Width methods** — `WcWidth` (POSIX wcwidth-style) and `GraphemeWidth` (Unicode grapheme cluster) for correct Unicode rendering
- **Color profile detection** — automatic TrueColor/ANSI256/ANSI/plain detection with graceful degradation
- **Tab stop and backspace optimization** — platform-specific movement optimization

## Key Classes and Methods

### Core Primitives (`uv` package)

- **`Terminal`** — manages application lifecycle (raw mode, event loop, start/stop/suspend)
  - `DefaultTerminal()` — create terminal using stdin/stdout
  - `ControllingTerminal()` — create terminal using controlling TTY
  - `NewTerminal(console, opts)` — create with explicit console and options
  - `Start()` / `Stop()` / `Wait()` — lifecycle control
  - `Events()` — returns `<-chan Event`
  - `SendEvent(e)` — inject custom events
  - `Screen()` — returns `*TerminalScreen`
  - `GetSize()` / `GetWinsize()` — terminal dimensions
  - `Write(p)` / `Read(p)` — low-level console I/O

- **`TerminalScreen`** — screen state manager; implements `Screen` interface
  - `EnterAltScreen()` / `ExitAltScreen()` — switch screen buffer
  - `Resize(width, height)` — resize screen
  - `Render()` / `Flush()` — diff-and-write to terminal
  - `WriteString(seq)` — write raw ANSI escape sequence
  - `SetCell(x, y, cell)` / `CellAt(x, y)` — cell manipulation
  - `HideCursor()` / `ShowCursor()` / `SetCursor(x, y)` — cursor control
  - `SetMouseMode(mode)` — configure mouse tracking
  - `SetKeyboardEnhancements(ke)` — enable Kitty keyboard extensions
  - `SetBackgroundColor(c)` / `SetForegroundColor(c)` — set colors
  - `SetProgressBar(pb)` — Windows progress bar
  - `SetWindowTitle(t)` — OSC window title

- **`Buffer`** — off-screen cell grid; implements `Screen` and `Drawable`
  - `NewBuffer(width, height)` — create empty buffer
  - `Resize(width, height)` — resize
  - `Fill(c)` / `FillArea(c, area)` — fill with cell
  - `Clear()` / `ClearArea(area)` — clear to empty cells
  - `Clone()` / `CloneArea(area)` — duplicate buffer
  - `Draw(scr, area)` — draw buffer onto a screen
  - `InsertLine(y, n, c)` / `DeleteLine(y, n, c)` — line insertion/deletion (ANSI IL/DL)
  - `InsertCell(x, y, n, c)` / `DeleteCell(x, y, n, c)` — cell insertion/deletion (ANSI ICH/DCH)

- **`RenderBuffer`** — buffer with touched-line tracking for diffing
  - `Touch(x, y)` / `TouchLine(x, y, n)` — mark cells/lines as changed
  - `SetCell(x, y, c)` — set cell and auto-touch if changed
  - `TouchedLines()` — count of dirty lines

- **`Window`** — parent/child buffer view with bounds tracking; implements `Screen` and `Drawable`
  - `NewWindow(x, y, w, h)` — child window with own buffer
  - `NewView(x, y, w, h)` — child view sharing parent's buffer
  - `MoveTo(x, y)` / `MoveBy(dx, dy)` — reposition
  - `Resize(w, h)` — resize window
  - `Parent()` / `HasParent()` — parent hierarchy

- **`Cell`** — single terminal cell (character + style + hyperlink + width)
  - `NewCell(method, grapheme)` — create cell from grapheme
  - `Equal(o)` — cell equality (content, style, link, width)
  - `Clone()` / `Empty()` / `IsZero()`

- **`Style`** — ANSI SGR styling (fg, bg, underline, attributes)
  - `String()` — full SGR sequence
  - `Diff(from)` — minimal SGR diff sequence (transition optimization)
  - `Styled(str)` — wrap string with styled sequence
  - `Equal(o)` / `IsZero()` / `ConvertStyle(p)` — color profile conversion

- **`Line`** / **`Lines`** — row of cells; slice of rows
  - `Set(x, cell)` — set cell with wide-character overflow handling
  - `At(x)` — get cell at position
  - `Render()` — render to styled string with ANSI sequences
  - `String()` — plain text string

- **`Position`** / **`Rectangle`** — geometry types (re-exported from `image.Point`/`image.Rectangle`)
  - `Pos(x, y)` / `Rect(x, y, w, h)` — shorthand constructors

- **`Cursor`** — cursor state (position, color, shape, blink)
  - `NewCursor(x, y)` — create cursor at position

- **`KeyboardEnhancements`** — Kitty keyboard protocol flags
  - `DisambiguateEscapeCodes` — distinguish Ctrl+i/Tab, Ctrl+m/Enter
  - `ReportEventTypes` — key repeat/release events
  - `ReportAlternateKeys` — alternate key values
  - `ReportAllKeysAsEscapeCodes` — all keys as escape codes
  - `ReportAssociatedText` — text associated with key events

- **`ProgressBar`** — Windows progress bar state
  - States: `ProgressBarNone`, `ProgressBarDefault`, `ProgressBarError`, `ProgressBarIndeterminate`, `ProgressBarWarning`

- **`StyledString`** — ANSI-styled string as `Drawable`
  - `NewStyledString(str)` — parse styled string
  - `Draw(buf, area)` — render to screen
  - `Lines(m)` — decompose into `[]Line`
  - `UnicodeWidth()` / `WcWidth()` — calculate display width

- **`Drawable`** — interface implemented by anything renderable to a `Screen`
  - `Draw(scr Screen, area Rectangle)`

- **`Screen`** — interface (all drawing targets)
  - `Bounds()` / `CellAt(x, y)` / `SetCell(x, y, c)` / `WidthMethod()`

- **Border functions** — `NormalBorder()`, `RoundedBorder()`, `BlockBorder()`, `ThickBorder()`, `DoubleBorder()`, `HalfBlockBorder()` (inner/outer), `DashedBorder()`

- **`MouseMode`** — `MouseModeNone`, `MouseModePress`, `MouseModeClick`, `MouseModeDrag`, `MouseModeMotion`
- **`MouseEncoding`** — `MouseEncodingLegacy`, `MouseEncodingSGR`, `MouseEncodingSGRPixel`

### Events (`event.go`)

- **`KeyPressEvent`** — keyboard press with `MatchString()`, `Keystroke()`, `Runes()`, `Mod()`, `IsRepeat()`
- **`KeyReleaseEvent`** — keyboard release
- **`MouseClickEvent`** — mouse click with `X`, `Y`, `Button`, `Mod()`, `Drag()`
- **`MouseDragEvent`** — mouse drag (same fields as click)
- **`MouseScrollEvent`** — scroll with `Direction` (up/down)
- **`WindowSizeEvent`** — terminal resize with `Width`, `Height`
- **`PixelSizeEvent`** — pixel dimensions
- **`FocusEvent`** — focus in/out
- **`PasteEvent`** — bracketed paste content
- **`MultiEvent`** — batch of events

### Key Table (`key.go`)

- **Key constants** — `KeyUp`, `KeyDown`, `KeyLeft`, `KeyRight`, `KeyHome`, `KeyEnd`, `KeyPgUp`, `KeyPgDown`, `KeyInsert`, `KeyDelete`, `KeyBegin`, `KeyFind`, `KeySelect`, `KeyKpEnter`, `KeyKpEqual`, `KeyKpMultiply`, `KeyKpPlus`, `KeyKpComma`, `KeyKpMinus`, `KeyKpDecimal`, `KeyKp0`–`KeyKp9`, and 100+ others
- **Key modifiers** — `ModShift`, `ModAlt`, `ModCtrl`, `ModMeta`, `ModHyper`, `ModSuper`, `ModCapsLock`, `ModNumLock`, `ModScrollLock`

### Layout Package (`layout/`)

- **`Layout`** — constraint-based layout solver
  - `New(area, constraints...)` — create layout
  - `Rects() []Rectangle` — resolve constraints to rectangles
- **Constraints** — `Min(n)`, `Max(n)`, `Len(n)`, `Percent(n)`, `Ratio(x, y)`, `Fill(n)` (sealed interface)
- **Direction** — `Horizontal`, `Vertical`
- **Alignment** — `Start`, `Center`, `End`, `SpaceBetween`, `SpaceAround`, `SpaceEvenly`

### Screen Package (`screen/`)

- **`Context`** — drawing context wrapping a `Screen`
  - `NewContext(scr)` — create context
  - `DrawString(s, x, y)` — draw styled string at position
  - `Print(s)` — print at current cursor position
  - `SetStyle(s)` / `WithStyle(s)` — fluent style
  - `SetForeground(c)` / `SetBackground(c)` — fluent colors
  - `SetAttrs(a)` / `WithAttrs(a)` — SGR attributes
  - `SetLink(link)` / `WithLink(link)` — hyperlinks
- **Functions** — `Clear(scr)`, `Fill(scr, cell)`, `Clone(scr)`, `MoveCursor(scr, x, y)`

### Internal Cassowary (`internal/casso/`)

- **`Solver`** — Cassowary constraint solver
  - `NewSolver()` — create solver
  - `Add(priority, constraint)` — add constraint
  - `Val(symbol)` — get variable value
  - `HasErrors()` / `Reset()` — solver state
- **Constraint types** — `LTE` (<=), `GTE` (>=), `EQ` (=)
- **`Priority`** — constraint strength (required, strong, medium, weak)

## Notable Algorithms / Named Patterns

### Cassowary Constraint Solver Algorithm
- **Source**: `internal/casso/solver.go` + `layout/layout.go`
- The layout package implements the [Cassowary algorithm](https://en.wikipedia.org/wiki/Cassowary_(software)) for resolving competing layout constraints. Ultraviolet's implementation is a ~1:1 translation from Ratatui (Rust), adapted for Go. The solver uses symbolic variables with priority-tagged constraints and handles relaxation when constraints conflict. Priority order (highest to lowest): `Min > Max > Len > Percent > Ratio > Fill`.

### Cell-Based Diffing Renderer
- **Source**: `terminal_renderer.go`, `RenderBuffer` in `buffer.go`
- The `TerminalRenderer` compares the current and new screen state via touched-line metadata (tracked in `RenderBuffer.Touched`), generates minimal ANSI escape sequences for the delta. Uses a `capabilities` mask to determine which optimization sequences are available (ECH for character erase, REP for character repeat, ICH/DCH for insert/delete character, etc.).

### Style Diff Algorithm
- **Source**: `StyleDiff()` in `cell.go:L258-L408`
- Computes minimal SGR sequence to transition from one style to another, avoiding full resets when cheaper. Handles all attributes (bold, italic, underline variants, blink, reverse, conceal, strikethrough), foreground/background colors, and underline color with proper ordering to avoid terminal quirks.

### Touched-Line Tracking
- **Source**: `RenderBuffer.TouchLine()` in `buffer.go:L660-L683`
- Marks dirty regions as `[FirstCell, LastCell)` per line. `SetCell` compares old vs new cell before marking touched, avoiding unnecessary redraws. This is the foundation of the diffing optimization.

### Wide Character Handling
- **Source**: `Line.Set()` in `buffer.go:L41-L98`
- When overwriting part of a wide character (emoji, CJK), fills remaining width with blank cells to avoid rendering artifacts. Placeholder zero-width cells for wide char trailers are handled symmetrically.

### Kitty Keyboard Protocol
- **Source**: `KeyboardEnhancements` in `uv.go:L139-L174`, `key_table.go`
- Supports the full Kitty keyboard extension: disambiguation of ambiguous escape codes, key repeat/release reporting, alternate key values, report-all-keys-as-escape-codes, and associated text reporting.

### SGR Mouse Encoding
- **Source**: `mouse.go`, `EncodeMouseMode()` / `EncodeMouseEncoding()` in `uv.go`
- Supports Legacy (X10), SGR (extended), and SGR-Pixel coordinate encodings. Decodes button, modifier, and drag state from CSI mouse sequences.

### Color Profile Conversion
- **Source**: `ConvertStyle()` in `cell.go:L427-L451`
- Maps TrueColor to the terminal's supported profile (ANSI, ANSI256, or ASCII). Converts colors through `colorprofile.Profile` to prevent sending 24-bit color to incapable terminals.

## Strengths

- **No terminfo/termcap dependency** — self-contained terminal capability detection via direct query sequences and heuristics, making it portable and lightweight
- **Bandwidth-optimized diffing** — only sends changed cells via optimized escape sequences (ECH/REP/ICH/DCH), critical for SSH connections
- **Clean layered architecture** — `Terminal` → `TerminalScreen` → `Screen` interface → `Buffer`/`Window` → `Cell`; code depends on interfaces not implementations
- **Comprehensive input model** — unified keyboard (including Kitty protocol) and mouse handling across all platforms
- **High-quality Unicode support** — dual width methods (wcwidth, grapheme cluster) for correct CJK/emoji rendering
- **Graceful degradation** — capability detection auto-disables unsupported features; color profile conversion prevents broken output on limited terminals
- **Thread-safe event loop** — uses `golang.org/x/sync/errgroup` for concurrent input reading, event processing, and window-resize handling
- **Suspend/resume** — proper SIGTSTP handling for backgrounding (e.g., shelling out to `$EDITOR`)
- **Well-tested** — extensive test suite with snapshot tests for renderer output
- **Powers production libraries** — underpins Bubble Tea v2 and Lip Gloss v2, meaning real-world usage and stability

## Weaknesses

- **Active development** — API may change; not yet at 1.0 stable
- **Go-only** — cannot be used directly from other languages without FFI
- **No built-in layout widgets** — layout package is purely spatial; no built-in text areas, lists, or complex widgets (those live in Bubble Tea)
- **Terminal capability variance** — not all terminals support all features (progress bars, Kitty keyboard, SGR pixel coords); graceful fallback is manual in user code
- **Renderer complexity** — `terminal_renderer.go` is 1589 lines with escape sequence optimization logic that is difficult to trace
- **Limited documentation** — aside from README, doc.go, and TUTORIAL.md, inline godoc is the primary reference
- **No async rendering** — `Render()` and `Flush()` are synchronous; for very large screens the blocking may be noticeable
- **Dependency on charmbracelet/x/ansi** — significant ANSI escape sequence logic lives in a separate `charmbracelet/x/ansi` package that must be kept in sync

## SugarCraft Mapping

Ultraviolet is a terminal TUI foundation layer. SugarCraft is a PHP monorepo of TUI library ports from the Charmbracelet ecosystem. The mapping below identifies which SugarCraft libs would receive which portions of Ultraviolet's functionality:

| Ultraviolet Component | SugarCraft Lib | Notes |
|---|---|---|
| `Cell` + `Style` + `Link` (ANSI SGR, hyperlinks, wide chars) | `sugar-bits` | Styled text rendering, ANSI escape sequence generation |
| `Buffer` / `RenderBuffer` / `Line` / `Lines` (cell grid, touched-line diffing) | `candy-core` | Screen buffer model; core rendering primitives |
| `Screen` interface + `TerminalScreen` (render, flush, alternate screen, cursor) | `candy-core` | Terminal I/O, screen management |
| `Terminal` (event loop, start/stop, raw mode, suspend/resume) | `candy-core` | Application lifecycle management |
| `KeyPressEvent` / `KeyReleaseEvent` / `MouseClickEvent` / `MouseScrollEvent` / `PasteEvent` / `FocusEvent` | `sugar-bits` | Input event types and keyboard/mouse handling |
| `Window` (parent/child hierarchy, shared-buffer views) | `candy-sprinkles` | Windowing model, view composition |
| `layout` package with Cassowary algorithm | `honey-bounce` or `candy-shine` | Constraint-based layout solver |
| `Border` functions (Normal, Rounded, Block, Thick, Double, etc.) | `candy-shell` | Box-drawing borders (shells contain content) |
| `StyledString` + `screen.Context` (styled text → cells) | `sugar-bits` | High-level styled string drawing |
| `ProgressBar` + `EncodeProgressBar()` | `sugar-bits` | Progress indication (Windows Terminal sequences) |
| `Cursor` + `EncodeCursorStyle()` + `EncodeCursorColor()` | `sugar-bits` | Cursor shape/color control |
| `MouseMode` / `MouseEncoding` + Kitty keyboard enhancements | `sugar-bits` | Advanced mouse and keyboard protocol support |
| Width methods (`WcWidth`, `GraphemeWidth`) | `sugar-bits` | Unicode character width calculation |
| `WindowSizeEvent` / `PixelSizeEvent` / `Winsize` | `candy-core` | Terminal dimension reporting |
| Color profile detection (`colorprofile.Detect`) | `sugar-bits` | TrueColor/ANSI256/ANSI detection and mapping |

**Summary**: Ultraviolet maps most closely to `candy-core` (foundation: buffer, screen, terminal, events) + `sugar-bits` (styling, input, cursor, mouse, progress bars, width methods) + `candy-shell`/`candy-sprinkles`/`honey-bounce` (layout/borders/windows). There is no single equivalent — a full port would be split across multiple libs following the Charm pattern.

## Analysis

Ultraviolet is a ground-up redesign of terminal TUI primitives that supersedes the ad-hoc implementations in earlier Charm libraries (Bubble Tea v1, Lip Gloss v1). Where ncurses requires `terminfo` databases and presents a C API, Ultraviolet is pure Go with self-contained terminal capability detection. The architecture is deliberately layered: the `Screen` interface (bounds, cell access, width method) is the keystone — all rendering and drawing code depends on it, not on concrete implementations. This allows the same drawing code to target a real terminal (`TerminalScreen`), an off-screen buffer (`Buffer`), a window view (`Window`), or a test mock.

The most technically distinctive feature is the **cell-based diffing renderer** in `terminal_renderer.go`. Rather than re-rendering the entire screen on every frame, `RenderBuffer` tracks touched lines (via `TouchLine`) and the renderer computes the minimal set of ANSI escape sequences to transition from the old terminal state to the new buffer state. It uses an `ECH` (Erase Character) sequence when overwriting with spaces, `REP` (Repeat) when the same character appears consecutively, and `ICH`/`DCH` (Insert/Delete Character) when the line length changes. This optimization is critical for network connections where bandwidth is the bottleneck.

The **Cassowary constraint solver** in `internal/casso/` and `layout/layout.go` is borrowed from Ratatui (which in turn derived it from Apple's UIKit/Auto Layout). It resolves competing sizing requirements (e.g., "column A should be 30 cells wide" + "columns A+B should fit in 80 cells") via priority-tagged constraints. When constraints conflict, lower-priority ones are relaxed first (e.g., a `Ratio` preference gives way to a `Min` hard requirement). The `floatPrecisionMultiplier` of 100.0 converts integer cell positions to floating-point for solver arithmetic, preserving sub-cell precision where needed.

Input handling in `decoder.go` (54k file — the largest in the repo) is the most complex subsystem. It handles legacy xterm key sequences, CSI sequences with intermediate bytes, SS3 (single shift 3) sequences, the full Kitty keyboard protocol (5 enhancement flags), SGR extended mouse encoding (including pixel coordinates), and Windows Console input. The `eventScanner` accumulates raw bytes and timeout-expires partial sequences, ensuring correct decoding even on slow or buffering terminals. The `key_table.go` (23k) provides lookup tables mapping escape sequence bytes to `Key` constants, with optional terminfo database integration for non-xterm terminals.

Ultraviolet's approach to cross-platform support is pragmatic: Unix uses `termios` for raw mode and `fcntl`/`ioctl` for window size; Windows uses the Console API directly (via `charmbracelet/x/termios` and `charmbracelet/x/windows`). Platform-specific files (`terminal_reader_unix.go`, `terminal_reader_windows.go`, `cancelreader_windows.go`, `poll_*.go`) handle the divergence. The `poll` package abstracts over epoll (Linux), kqueue (macOS/BSD), and `select` (fallback) for input multiplexing.

The library is production-grade in that it powers Bubble Tea v2 and Lip Gloss v2 — the flagship Charm TUI frameworks — but it is explicitly API-unstable until v1.0. For SugarCraft, porting Ultraviolet would provide the foundational TUI layer upon which all other terminal components (text rendering, layout, input events) would build, following the same layered architecture that makes Ultraviolet modular and testable.
