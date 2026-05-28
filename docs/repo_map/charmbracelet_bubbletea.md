# charmbracelet/bubbletea

## Metadata
- **URL:** https://github.com/charmbracelet/bubbletea
- **Language:** Go
- **Stars:** ~23,000+ (GitHub)
- **License:** MIT
- **Description:** The fun, functional and stateful way to build terminal apps. A Go framework based on The Elm Architecture. Bubble Tea is well-suited for simple and complex terminal applications, either inline, full-window, or a mix of both.

## Feature List

### Core Architecture
- **Elm Architecture Pattern**: Model/Update/View paradigm with functional state management
- **Model Interface**: Any Go type implementing `Init()`, `Update(Msg)`, and `View()` methods
- **Message System**: Typed messages (`Msg` interface) for all I/O events (keypress, mouse, tick, etc.)
- **Command Pattern**: `Cmd` functions (`func() Msg`) for async I/O operations that return messages
- **Subscription System**: Time-based commands (Tick, Every) for recurring events
- **Batch & Sequence**: Run multiple commands concurrently or sequentially

### Input Handling
- **Keyboard Input**: Full key press/release detection with modifier support (Ctrl, Alt, Shift, Meta)
- **Mouse Input**: Click, release, wheel, and motion events with SGR extended mode
- **Focus Events**: Report terminal focus gained/lost
- **Kitty Keyboard Protocol**: Enhanced key disambiguation, repeat detection, alternate key values
- **Bracketed Paste**: Support for bracketed paste mode

### Rendering
- **High-Performance Cell-Based Renderer**: Built on `charmbracelet/ultraviolet`
- **Synchronized Output**: Terminal mode 2026 for atomic updates
- **Color Support**: True color, 256-color, and ANSI color downsampling via `charmbracelet/colorprofile`
- **Alternate Screen**: Full-window mode support
- **Optimized Cursor Movement**: Hard tabs, backspace, and newline mapping
- **Grapheme Width**: Proper Unicode grapheme cluster handling

### Terminal Features
- **Window Title**: Set terminal window title
- **Cursor Control**: Position, shape (block/underline/bar), blink, color
- **Progress Bar**: Terminal-native progress bar integration
- **Clipboard**: System clipboard read/write via ANSI sequences
- **Foreground/Background Color**: Terminal-wide color setting
- **Terminal Size**: Automatic resize detection and handling

### Program Control
- **Signal Handling**: Graceful SIGINT/SIGTERM handling
- **Panic Recovery**: Automatic recovery with terminal restoration
- **Suspend/Resume**: Ctrl+Z support with terminal state preservation
- **Logging**: Debug logging to file via `tea.LogToFile()`
- **Filter**: Event filtering before processing
- **Exec**: Run external commands (vim, shells) while pausing the TUI

### Examples
- 60+ example programs demonstrating various features
- Tutorials for basics and commands

## Key Classes and Methods

### `tea.Model` Interface
```go
type Model interface {
    Init() Cmd                    // Returns initial command (nil for none)
    Update(Msg) (Model, Cmd)       // Handle message, return updated model + command
    View() View                   // Render UI
}
```

### `tea.Program` Struct
```go
func NewProgram(model Model, opts ...ProgramOption) *Program
func (p *Program) Run() (Model, error)
func (p *Program) Send(msg Msg)              // Inject message from outside
func (p *Program) Quit()                      // Request quit
func (p *Program) Kill()                      // Force kill
func (p *Program) Println(args ...any) Cmd   // Print above TUI
func (p *Program) Printf(template string, args ...any) Cmd
```

### `tea.View` Struct
```go
type View struct {
    Content               string
    OnMouse              func(MouseMsg) Cmd
    Cursor               *Cursor
    BackgroundColor      color.Color
    ForegroundColor      color.Color
    WindowTitle          string
    ProgressBar          *ProgressBar
    AltScreen            bool
    ReportFocus          bool
    DisableBracketedPasteMode bool
    MouseMode            MouseMode
    KeyboardEnhancements  KeyboardEnhancements
}
```

### `tea.Cmd` Type
```go
type Cmd func() Msg  // Commands are functions that return messages
```

### Special Commands
```go
func Quit() Msg                        // Special quit command
func Suspend() Msg                      // Suspend program
func Interrupt() Msg                   // Send interrupt
func Batch(cmds ...Cmd) Cmd            // Run commands concurrently
func Sequence(cmds ...Cmd) Cmd         // Run commands in order
func Every(duration time.Duration, fn func(time.Time) Msg) Cmd
func Tick(d time.Duration, fn func(time.Time) Msg) Cmd
func RequestWindowSize() Msg
func Exec(c ExecCommand, fn ExecCallback) Cmd
func ExecProcess(c *exec.Cmd, fn ExecCallback) Cmd
```

### `tea.KeyPressMsg` / `tea.Key`
```go
type Key struct {
    Text         string   // Printable characters
    Mod          KeyMod  // Modifier keys
    Code         rune     // Key code
    ShiftedCode  rune     // Shifted key (for modifiers)
    BaseCode     rune     // Base key (international keyboards)
    IsRepeat     bool     // Key repeat
}
func (k Key) String() string           // Human-readable key name
func (k Key) Keystroke() string        // "ctrl+shift+a" format
```

### `tea.Mouse`
```go
type Mouse struct {
    X, Y    int          // Coordinates (0-based)
    Button  MouseButton  // Which button
    Mod     KeyMod       // Modifier keys
}
```

### Mouse Message Types
- `MouseClickMsg` — Button click
- `MouseReleaseMsg` — Button release
- `MouseWheelMsg` — Wheel scroll
- `MouseMotionMsg` — Movement (with/without button held)

### Program Options
```go
func WithContext(ctx context.Context) ProgramOption
func WithInput(input io.Reader) ProgramOption
func WithOutput(output io.Writer) ProgramOption
func WithEnvironment(env []string) ProgramOption
func WithoutSignalHandler() ProgramOption
func WithoutCatchPanics() ProgramOption
func WithoutSignals() ProgramOption
func WithoutRenderer() ProgramOption
func WithFilter(filter func(Model, Msg) Msg) ProgramOption
func WithFPS(fps int) ProgramOption
func WithColorProfile(profile colorprofile.Profile) ProgramOption
func WithWindowSize(width, height int) ProgramOption
```

## Notable Algorithms / Named Patterns

### Elm Architecture
The framework implements The Elm Architecture:
1. **Model** — Single source of truth for application state
2. **Update** — Pure function that takes current state + message, returns new state + commands
3. **View** — Render function that produces UI from state

This is a unidirectional data flow pattern contrasting with MVC.

### Command Pattern for Async I/O
```go
// cmd is a function that performs I/O and returns a message
type Cmd func() Msg

// Example: timer that sends tickMsg every second
func tick() tea.Msg {
    time.Sleep(time.Second)
    return tickMsg{}
}
```

### BatchMsg & SequenceMsg
- `BatchMsg` — Executes commands concurrently, no ordering guarantees
- `sequenceMsg` — Executes commands sequentially

### Cursed Renderer (cell-based)
The default renderer (`cursedRenderer`) uses:
- `uv.TerminalRenderer` for actual rendering
- `uv.ScreenBuffer` for cell-based buffer
- Delta rendering (only redraws changed lines)
- Synchronized output mode (ANSI 2026) for flicker-free updates
- Hard tab optimization for cursor movement
- Backspace optimization

### Terminal Capability Detection
```go
// Automatically detects terminals supporting synchronized output (mode 2026)
func shouldQuerySynchronizedOutput(environ uv.Environ) bool {
    // Returns true for: Windows Terminal, ghostty, wezterm, alacritty, kitty, rio
    // Excludes SSH sessions unless it's a known-good terminal
}
```

### Every vs Tick
- `Every` — Syncs with system clock (tick at :00, :15, :30, :45 for minute intervals)
- `Tick` — Independent of clock (precisely every N duration from start)

## Strengths

1. **Battle-Tested**: Used in production by Microsoft Azure, AWS, NVIDIA, CockroachDB, MinIO, Ubuntu, and 18,000+ projects
2. **Clean Architecture**: Elm pattern is well-understood and produces testable, predictable code
3. **High Performance**: Cell-based renderer with delta updates and synchronized output
4. **Excellent Keyboard Handling**: Full modifier support, key repeat detection, Kitty keyboard protocol
5. **Comprehensive Input**: Keyboard, mouse (click, wheel, motion), focus, paste
6. **Rich Terminal Features**: Alt screen, cursor control, colors, progress bar, clipboard
7. **Well-Documented**: Tutorial, examples, upgrade guide, GoDocs
8. **Extensible**: Companion libraries (bubbles, lipgloss, harmonica, bubblezone)
9. **Graceful Degradation**: Falls back gracefully on older terminals
10. **Panic Recovery**: Automatically restores terminal state on crashes
11. **Signal Handling**: Proper handling of SIGINT, SIGTERM, SIGTSTP
12. **Debugging Support**: Built-in logging to file

## Weaknesses

1. **Go-Centric**: The Elm architecture maps naturally to Go's type system and concurrency
2. **Goroutine Memory**: Each command spawns a goroutine that may leak if not properly handled
3. **No Built-in Layout System**: View is just a string (use lipgloss for layout)
4. **Async Command Cancellation**: Commands cannot be cancelled mid-execution
5. **Complexity for Simple TUIs**: Full Elm pattern may be overkill for simple scripts
6. **Terminal Dependency**: Rich features require modern terminals (not all features work everywhere)
7. **Single-Threaded Update**: The update function runs on the main thread (intentional for simplicity)
8. **Learning Curve**: Elm pattern is unfamiliar to developers used to imperative UI

## SugarCraft Mapping

### Direct Mappings (1:1)

| bubbletea | SugarCraft | Notes |
|---|---|---|
| `bubbletea` | `candy-core` | The TUI runtime — Model interface, Program, Update loop, renderer |
| `lipgloss` | `candy-sprinkles` | Declarative styling, colors, layout |
| `harmonica` | `honey-bounce` | Spring animations for smooth motion |
| `bubblezone` | `candy-zone` | Mouse zone tracking for clickable regions |
| `bubbles` | `sugar-bits` | 14 prebuilt components (TextInput, List, Table, etc.) |
| `ntcharts` | `sugar-charts` | Terminal charting library |
| `huh` | `sugar-prompt` | Interactive form library |
| `glamour` | `candy-shine` | Markdown rendering |
| `colorprofile` | `candy-palette` | Terminal color detection |
| `exec` (internal) | `candy-pty` | Process spawning (PTY) |
| `x/vt` | `candy-vt` | Virtual terminal emulation |
| `x/vcr` | `candy-vcr` | Session recording/replay |

### Many-to-Many Feature Mapping

| bubbletea Feature | SugarCraft Equivalent |
|---|---|
| Model interface | `SugarCraft\Core\Model` |
| Program / Run loop | `SugarCraft\Core\Program` |
| Cmd (func() Msg) | `Closure(): ?Msg` |
| Msg types | `SugarCraft\Core\Msg` subclasses |
| View (string) | `SugarCraft\Core\View` |
| tea.KeyPressMsg | `SugarCraft\Core\KeyMsg` |
| tea.MouseMsg | `SugarCraft\Core\MouseMsg` |
| tea.Quit | `SugarCraft\Core\QuitMsg` |
| tea.Batch / Sequence | `SugarCraft\Core\Batch`, `SugarCraft\Core\Sequence` |
| tea.Every / Tick | `SugarCraft\Core\Every`, `SugarCraft\Core\Tick` |
| tea.ExecProcess | `SugarCraft\Pty\Process` + `SugarCraft\Core\Program::exec()` |
| tea.NewProgram + options | `SugarCraft\Core\Program::new()` + `ProgramOption` |
| Alt screen | `View::altScreen` |
| Mouse mode | `View::mouseMode` |
| Cursor control | `View::cursor` |
| Window title | `View::windowTitle` |
| Progress bar | `View::progressBar` |
| Color profile | `SugarCraft\Palette\ColorProfile` |

## Analysis

Bubble Tea is a mature, production-ready TUI framework that brings The Elm Architecture to Go terminal applications. Its core innovation is the unidirectional data flow pattern: models are immutable data structures, updates are pure functions that return both a new model and optional commands, and views simply render the current state. This architectural choice makes applications highly testable and predictable, as the update logic never has side effects beyond returning a new model.

The framework excels at handling the complexity of real terminal applications. Its high-performance cell-based renderer built on the ultraviolet library handles delta updates efficiently, only redrawing changed lines. Support for synchronized output mode (ANSI 2026) eliminates flickering on supported terminals. The comprehensive input handling covers keyboard (with full modifier support via the Kitty keyboard protocol), mouse (click, wheel, motion with SGR extended mode), focus events, and bracketed paste. The renderer gracefully degrades on older terminals.

SugarCraft's `candy-core` port maps directly to bubbletea's core: the `Model` interface with `init()`/`update()`/`view()` mirrors the Go interface exactly. The main challenge in the PHP port is simulating Go's concurrency model where commands are goroutines that send messages back via channels. PHP uses callbacks and the ReactPHP event loop instead. The immutable, fluent pattern with `with*()` setters and `mutate()` helpers that return new instances mirrors bubbletea's approach of treating models as values. Key differences include PHP's lack of native tuples (the `[Model, Cmd]` return type becomes `array{0: Model, 1: ?Closure}`) and the fundamentally single-threaded nature of PHP compared to Go's lightweight goroutines. The PHP port successfully captures bubbletea's architectural essence while adapting to PHP's event-driven paradigm through ReactPHP integration.
