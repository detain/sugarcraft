# charmbracelet/tree-sitter-vhs

## Metadata
- **URL:** https://github.com/charmbracelet/tree-sitter-vhs
- **Language:** Go (grammar.js) + C (generated parser)
- **Stars:** ~100-200 (estimated based on Charmbracelet ecosystem size; GitHub API unavailable for exact count)
- **License:** MIT (Copyright 2022-2023 Charmbracelet, Inc.)
- **Description:** Tree-sitter grammar for syntax highlighting VHS `.tape` files (the terminal recording/playback format used by the Charmbracelet VHS tool)

## Feature List
- **VHS Tape Parsing:** Full grammar for parsing VHS `.tape` script files used to record and replay terminal sessions
- **Command Recognition:** Parses all VHS commands including `Output`, `Set`, `Type`, `Sleep`, `Enter`, `Backspace`, `Escape`, `Hide`, `Show`, `Copy`, `Paste`, arrow keys, and more
- **Keyboard Modifiers:** Supports `Ctrl+`, `Alt+`, `Shift+` modifier combinations with keys
- **Settings Parsing:** Handles all VHS configuration options: `FontSize`, `FontFamily`, `Width`, `Height`, `Theme`, `Shell`, `Framerate`, `PlaybackSpeed`, `CursorBlink`, `WindowBar`, etc.
- **Duration/Animation:** Parses timing expressions like `500ms`, `2s`, and delay prefixes like `Type@500ms`
- **Multi-language Bindings:** Generates parsers for Node.js, Go, Python, Rust, Swift, and C
- **Syntax Highlighting Queries:** `highlights.scm` defines highlighting patterns for editors (Neovim treesitter, Emacs, etc.)
- **Incremental Parsing:** Tree-sitter's incremental parsing for efficient re-parsing on edits
- **Comment Support:** Parses `#` comments

## Key Classes and Methods

This is a **tree-sitter grammar** (not a traditional class-based library). The core is defined in `grammar.js`:

- **`program` rule:** Root node containing repeated `command` or `comment` nodes
- **`command` rule:** Choice node covering all 30+ VHS commands
- **`control` rule:** Regex `/Ctrl\+(Alt\+)?(Shift\+)?([^/\d/\s:]|Enter)/` for Ctrl combinations
- **`alt` rule:** Regex for Alt key combinations
- **`shift` rule:** Regex for Shift key combinations
- **`setting` rule:** Handles all `Set` subcommands (FontFamily, FontSize, Theme, etc.)
- **`type` rule:** `seq('Type', optional($.duration), repeat1($.string))` — typed text with optional delay
- **`sleep` rule:** `seq('Sleep', $.time)` — pause durations
- **`output` rule:** `seq('Output', $.path)` — output file path
- **`env` rule:** `seq('Env', $.string, $.string)` — environment variable setting

### Generated Artifacts (src/parser.c)
- **`tree_sitter_vhs()`:** Main parser entry point, returns `TSLanguage*`
- **`src/node-types.json:** Auto-generated node type definitions for all 35+ node types

### Language Bindings
- **Node.js:** `bindings/node/binding.cc` — NAPI C++ binding, exports `tree_sitter_vhs()` + language object
- **Go:** `bindings/go/binding.go` — cgo wrapper, `Language() unsafe.Pointer`
- **Python:** `bindings/python/` — setuptools Extension building against `src/parser.c`
- **Rust:** `bindings/rust/lib.rs` — `language()` function + `NODE_TYPES` constant
- **Swift:** `Package.swift` — Swift Package Manager integration
- **C:** `Makefile`-based static/dynamic library build

## Notable Algorithms / Named Patterns

- **Tree-sitter Incremental Parsing:** Uses tree-sitter's ability to re-parse only changed portions of the document, critical for IDE use cases
- **Regex-based Lexical Recognition:** Heavy use of regex patterns for keyboard modifiers (`/Ctrl\+(Alt\+)?(Shift\+)?([^/\d/\s:]|Enter)/`)
- **PEG-like Grammar Composition:** `grammar.js` uses tree-sitter's PEG-based grammar DSL with `seq()`, `choice()`, `repeat()`, `optional()`
- **External Scanner Pattern:** Commented预留 spot (`// NOTE: if your language has an external scanner, add it here.`) for advanced grammars needing custom lexers
- **Highlights Query Language:** `highlights.scm` uses tree-sitter's query language for semantic highlighting:
  ```scheme
  ["Output" "Backspace" "Down" ...] @keyword
  [ "Shell" "FontFamily" ... ] @type
  (control) @function.macro
  (comment) @comment @spell
  ```

## Strengths
- **Well-maintained Ecosystem:** Part of Charmbracelet's mature open-source ecosystem with proper CI/CD
- **Multi-platform Support:** CI tests on Ubuntu, Windows, and macOS
- **Comprehensive Test Coverage:** Tree-sitter corpus tests in `test/corpus/` covering all commands and edge cases
- **Extensive Language Support:** 6 language bindings (Node, Go, Python, Rust, Swift, C)
- **IDE Integration Ready:** Officially supports Neovim treesitter and Emacs tree-sitter-langs
- **Semantic Highlighting:** highlights.scm enables rich syntax highlighting in supporting editors
- **Version Stability:** At v0.0.1 — stable, minimal API surface to maintain
- **MIT Licensed:** Permissive license for broad adoption

## Weaknesses
- **Shallow Feature Set:** Grammar is narrow — only parses one file format (VHS .tape), not a general-purpose tool
- **Limited Error Recovery:** Tree-sitter grammars can have strict error recovery, but the grammar doesn't define explicit error nodes
- **Single-purpose:** Cannot be repurposed for other formats; tightly coupled to VHS syntax
- **No Runtime Logic:** Just parsing/validation, no interpretation or execution of .tape files
- **Generated Code:** `src/parser.c` is auto-generated (154KB) — not meant to be edited directly
- **Documentation Sparse:** Minimal docs beyond README; assumes familiarity with tree-sitter
- **External Dependency:** Requires tree-sitter runtime (`tree-sitter >= 0.22.0`)

## SugarCraft Mapping

### Direct Mapping (Many-to-Many)

| tree-sitter-vhs Concept | SugarCraft Equivalent | Notes |
|------------------------|------------------------|-------|
| VHS `.tape` file format | No direct equivalent | SugarCraft has no terminal recording/playback format |
| `grammar.js` parser definition | N/A | SugarCraft uses PHP, not Go/JS |
| `highlights.scm` syntax queries | Could use with `sugar-bits` if PHP has tree-sitter bindings | PHP tree-sitter bindings exist but not used in SugarCraft |
| VHS command parsing | Potential `sugar-vhs` library | Could parse/validate .tape files in PHP, or generate them |
| `Set FontSize`, `Set Theme` | `sugar-bits` Theme/Font handling | Similar terminal styling concepts |
| `Type "..."`, `Enter`, `Sleep` | `sugar-bits` TUI input events | Equivalent to keyboard event simulation |
| `Output demo.gif` | VHS renders to GIF; SugarCraft has no VHS port | Could create `sugar-vhs` to parse/generate .tape |

### Suggested SugarCraft Additions

1. **`sugar-vhs`** (new library): Parse and validate VHS `.tape` files in PHP
   - Read/parse `.tape` syntax
   - Validate command sequences
   - Could generate `.tape` files from PHP (for VHS integration)
   - Would use `grammar.js` as reference but reimplement in PHP

2. **`sugar-bits` enhancement**: If VHS support is needed, extend `sugar-bits` to understand terminal styling concepts (FontSize, Theme, etc.)

3. **VHS Integration**: SugarCraft could integrate with the actual VHS tool (Go binary) rather than reimplementing the format

### Not Applicable
- Tree-sitter itself has no SugarCraft equivalent — SugarCraft is PHP-only
- The multi-language binding architecture is tree-sitter-specific and not applicable to SugarCraft

---

## Analysis

**charmbracelet/tree-sitter-vhs** is a specialized tree-sitter grammar that provides syntax highlighting and parsing support for VHS `.tape` files — the scripting format used by the Charmbracelet VHS tool to record and replay terminal sessions. It is not a general-purpose library but rather an editor integration tool that enables IDEs like Neovim and Emacs to provide real-time syntax highlighting for `.tape` files.

The grammar itself is well-engineered using tree-sitter's JavaScript-based DSL (`grammar.js`). It defines 30+ command types covering the full VHS command set: terminal output configuration (`Output`), display settings (`Set FontSize`, `Set Theme`, etc.), keyboard simulation (`Type`, `Enter`, `Ctrl+`, etc.), timing (`Sleep`, `Duration`), and block-level commands (`Hide`/`Show` blocks). The use of regex patterns for keyboard modifiers (`/Ctrl\+(Alt\+)?(Shift\+)?([^/\d/\s:]|Enter)/`) allows flexible matching while maintaining precision.

The repository exemplifies best practices in tree-sitter grammar development: comprehensive corpus tests (`test/corpus/*.txt`), multi-platform CI testing, official integrations with major editors (Neovim treesitter, Emacs tree-sitter-langs), and multi-language bindings for consumption in different ecosystems. The `highlights.scm` file demonstrates tree-sitter's semantic highlighting capabilities, mapping commands to highlight groups like `@keyword`, types to `@type`, and comments to `@comment`.

For SugarCraft, this grammar has limited direct applicability since SugarCraft ports TUI libraries to PHP while tree-sitter is inherently multi-language. However, it suggests a potential `sugar-vhs` library that could parse `.tape` files in PHP, enabling PHP applications to either validate VHS scripts or generate them as output. The more practical mapping would be in styling concepts: VHS's `Set FontSize`/`Set Theme` parallels how `sugar-bits` handles terminal styling, and the keyboard simulation commands (`Type`, `Enter`, arrow keys) mirror the input event handling in `sugar-bits` TUI components.
