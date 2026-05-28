# pterm/pterm

## Metadata
- **URL**: https://github.com/pterm/pterm
- **Language**: Go (1.25.0)
- **Stars**: ~6,000+ (GitHub badge visible on README; shallow-clone unavailable for exact count)
- **License**: MIT
- **Description**: A modern Go framework to make beautiful CLIs. It can be used without configuration, but if desired, everything can be customized down to the smallest detail. Emphasizes ease of use, cross-platform support (Windows CMD, macOS iTerm2, GitHub Actions CI), high test coverage (28,952 automated tests), ANSI color consistency with TrueColor support, a flexible Printer/component system, and comprehensive documentation.

## Feature List

- **ANSI 3/4-bit Color** — 16 standard foreground + 16 background colors via `Color` type (const definitions in `color.go:L26-L93`)
- **TrueColor / RGB** — 24-bit color support via `RGB` struct and `RGBStyle`, including per-character gradient fades (`Fade()` method, `rgb.go:L175-L210`)
- **Style System** — Compose colors, background, and text effects (Bold, Italic, Underline, etc.) into reusable `Style` chains (`color.go:L263-L396`)
- **Terminal Size Detection** — `GetTerminalWidth()`, `GetTerminalHeight()`, `GetTerminalSize()` using `golang.org/x/term`, with `FallbackTerminalWidth=80` / `FallbackTerminalHeight=10` (`terminal.go`)
- **Area Printer** — Dynamic content region that can be updated in-place using `atomicgo.dev/cursor` for cursor positioning; supports fullscreen and centered modes (`area_printer.go`)
- **Progressbar Printer** — Animated progress bar with title, count, percentage (RGB gradient from red→green), elapsed time; uses `atomicgo.dev/schedule` for periodic re-render (`progressbar_printer.go`)
- **Spinner Printer** — Loading animation loop with configurable sequence frames, timer display, and `Info`/`Success`/`Fail`/`Warning` terminal states (`spinner_printer.go`)
- **Logger** — Structured logging with `LogLevel` (TRACE/DEBUG/INFO/WARN/ERROR/FATAL/PRINT), colorful and JSON formatters, caller info, key-value args, `log/slog` integration via `SlogHandler` (`logger.go`, `slog_handler.go`)
- **Table Printer** — Renders 2D `[][]string` data with optional header row, per-column padding, alternating row styles, CSV/TSV/struct-slice import utilities (`table_printer.go`, `putils/tabledata_*.go`)
- **Box Printer** — Unicode box-drawing characters (│ ─ └ ┘ ┐ ┌) with configurable title positioning (6 positions), padding on all sides, custom corner/edge characters (`box_printer.go`)
- **Panel Printer** — 2D grid layout of content boxes with equalized column widths via `runewidth` for proper Unicode display (`panel_printer.go`)
- **Bar Chart Printer** — Vertical and horizontal bar charts with positive/negative value support, auto-scaling to terminal dimensions, value labels (`barchart.go`)
- **Heatmap Printer** — Color-intensity grid visualization (`heatmap_printer.go`)
- **BigText Printer** — ASCII art letter rendering with built-in 95-character font map (`bigtext_printer.go:L146-L564`); per-letter `Style` or `RGB` coloring
- **Header Printer** — Full-width or text-width banner with background fill and margin (`header_printer.go`)
- **Section Printer** — Indented section titles with configurable indent character and level (`section_printer.go`)
- **Tree Printer** — ASCII tree rendering via recursive `walkOverTree()` function, supporting `TreeNode` children and `LeveledList` input (`tree_printer.go`)
- **Bullet List Printer** — Indented bullet lists with per-level custom bullet/text styles (`bulletlist_printer.go`)
- **Center Printer** — Horizontal centering of text blocks, optionally per-line (`center_printer.go`)
- **Paragraph Printer** — Auto-wrapping text to terminal width (`paragraph_printer.go`)
- **Prefix Printer** — Styled prefix labels (INFO, WARN, ERROR, etc.) with `TextPrinter` interface (`prefix_printer.go`)
- **Interactive Select** — Keyboard-navigated menu with fuzzy search via `lithammer/fuzzysearch`, mouse/arrow-key/Enter support (`interactive_select_printer.go`)
- **Interactive Multi-select** — Checkbox-style multi-option selector (`interactive_multiselect_printer.go`)
- **Interactive Confirm / Continue** — Yes/No and Continue prompts (`interactive_confirm_printer.go`, `interactive_continue_printer.go`)
- **Interactive Text Input** — Line-based text entry with keyboard capture (`interactive_textinput_printer.go`)
- **Theme System** — Global `ThemeDefault` struct with ~45 named `Style` fields covering every component (`theme.go`)
- **Global Output Control** — `DisableOutput()` / `EnableOutput()` and `DisableStyling()` / `EnableStyling()` for quiet mode and file output (`pterm.go`, `print.go`)
- **Custom Writer** — `SetDefaultOutput(io.Writer)` redirects all output; per-printer `WithWriter()` overrides (`print.go:L16-L19`)
- **slog Handler** — Bridge to Go 1.21+ `log/slog` standard library (`slog_handler.go`)
- **putils Utilities** — `LettersFromString()`, `LettersFromStringWithStyle()`, `LettersFromStringWithRGB()`, `DownloadWithProgressbar()`, `RunWithSpinner()`, `TableDataFromCSV()`, `TableDataFromStructSlice()`, `TreeFromLeveledList()` (`putils/*.go`)

## Key Classes and Methods

### Core / Color
- `Color` (uint8): ANSI color constant; `.Sprint()`, `.Sprintf()`, `.Print()`, `.Println()` — returns `*TextPrinter` (`color.go:L136-L256`)
- `Style` ([]Color): composable style chain; `.Add()`, `.RemoveColor()`, `.Sprint()`, `.Print()` (`color.go:L263-L396`)
- `RGB`: struct with `R`, `G`, `B` uint8 and `Background` bool; `.Fade()`, `.Sprint()`, `.Print()`, `.ToRGBStyle()` (`rgb.go:L14-L318`)
- `RGBStyle`: foreground/background `RGB` + options; `.AddOptions()`, `.Sprint()`, `.Print()` (`rgb.go:L21-L156`)

### Interfaces
- `TextPrinter`: `.Sprint()`/`.Sprintf()`/`.Sprintln()`/`.Sprintfln()` (return string) + `.Print()`/`.Printf()`/`.Println()`/`.Printfln()` + `.PrintOnError()`/`.PrintOnErrorf()` (return `*TextPrinter`) — implemented by `Color`, `Style`, `RGB`, `BoxPrinter`, `ProgressbarPrinter`, `SpinnerPrinter`, `Logger`, etc. (`interface_text_printer.go`)
- `LivePrinter`: `.GenericStart()`/`.GenericStop()` (return `*LivePrinter, error`) + `.SetWriter(io.Writer)` — implemented by `ProgressbarPrinter`, `SpinnerPrinter`, `AreaPrinter` (`interface_live_printer.go`)
- `RenderPrinter`: `.Render()` (writes to terminal) + `.Srender()` (returns string) — implemented by `TablePrinter`, `BarChartPrinter`, `BigTextPrinter`, `PanelPrinter`, `TreePrinter` (`interface_renderable_printer.go`)

### Printers
- `ProgressbarPrinter`: `.Start()`/`.Stop()`, `.Increment()`, `.Add()`, `.UpdateTitle()`, `.WithTitle()`/`.WithTotal()`/`.WithBarCharacter()` etc. (`progressbar_printer.go`)
- `SpinnerPrinter`: `.Start()`/`.Stop()`, `.UpdateText()`, `.Info()`/`.Success()`/`.Fail()`/`.Warning()` (`spinner_printer.go`)
- `AreaPrinter`: `.Start()`/`.Stop()`, `.Update()`, `.Clear()`, `.WithFullscreen()`/`.WithCenter()` (`area_printer.go`)
- `Logger`: `.Trace()`/`.Debug()`/`.Info()`/`.Warn()`/`.Error()`/`.Fatal()`/`.Print()`, `.WithLevel()`/`.WithFormatter()`/`.WithMaxWidth()`, `.Args()`, `.ArgsFromMap()` (`logger.go`)
- `TablePrinter`: `.Srender()`/`.Render()`, `.WithData()`/`.WithHasHeader()`/`.WithBoxed()`/`.WithSeparator()`, `.WithCSVReader()` (`table_printer.go`)
- `BoxPrinter`: `.Sprint()`/`.Print()`, `.WithTitle()`/`.WithTitleTopLeft()`/`.WithTitleBottomCenter()` etc., `.WithPadding()`/`.WithBoxStyle()` (`box_printer.go`)
- `BarChartPrinter`: `.Srender()`/`.Render()`, `.WithBars()`/`.WithHorizontal()`/`.WithShowValue()`/`.WithHeight()`/`.WithWidth()` (`barchart.go`)
- `BigTextPrinter`: `.Srender()`/`.Render()`, `.WithLetters()`, `.WithBigCharacters()` (`bigtext_printer.go`)
- `TreePrinter`: `.Srender()`/`.Render()`, `.WithRoot()`/`.WithIndent()`, recursive `walkOverTree()` (`tree_printer.go`)
- `PanelPrinter`: `.Srender()`/`.Render()`, `.WithPanels()`/`.WithSameColumnWidth()` (`panel_printer.go`)
- `InteractiveSelectPrinter`: `.Show()`, `.WithOptions()`/`.WithMaxHeight()`/`.WithFilter()`/`.WithOnInterruptFunc()` (`interactive_select_printer.go`)
- `InteractiveMultiSelectPrinter`: similar `.Show()` pattern with checkbox state (`interactive_multiselect_printer.go`)
- `InteractiveConfirmPrinter`, `InteractiveContinuePrinter`: `.Show()` returning bool (`interactive_confirm_printer.go`, `interactive_continue_printer.go`)
- `InteractiveTextInputPrinter`: `.Show()` returning string (`interactive_textinput_printer.go`)
- `HeaderPrinter`: `.Sprint()`/`.Print()`, `.WithFullWidth()`/`.WithBackgroundStyle()`/`.WithMargin()` (`header_printer.go`)
- `SectionPrinter`: `.Sprint()`/`.Print()`, `.WithLevel()`/`.WithIndentCharacter()` (`section_printer.go`)
- `CenterPrinter`: `.Sprint()`/`.Print()`, `.WithCenterEachLineSeparately()` (`center_printer.go`)
- `ParagraphPrinter`: `.Sprint()`, `.WithMaxWidth()` (`paragraph_printer.go`)
- `BulletListPrinter`: `.Srender()`/`.Render()`, `.WithItems()` (`bulletlist_printer.go`)
- `PrefixPrinter`: `.Sprint()`/`.Print()` (used for `Info`, `Success`, `Warning`, `Error`, `Debug`, `Fatal` global instances) (`prefix_printer.go`)
- `HeatmapPrinter`: `.Srender()`/`.Render()` (`heatmap_printer.go`)

### Utilities (putils)
- `LettersFromString(text string) Letters` — creates per-char `Letter` slice with `ThemeDefault.LetterStyle` (`putils/letters_from_string.go:L11`)
- `LettersFromStringWithStyle(text string, style *Style) Letters` — same with custom style
- `LettersFromStringWithRGB(text string, rgb RGB) Letters` — per-char RGB coloring
- `DownloadWithProgressbar()` — download file with progress bar
- `RunWithSpinner()` — run a function with spinner overlay
- `TableDataFromCSV()`, `TableDataFromTSV()`, `TableDataFromSeparatedValues()`, `TableDataFromStructSlice()` — populate `TableData`

## Notable Algorithms / Named Patterns

### MapRangeToRange (internal/map_range_to_range.go:L3-L8)
Linear interpolation between two numeric ranges. Used extensively for:
- Bar chart values → terminal character positions
- Progressbar percentage → RGB color fade
- Keyboard navigation offset mapping
```go
func MapRangeToRange(fromMin, fromMax, toMin, toMax, current float32) int {
    if fromMax-fromMin == 0 { return 0 }
    return int(toMin + ((toMax-toMin)/(fromMax-fromMin))*(current-fromMin))
}
```

### RGB.Fade() (rgb.go:L175-L210)
Multi-stop color gradient interpolation. Takes `min`, `max`, `current` float32 and variadic `end ...RGB` to interpolate across multiple color stops. Used for progress bar color (red→green) and text gradient effects.

### TextPrinter Interface Pattern
Every printer implements `TextPrinter` with 4 pairs of methods (Sprint/Sprintf/Sprintln/Sprintfln returning string, and Print/Printf/Println/Printfln writing to io). This gives all components a consistent, familiar API surface.

### LivePrinter / Cursor Atomic Pattern
`ProgressbarPrinter`, `SpinnerPrinter`, and `AreaPrinter` implement `LivePrinter` using `atomicgo.dev/cursor` for VT100 cursor control. `Start()` calls `cursor.Hide()`, `Stop()` calls `cursor.Show()`, and updates use `Fprinto()` / `\r` (carriage return) to overwrite the current line.

### Recursive Tree Walk (tree_printer.go:L142-L168)
```go
func walkOverTree(list []TreeNode, p TreePrinter, prefix string) string
```
Recursively renders tree nodes using prefix accumulation and pipe/branch Unicode characters. Handles both leaf nodes and branch nodes with children.

### Fuzzy Search in InteractiveSelect (interactive_select_printer.go:L296)
Uses `lithammer/fuzzysearch` to rank-filter options as the user types, updating `displayedOptions` window for virtual scrolling.

### go-runewidth for Unicode (box_printer.go, panel_printer.go, header_printer.go)
`github.com/mattn/go-runewidth` is used to correctly calculate visual width of Unicode characters (especially CJK-wide characters) in `BoxPrinter.Sprint()` and `PanelPrinter.Srender()`.

### slog Handler Bridge (slog_handler.go)
`SlogHandler` implements Go's `log/slog.Handler` interface, converting `slog.Attr` records to `Logger.ArgsFromMap()`, routing to appropriate `Logger.Trace/Debug/Info/Warn/Error` methods. This enables pterm logging to be used as a drop-in for the standard library's structured logger.

### Global Default Instances Pattern
Every printer type has a `DefaultXxx` package-level variable (e.g., `DefaultProgressbar`, `DefaultSpinner`, `DefaultArea`, `DefaultLogger`) pre-configured with theme defaults, allowing zero-config usage while still supporting full customization via `With*()` builder methods that return value receivers (enabling fluent chaining).

## Strengths

- **Comprehensive Coverage**: 25+ printers covering virtually every terminal UI need in a single library — no need to assemble multiple packages
- **Excellent Ergonomics**: The `With*()` builder pattern on value receivers means all printers can be used both as zero-config globals (`pterm.Info.Println("msg")`) and as fully customized instances (`pterm.DefaultProgressbar.WithTotal(100).WithTitle("Done").Start()`)
- **TrueColor + Fade Gradients**: The `RGB.Fade()` multi-stop interpolation and `RGBStyle` support enable sophisticated terminal visualizations; maps well to progress bars, charts, and text gradients
- **High Test Coverage**: 28,952 automated tests with coverage reporting; instills confidence and aids refactoring
- **Cross-Platform**: Works consistently across Windows CMD, macOS iTerm2, Linux terminals, and CI environments (GitHub Actions, etc.)
- **Interactive Input**: Full keyboard capture with arrow keys, fuzzy search filtering, and Ctrl+C graceful cancellation via `atomicgo.dev/keyboard`
- **slog Integration**: `SlogHandler` bridges to Go 1.21's standard `log/slog`, making it easy to adopt incrementally
- **Structured Logging**: The `Logger` supports colorful console and JSON output, caller info, key-value args, and max-width word-wrapping of long messages
- **Unicode Correctness**: Uses `go-runewidth` for CJK character width, ensuring box/panel/header layouts don't break with international text
- **Area Updates**: The `AreaPrinter` with `cursor.NewArea()` enables full dynamic content regions — charts, simulations, games — that don't overwrite surrounding terminal content

## Weaknesses

- **Go-Only**: Cannot be used from other languages; SugarCraft is a PHP port targeting PHP developers, so pterm is a reference implementation rather than a shared runtime
- **Fluent Value Receiver Pattern Can Bite**: `With*()` methods return value receivers (`func (p ProgressbarPrinter) WithTitle(...) *ProgressbarPrinter`), which means mutations require explicit `&p` — this is idiomatic Go but means the default instances (`DefaultProgressbar`) are never mutated, which may confuse users expecting global state changes to affect existing instances
- **No Layout Composer**: Unlike Bubble Tea's `Merge()` or Charm's `lipgloss`, pterm lacks a formal layout engine; `PanelPrinter` is a 2D grid but lacks fine-grained control for complex dashboard compositions
- **Global Mutable State**: `ActiveProgressBarPrinters`, `activeSpinnerPrinters`, `ThemeDefault` are package-level mutable variables — in concurrent programs these need external synchronization (mitigated by `loggerMutex sync.Mutex`)
- **Progressbar Renders to stderr**: `DefaultProgressbar` uses `Writer: os.Stderr` to avoid interfering with stdout data pipes, but this can surprise users who expect all output to go to the same destination
- **No Async/Await**: Spinner and progressbar updates use goroutines and `time.Sleep` rather than a proper async event loop; in high-concurrency scenarios (many simultaneous spinners) this could cause scheduling jitter
- **Dependency on `gookit/color`**: The entire color rendering pipeline is delegated to `github.com/gookit/color`; if that package has breaking changes, pterm's color output could be affected
- **Interactive Printers Are Blocking**: `keyboard.Listen()` is a blocking call with no way to multiplex keyboard events across multiple concurrent interactive components
- **No Accessibility Support**: No screen reader / braille terminal support; all visual output is purely ANSI

## SugarCraft Mapping

SugarCraft is a PHP TUI library monorepo (40+ libs, PHP 8.3+). pterm maps to SugarCraft as follows (many-to-many):

| pterm Component | SugarCraft Lib(s) | Notes |
|---|---|---|
| `Color`, `Style`, `RGB`, `RGBStyle`, `DisableColor`/`EnableColor`, `DisableStyling`/`EnableStyling` | `candy-core` | Core color system, ANSI SGR rendering, RGB TrueColor support |
| `Print`/`Sprint` family, `Fprint`/`Printo`, `SetDefaultOutput` | `candy-core` | Output functions, line clearing, raw output mode |
| `GetTerminalWidth()`/`GetTerminalHeight()`/`GetTerminalSize()` | `candy-core` | Terminal dimension detection via `golang.org/x/term` (FFI in PHP) |
| `ThemeDefault` | `candy-shine` | Default styling constants and theme preset system |
| `ProgressbarPrinter` | `sugar-bits` | Progress bar display with title/percentage/elapsed time |
| `SpinnerPrinter` | `sugar-bits` | Loading spinner with timer and Info/Success/Fail states |
| `AreaPrinter` | `sugar-bits` or `honey-bounce` | Dynamic content region; `honey-bounce` if animation/tweening is added |
| `Logger` + `SlogHandler` | `sugar-bits` | Structured logging with levels, JSON/colorful formatting |
| `TablePrinter` | `sugar-bits` | Table rendering from 2D string array, CSV import |
| `BoxPrinter` | `sugar-bits` | Unicode box-drawing with title, padding, 6 title positions |
| `PanelPrinter` | `sugar-bits` | 2D grid panel layout with equalized column widths |
| `BarChartPrinter` | `sugar-bits` (or `honey-bounce` if charts gain animation) | Vertical/horizontal bars with negative value support |
| `BigTextPrinter` | `sugar-bits` | ASCII art big letter rendering (95-char font map) |
| `HeaderPrinter` | `sugar-bits` | Full-width banner with background fill |
| `SectionPrinter` | `sugar-bits` | Indented section titles with `#` indent character |
| `TreePrinter` + `walkOverTree()` | `sugar-bits` | ASCII tree rendering from `TreeNode` recursive structure |
| `BulletListPrinter` | `sugar-bits` | Indented bullet lists with per-level custom bullets |
| `CenterPrinter` | `sugar-bits` | Horizontal text centering (block-level and per-line) |
| `ParagraphPrinter` | `sugar-bits` | Auto-wrapped text to terminal width |
| `HeatmapPrinter` | `sugar-bits` | Color-intensity grid (potential `honey-bounce` candidate) |
| `InteractiveSelectPrinter` + `InteractiveMultiSelectPrinter` + fuzzy search | `sugar-bits` | Keyboard-navigated select/multiselect with live filtering |
| `InteractiveConfirmPrinter`, `InteractiveContinuePrinter` | `sugar-bits` | Yes/No and Continue prompts |
| `InteractiveTextInputPrinter` | `sugar-bits` | Line-based text entry |
| `PrefixPrinter` (`Info`/`Success`/`Warning`/`Error`/`Debug`/`Fatal`) | `sugar-bits` | Styled prefix logging (`Info.Println("msg")` style) |
| `MapRangeToRange()` algorithm | `honey-bounce` (math) | Linear interpolation used for bar chart scaling and progress color fades |
| `RGB.Fade()` multi-stop color interpolation | `candy-shine` or `honey-bounce` | Gradient generation; potential standalone color utility lib |
| `putils.LettersFromString*` utilities | `sugar-bits` | Per-character styled letter creation for `BigTextPrinter` |
| `putils.TableDataFromCSV/StructSlice/etc` | `sugar-bits` | Data import utilities for tables |
| `putils.DownloadWithProgressbar`, `putils.RunWithSpinner` | `sugar-bits` | Composed utility functions combining multiple printers |

**Key insight**: pterm's entire feature surface maps almost 1:1 onto `sugar-bits` (the primary SugarCraft component/data lib). The color foundation (`Color`, `Style`, `RGB`, `ThemeDefault`) maps to `candy-core` and `candy-shine`. Math utilities (`MapRangeToRange`, `Fade`) could live in `honey-bounce`. The `BigTextPrinter` font map is a unique artifact — no direct SugarCraft equivalent; would need to be re-implemented in PHP with ASCII art letter definitions.

## Analysis

pterm is a remarkably comprehensive, well-tested terminal output library for Go that has matured into the de-facto standard for beautiful Go CLI tools. Its 25+ printer components cover the full spectrum from simple colored text output (`FgRed.Println("error")`) to complex interactive TUI elements (fuzzy-searching multi-select menus with keyboard navigation) to data visualization (bar charts, heatmaps, live-updating areas). The design philosophy is consistent: zero-config defaults that work out of the box, combined with a fluent `With*()` builder pattern that returns value receivers, enabling both global shortcut usage and fully customized instances without mutating shared state.

The library's architecture is organized around three key interfaces: `TextPrinter` (all formatted text output), `LivePrinter` (things that update in-place via cursor manipulation), and `RenderPrinter` (things that draw to a string then output). This clean separation of concerns means each printer only implements what it needs. The `TextPrinter` interface in particular is a masterclass in API consistency — 8 formatting methods (Sprint/Sprintf/Sprintln/Sprintfln × return string vs. write output), plus `PrintOnError`/`PrintOnErrorf`, all implemented identically across 15+ types. This is the pattern SugarCraft's `sugar-bits` should emulate at the PHP level.

The most sophisticated component is the `InteractiveSelectPrinter` which combines fuzzy search ranking (`lithammer/fuzzysearch`), virtual scrolling for large option lists (only `MaxHeight` items rendered at a time), and VT100 cursor control via `atomicgo.dev/cursor`. The `ProgressbarPrinter`'s RGB gradient for percentage display (`NewRGB(255,0,0).Fade(...)`) is a particularly nice touch — the progress bar color shifts from red to yellow to green as it fills. The `MapRangeToRange` linear interpolation algorithm (used for bar chart scaling, progress percentage, and RGB fades) is a simple but critical primitive that SugarCraft should extract as a shared utility.

The main architectural limitation is that pterm lacks a formal layout system — complex dashboards require manual coordination between `PanelPrinter`, `BoxPrinter`, and `AreaPrinter`. The `AreaPrinter` is a clever escape hatch: it captures a terminal region using `cursor.NewArea()` and can update its content without disturbing surrounding output, enabling dynamic visualizations. The trade-off is that `cursor.Area` from `atomicgo.dev/cursor` is a C-binding-based library, which limits portability and requires compilation of a Cgo dependency — a consideration for SugarCraft's PHP FFI approach.

The library's 28,952 tests across ~70 test files demonstrates an extraordinary commitment to regression coverage, with snapshot-style tests asserting exact ANSI escape sequence output. This testing discipline is a model for SugarCraft — every printer should have snapshot tests asserting exact `\x1b[...m` byte sequences. The dependency footprint is modest (5 direct imports: cursor, keyboard, schedule, gookit/color, fuzzysearch, go-runewidth, term, text), which is reasonable for the functionality provided.
