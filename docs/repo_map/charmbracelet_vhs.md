# charmbracelet/vhs

## Metadata
- URL: https://github.com/charmbracelet/vhs
- Language: Go
- Stars: ~19,791
- License: MIT
- Description: Your CLI home video recorder 📼 — Write terminal GIFs as code for integration testing and demoing your CLI tools.

## Feature List
- **Tape DSL**: Domain-specific language for scripting terminal interactions (`.tape` files)
- **Terminal Recording**: Capture terminal sessions to GIF, MP4, and WebM formats
- **Frame Capture**: Screenshot-based frame capture from xterm.js canvas via go-rod browser automation
- **Live Recording Mode**: `vhs record` creates `.tape` files from live terminal sessions using PTY
- **SSH Server**: Built-in SSH server for remote tape execution (`vhs serve`)
- **Video Encoding**: FFmpeg-based video encoding with palette generation for optimal GIF colors
- **Theme Support**: 30+ built-in base16 themes with fuzzy matching for typos
- **Visual Styling**: Border radius, window bars (Colorful/Rings variants), margins, padding
- **Typing Simulation**: Configurable typing speed with per-command overrides (`@500ms`)
- **Key Sequence Support**: Full keyboard support including Ctrl+ combinations, modifiers (Alt, Shift)
- **Loop Offset**: Start GIF loops at arbitrary frames for better preview frames
- **Playback Speed**: Speed up or slow down final render
- **GIF Publishing**: Built-in publishing to vhs.charm.sh for shareable URLs
- **Input/Output**: Multiple output formats, frame sequence output to directory
- **Screenshot**: Capture single frames at any point during recording
- **Wait Command**: Wait for regex pattern on screen or line before proceeding

## Key Classes and Methods

### Core Engine
- **`VHS`** (vhs.go): Main struct controlling setup, options, browser/page lifecycle, frame recording
  - `Start()`: Initializes ttyd, browser, and go-rod page
  - `Setup()`: Applies options to terminal (viewport, theme, fonts)
  - `Record(ctx)`: Goroutine capturing xterm.js canvas frames at configured framerate
  - `Render()`: Converts frames to GIF/MP4/WebM via FFmpeg
  - `ApplyLoopOffset()`: Renames frames to shift loop start point

- **`Options`** (vhs.go): Configuration struct for shell, fonts, typing speed, theme, video settings

- **`Evaluate(ctx, tape, out, opts)`** (evaluator.go): Main entry point - parses tape, executes commands, produces output

### Command Execution
- **`Execute(c, v)`** (command.go): Dispatches commands to handler functions
- **`CommandFuncs`** (command.go): Map of `CommandType → func(c, v) error`
- **`ExecuteKey(k)`** (command.go): Higher-order function for key press execution
- **`ExecuteType(c, v)`** (command.go): Types string with configurable per-char delay
- **`ExecuteSleep(c, v)`** (command.go): Sleeps for specified duration
- **`ExecuteSet(c, v)`** (command.go): Applies settings (font, theme, dimensions)
- **`ExecuteHide/ExecuteShow(c, v)`** (command.go): Pause/resume frame recording

### Parser/Lexer (Tape DSL)
- **`Lexer`** (lexer/lexer.go): Tokenizer for `.tape` files
  - `NextToken()`: Returns next token with line/column info
  - `readString()`, `readNumber()`, `readRegex()`, `readJSON()`: Literal parsers

- **`Token`** (token/token.go): Token struct with `Type`, `Literal`, `Line`, `Column`
- **`Parser`** (parser/parser.go): Recursive descent parser
  - `Parse()`: Returns `[]Command`
  - `parseCommand()`, `parseSet()`, `parseType()`, `parseCtrl()`, `parseSleep()`, etc.

- **`Command`** (parser/parser.go): Parsed command with `Type`, `Options`, `Args`
- **`CommandType`** (parser/parser.go): Type alias to `token.Type`

### Video/FFmpeg
- **`FilterComplexBuilder`** (ffmpeg.go): Builds FFmpeg `-filter_complex` strings
  - `WithWindowBar()`, `WithBorderRadius()`, `WithMarginFill()`, `WithGIF()`
  - `Build()`: Returns `[]string` for exec.Command

- **`StreamBuilder`** (ffmpeg.go): Builds FFmpeg input streams
  - `WithMargin()`, `WithBar()`, `WithCorner()`, `WithMP4()`, `WithWebm()`

- **`VideoOptions`** (video.go): Framerate, playback speed, input dir, max colors, output paths
- **`makeMedia(opts, targetFile)`** (video.go): Spawns ffmpeg process

### Image Generation
- **`MakeBorderRadiusMask(width, height, radius, target)`** (draw.go): Creates rounded corner PNG mask
- **`MakeWindowBar(termWidth, termHeight, opts, file)`** (draw.go): Creates window bar PNG
- **`circle`**, **`rect`**, **`roundedrect`** (draw.go): Image drawables using `image/color` and `draw` packages

### Themes
- **`Theme`** (themes.go): 16-color base16 theme struct for xterm.js
- **`findTheme(name)`** (themes.go): Fuzzy matches theme names via Levenshtein distance
- **`DefaultTheme`** (themes.go): Default dark theme (background #171717)

### Recording
- **`Record(cmd, args)`** (record.go): PTY-based tape recording
- **`inputToTape(input)`** (record.go): Converts PTY input to tape format
- **`EscapeSequences`** (record.go): Map of ANSI escape sequences to VHS commands

### SSH Server
- **`serveCmd`** (serve.go): SSH server middleware using `charmbracelet/wish`
- **`config`** (serve.go): Environment-based configuration (port, host, UID/GID)

## Notable Algorithms / Named Patterns
- **Recursive Descent Parser**: The parser uses classic top-down parsing with `curToken`/`peekToken` lookahead
- **Higher-Order Functions for Command Execution**: `ExecuteKey(k)` returns a `CommandFunc` closure for DRY key handling
- **Levenshtein Distance for Theme Matching**: `agnivade/levenshtein.ComputeDistance()` for fuzzy theme lookup
- **Frame Sequence Renaming for Loop Offset**: Concurrent goroutine renaming frame files to shift loop start
- **PTY Terminal Recording**: Using `creack/pty` for live session capture
- **Canvas Screenshot to PNG**: go-rod's `CanvasToImage()` for capturing xterm.js text/cursor layers
- **FFmpeg Filter Graph Building**: Builder pattern for constructing complex filter_complex strings
- **Goroutine Channel Pattern**: `Record()` uses context cancellation and error channel for frame capture loop

## Strengths
- **Clean Architecture**: Clear separation between lexer/parser/executor/video generation
- **Extensibility**: New commands added by registering in `CommandFuncs` map and `parser.CommandTypes`
- **Production Quality**: Comprehensive error handling, dependency checking, version validation
- **CI Integration**: GitHub Action available (`charmbracelet/vhs-action`)
- **Rich Visual Options**: Border radius, window bars, margins, themes provide polished output
- **Scriptable**: Both the tape format and CLI are well-designed for automation
- **Cross-Platform**: Supports Linux, macOS, Windows via ttyd
- **SSH Server**: Enables remote execution without local installation
- **Built-in Publishing**: Easy sharing via `vhs publish`

## Weaknesses
- **External Dependencies**: Requires `ttyd` and `ffmpeg` binaries on PATH
- **Browser Automation**: Heavy dependency on go-rod/browser for frame capture
- **No Real Terminal**: Uses xterm.js in browser, not a real TTY - some terminal apps may behave differently
- **GIF Quality Tradeoffs**: Palette generation can be slow for long recordings
- **No Audio Support**: Video output is silent
- **PTY Recording Fragility**: `inputToTape` escape sequence parsing can miss edge cases
- **Loop Offset Complexity**: Frame renaming during render is complex and potentially racy

## SugarCraft Mapping

| VHS Concept | SugarCraft Lib | Notes |
|------------|----------------|-------|
| `.tape` format / DSL | `sugar-bits` | Both define scripting formats for TUI demos |
| Frame capture / recording | `candy-shell` | Could provide terminal capture primitives |
| Video encoding / FFmpeg | `sugar-charts` | Both produce output files, FFmpeg could be wrapped |
| Theme system (base16) | `sugar-bits` | 16-color theme mapping aligns with Charm color systems |
| Typing simulation | `sugar-prompt` | Input emulation concepts overlap |
| Window chrome (bars, borders) | `candy-shine` | Visual styling of terminal windows |
| SSH server | N/A | PHP has no native SSH server lib in SugarCraft |
| Recording mode | N/A | Would need PTY/terminal capture - platform-specific |

### Potential New Libs:
- **`sugar-vhs`**: PHP port of VHS concept - tape format parser, frame capture, video encoding
  - Heavy dependency on FFmpeg via `symfony/process` or similar
  - No direct equivalent to go-rod browser automation in PHP
  - Would need platform-specific TTY capture code
  - **Not a straightforward port** - architecture would differ significantly

## Analysis

VHS is a sophisticated terminal recording tool that bridges the gap between manual demo creation and programmatic generation. Its core innovation is the `.tape` DSL—a simple, readable format that describes terminal interactions as code. This approach enables version control of demos, CI integration, and reproducible outputs.

The architecture is well-modularized. The lexer/parser division cleanly separates tokenization from semantic analysis, and the `CommandFunc` map provides a clean extension point for new commands. The evaluator orchestrates the full pipeline: parse tape → validate dependencies → start ttyd/browser → execute commands → render frames → encode video.

The most technically interesting aspects are the frame capture mechanism (using go-rod to automate a browser rendering xterm.js) and the FFmpeg filter graph construction. The `FilterComplexBuilder` demonstrates fluent builder pattern for constructing complex shell commands, and the concurrent frame renaming for loop offset shows careful goroutine synchronization.

For SugarCraft, VHS represents a valuable tooling library rather than something to port directly. The `.tape` format is a proven pattern for describing TUI demos as code—something SugarCraft could adopt. The FFmpeg wrapping in `ffmpeg.go` provides a reference implementation for video encoding that could inform `sugar-charts` video output. However, PHP lacks a direct equivalent to go-rod for browser automation, making frame capture difficult to port. The SSH server concept is also not applicable to the PHP ecosystem in the same way.

The project's biggest strength is its polish: comprehensive error messages, dependency validation, theme support with fuzzy matching, multiple output formats, and publishing infrastructure. These are all areas where SugarCraft demos could improve by following similar patterns.

---

*Research conducted via GitHub API, code analysis from HEAD commit e9c41d3*
