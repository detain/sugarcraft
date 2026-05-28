# charmbracelet/gum

## Metadata
- URL: https://github.com/charmbracelet/gum
- Language: Go
- Stars: ~8.7k (as of 2024)
- License: MIT
- Description: A tool for glamorous shell scripts. Gum provides highly configurable, ready-to-use utilities (choose, confirm, file, filter, format, input, join, pager, spin, style, table, write, log) to help write shell scripts with interactive TUI elements without writing Go code.

## Feature List

1. **input** — Single-line text input prompt with customizable placeholder, prompt text, password masking, cursor styles
2. **write** — Multi-line text area input with ctrl+d to submit, ctrl+e to open external editor
3. **filter** — Fuzzy-searchable list filter with real-time matching, multi-select support (--limit, --no-limit), reverse layout option
4. **choose** — Grid-based item selector (not fuzzy) with vim-like navigation (hjkl/arrows), multi-select with toggle
5. **confirm** — Yes/No confirmation prompt with exit codes 0 (yes) / 1 (no) for scripting
6. **file** — Interactive file/directory browser with tree navigation
7. **pager** — Scrollable viewport for reading files with line numbers
8. **spin** — Spinner animation while running a command, captures stdout/stderr, shows output
9. **style** — Apply lipgloss styling (colors, borders, alignment, padding, margin) to text
10. **table** — Render CSV/tabular data with cursor-based row selection
11. **format** — Format text as markdown, code (syntax highlighted), template, or emoji
12. **join** — Join text boxes horizontally or vertically (lipgloss layout composer)
13. **log** — Structured logging with levels (debug, info, warn, error) and timestamp formatting
14. **completion** — Generate shell completion scripts (bash, zsh, fish)
15. **version** — Semver constraint checking against current gum version

## Key Classes and Methods

### Core Architecture

- **main.go** — Entry point using `kong` CLI parser, sets up color profile, handles exit codes (aborted=42, timeout=43)
- **gum.go** — Root `Gum` struct defining all command substructures (`Choose`, `Confirm`, `Filter`, `Input`, etc.) via `kong` tags

### Command Implementations (per-package pattern)

Each command follows `cmd/options.go + cmd/command.go + cmd/<model>.go`:

- **input/input.go**: `model` struct with `textinput.Model`, `Init()` returns `textinput.Blink`, `Update()` handles key msgs (enter/esc/ctrl+c), `View()` renders header+input
- **filter/filter.go**: `model` with `textinput.Model` + `viewport.Model`, fuzzy matching via `sahilm/fuzzy` package, `matchedRanges()` converts byte positions to visible char positions using `uniseg` grapheme iteration
- **choose/choose.go**: `model` with `[]item`, `paginator.Model` for paging, vim-style 2D navigation (up/down/left/right), `selectAll()`/`deselectAll()` methods
- **confirm/confirm.go**: `model` with `confirmation bool`, toggle between affirmative/negative states
- **write/write.go**: `model` with `textarea.Model`, `startEditorMsg`/`editorFinishedMsg` for external editor integration via `charmbracelet/x/editor`
- **spin/spin.go**: `model` with `spinner.Model`, PTY handling via `charmbracelet/x/xpty`, `commandStart()` runs exec in background, captures output
- **table/table.go**: wraps `bubbles/table.Model`, `countView()` renders "n/total" pagination indicator
- **style/options.go**: `Styles` struct embedding all lipgloss options (foreground, background, border, align, width, height, margin, padding, bold, italic, etc.)
- **format/formats.go**: `code()` uses `glamour` for syntax highlighting, `emoji()` renders emoji, `markdown()` with theme support, `template()` uses `text/template` with `termenv.TemplateFuncs`

### Internal Utilities

- **internal/decode/align.go**: `Align` map string→lipgloss.Position (center/left/top/bottom/right)
- **internal/exit/exit.go**: `ErrExit` type for exit code propagation
- **internal/timeout/context.go**: Timeout context wrapper
- **internal/stdin/stdin.go**: stdin reading utilities
- **internal/tty/tty.go**: TTY detection utilities

## Notable Algorithms / Named Patterns

1. **Fuzzy Matching** — `filter` uses `sahilm/fuzzy` library with `fuzzy.Find()`/`fuzzy.FindNoSort()` for real-time filtering, `exactMatches()` for non-fuzzy substring matching
2. **Grapheme-aware Width Calculation** — `bytePosToVisibleCharPos()` in filter.go uses `uniseg.NewGraphemes()` to correctly map byte offsets to visible character positions for ANSI-aware highlighting
3. **Bubble Tea MVC Pattern** — All interactive commands follow `tea.Model` interface: `Init() tea.Cmd`, `Update(tea.Msg) (tea.Model, tea.Cmd)`, `View() string`
4. **Kong CLI Framework** — Command options defined as Go structs with `kong` struct tags (`short:"v"`, `cmd:""`, `help:"..."`, `env:"..."`, `default:"..."`, `enum:"..."`)
5. **PTY Handling** — `spin` uses `charmbracelet/x/xpty` to allocate pseudo-terminals for proper output capture on Unix systems
6. **Lipgloss Style Composition** — Heavy use of `lipgloss.NewStyle()` with fluent API: `.Foreground()`, `.Background()`, `.Padding()`, `.Margin()`, `.Border()`, `.Align()`
7. **Key Binding Pattern** — `key.NewBinding(key.WithKeys("..."), key.WithHelp("...", "..."))` for keyboard shortcuts with help text
8. **Ordered Clamping** — Uses `charmbracelet/x/exp/ordered.Clamp()` for bounds-safe cursor movement

## Strengths

- **Zero Go code required** — Users can create sophisticated TUI scripts purely in shell
- **Consistent API across commands** — All commands accept standardized style flags (--foreground, --background, --border, --padding, --margin, --width, --height, --align)
- **Environment variable support** — Every flag maps to `GUM_<COMMAND>_<FLAG>` env var for dotfile configuration
- **Cross-platform** — Supports Linux, macOS, Windows, FreeBSD, OpenBSD, NetBSD with package managers and binaries
- **Well-documented with animated demos** — Every command has a VHS-generated GIF in the README
- **Extensible** — Style system composes well; commands can be piped together (e.g., `gum filter | gum choose`)
- **Bubble Tea ecosystem** — Built on mature `charmbracelet/bubbles` and `charmbracelet/bubbletea` libraries
- **Exit code semantics** — `confirm` and other commands properly exit with 0/1 for scripting conditionals

## Weaknesses

- **Go-only internals** — Cannot be extended in shell; requires Go compilation for custom commands
- **No async input streaming** — stdin is read upfront, not incrementally (important for `filter` with large datasets)
- **PTY dependency** — The `spin` command has platform-specific PTY handling that can be fragile on some configurations (acknowledged in code with Windows Git Bash fallback)
- **Limited layout engine** — `join` is basic horizontal/vertical concatenation; no grid or flexbox-like layout
- **No state persistence** — Each invocation is stateless; cannot build multi-step wizards easily
- **Fuzzy only** — Filter uses fuzzy matching by default; no exact-match-first option without --no-fuzzy flag
- **Tied to Bubble Tea** — Cannot be used as a library from other Go programs without embedding the CLI

## SugarCraft Mapping

| gum command | SugarCraft lib | Notes |
|------------|----------------|-------|
| `gum input` | `sugar-prompt` (planned) | Text input prompt with styling; mirrors `bubbles/textinput` |
| `gum write` | `sugar-textarea` (planned) | Multi-line text area; mirrors `bubbles/textarea` |
| `gum filter` | `sugar-filter` (planned) | Fuzzy list filter; same fuzzy algorithm (`sahilm/fuzzy`) |
| `gum choose` | `sugar-choose` (planned) | Item selector grid; similar to `bubbles/list` + paginator |
| `gum confirm` | `sugar-confirm` (planned) | Yes/no confirmation |
| `gum file` | `sugar-file` (planned) | File tree browser |
| `gum pager` | `sugar-pager` (planned) | Viewport scroll; mirrors `bubbles/viewport` |
| `gum spin` | `sugar-spin` (planned) | Spinner + command execution |
| `gum style` | `candy-shine` | Lipgloss wrapper; `sugar-shine` in SugarCraft |
| `gum format` | `sugar-format` (planned) | Markdown/code/template/emoji rendering via glamour |
| `gum table` | `sugar-table` (planned) | Table with row selection; mirrors `bubbles/table` |
| `gum log` | `candy-log` | Structured logging |
| `gum join` | `candy-shine` | Layout joining via lipgloss; part of `sugar-shine` |
| Bubbles dep | `candy-sprinkles` | TUI component primitives (textinput, textarea, viewport, spinner, etc.) |
| Lipgloss dep | `candy-shine` / `sugar-shine` | Styling/rendering engine |

**Key insight**: `gum` is essentially a CLI wrapper around `charmbracelet/bubbles` and `charmbracelet/lipgloss`. SugarCraft's `candy-sprinkles` maps to the bubbles layer, and `candy-shine` maps to lipgloss. The gum commands themselves (`input`, `filter`, `choose`, etc.) would become individual SugarCraft leaf libs that depend on the foundation libs.

## Analysis

`charmbracelet/gum` is a CLI-first TUI building toolkit that wraps the Bubble Tea ecosystem into composable shell commands. The project exemplifies the "batteries included" philosophy — instead of writing Go to use Bubbles, users write shell scripts. This makes it uniquely accessible for DevOps engineers, script authors, and dotfile maintainers who want interactive TUI elements without leaving their shell workflow.

The architecture is intentionally flat: each command is a self-contained Go package with a clear separation between `Options` (CLI flag binding via Kong), `Command` (Kong Run callback that constructs the model and executes the Tea program), and the `Model` (the Bubble Tea model implementing Init/Update/View). This pattern is highly consistent across all 13+ commands, making the codebase easy to understand and extend.

The main limitation for Gum is that it's not a library — it's purely a binary. SugarCraft's approach of providing the same functionality as composable PHP classes would fill this gap, allowing Go developers who want to embed these TUI components in their own applications to do so without exec'ing a subprocess. The fuzzy filtering algorithm, keybinding system, and Bubble Tea MVC pattern are all directly portable. The `spin` command's PTY handling is the most platform-specific part and would need careful consideration for any cross-platform port.