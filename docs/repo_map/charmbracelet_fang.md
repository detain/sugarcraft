# charmbracelet/fang

## Metadata
- **URL:** https://github.com/charmbracelet/fang
- **Language:** Go
- **Stars:** ~500-600 (estimate based on Charm ecosystem popularity)
- **License:** MIT (Copyright 2024-2026 Charmbracelet, Inc)
- **Description:** CLI starter kit — a small, experimental library for batteries-included Cobra applications. Provides fancy styled help/usage pages, styled errors, automatic `--version` support, manpage generation via `mango`, shell completions, and themeable output.

## Feature List
- **Fancy help output** — fully styled help and usage pages with ANSI colors
- **Fancy errors** — fully styled error messages with header and details
- **Automatic `--version`** — automatically wires `--version` flag to build info or custom version
- **Manpage generation** — hidden `man` command using `mango-cobra` (generates roff directly instead of markdown)
- **Shell completions** — adds a `completion` command for generating shell completions
- **Themeable output** — built-in themes (`DefaultColorScheme`, `AnsiColorScheme`) with light/dark auto-detection
- **Silent usage on error** — `SilenceUsage = true` (help not shown after user error)
- **Virtual Terminal Processing** — enables VT processing on Windows for ANSI escape support
- **Signal handling** — optional signal notification via `WithNotifySignal`
- **Command grouping** — respects Cobra's `Group` IDs for organizing subcommands

## Key Classes and Methods

### `fang.go`
- `Execute(ctx, root *cobra.Command, options ...Option)` — Main entry point; applies fang to a Cobra command tree
- `buildVersion(opts)` — Constructs version string from build info or explicit version/commit
- `getKey(info, key)` — Extracts VCS revision from `debug.BuildInfo.Settings`

### `help.go`
- `helpFn(cobra.Command, *colorprofile.Writer, Styles)` — Renders the styled help output
- `DefaultErrorHandler(io.Writer, Styles, error)` — Default error rendering with styled header
- `isUsageError(error)` — Detects usage errors via string prefix matching (hack for Cobra)
- `styleUsage(cobra.Command, Program, bool)` — Styles the usage line, parsing use vs use-line
- `styleExamples(cobra.Command, Styles) []string` — Styles example lines, handling comments/args/flags/pipes
- `styleExample(cobra.Command, string, bool, Codeblock)` — Parses and styles a single example line
- `evalCmds(cobra.Command, Styles)` — Evaluates subcommands into styled key/help map
- `evalFlags(cobra.Command, Styles)` — Evaluates flags into styled key/help map
- `evalGroups(cobra.Command)` — Extracts command groups
- `renderGroup(io.Writer, Styles, int, string, iter.Seq2)` — Renders a group of items with alignment
- `calculateSpace([]string, []string)` — Calculates column spacing for alignment

### `theme.go`
- `ColorScheme` struct — Defines colors for: Base, Title, Description, Codeblock, Program, DimmedArgument, Comment, Flag, FlagDefault, Command, QuotedString, Argument, Help, Dash, ErrorHeader, ErrorDetails
- `DefaultColorScheme(lipgloss.LightDarkFunc)` — Returns default Charm-themed colorscheme
- `AnsiColorScheme(lipgloss.LightDarkFunc)` — Returns ANSI-compatible colorscheme
- `Styles` struct — Lipgloss styles for Text, Title, Span, ErrorHeader, ErrorText, FlagDescription, FlagDefault, Codeblock, Program
- `Codeblock` struct — Styles for code blocks (Base, Program, Text, Comment)
- `Program` struct — Styles for program name, command, flag, argument rendering
- `makeStyles(ColorScheme)` — Converts ColorScheme to concrete Lipgloss Styles
- `titleFirstWord(string)` — Capitalizes first word while preserving whitespace

### Option Functions (fang.go)
- `WithoutCompletions()` — Disables shell completion generation
- `WithoutManpage()` — Disables man page generation
- `WithoutVersion()` — Disables automatic version flag
- `WithVersion(string)` — Sets explicit version string
- `WithCommit(string)` — Sets explicit commit SHA
- `WithColorSchemeFunc(ColorSchemeFunc)` — Sets custom colorscheme factory
- `WithErrorHandler(ErrorHandler)` — Sets custom error handler
- `WithNotifySignal(...os.Signal)` — Sets signals that interrupt execution

### Platform-Specific (fang_windows.go, fang_other.go)
- `enableVirtualTerminalProcessing(io.Writer)` — Enables Windows VT processing (Windows only, no-op on others)

## Notable Algorithms / Named Patterns
- **Option pattern** — Functional options (`type Option func(*settings)`) for deferred configuration
- **Lazy terminal width** — `sync.OnceValue` caching for terminal size detection with `__FANG_TEST_WIDTH` override
- **Iter.Seq2 iteration** — Go 1.25 iter package usage for command/flag rendering
- **Build info version extraction** — Reads `runtime/debug.BuildInfo` for VCS revision and version
- **Usage error detection** — String prefix matching on error messages (Cobra PR #2266 workaround)
- **Color scheme factory** — `ColorSchemeFunc` accepts `lipgloss.LightDarkFunc` for automatic light/dark adaptation
- **ANSI truncation** — Uses `ansi.Truncate` for example text overflow handling

## Strengths
- Minimal API surface — single `Execute()` entry point with clean option pattern
- Themeable — separate `ColorScheme` and `Styles` for full customization
- Light/dark auto-detection — respects terminal's color scheme via `lipgloss.HasDarkBackground`
- Battery-included — manpages, completions, version flags all wired automatically
- Windows support — VT processing via Windows API for proper ANSI on older Windows
- Testing — Golden file testing via `github.com/charmbracelet/x/exp/golden`
- Cobra-compatible — builds on Cobra's command grouping and flag system
- Small and focused — single responsibility for styled CLI output

## Weaknesses
- Cobra-only — no support for other CLI frameworks (kingpin, urfave/cli, etc.)
- Experimental — self-described as "small, experimental library"
- No `fang.Printf`/`fang.Log` — purely presentation layer, not a logging library
- Limited layout algorithm — manual spacing calculation, no automatic column fitting algorithm
- Hardcoded widths — max width capped at 120, minimum spacing 10
- Usage error detection is a hack — string prefix matching is fragile
- Go 1.25 required — uses latest Go iterators package

## SugarCraft Mapping

| SugarCraft Lib | Relationship | Notes |
|---|---|---|
| **CandyKit** (`candy-kit/`) | **Direct port** | `candy-kit` is SugarCraft's fang equivalent — `StatusLine`, `Banner`, `Section`, `Stage`, `HelpText` helpers |
| **CandyShine** (`candy-shine/`) | **Thematic cousin** | Both deal with terminal styling; fang for CLI help/errors, Glamour port for markdown rendering |
| **CandyLog** (`candy-log/`) | **Indirect complement** | Both produce styled terminal output; fang styles CLI framework output, CandyLog is a logging library |
| **CandyPalette** (`candy-palette/`) | **Dependency** | fang uses `colorprofile` for terminal color detection; both handle terminal capability detection |
| **CandySprinkles** (`candy-sprinkles/`) | **Thematic relation** | Both build on lipgloss for terminal styling; fang applies it to help/error rendering |

## Analysis

**charmbracelet/fang** is a focused library that wraps the popular [Cobra](https://github.com/spf13/cobra) CLI framework to add battery-included presentation features. Its core value proposition is eliminating the boilerplate of styled help output, error handling, version flags, manpage generation, and shell completions. The design uses the functional options pattern with a single `Execute()` entry point that mutates a `settings` struct via `Option` callbacks. The help rendering in `help.go` is the most complex part — it parses the command tree, evaluates groups, commands, and flags separately, then renders them with lipgloss styles and proper column alignment.

The theming system uses a `ColorScheme` struct that holds `color.Color` values for different elements (titles, flags, arguments, etc.) which are then converted to concrete `lipgloss.Style` instances via `makeStyles()`. The default scheme uses the Charm palette (`charmtone.Charple`, `charmtone.Malibu`, etc.) while an ANSI scheme provides basic colors. The `ColorSchemeFunc` signature accepts `lipgloss.LightDarkFunc` which enables the library to adapt colors based on terminal background without requiring callers to manage light/dark state manually.

The `candy-kit` library in SugarCraft is the direct port of fang, providing PHP developers with similar CLI presentation helpers when building Cobra-based (or similar) PHP CLI applications. Unlike fang which is strictly tied to Cobra, SugarCraft's port may need to be framework-agnostic or work with a PHP CLI framework equivalent. The fang philosophy — "CLI starter kit" with "batteries included" — aligns with SugarCraft's goal of providing ready-to-use TUI building blocks.