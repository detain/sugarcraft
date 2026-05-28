# charmbracelet/colorprofile

## Metadata
- URL: https://github.com/charmbracelet/colorprofile
- Language: Go
- Stars: ~111
- License: MIT
- Description: A simple, powerful—and at times magical—package for detecting terminal color profiles and performing color (and CSI) degradation.

## Feature List
- **Terminal Color Profile Detection**: Detects 5 profile levels: NoTTY, ASCII, ANSI (4-bit/16 colors), ANSI256 (8-bit/256 colors), and TrueColor (24-bit/16M colors)
- **Environment Variable Respect**: Honors `NO_COLOR`, `CLICOLOR`, `CLICOLOR_FORCE`, and `COLORTERM` environment variables per no-color.org and clicolor.org standards
- **Terminfo Database Queries**: Uses the terminfo database (via `Tc` and `RGB` capabilities) to determine terminal color support
- **Tmux Detection**: Queries `tmux info` output to determine tmux-overridden color capabilities
- **Windows-Specific Detection**: Uses Windows API (`RtlGetNtVersionNumbers`) and checks `ANSICON`, `ConEmuANSI`, and `WT_SESSION` for Windows color support
- **Modern Terminal Recognition**: Recognizes Alacritty, Contour, Foot, Ghostty, Kitty, Rio, ST, Wezterm as TrueColor-capable
- **Color Downsampling**: Converts TrueColor (24-bit) colors to ANSI256 or ANSI equivalents with caching
- **Automatic Writer**: `Writer` type that intercepts ANSI escape sequences and automatically downgrades colors based on detected/profile
- **SGR Sequence Handling**: Parses and converts ANSI SGR (Select Graphic Rendition) sequences including foreground, background, and underline colors
- **Cross-Platform Support**: Platform-specific implementations for Windows vs Unix via build tags

## Key Classes and Methods

### `Profile` (type byte)
- `Detect(output io.Writer, env []string) Profile` — Detects color profile from terminal output and environment variables
- `Env(env []string) Profile` — Detects color profile from environment alone (no TTY required)
- `Convert(c color.Color) color.Color` — Converts a color to the profile's supported color space with caching
- `String() string` — Returns string representation of the profile

### `Profile` constants
- `NoTTY` — No terminal/TTY support
- `ASCII` — No color, text decoration only
- `ANSI` — 16 colors (4-bit)
- `ANSI256` — 256 colors (8-bit)
- `TrueColor` — 16 million colors (24-bit)

### `Writer` struct
- `NewWriter(w io.Writer, environ []string) *Writer` — Creates a new auto-downsampling writer
- `Write(p []byte) (int, error)` — Writes bytes, auto-downsampling ANSI sequences as needed
- `WriteString(s string) (n int, err error)` — Convenience wrapper for string writing
- `Profile Profile` — Public field to manually set the profile (can override detected profile)

### Environment Detection Functions
- `Terminfo(term string) Profile` — Queries terminfo database for color capabilities
- `Tmux(env []string) Profile` — Detects color profile via `tmux info` command
- `windowsColorProfile(env map[string]string) (Profile, bool)` — Windows API-based detection

## Notable Algorithms / Named Patterns
- **Color Conversion with Thread-Safe Caching**: Uses `sync.RWMutex` protected cache map (`map[Profile]map[color.Color]color.Color`) to memoize color conversions, avoiding recomputation for repeated colors
- **Environment Precedence Hierarchy**: NO_COLOR > CLICOLOR_FORCE > CLICOLOR > TERM capabilities > terminfo > tmux
- **Profile Maximum Selection**: When in tmux with a real terminal, takes `max(env, terminfo, tmux)` to ensure highest common denominator
- **Platform-Specific Build Tags**: `env_windows.go` with `//go:build windows` and `env_other.go` with `//go:build !windows` for platform-specific implementations
- **ANSI Sequence Parsing**: Uses `github.com/charmbracelet/x/ansi` parser to decode ANSI escape sequences and `ansi.DecodeSequence()` state machine for stream processing
- **SGR Parameter Iteration**: Iterates through SGR parameters handling color codes (30-37 foreground, 38 24-bit foreground, 40-47 background, 48 24-bit background, 58 underline color, 90-97 bright foreground, 100-107 bright background)

## Strengths
- **Standards Compliant**: Properly respects `NO_COLOR` (no-color.org) and `CLICOLOR`/`CLICOLOR_FORCE` (bixense.com/clicolors) standards
- **Comprehensive Terminal Detection**: Multiple detection mechanisms (environment, terminfo, tmux, Windows API) ensure accurate detection across diverse terminals
- **High-Quality Color Downsampling**: Uses `go-colorful` library for perceptually accurate color quantization when converting 24-bit to 8-bit/4-bit colors
- **Performance Optimized**: Thread-safe caching of converted colors prevents redundant computation; TrueColor pass-through avoids caching overhead for highest tier
- **Writer Pattern for Transparent Downsampling**: The `Writer` type provides drop-in replacement for `io.Writer` that auto-downsamples without requiring code changes
- **Modern Terminal Recognition**: Recognizes all major modern terminals (Kitty, Alacritty, Wezterm, Ghostty, etc.) as TrueColor-capable
- **Well-Tested**: Comprehensive test suite covering environment variable combinations, caching behavior, and cross-platform scenarios

## Weaknesses
- **Unix-Only Terminfo by Default**: On non-Windows platforms without `TERM` set and not in a known terminal, detection may fall back to conservative defaults
- **Tmux Detection Requires External Command**: `Tmux()` function spawns `tmux info` subprocess, which adds latency compared to environment variable checks
- **No Automatic Re-Detection**: The `Writer.Profile` is set once at creation; if terminal capabilities change mid-stream, detection doesn't adapt
- **Limited to CSI SGR Sequences**: Only handles SGR (`m` command) sequences; other CSI sequences pass through unprocessed
- **Cache Memory Growth Risk**: In applications with many unique colors over long runtimes, the color conversion cache could grow unbounded (no eviction policy)

## SugarCraft Mapping
The SugarCraft mapping is **indirect** — `colorprofile` deals with terminal detection and ANSI sequence manipulation at a lower level than typical TUI component libs. The closest SugarCraft equivalents are:

- **`candy-core`**: The foundational TUI framework. Terminal capability detection and color management are foundational concerns that `candy-core` would need to address. SugarCraft's equivalent would handle terminal detection for proper rendering.
- **`sugar-bits`**: Contains basic UI components (gum). Color detection and downsampling would be a utility service available to `sugar-bits` components.
- **`candy-shine`**: Terminal styling library. `shine` works at the ANSI sequence level (producing styled output), while `colorprofile` works at the detection and conversion level — they are complementary.

The **architectural relationship**: `colorprofile` is a dependency that would typically be vendored or utilized by the core rendering layer to answer "what can this terminal handle?" before producing ANSI output. A SugarCraft port would likely be a utility service class (e.g., `SugarCraft\Core\Terminal\ColorProfile`) rather than a full component lib.

## Analysis

`charmbracelet/colorprofile` is a focused, well-designed Go library that solves a specific but important problem in terminal applications: determining what color capabilities a terminal supports and gracefully degrading ANSI color output when necessary. The library operates at the intersection of terminal emulation detection and ANSI sequence transformation, making it infrastructure software that sits beneath higher-level TUI frameworks.

The detection mechanism is its strongest aspect. Rather than relying on a single signal, it layers multiple detection strategies: environment variable parsing (TERM, COLORTERM, NO_COLOR, etc.), terminfo database queries for terminals that support it, tmux info output parsing for tmux sessions, and Windows API calls for Windows Terminal/ConEmU/ANSICON. This belt-and-suspenders approach is necessary because terminal color capabilities can be misreported or overridden at various layers. The precedence rules are well-documented and standards-compliant.

The color conversion logic uses the `go-colorful` library for perceptually accurate color quantization when downsampling 24-bit RGB colors to 256 indexed colors or 16 basic ANSI colors. This is important because naive nearest-neighbor color conversion produces ugly results — perceptually-aware conversion finds the nearest color in a perceptually-uniform color space. The conversion cache uses a thread-safe map with read-write locks, which is appropriate since color conversions are typically read-heavy after an application's startup.

The `Writer` type is the most magical part of the library. By wrapping an `io.Writer` and intercepting writes, it can transparently convert ANSI escape sequences on the fly without the calling code needing to know the terminal's capabilities. This is a classic decoration pattern. The implementation parses ANSI sequences incrementally using a state machine from `charmbracelet/x/ansi`, which handles the complexity of parsing multi-byte escape sequences correctly.

At 111 stars and as part of the Charmbracelet ecosystem (which includes popular projects like Bubbletea, Glow, and Tree), this is a well-regarded but specialized utility. It would not typically be a standalone application concern — rather, it's the kind of infrastructure library that TUI frameworks depend on. A SugarCraft port would integrate it as a terminal capability service rather than a component library, since its primary value is in detection and transformation rather than visual widgets or user interaction patterns.
