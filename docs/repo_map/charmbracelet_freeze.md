# charmbracelet/freeze

## Metadata
- URL: https://github.com/charmbracelet/freeze
- Language: Go
- Stars: ~4,583
- License: MIT License
- Description: Generate images of code and terminal output. Freeze produces PNGs, SVGs, and WebPs of code and terminal output alike, with full syntax highlighting, themed styling, and customizable window decorations.

## Feature List

1. **Code to Image Generation** - Converts source code files into syntax-highlighted PNG/SVG/WebP images
2. **Terminal Output Capture** - Captures ANSI-colored terminal output via `--execute` flag using PTY
3. **Syntax Highlighting** - Uses Chroma lexer with 100+ themes (dracula, monokai, nord, github, etc.)
4. **Built-in Themes** - Ships with custom "charm" theme as default; all Chroma themes available
5. **Interactive TUI Mode** - Full no-code configuration via `freeze --interactive` using huh form library
6. **Window Decorations** - Optional macOS-style window controls (red/yellow/green dots)
7. **Border Styling** - Customizable border radius, width, and color
8. **Shadow Effects** - Configurable drop shadow with blur, x-offset, and y-offset
9. **Padding & Margin** - 1, 2, or 4-value padding/margin support (CSS-style)
10. **Font Customization** - Font family, size, line height, ligatures, and embedded font file support
11. **Line Number Display** - Optional line numbers with `--show-line-numbers`
12. **Line Range Selection** - Capture specific line ranges with `--lines start,end`
13. **Language Detection** - Auto-detects language from filename or content analysis
14. **Stdin Piping** - Accepts piped input via stdin with language detection
15. **Multiple Output Formats** - SVG (default), PNG (via rsvg-convert or resvg), WebP
16. **Configuration Presets** - Built-in presets: `base` (minimal), `full` (macOS-like screenshot)
17. **User Config Persistence** - Saves settings to `$XDG_CONFIG/freeze/user.json`
18. **tmux Integration** - Works with `tmux capture-pane` for TUI screenshots

## Key Classes and Methods

### main.go
- `main()` — Entry point; handles CLI parsing via kong, orchestrates input reading, lexing, SVG generation, and output format conversion
- `printFilenameOutput(filename string)` — Prints the "WROTE" success message with lipgloss styling
- `printErrorFatal(msg string, err error)` — Prints formatted error and exits with code 1
- `helpPrinter(kong.HelpOptions, *kong.ParseContext)` — Custom help formatter

### config.go
- `Config` struct — Main configuration containing Window, Settings, Decoration (Border/Shadow), Font, and Line options
- `Shadow` struct — blur, x, y offset configuration
- `Border` struct — radius, width, color configuration
- `Font` struct — family, file, size, ligatures configuration
- `expandPadding(p []float64, scale float64) []float64` — Expands 1/2/4 padding values to 4-value array with scaling
- `loadUserConfig()` / `saveUserConfig(Config)` — User config file persistence via xdg

### interactive.go
- `runForm(config *Config) (*Config, error)` — Builds and runs huh form for interactive configuration with file picker, theme selector, font settings, border/shadow controls
- `validateColor(s string) error` — Validates hex color format with regex
- `validateInteger(s string) error` / `validateFloat(s string) error` — Input validation
- `parsePadding(v string) []float64` — Parses space-separated padding values

### ansi.go
- `dispatcher` struct — Handles ANSI escape sequence dispatching for terminal output rendering
- `dispatcher.Print(r rune)` — Inserts rune into SVG tspan element, handles wide characters
- `dispatcher.Execute(code byte)` — Handles tab/newline execution
- `dispatcher.CsiDispatch(cmd ansi.Cmd, params ansi.Params)` — Dispatches CSI sequences (SGR color codes: 0-107 for foreground/background)
- `dispatcher.beginBackground(fill string)` / `dispatcher.endBackground()` — Background color rect management
- `ansiPalette` map — Maps ANSI color codes (30-37, 90-97) to hex colors
- `palette` — 256-color ANSI palette array

### svg/svg.go
- `AddShadow(element *etree.Element, id string, x, y, blur float64)` — Adds SVG filter definition for drop shadow
- `AddClipPath(element *etree.Element, id string, x, y, w, h float64)` — Adds clipping path definition
- `AddCornerRadius(e *etree.Element, radius float64)` — Adds rx/ry attributes for rounded corners
- `Move(e *etree.Element, x, y float64)` — Sets element x/y position
- `AddOutline(e *etree.Element, width float64, color string)` — Adds stroke outline
- `NewWindowControls(r float64, x, y float64)` — Creates macOS-style window control circles (red/yellow/green)
- `GetDimensions(element *etree.Element)` / `SetDimensions(element *etree.Element, width, height float64)` — SVG dimension manipulation

### png.go
- `libsvgConvert(doc *etree.Document, _, _ float64, output string) error` — Converts SVG to PNG via system `rsvg-convert` binary (faster path)
- `resvgConvert(doc *etree.Document, w, h float64, output string) error` — Fallback PNG conversion using embedded resvg-go WebAssembly renderer with embedded JetBrains Mono fonts

### pty.go
- `executeCommand(config Config) (string, error)` — Executes command in PTY, captures output including ANSI codes; uses shellwords parsing, context timeout, xpty for PTY management

### style.go
- `charmStyle` — Custom Chroma syntax highlighting style definition with 20+ token type mappings

### font/font.go
- `JetBrainsMonoTTF` / `JetBrainsMonoNLTTF` — Embedded JetBrains Mono font files (OFL licensed)
- `JetBrainsMono` / `JetBrainsMonoNL` — Base64-encoded font strings for embedding in SVG/PNG

### cut.go
- `cut(input string, window []int) string` — Slices input to line range [start, end)
- `clamp(n, low, high int) int` — Constrains value to range

### input/input.go
- `ReadFile(file string) (string, error)` — Reads file contents
- `ReadInput(in io.Reader) (string, error)` — Reads stdin
- `IsPipe(in *os.File) bool` — Detects if stdin is a pipe

### help.go
- Contains help text and flag documentation

## Notable Algorithms / Named Patterns

1. **ANSI SGR (Select Graphic Rendition) Parsing** - The `dispatcher.CsiDispatch` method handles ANSI escape sequences (CSI Pm m format) for colors, bold, italic, underline, strikethrough. Uses a state machine to track background color state across lines.

2. **Chroma Tokenization Pipeline** - Input → Chroma Lexer → Token Iterator → Chroma SVG Formatter → SVG output. For ANSI input, bypasses Chroma entirely and uses custom dispatcher.

3. **SVG Dimension Calculation** - Automatic width calculation using `fontHeightToWidthRatio = 1.68` and `lipgloss.Width()` for longest line measurement. Height scales with font size and line height relative to defaults (14px, 1.2em).

4. **Layered Configuration Merging** - Configuration precedence: preset JSON (`base`/`full`) → user config (`~/.config/freeze/user.json`) → CLI flags. Implemented via kong's resolver system.

5. **Font Metric Calculations** - Character width calculated using runewidth package for proper Unicode wide character handling. Tab width defaults to 4 (code) or 6 (ANSI).

6. **PNG Conversion Dual-Path** - Primary: `rsvg-convert` system binary for speed. Fallback: `resvg-go` WASM renderer with embedded fonts for portability.

7. **XDG Base Directory** - User config stored via `xdg.ConfigHome/freeze/user.json` for Unix portability.

## Strengths

- **Zero External Runtime Dependencies** - Self-contained binary; PNG conversion falls back to embedded resvg WASM when rsvg-convert unavailable
- **Rich Visual Output** - Professional-quality code screenshots with window decorations, shadows, rounded corners, and custom fonts
- **Flexible Configuration** - Four-value padding/margin syntax, layered config system, CLI flag mapping to JSON config keys
- **Interactive Mode** - No-code configuration via TUI form with file picker, theme browser, and live validation
- **Language Auto-Detection** - Filename-based and content-based language detection via Chroma
- **Multi-Format Output** - SVG (vector, default), PNG (raster at 4x scale for auto-sized), WebP
- **Thorough ANSI Support** - Full 256-color palette, SGR sequences, background colors, bold/italic/underline/strikethrough
- **Embedded Fonts** - JetBrains Mono included and embedded in output for consistent rendering
- **MIT Licensed** - Permissive open source license
- **Charm Ecosystem Integration** - Uses huh (forms), lipgloss (styling), log (logging), x/ansi, x/cellbuf from charmbracelet

## Weaknesses

- **Go-only Implementation** - Cannot be directly used as a library from other languages; must be invoked as subprocess
- **PNG Quality on Auto-Size** - Fixed 4x scale for auto-sized PNG output; no user control over resolution
- **Font Embedding Size** - JetBrains Mono TTF files add ~200KB to binary size
- **Limited Image Format Support** - No JPEG, GIF, or other common formats
- **tmux Required for TUI Capture** - TUI screenshots require tmux; no built-in terminal capture
- **No Animation Support** - Static images only; cannot capture animated terminal sessions
- **Hardcoded Fallbacks** - resvg fallback uses only JetBrains Mono; custom fonts in config won't render correctly in PNG fallback path
- **Interactive Mode Complexity** - Large form with 15+ fields; could be overwhelming for simple use cases

## SugarCraft Mapping

SugarCraft is a PHP monorepo porting the Charmbracelet TUI ecosystem to PHP. The mapping below shows how freeze's functionality could be represented in SugarCraft:

| freeze Component | SugarCraft Library | Mapping Rationale |
|-----------------|-------------------|------------------|
| Core image generation | `sugar-bits` | Core output/rendering primitives |
| ANSI escape parsing | `candy-core` | Terminal ANSI code handling (colors, styles) |
| Chroma syntax highlighting | `sugar-bits` | Token-based rendering, syntax highlighting |
| SVG output generation | `candy-shine` (potential) | Vector graphics rendering |
| Interactive TUI forms | `sugar-prompt` / `huh` port | Form components, file picker |
| Config/flag parsing | `candy-core` | Command-line argument handling |
| Window decorations | `sugar-bits` | Border rendering, rounded corners |
| Shadow effects | `sugar-bits` / `candy-shine` | Visual effects, filters |
| PTY execution | `candy-pty` | Pseudo-terminal control |
| Font handling | `candy-core` / `sugar-bits` | Font metrics, text rendering |
| Style/theming | `sugar-bits` | Theme management, style registry |

### Direct SugarCraft Equivalents

- **ANSI parsing & color handling** → `SugarCraft\Core\Ansi` (if exists) or `SugarCraft\Bits\Renderer` - The 256-color palette and SGR sequence handling in `ansi.go` would map to a PHP ANSI parser

- **SVG manipulation** → `SugarCraft\Shine` - The etree-based SVG DOM manipulation for adding shadows, clips, corners, and positioning maps to vector graphics operations

- **Image export (PNG/WebP)** → No current SugarCraft equivalent - This is a significant gap; a `SugarCraft\Image` or `SugarCraft\Export` library could handle rasterization

- **Interactive forms** → `SugarCraft\Prompt` - The huh-based form with file picker, inputs, selects maps to SugarCraft's form component suite

- **Theme registry** → `SugarCraft\Bits\Theme` - Chroma-style theme registry with named styles (dracula, monokai, etc.) 

- **Configuration system** → `SugarCraft\Core\Config` - Layered config with XDG paths and JSON presets maps to SugarCraft's config management

- **PTY command execution** → `SugarCraft\Candy\Pty` - The `--execute` flag with PTY spawning maps directly to the candy-pty library

## Analysis

Charmbracelet/freeze is a well-engineered Go CLI tool that transforms code and terminal output into publication-ready images. Its architecture follows a clear pipeline: input → tokenization (via Chroma) → SVG rendering → optional format conversion. The use of Chroma for syntax highlighting is strategic—it leverages a mature, well-tested library with support for 100+ languages and themes, avoiding reinventing the lexer wheel.

The project's greatest strength is its visual output quality. The combination of embedded JetBrains Mono fonts, SVG-based vector rendering, and CSS-style visual properties (padding, margin, border-radius, shadow) produces consistently professional screenshots. The interactive TUI mode, built on huh, makes these properties accessible without reading documentation—users can preview and tweak settings in real-time.

From a SugarCraft perspective, freeze represents the "output" side of a TUI ecosystem. While SugarCraft focuses on building interactive terminal applications, freeze demonstrates how to render styled content to images. The ANSI parsing code in `ansi.go` is particularly relevant—SugarCraft's `candy-core` could benefit from similar SGR sequence handling for proper color and style support. The SVG manipulation patterns in `svg/svg.go` also offer insights for potential vector graphics support in SugarCraft's render pipeline.

The main gap for a PHP port would be the PNG/WebP conversion layer, which relies on either system rsvg-convert or resvg-go's WASM renderer—neither translates directly to PHP. A PHP implementation would likely need to either use ImageMagick/FFI or focus on SVG output only, potentially using an external tool for rasterization.
