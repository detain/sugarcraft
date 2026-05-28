# charmbracelet/sequin

## Metadata
- URL: https://github.com/charmbracelet/sequin
- Language: Go
- Stars: 804
- License: MIT
- Description: Human-readable ANSI sequences. A debugging tool for TUIs that decodes and explains ANSI escape sequences in a human-readable format.

## Feature List
- **ANSI Sequence Parsing**: Decodes CSI (Control Sequence Introducer), DCS (Device Control String), OSC (Operating System Command), ESC, PM (Privacy Message), SOS (Start of String), APC (Application Program Command), and C0/C1 control codes
- **SGR (Select Graphic Rendition) Handler**: Explains text styling sequences including bold, faint, italic, underline (with styles: single, double, curly, dotted, dashed), blink, reverse, conceal, strikethrough, and 24-bit RGB/ANSI256 color support
- **Cursor Movement**: Describes cursor up/down/forward/backward, save/restore position, set position, and cursor style (block, underline, bar - blinking/steady)
- **Screen/Line Operations**: Erase screen (above/below/full/display), scrolling regions, insert/delete lines, scroll up/down
- **Terminal Mode Handling**: DEC mode requests (cursor keys, mouse tracking, altscreen, bracketed paste, synchronized output, etc.)
- **Kitty Keyboard Protocol**: Full support for Kitty's extended keyboard flags and push/pop operations
- **OSC 133 (Final Term)**: Shell integration for prompt/command tracking
- **Window Title/Hyperlink**: OSC 1/2 for icon/window title, OSC 8 for hyperlinks with URI parsing
- **Terminal Colors**: OSC 10/11/12 for terminal foreground/background/cursor color queries
- **Clipboard Operations**: OSC 52 for system/primary clipboard get/set via base64 encoding
- **Working Directory Notification**: OSC 7 for file:// URL-based cwd reporting
- **Desktop Notifications**: OSC 9 for system notifications
- **Termcap/Terminfo**: DCS q for termcap entry requests with hex decoding
- **Theme Support**: Charm theme (auto-detects dark/light) and Base16 themes
- **Raw Mode**: Syntax highlighting for inline sequences without full explanations
- **PTY Execution**: Run commands directly with fake TTY for capturing raw ANSI output
- **Golden File Testing**: Integrates with `charmbracelet/x/exp/golden` for snapshot testing

## Key Classes and Methods

### main.go
- `main()` — Entry point using `fang` framework
- `cmd()` — Cobra command definition with `--raw` flag
- `process(w, in)` — Core ANSI parsing loop using `ansi.DecodeSequence()`
- `ctrlCodes` map — C0 and C1 control character mnemonics (0x00-0x1f, 0x7f, 0x80-0x9f)

### handlers.go
- `csiHandlers` map — Maps CSI final bytes to handler functions (`'m'`: SGR, `'c'`: device attrs, cursor 'A'-'H'/'n'/'s'/'u'/'q', screen 'r'/'J'/'K'/'L'/'M'/'S'/'T', modes, kitty 'u' flags)
- `oscHandlers` map — OSC command handlers (0/1/2: title, 7: cwd, 8: hyperlink, 9: notify, 10/11/12: colors, 22: pointer, 52: clipboard, 110/111/112: reset colors, 133: finalterm)
- `dcsHandlers` map — DCS handlers ('q': termcap)
- `escHandler` map — ESC handlers (7/8: save/restore cursor, >/=: keypad mode, \\: string terminator)
- `handlerFn` type — Function signature: `func(*ansi.Parser) (string, error)`
- `printf()` — Factory for simple string-returning handlers

### sgr.go
- `handleSgr(p)` — Main SGR handler; interprets params 0-109 including basic colors (30-37, 40-47, 90-97, 100-107), 24-bit color (38/48 with subparams), underline color (58/59)
- `readColor()` — Parses color from SGR params (ANSI, ANSI256, RGB)
- `getColorLabel()` — Human-readable color label with hex values
- `getColorType()` — Returns "ANSI", "ANSI256", or "24-bit RGB"
- `basicColors` map — Maps 0-7 to color names (Black/Red/Green/Yellow/Blue/Magenta/Cyan/White)

### theme.go
- `theme` struct — Holds Lipgloss styles for raw, kind, sequence, separator, text, error, explanation with per-kind color mapping
- `charmTheme(hasDarkBG)` — Returns Charm's light/dark adaptive theme with specific hex colors per sequence type
- `base16Theme()` — Alternative monochrome-friendly theme using Lipgloss brightness constants
- `kindStyle(kind)` — Returns appropriate styled prefix label (CSI, DCS, OSC, APC, PM, SOS, ESC, Ctrl, Text)

### cursor.go
- `handleCursor(p)` — Handles CUU/CUD/CUF/CUB, CUP, DECKPAM/DECNKM, DECXCPR, DECSC/DECRC, DECSTBM, cursor style (DECSC/DSR)
- `descCursorStyle()` — Maps cursor style codes 0-6 to descriptive names

### screen.go
- `handleScreen(p)` — Handles DECSC (set scrolling region), DECSTBM, ED (erase display modes 0-3)

### line.go
- `handleLine(p)` — Handles EL (erase line 0-2), IL (insert lines), DL (delete lines), SU/SD (scroll up/down)

### mode.go
- `handleMode(p)` — Handles SM/RMM/RMQ modes with private (?) prefix support
- `modeDesc()` — Maps mode numbers to names: 1 (cursor keys), 25 (cursor visibility), 1000-1006 (mouse modes), 1049 (altscreen), 2004 (bracketed paste), 2026 (synced output), 2027 (grapheme), 9001 (win32 input)

### kitty.go
- `handleKitty(p)` — Handles Kitty keyboard protocol: request (?), disable (0), push (>), pop (<), set (=)
- `flagDesc()` — Bitmask decoder for Kitty flags (1=disambiguate, 2=report events, 4=alternate keys, 8=all keys, 16=associated text)
- `modeDesc()` — Kitty mode descriptions (1=set, 2=add, 3=remove)

### color.go
- `handleTerminalColor(p)` — OSC 10/11/12 for foreground/background/cursor color (set/request)
- `handleResetTerminalColor(p)` — OSC 110/111/112 for resetting colors

### title.go
- `handleTitle(p)` — OSC 0 (both icon+title), 1 (icon only), 2 (window only)

### hyperlink.go
- `handleHyperlink(p)` — OSC 8 with uri;id;permalink format parsing

### clipboard.go
- `handleClipboard(p)` — OSC 52 for clipboard get/set with base64 payload

### cwd.go
- `handleWorkingDirectoryURL(p)` — OSC 7 parsing with file:// URL validation

### pointer.go
- `handlePointerShape(p)` — OSC 22 for pointer shape designation

### notify.go
- `handleNotify(p)` — OSC 9 for desktop notification body

### termcap.go
- `handleTermcap(p)` — DCS q parsing with hex-decoded capability names

### xt.go
- `handleXT(p)` — DECRQTS XT version query

### final_term.go
- `handleFinalTerm(p)` — OSC 133 shell integration: prompt start (A), command start (B), executed (C), finished with exit code (D)

### exec.go
- `executeCommand(ctx, args)` — Creates PTY, executes command, returns captured output for ANSI inspection

### main_test.go
- Comprehensive golden-file test suite covering all sequence types using `charmbracelet/x/exp/golden`
- Test data maps for c0c1, ascii, cursor, screen, line, mode, kitty, sgr, title, cwd, hyperlink, notify, termcolor, clipboard, finalterm, keypad

## Notable Algorithms / Named Patterns
- **ANSI Parser State Machine**: Uses `ansi.GetParser()` / `ansi.PutParser()` pool pattern with `ansi.DecodeSequence()` for incremental streaming parsing
- **Handler Registry Pattern**: Map[int]handlerFn dispatch tables for CSI/OSC/DCS/ESC sequences
- **Bitmask Flag Encoding**: Kitty keyboard flags use bit 0-4 for combined capability flags
- **Theme Abstraction**: Theme supports both light/dark adaptive (via `lipgloss.HasDarkBackground()`) and static themes
- **Color Type Dispatch**: Type switch on `ansi.Color` interface (BasicColor, IndexedColor, RGBColor) for rendering
- **Base64 Clipboard Encoding**: Clipboard content uses standard base64 with OSC 52 protocol
- **Hex-Decoded Termcap**: Termcap entries use hex encoding for capability names

## Strengths
- **Comprehensive ANSI Coverage**: Handles virtually all common ANSI escape sequences including SGR (full 24-bit color, underline color, underline styles), cursor, screen, modes, Kitty extensions
- **Well-Structured Handler Pattern**: Clean separation of concerns with handler maps per sequence class
- **Golden File Testing**: Production-quality snapshot testing via `x/exp/golden` ensures correctness
- **Charm Ecosystem Integration**: Uses `charmbracelet/x/ansi` parser, `lipgloss` for styling, `fang` for CLI
- **Developer Tool Focus**: Purpose-built for debugging TUIs and ANSI output, with PTY execution support
- **Theme Flexibility**: Auto-detecting light/dark themes via terminal detection
- **MIT Licensed**: Permissive open source licensing
- **Active Maintenance**: Part of charmbracelet ecosystem with regular releases

## Weaknesses
- **No APC (Application Program Command) Support**: APC sequences are recognized but not decoded (TODO comment in code)
- **Incomplete SGR Coverage**: Some SGR parameters still marked TODO (line 28 comment)
- **Single File Output**: Cannot process multiple files or directories directly
- **No Interactive Mode**: Only reads from stdin or single file, no REPL for exploring sequences
- **No Sequence Generation**: Only parses/explains, cannot generate ANSI sequences
- **Limited Windows Support**: Relies on PTY/xterm which may have limitations on Windows despite conpty support

## SugarCraft Mapping

| Sequin Feature | SugarCraft Library | Mapping Rationale |
|----------------|-------------------|-------------------|
| ANSI sequence parsing (SGR, CSI, OSC) | `candy-core` | Core TUI rendering foundation that would use ANSI sequences |
| 24-bit color, ANSI256, basic colors | `candy-shine` | Color handling and rendering (Shine = visual styling) |
| Text styling (bold, italic, underline) | `candy-shine` | Text attribute rendering |
| Cursor movement/positioning | `candy-core` | Cursor management in terminal rendering |
| Screen erase/scroll operations | `candy-core` | Screen buffer management |
| Terminal mode handling (mouse, bracketed paste) | `candy-core` | Terminal capability negotiation |
| Theme support (dark/light) | `candy-shine` | Theme system for visual styling |
| Golden file comparison for ANSI output | `sugar-bits` | Snapshot testing patterns for TUI output |
| PTY execution for command output capture | `candy-pty` (if exists) | PTY/sudo process handling |

**SugarCraft Category**: `candy-*` (foundation/system/framework)

**Primary Mapping**: `candy-core` for ANSI parsing/rendering primitives, `candy-shine` for visual styling/color handling

**Indirect Mapping**: Sequin is primarily a debugging/development tool rather than a runtime TUI component. SugarCraft equivalent would be the internal ANSI rendering pipeline within `candy-core` that generates the raw sequences sequin consumes.

## Analysis

Sequin is a focused, well-crafted ANSI escape sequence debugger and learning tool from the Charmbracelet ecosystem. It fills a crucial gap in TUI development: understanding what sequences are actually being generated and transmitted. The architecture is elegant—using a pool-based parser from `charmbracelet/x/ansi` with handler dispatch tables that make it easy to extend support for new sequence types.

The code demonstrates excellent Go practices: comprehensive golden-file testing ensures correctness, the handler pattern is clean and extensible, and theme handling gracefully adapts to terminal appearance. The reliance on the broader charm ecosystem (lipgloss for styling, x/ansi for parsing, fang for CLI) shows good code reuse philosophy.

From a SugarCraft porting perspective, sequin presents an interesting case: it's not a TUI component itself but rather a developer tool that inspects TUI output. The core ANSI parsing and rendering logic that sequin *describes* would be the target for porting, not sequin itself. The 804 stars indicate moderate but real popularity—developers building TUIs need tools like this to debug their output. The TODO comments for APC and some SGR parameters suggest it's not yet 100% complete, but covers the vast majority of real-world sequences encountered in CLI development.
