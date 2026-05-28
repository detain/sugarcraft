# charmbracelet/x

## Metadata
- **URL:** https://github.com/charmbracelet/x
- **Language:** Go
- **Stars:** 293
- **License:** MIT
- **Description:** Charm experimental packages — a monorepo containing 30+ experimental Go packages for terminal UI, ANSI escape sequences, color handling, virtual terminal emulation, and cross-platform terminal utilities.

## Feature List

### Core Terminal Packages
- **ansi** — ANSI escape sequence parser, constants, and generators (ECMA-48 compliant)
- **term** — Platform-independent terminal/TTY interfaces (raw mode, window size, password input)
- **termios** — Unified Termios API for Unix systems
- **xpty** — Cross-platform PTY (pseudo-terminal) interface supporting both Unix and Windows ConPTY
- **vt** — Full virtual terminal emulator with screen buffer, CSI/ESC/DCS handlers
- **cellbuf** — Cell-based terminal display buffer with line wrapping and scrolling

### Color & Graphics
- **colors** — Color utilities
- **charmtone** — Charm color palette utilities
- **toner** — Color toning utilities
- **mosaic** — Image to terminal rendering
- **ansi/kitty** — Kitty graphics protocol implementation (terminal images)

### Input Handling
- **input** — Terminal event input handler and driver with escape sequence parsing
- **input/clipboard** — Clipboard event handling
- **input/key.go** — Key event definitions and parsing

### Testing Utilities
- **teatest** — Testing library for Bubble Tea programs with fixture-based assertions
- **golden** — Golden file equality verification
- **vcr** — HTTP recording and playback for testing

### Data & Utilities
- **json** — JSON parsing with generics
- **maps** — Generic map utilities
- **slice** — Generic slice utilities
- **ordered** — Generic min/max/clamp functions
- **higherorder** — Generic higher-order functions
- **strings** — String utilities
- **wcwidth** — Wide character width calculation (wrapper around go-runewidth)
- **gitignore** — Gitignore pattern matching

### Specialized Packages
- **pony** — Declarative type-safe markup language for building TUIs using Go templates and Bubble Tea
- **powernap** — LSP client utilities
- **sshkey** — SSH key parsing with passphrase prompts
- **editor** — Open files in text editors
- **etag** — HTTP ETag generation
- **errors** — errors.Join for older Go versions
- **conpty** — Windows Console Pseudo-terminal library
- **open** — Cross-platform file/URL opener

## Key Classes and Methods

### ansi Package
- **Color types:** `BasicColor`, `IndexedColor`, `RGBColor`, `TrueColor` — implement `color.Color` interface
- **Constants:** `SaveCursor`, `RestoreCursor`, `CUU1`, `CUD1`, `CUF1`, etc. for cursor movement
- **Functions:** `CursorUp(n)`, `CursorDown(n)`, `CursorForward(n)`, `CursorBack(n)`, `ClearScreen()`, `ClearLine()`
- **Parser:** `ansi.Parser` struct with `SetHandler()`, `Parse()` methods for processing escape sequences
- **Kitty graphics:** `kitty.Writer`, `kitty.Encoder`, `kitty.Options` for terminal image rendering

### vt Package (Virtual Terminal)
- **Emulator:** `NewEmulator(w, h)` creates terminal with full CSI/ESC/DCS parsing
- **Screen:** `Bounds()`, `Touched()`, `String()`, `Render()` for screen content access
- **Handlers:** `Callbacks` struct for events like title changes, color requests, bell

### cellbuf Package
- **Cell:** `NewCell(r rune, comb ...rune)`, `NewCellString(s)`, `NewGraphemeCell(s)`
- **Line:** `Width()`, `Len()`, `String()`, `At(x)`, `Set(x, cell)` methods
- **Buffer:** Cell-based buffer with grapheme cluster support, wide char handling

### term Package
- **State:** `IsTerminal(fd)`, `MakeRaw(fd)`, `GetState(fd)`, `SetState(fd, state)`, `Restore(fd, oldState)`
- **Size:** `GetSize(fd)` returns (width, height)
- **Password:** `ReadPassword(fd)` reads without local echo

### xpty Package
- **Pty interface:** `Resize(w, h)`, `Size()`, `Name()`, `Start(cmd)` methods
- **Factory:** `NewPty(width, height, opts...)` returns Unix or ConPTY based on OS
- **Types:** `UnixPty`, `ConPty` concrete implementations

### input Package
- **Driver:** `NewDriver()`, `ReadEvents()`, `Stop()` for input event handling
- **Key parsing:** `parseKeyEvent()`, `parseEscapeSequence()` for ANSI key codes
- **Clipboard:** `ReadClipboard()`, `WriteClipboard()` support

### pony Package
- **Template:** `MustParse[T](tmpl)` parses markup into typed template
- **Render:** `Render(data, width, height)` outputs ANSI-rendered string
- **Elements:** `vstack`, `hstack`, `text`, `box`, `scrollview`, `divider`, `spacer`, `slot`
- **Styling:** `foreground-color`, `background-color`, `font-weight`, `font-style` attributes

### exp/teatest Package
- **T:** `NewT(m *testing.T)` wraps test harness
- **Send:** `Send(msg)` sends message to program
- **Assert:** `RequireEqual()`, `RequireOutput()` for output assertions

## Notable Algorithms / Named Patterns

### ECMA-48 ANSI Parser
The `ansi` package implements a strict ECMA-48 compliant parser using a state machine (`ansi.Parser`) that handles:
- C0/C1 control codes
- CSI (Control Sequence Introducer) sequences with parameterized SGR, cursor, mode commands
- OSC (Operating System Command) for titles, colors, hyperlinks
- DCS (Device Control String) for terminal queries
- Kitty graphics protocol extensions

### Cell-Based Rendering with Damage Tracking
The `vt` package uses a cell-based screen model where:
- Each cell holds a rune, width, and style attributes
- Damage tracking via `Touched()` returns only modified lines for efficient redraws
- Wide character handling with placeholder cells

### Grapheme-Aware Width Calculation
The `cellbuf` package uses:
- `runewidth.RuneWidth()` for standard width calculation
- `uniseg.FirstGraphemeClusterInString()` for grapheme cluster detection
- Combining character support in cells for visual width accuracy

### Golden File Testing Pattern
The `exp/golden` package implements snapshot testing:
- Compare output against stored `.golden` files
- Auto-update capability with `Update()` method
- Line-break normalization for cross-platform tests

### Declarative Markup with Go Templates
The `pony` package combines:
- XML-like markup syntax for layout definition
- Go `text/template` for dynamic content interpolation
- Component registry pattern for extensibility
- Box model with borders, padding, margins

## Strengths

- **Comprehensive Terminal Coverage:** One of the most complete Go terminal utility libraries covering escape sequences, PTY, virtual terminals, and rendering
- **ECMA-48 Compliance:** Strict ANSI parser follows international standards, ensuring wide compatibility
- **Cross-Platform:** Full support for Unix/Linux, macOS, and Windows (including ConPTY)
- **Modular Design:** Each package is independently usable without the whole monorepo
- **Generics Usage:** Modern Go 1.18+ generics in `exp/ordered`, `exp/slice`, `exp/maps`, `exp/higherorder` for type-safe utilities
- **Bubble Tea Integration:** `teatest` provides first-class testing support for the Bubble Tea TUI framework
- **Kitty Graphics Protocol:** Full implementation of terminal image rendering via Kitty protocol
- **Active Maintenance:** Regular updates with 68 forks and active issue tracking

## Weaknesses

- **Experimental Status:** Many packages are explicitly marked experimental with no backwards compatibility promises
- **Sparse Documentation:** Some packages lack comprehensive godoc; pony package relies heavily on AI-generated docs
- **Large Surface Area:** 30+ packages with varying maturity levels can be overwhelming
- **No Clear Entry Point:** Unlike bubbleglue or lipgloss, this is a kitchen-sink repository rather than a focused library
- **LFS Dependencies:** Repository uses Git LFS for font files; some examples may not work without LFS filters
- **Complex Dependency Graph:** Packages depend on each other in non-obvious ways (e.g., vt depends on ansi, ultraviolet)

## SugarCraft Mapping

The SugarCraft project ports Charmbracelet ecosystem Go libraries to PHP. The `charmbracelet/x` monorepo maps to several potential SugarCraft libs:

### Direct Ports
| Package | SugarCraft Lib | Notes |
|---------|---------------|-------|
| `ansi` | `candy-core` (planned) | ANSI escape sequences, cursor control, color SGR sequences |
| `vt` | None yet | Full virtual terminal emulator would be complex to port |
| `cellbuf` | None yet | Cell-based buffer for terminal rendering |
| `term` | `candy-core` | Raw mode, terminal size, password input |
| `wcwidth` | `candy-core` | Wide char width calculation |

### Color/Graphics
| Package | SugarCraft Lib | Notes |
|---------|---------------|-------|
| `colors` | `sugar-bits` (existing) | Color parsing and manipulation |
| `charmtone` | `sugar-bits` | Charm color palette |
| `toner` | None yet | Color toning (darken/lighten/saturate) |
| `mosaic` | None yet | Image to terminal rendering |
| `ansi/kitty` | None yet | Kitty graphics protocol |

### Input Handling
| Package | SugarCraft Lib | Notes |
|---------|---------------|-------|
| `input` | `candy-core` (planned) | Terminal event input driver |
| `input/key` | `candy-core` | Key event definitions |

### Testing
| Package | SugarCraft Lib | Notes |
|---------|---------------|-------|
| `teatest` | `candy-core` tests | Bubble Tea testing patterns |
| `golden` | `candy-core` tests | Snapshot/golden file testing |

### Utilities
| Package | SugarCraft Lib | Notes |
|---------|---------------|-------|
| `json` | `candy-core` | JSON parsing with generics (native PHP) |
| `gitignore` | None needed | PHP has `fnmatch()` and `glob()` |
| `xpty` | `candy-pty` (existing) | PTY interface |
| `termios` | `candy-pty` | Termios wrapper |

### Not Applicable (Go-specific or deprecated)
| Package | Reason |
|---------|--------|
| `pony` | AI-generated experiment, declarative TUI markup specific to Go |
| `powernap` | LSP client utilities for Go |
| `conpty` | Windows-specific |
| `open` | Cross-platform open (use PHP `exec('xdg-open ...')`) |
| `errors` | Go-specific `errors.Join` equivalent |

## Analysis

charmbracelet/x is Charm's experimental laboratory — a monorepo where new terminal-related ideas are incubated before being promoted to their own dedicated repositories (like bubbleglue, lipgloss, or bubbletea). With 293 stars and 68 forks, it represents a significant investment in terminal infrastructure for the Go ecosystem.

The repository's crown jewel is the `ansi` package, which provides the most complete Go implementation of ECMA-48 ANSI escape sequence handling. This includes not just basic cursor movement but full SGR (Select Graphic Rendition) color support, DECRTMM (managing margins), and the Kitty graphics protocol for rendering images in terminals. The `vt` package builds on this foundation to create a complete virtual terminal emulator that can parse and render ANSI escape sequences in real-time.

The `pony` package is particularly interesting as an AI-generated experiment in declarative TUI markup — it allows developers to describe terminal UIs using XML-like syntax with Go template interpolation. While marked experimental and AI-generated, it demonstrates interesting patterns for building type-safe declarative TUI frameworks that could inspire similar PHP implementations.

For SugarCraft, this repository suggests several additional porting opportunities beyond the current 40+ libraries. The `wcwidth` and `termios` functionality overlaps significantly with `candy-core` and `candy-pty`, while color-related packages could enhance `sugar-bits`. The `cellbuf` concept of a cell-based terminal buffer could be particularly valuable for building terminal emulators or more sophisticated TUI components in PHP.

The repository's MIT license and the Charm team's commitment to open source make it a reliable reference implementation for ANSI/vt100 terminal behavior. SugarCraft implementers would benefit from studying the parser state machines and damage-tracking algorithms when building PHP terminal applications.