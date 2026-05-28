# charmbracelet/bubbles

## Metadata
- **URL:** https://github.com/charmbracelet/bubbles
- **Language:** Go
- **Stars:** ~2,000+ (MIT licensed)
- **License:** MIT
- **Description:** A collection of pre-built, composable TUI components for Bubble Tea applications. Provides 14 components (Spinner, TextInput, TextArea, Table, Progress, Paginator, Viewport, List, FilePicker, Timer, Stopwatch, Help, Key, Cursor) used in production by Charm tools like Crush.

## Feature List
- **Spinner** — Animated loading indicator with 12 built-in frames (Line, Dot, MiniDot, Jump, Pulse, Points, Globe, Moon, Monkey, Meter, Hamburger, Ellipsis)
- **TextInput** — Single-line text entry with echo modes (normal/password/none), validation, suggestions, clipboard paste, word/character navigation, undo/redo, and virtual cursor
- **TextArea** — Multi-line text input with line numbers, soft-wrapping, clipboard paste, transpose characters, case transforms (upper/lower/capitalize), and extensive keybindings
- **Table** — Tabular data display with column/row navigation, pagination, and help integration
- **Progress** — Progress bar with gradient fills, color blending (half-block Unicode), and 60fps animation via Harmonica
- **Paginator** — Pagination logic with Arabic numerals or dot-style indicators
- **Viewport** — Scrollable content area with mouse wheel support, soft-wrap, gutter functions, and high-performance mode using alternate screen buffer
- **List** — Feature-rich list component with fuzzy filtering (sahilm/fuzzy), pagination, auto-generated help, status messages, activity spinner, and item delegates
- **FilePicker** — File system browser with hidden file toggle, extension filtering, and directory navigation
- **Timer** — Countdown timer with configurable tick intervals and timeout messages
- **Stopwatch** — Count-up stopwatch with start/stop/reset controls
- **Help** — Auto-generated help view from keybindings with short/long modes
- **Key** — Keybinding framework with configurable keys, help text, and enable/disable semantics
- **Cursor** — Virtual cursor manager for text inputs with blink modes (blink/static/hide)

## Key Components

### Spinner
- `New(opts ...Option) Model` — Creates spinner with options
- `Model.Update(msg tea.Msg) (Model, tea.Cmd)` — Tea update loop
- `Model.View() string` — Renders current frame
- `Model.Tick() tea.Msg` — Command to advance one frame
- `WithSpinner(Spinner)` / `WithStyle(lipgloss.Style)` — Option functions

### TextInput
- `New() Model` — Creates with defaults
- `Model.Update(msg tea.Msg) (Model, tea.Cmd)` — Handles keypresses, paste, suggestions
- `Model.View() string` — Renders prompt + value + cursor + suggestions
- `Model.SetValue(s) / Model.Value() string` — Get/set text
- `Model.SetCursor(pos) / Model.CursorStart() / Model.CursorEnd()` — Cursor movement
- `Model.Focus() / Model.Blur()` — Focus state
- `Model.SetSuggestions([]string)` — Autocomplete suggestions
- `Model.SetWidth(w)` — Horizontal viewport width
- `Model.ValidateFunc` — Input validation callback
- `Paste() / Blink()` — Commands

### TextArea
- `New() Model` — Creates with defaults
- `Model.Update(msg tea.Msg) (Model, tea.Cmd)` — Full multi-line editing
- `Model.View() string` — Renders with line numbers, prompts
- `Model.SetValue(s) / Model.Value() string`
- `Model.SetCursor(row, col) / CursorRow() / CursorColumn()`
- `Model.Focus() / Model.Blur()`
- Line number display, soft-wrap, clipboard integration

### Table
- `New(columns []Column, rows []Row)` — Constructor
- `Model.Update(msg tea.Msg) (Model, tea.Cmd)`
- `Model.View() string`
- `Model.SetColumns/SetRows`
- Navigation via KeyMap (LineUp/Down, PageUp/Down, HalfPageUp/Down, GotoTop/Bottom)

### Progress
- `New(opts ...Option) Model`
- `Model.Update(msg tea.Msg) (Model, tea.Cmd)` — 60fps animation
- `Model.View() string` — Renders bar with percentage
- `Model.SetTotal(float64)` / `Model.Percent() float64`
- `WithColors(color...)` / `WithDefaultBlend()` / `WithoutPercentage()` — Options

### List
- `New(items []Item, delegate ItemDelegate, opts ...Option) Model`
- `Model.Update(msg tea.Msg) (Model, tea.Cmd)`
- `Model.View() string` — Renders list with optional title/filter/pagination/help
- `Model.AddItem(item Item)` / `Model.RemoveItem(index)`
- `Model.ResetFilter()` / `Model.FilterState() FilterState`
- Fuzzy filtering via `sahilm/fuzzy` with `DefaultFilter` / `UnsortedFilter`

### Viewport
- `New(opts ...Option) Model`
- `Model.Update(msg tea.Msg) (Model, tea.Cmd)`
- `Model.View() string`
- `Model.ScrollTop() / ScrollBottom() / ScrollTo(line)`
- `Model.SetContent(string)` — Lines to display
- `Model.YOffset() int` — Current scroll position
- `WithWidth / WithHeight` — Dimensions
- `MouseWheelEnabled` / `FillHeight` / `SoftWrap` — Options

### Help
- `New() Model`
- `Model.Update(msg tea.Msg) (Model, tea.Cmd)`
- `Model.View() string`
- `Model.ShowAll` — Toggle short/full help
- Generates help text from `KeyMap` interface

### Key
- `NewBinding(opts ...BindingOpt) Binding` — Creates binding
- `WithKeys("up", "k")` — Keys that trigger binding
- `WithHelp("↑/k", "move up")` — Help text
- `WithDisabled()` — Disables binding
- `Matches(k Key, b ...Binding) bool` — Checks if key matches any binding

### Cursor
- `New() Model`
- `Model.Update(msg tea.Msg) (Model, tea.Cmd)`
- `Model.View() string`
- `Model.Blink() / Focus() / Blur()`
- `Model.SetMode(Mode)` — CursorBlink / CursorStatic / CursorHide
- `Model.SetChar(string)` — Character under cursor

## Notable Algorithms / Named Patterns
- **Elm Architecture (Model/Update/View)** — Every component implements the Tea.Model interface with `Update(tea.Msg) (Model, tea.Cmd)` and `View() string`
- **ID-based Message Routing** — Spinner, Timer, Stopwatch, Cursor use atomic ID counters to ensure messages only reach intended components
- **Tag-based Message Gating** — Tick messages carry incrementing `tag` values to prevent stale/duplicate frame messages
- **Context-based Cursor Blinking** — Uses `context.WithTimeout` for cancellable blink cycles
- **Fuzzy Filtering** — List uses `sahilm/fuzzy` library for real-time filtering with matched index reporting
- **Virtual Cursor** — TextInput/TextArea use a virtual cursor model that renders inline (block/underline/bar) rather than using terminal cursor positioning
- **Half-block Color Blending** — Progress bar uses Unicode half-block (`▌`) with separate foreground/background colors for 2x color resolution
- **Soft-wrapped Line Info** — TextArea tracks `LineInfo` with Width, Height, CharWidth, ColumnOffset, RowOffset for precise cursor positioning across wrapped lines
- **Option Functional Builders** — All components use `Option func(*Model)` pattern for configuration
- **KeyMap Interface** — Help component introspects `ShortHelp() / FullHelp() []key.Binding` on any implementation

## Strengths
- **Mature, production-tested** — Used in Charm's own Crush shell and many other real applications
- **Comprehensive documentation** — README shows all components with screenshots, each package has GoDoc
- **Consistent API** — Every component follows the same Elm-architecture pattern
- **Composable** — Key components (Key, Cursor, Viewport) are reusable across multiple higher-level components
- **Accessible** — Virtual cursor approach works even when terminal cursor positioning is unavailable
- **Performant** — Viewport has high-performance alternate-screen-buffer mode; Progress uses 60fps with Harmonica
- **Well-tested** — Components have comprehensive test coverage with golden file tests

## Weaknesses
- **Go-specific** — The Elm-architecture pattern is native to Go; PHP ports require significant reinterpretation
- **Goroutine-based concurrency** — Tick/animator patterns rely on Go's concurrency model
- **Large components** — TextArea is 1900+ lines, List is 1300+ lines — porting scope is substantial
- **Tight Bubble Tea coupling** — Depends on `charm.land/bubbletea/v2` for tea.Msg, tea.Cmd, tea.Cursor
- **No built-in theming system** — Styling is done via per-component Style structs, not a unified theme
- **Fuzzy filtering is external** — Depends on `sahilm/fuzzy` — a pure-Go fuzzy library that would need a PHP equivalent

## SugarCraft Mapping

### sugar-bits / candy-forms
`charmbracelet/bubbles` maps directly to `SugarCraft\Bits` → `SugarCraft\Forms` (per MATCHUPS.md row 33)

| Bubbles Component | SugarCraft Component | Status |
|---|---|---|
| spinner | `SugarCraft\Forms\Spinner\Spinner` | 🟢 aliased from candy-forms |
| textinput | `SugarCraft\Forms\TextInput\TextInput` | 🟢 aliased from candy-forms |
| textarea | `SugarCraft\Forms\TextArea\TextArea` | 🟡 in progress |
| table | `SugarCraft\Table\Table` | 🟢 separate sugar-table repo |
| progress | `SugarCraft\Forms\Progress\Progress` | 🟢 |
| paginator | `SugarCraft\Forms\Paginator\Paginator` | 🟢 |
| viewport | `SugarCraft\Forms\Viewport\Viewport` | 🟢 |
| list | `SugarCraft\Forms\ItemList\ItemList` | 🟡 (fuzzy filtering gaps) |
| filepicker | `SugarCraft\Forms\FilePicker\FilePicker` | 🟢 |
| timer | `SugarCraft\Forms\Timer\Timer` | 🟢 |
| stopwatch | `SugarCraft\Forms\Stopwatch\Stopwatch` | 🟢 |
| help | `SugarCraft\Forms\Help\Help` | 🟢 |
| key | `SugarCraft\Forms\Key\Key` | 🟢 |
| cursor | `SugarCraft\Forms\Cursor\Cursor` | 🟢 |

### sugar-prompt (charmbracelet/huh)
Note: `charmbracelet/huh` is a separate form library that builds on bubbles. SugarCraft maps `huh` → `sugar-prompt` (MATCHUPS.md row 34). Bubbles components (TextInput, TextArea, List, etc.) provide the primitive widgets that huh combines into a form framework.

### candy-forms extraction
Per MATCHUPS.md row 45: "Foundation: form primitives (TextInput, TextArea, ItemList, Viewport, FilePicker, Field interface, Confirm, Form) — extraction in progress"

## Analysis

**charmbracelet/bubbles** is the definitive reference implementation for composable TUI primitives in the Bubble Tea ecosystem. It provides 14 components spanning input widgets (TextInput, TextArea, FilePicker), display widgets (Table, Progress, Viewport, List), utility widgets (Help, Paginator, Spinner), timing widgets (Timer, Stopwatch), and infrastructure (Key, Cursor). The design philosophy emphasizes the Elm architecture with pure `Update`/`View` functions, making components naturally composable and testable.

Comparing to SugarCraft's current state: the PHP port in `candy-forms` has structural parity for most components but currently lags in several areas. The **fuzzy filtering** in List/ItemList doesn't yet match the `sahilm/fuzzy` quality — the Go library provides ranked matches with character indices, which is essential for highlighted filtering UI. The **TextArea soft-wrap line tracking** in bubbles maintains complex `LineInfo` structs with Width, Height, CharWidth, ColumnOffset, RowOffset, CharOffset — the PHP port needs equivalent accounting for double-width Unicode runes when computing cursor positions across wrapped lines. The **virtual cursor** system in TextInput/TextArea is sophisticated, supporting both inline virtual cursors and real cursor reporting via `tea.Cursor` — this enables proper terminal cursor positioning for accessibility tools but adds porting complexity.

The **TextArea case transforms** (`alt+u/l/c` for uppercase/lowercase/capitalize word forward) and **character transpose** (`ctrl+t`) are minor features present in Go but not yet in PHP. The **progress bar's half-block color blending** using Unicode `▌` with separate foreground/background is elegant — it doubles the color resolution without requiring true color support, something worth carefully porting. The **Help component's KeyMap interface introspection** (calling `ShortHelp() / FullHelp()` on any implementation) is a clean polymorphic pattern that could improve the PHP port's flexibility.

Overall, bubbles is a high-quality reference for SugarCraft's component library. The Go code is readable, well-tested, and demonstrates idiomatic patterns for TUI development. The main porting challenges are Go-specific concurrency (atomic IDs, context-based timers), the fuzzy filtering library dependency, and the complex line-wrapping math in TextArea.
