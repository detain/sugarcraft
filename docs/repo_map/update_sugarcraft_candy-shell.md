# Overview

CandyShell is a PHP port of `charmbracelet/gum` (~8.7k stars, Go) — a composer-installable CLI providing 13 subcommands for interactive TUI primitives in shell scripts. The package sits at the **application framework tier** (`Candy-` prefix per naming conventions), wrapping foundation libs (`candy-core`, `candy-forms`, `candy-sprinkles`, `candy-shine`, `candy-pty`) with a Symfony Console surface. It provides ~85% functional parity with gum for typical shell script use cases.

**Biggest opportunity areas:**
- Fuzzy matching implementation (filter's `--fuzzy` is a no-op)
- 2D grid choose (candy-shell only has 1D list navigation)
- External editor integration for write command
- Per-element style flags (dotted form `--header.foreground=red`)
- Bubble Tea-style subscriptions for concurrent async commands

**Biggest missing capabilities:**
- No TST-based or bitmap-tracked search algorithms
- No Cassowary constraint solver for responsive layouts
- No proper enum validation for CLI option constraints
- No command palette or fuzzy command lookup
- No component composition system beyond single-command prompts

---

# Internal Capability Summary

## Current Architecture

### Two-Tier Design

The package separates **interactive** commands (choose, confirm, file, filter, input, pager, spin, write) that run a TEA event loop via `Program` vs **non-interactive** commands (format, join, log, style, table) that execute synchronously.

```
Command (Symfony Console layer)
  └── Model (TEA pattern: init/update/view/subscriptions)
        └── Components (candy-forms: TextInput, ItemList, etc.)
```

### Key Source Files

| Component | Files | Notes |
|---|---|---|
| Application | `src/Application.php` | Auto-discovery, env var fallback, typo suggester |
| Commands | `src/Command/` (13 files) | Symfony Command subclasses with `#[Command]` attributes |
| Models | `src/Model/` (8 files) | TEA models wrapping candy-forms components |
| Attributes | `src/Attribute/` (5 files) | `#[Command]`, `#[Alias]`, `#[Example]`, `#[Flag]`, `#[ValueEnum]` |
| Completion | `src/Completion/` (3 files) | Bash, Zsh, Fish completion generators |
| Help | `src/Help/` (2 files) | HelpFormatter, TypoSuggester |
| Process | `src/Process/` (3 files) | RealProcess (deprecated), FakeProcess for tests |

### Dependencies

```
candy-shell
├── symfony/console ^6.4 || ^7.0     CLI framework
├── sugarcraft/candy-core             TEA runtime: Program, Model, Msg, KeyType, Cmd
├── sugarcraft/candy-forms             Components: TextInput, ItemList, FilePicker, etc.
├── sugarcraft/candy-pty               PosixProcess for spin command
├── sugarcraft/candy-shine            Renderer (markdown), Theme
└── sugarcraft/candy-sprinkles        Style, Border, Align, Table
```

## Current Features

### 13 Subcommands

| Command | Type | Component | Key Features |
|---|---|---|---|
| choose | interactive | ItemList | multi-select, ordered output, cursor styles |
| confirm | interactive | Confirm | exit codes 0/1/2, affirmative/negative labels |
| file | interactive | FilePicker | directory tree, hidden files, size display |
| filter | interactive | ItemList | stdin filtering, --fuzzy (broken), multi-select |
| input | interactive | TextInput | password masking, char-limit, placeholder |
| pager | interactive | Viewport | soft-wrap, line numbers, match highlighting |
| spin | interactive | Spinner + Process | 12 spinner styles, output capture, PTY |
| write | interactive | TextArea | multi-line, line numbers, max-lines |
| format | non-interactive | Shine Renderer | markdown/code/template/emoji rendering |
| join | non-interactive | Width util | horizontal/vertical concatenation |
| log | non-interactive | Style | text/json/logfmt formatters, time aliases |
| style | non-interactive | Sprinkles Style | colors, borders, padding, alignment |
| table | non-interactive | Sprinkles Table | CSV parsing, border styles, column widths |

### CLI Design Patterns

1. **Attribute-based meta-programming** — `#[Command]`, `#[Alias]`, `#[Example]` for auto-discovery and rich help
2. **Env var fallback** — `CANDYSHELL_<FLAG>` global prefix (vs gum's `GUM_<CMD>_<FLAG>` per-command)
3. **Levenshtein typo suggester** — distance ≤ 2 for command name correction
4. **Shell completion** — custom Bash/Zsh/Fish generators from app introspection
5. **i18n support** — 16 locales via `Lang.php` wrapper

## Strengths

- Complete 13-command surface area matching gum's scope
- Clean separation: Command → Model → Component
- PHP 8.3+ strict typing throughout
- Comprehensive i18n (16 locales)
- Env var configuration fallback
- Shell completion for 3 shells
- 23 test files, 13 VHS demos

## Weaknesses

- `--fuzzy` is no-op (substring matching only)
- `choose` uses 1D list, not gum's 2D grid with vim-style navigation
- No external editor integration for `write` (gum uses `$EDITOR` via `charmbracelet/x/editor`)
- `subscriptions()` returns null — no Bubble Tea-style concurrent commands
- `CommandScanner` uses `get_declared_classes()` — must pre-load classes
- `#[Flag(enum: ...)]` validation not wired (Symfony InputOption has no allowed-values constraint)

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|---|---|---|---|
| `charmbracelet/gum` | **Primary upstream** | 13-command surface, fuzzy filter, grid choose, external editor, per-element styles | Critical |
| `charmbracelet/bubbletea` | TEA runtime upstream | Elm architecture, Cmd/Batch/Sequence, subscriptions, mouse handling, synchronized output | Critical |
| `charmbracelet/lipgloss` | Style system upstream | CSS-like styling, color blending, borders, layer compositing, hyperlink support | High |
| `textualize/textual` | Sister TUI framework | Reactive state, CSS layout, message pump, 40+ widgets, command palette, testing | High |
| `ratatui/ratatui` | Rust TUI reference | Cassowary constraints, buffer diffing, widget trait pattern, stateful widgets | High |
| `php-tui/php-tui` | PHP TUI library | Ratatui port, Cassowary solver, double-buffering, 15+ widgets, extensions | High |
| `p-gen/smenu` | Terminal filter reference | Ternary Search Tree, fuzzy search, bitmap matching, display modes (line/column/tabulate) | Medium |
| `c9s/CLIFramework` | PHP CLI framework | Hierarchical commands, completion generation, extension system, service container | Medium |
| `charmbracelet/huh` | Form library | Dynamic reactivity, multi-field forms, validation, field binding | Low |

---

# Feature Gap Analysis

## Critical Priority

### 1. Fuzzy Matching in Filter
- **Title:** Implement real fuzzy matching algorithm for `filter` command
- **Description:** The `--fuzzy` flag currently maps to `--strict` (substring matching). gum uses `sahilm/fuzzy` library; smenu uses TST-based fuzzy traversal.
- **Why it matters:** Fuzzy matching is a core feature for interactive filtering — without it, users cannot find items that don't exactly match their input prefix.
- **Source repo:** `charmbracelet/gum` (filter.go uses `sahilm/fuzzy`)
- **Source PR/issue:** `charmbracelet/gum` fuzzy implementation
- **Implementation ideas:**
  1. Port `sahilm/fuzzy` Go library to PHP (pure PHP, no FFI needed)
  2. Implement TST-based approach like smenu with bitmap tracking
  3. Use existing PHP fuzzy libraries if available
- **Estimated complexity:** Medium — requires implementing ranking/scoring algorithm
- **Expected impact:** High — makes filter useful for real-world fuzzy scenarios

### 2. 2D Grid Choose
- **Title:** Add 2D grid-based item selection to `choose` command
- **Description:** gum's choose uses `paginator.Model` with left/right navigation for 2D grid layout. candy-shell's choose uses 1D ItemList with up/down only.
- **Why it matters:** Grid choose is essential for selecting from large numbers of items efficiently (e.g., emoji picker, file picker from many files).
- **Source repo:** `charmbracelet/gum` (choose/choose.go + choose/model.go)
- **Source PR/issue:** gum's use of `paginator.Model` and 2D navigation
- **Implementation ideas:**
  1. Create new `GridItemList` component in candy-forms
  2. Extend `ItemList` with column count and 2D cursor movement
  3. Add left/right key handling in the TEA model
- **Estimated complexity:** High — requires new component and model changes
- **Expected impact:** High — enables vim-style grid navigation

### 3. Per-Element Style Flags
- **Title:** Wire complete dotted-style per-element flags (`--cursor.foreground=red`)
- **Description:** The `SubStyleParser` exists but `--style "element.property=value"` is not fully wired across all commands. gum supports `--header.foreground`, `--cursor.bold`, etc.
- **Why it matters:** Per-element styling is a key gum feature for customization.
- **Source repo:** `charmbracelet/gum` (style options with dotted form)
- **Implementation ideas:**
  1. Complete wiring of `SubStyleParser` output to component styles
  2. Ensure all interactive commands expose their element names in help
- **Estimated complexity:** Low-Medium — mostly wiring existing infrastructure
- **Expected impact:** Medium — improves visual customization

## High Priority

### 4. External Editor for Write
- **Title:** Implement `$EDITOR` integration for write command (Ctrl+E)
- **Description:** gum's write uses `charmbracelet/x/editor` to open external editor on Ctrl+E. candy-shell has no editor integration.
- **Why it matters:** Multi-line text editing in terminal is painful without external editor fallback.
- **Source repo:** `charmbracelet/gum` (write/write.go uses `x/editor`)
- **Implementation ideas:**
  1. Use `getenv('EDITOR')` or `getenv('VISUAL')` to find editor
  2. Write temp file, spawn editor, read back result
  3. Integrate via TEA model message loop
- **Estimated complexity:** Medium — requires process spawning and temp file management
- **Expected impact:** Medium — enables real text editing workflows

### 5. Command Scanner Fix
- **Title:** Replace `get_declared_classes()` with proper autoloader-based discovery
- **Description:** `CommandScanner::scan()` only finds already-loaded classes. Commands must be autoloaded or required before scanning.
- **Why it matters:** Auto-discovery doesn't work reliably with Composer's PSR-4 autoloading.
- **Source repo:** `c9s/CLIFramework` uses reflection-based approach
- **Implementation ideas:**
  1. Use ` spl_autoload_functions()` to trigger autoloading
  2. Use Composer API to get registered namespaces and scan them
  3. Use `ReflectionExtension::getClasses()` for already-loaded classes
- **Estimated complexity:** Medium — requires understanding Composer's internals
- **Expected impact:** High — makes auto-discovery actually work

### 6. Enum Option Validation
- **Title:** Wire live validation for `#[Flag(enum: ...)]` attribute constraints
- **Description:** `Flag::$enum` holds class-string but Symfony InputOption has no native validation. User can pass invalid values without error.
- **Why it matters:** Type safety for CLI options is a core promise of the attribute system.
- **Source repo:** Symfony Console InputOption documentation
- **Implementation ideas:**
  1. Post-process options in `Application::doRun()` before command execution
  2. Normalize values using enum's `tryFrom()` or custom factory
  3. Throw `InvalidArgumentException` with suggestion if value is invalid
- **Estimated complexity:** Low — mostly wiring existing infrastructure
- **Expected impact:** Medium — improves DX with clear error messages

## Medium Priority

### 7. Bubble Tea Subscriptions
- **Title:** Implement `subscriptions()` return value in TEA models
- **Description:** `subscriptions()` currently returns null on all models. gum uses `Tea.Batch` for concurrent commands (e.g., spinner + timeout).
- **Why it matters:** Enables time-based recurring events, timeouts, and concurrent async operations.
- **Source repo:** `charmbracelet/bubbletea` (Every, Tick, Batch, Sequence)
- **Implementation ideas:**
  1. Create `Subscription` interface in candy-core
  2. Implement `Tick`, `Every` subscription types
  3. Wire subscriptions into Program's event loop
- **Estimated complexity:** High — requires changes to Program loop
- **Expected impact:** Medium — enables timeouts and animated spinners

### 8. Command Palette
- **Title:** Add fuzzy command lookup with `candyshell` (no subcommand)
- **Description:** textual has excellent command palette pattern. gum has none. Could add as candy-shell enhancement.
- **Why it matters:** Improves discoverability for new users and power users who can't remember all 13 commands.
- **Source repo:** `textualize/textual` (command.py — extensive command palette)
- **Implementation ideas:**
  1. When no subcommand given, show searchable list of all commands
  2. Use fuzzy matching against command names and descriptions
- **Estimated complexity:** Medium — new command + fuzzy integration
- **Expected impact:** Low-Medium — nice-to-have for discoverability

### 9. Logfmt Formatter Enhancement
- **Title:** Enhance logfmt formatter with proper key ordering and escaping
- **Description:** Current logfmt is basic. gum's log uses structured fields with proper escaping.
- **Source repo:** `charmbracelet/gum` log implementation
- **Implementation ideas:**
  1. Add proper value escaping (spaces, equals, quotes)
  2. Sort keys deterministically
  3. Support nested structured data
- **Estimated complexity:** Low — incremental improvements
- **Expected impact:** Low — already functional for most use cases

## Low Priority

### 10. Go Template Functions
- **Title:** Add Go-style template functions to format --type template
- **Description:** gum's template mode supports `upper`, `lower`, `default`, etc. candy-shell only has lightweight `{{VAR}}` expansion.
- **Source repo:** `charmbracelet/gum` (format/formats.go template functions)
- **Estimated complexity:** Medium — requires template engine extension
- **Expected impact:** Low — most users only need variable substitution

### 11. Hyperlink Support
- **Title:** Add OSC 8 hyperlink support to styles
- **Description:** lipgloss supports hyperlinks via `Transform(func(string) string)`. candy-sprinkles does not.
- **Source repo:** `charmbracelet/lipgloss` hyperlink support
- **Estimated complexity:** Low — depends on Sprinkles capabilities
- **Expected impact:** Low — niche use case

---

# Algorithm / Performance Opportunities

## Fuzzy Matching Algorithm

**Current approach:** Substring matching via `ItemList` — `stripos($haystack, $needle) !== false`

**External approach (gum/sahilm/fuzzy):**
- Uses fuzzy scoring algorithm that ranks matches by:
  - Consecutive character match bonus
  - Start-of-word bonus
  - Gap penalty for non-matching characters between matches
- Returns matches sorted by score with character positions for highlighting

**External approach (smenu/TST):**
- Ternary Search Tree for O(k) prefix search where k = key length
- Bitmap tracking for match highlighting per character position
- Fuzzy traversal builds candidate sets incrementally as user types

**Why external is better:**
- Substring matching fails when user doesn't know exact substring
- Fuzzy matching is expected behavior for interactive filtering
- TST provides sublinear performance for large lists

**Tradeoffs:**
- Fuzzy algorithm requires more computation per keystroke
- TST requires building index structure (good for repeated use)
- For small lists (<100 items), substring may be faster

**Applicability:**
- Most applicable to `filter` command — core use case
- Could also enhance `choose` with fuzzy pre-filtering

## Buffer Diffing / Delta Rendering

**Current approach:** Full re-render on every frame via `view()` returning ANSI string

**External approach (ratatui, php-tui, bubbletea):**
- Maintain two buffers (previous and current)
- Compute diff at cell level — only changed cells sent to terminal
- Uses ANSI 2026 synchronized output for atomic updates

**Why external is better:**
- Reduces terminal I/O significantly for large viewports
- Eliminates flicker on complex UIs
- Critical for performance with many components

**Tradeoffs:**
- More complex implementation
- Requires tracking previous buffer state
- Most candy-shell commands are simple (single component)

**Applicability:**
- Would primarily benefit `pager` command with large content
- Would benefit `choose` with many visible items
- Not critical for most shell script use cases

## Grapheme-Aware Width Calculation

**Current approach:** `Width::wrap()` handles ANSI stripping but may not handle all Unicode edge cases

**External approach (displaywidth library):**
- Uses `clipperhouse/displaywidth` for correct Unicode width
- Handles combining characters, emoji, CJK width properly

**Why external is better:**
- Correct handling of exotic Unicode in terminal width calculation
- Avoids layout bugs with emoji in content

**Tradeoffs:**
- Additional dependency
- Most shell script content is ASCII

---

# Architecture Improvements

## 1. Event Loop Enhancement

**Current state:** Program runs a simple ReactPHP-based event loop with tick-driven updates.

**Proposed improvement:** Add subscription infrastructure:

```php
// In Model interface
public function subscriptions(): ?Subscriptions;

// In Program
foreach ($model->subscriptions() ?? [] as $subscription) {
    // Register tick/timeout/event handlers
}
```

This matches bubbletea's subscription model and enables:
- Timeouts on interactive commands
- Spinner animation alongside process execution
- Periodic UI updates without blocking

## 2. Component Composition

**Current state:** Each command wraps a single component in its TEA model.

**Proposed improvement:** Allow composing multiple components in one model:
- `choose` + `filter` combination for searchable grids
- `pager` + `input` for search-within-content
- Modal overlays using layer compositing

This would draw from lipgloss's `Compositor` concept.

## 3. Extension System

**Current state:** No formal extension system; commands are discovered via attributes.

**Proposed improvement:** Add extension points:
- Pre/post execute hooks
- Custom formatter registration
- Custom component factory injection

This would draw from CLIFramework's extension system pattern.

---

# API / Developer Experience Improvements

## 1. Better Error Messages

**Current:** Typo suggestion only for command names (distance ≤ 2).

**Proposed:** Extend typo suggestion to:
- Flag names within a command
- Subcommand paths (e.g., "Did you mean `candyshell filter`?")
- Enum value names

## 2. Typed Option Binding

**Current:** Options return mixed types (strings, integers, booleans).

**Proposed:** Use PHP 8.3 typed properties and constructor property promotion in command classes:
```php
final class ChooseCommand extends Command
{
    public function __construct(
        private readonly int $height = 10,
        private readonly bool $ordered = false,
    ) {}
}
```

## 3. Shell Completion Enhancement

**Current:** Basic completion for command names only.

**Proposed:** Add dynamic completion from `#[Flag(enum: ...)]`:
- Complete enum values when cursor is on a flag
- Complete file paths for `--file` flags
- Complete directory paths for `--cwd` flags

---

# Documentation / Cookbook Opportunities

## 1. Shell Scripting Guide

candy-shell's primary purpose is shell script integration. Documentation should include:
- Basic usage examples for each command
- Pipeline compositions (`candyshell filter | candyshell choose`)
- Exit code handling in scripts
- Environment variable configuration examples

## 2. Custom Command Tutorial

Creating custom commands with `#[Command]` attribute is documented but could use:
- Step-by-step tutorial building a custom prompt
- Example of composing multiple components
- Testing strategies for custom commands

## 3. Migration Guide from gum

Users coming from gum need a quick reference:
- Flag name differences (all caps vs lowercase)
- Env var prefix differences (`GUM_CHOOSE_HEIGHT` vs `CANDYSHELL_HEIGHT`)
- Behavioral differences (2D grid vs 1D list, fuzzy vs substring)

---

# UX / TUI Improvements

## 1. Cursor Shape Control

**Current:** Limited `--cursor-mode` support (blink/static/hidden).

**Proposed:** Support for cursor shapes (block/underline/bar) via candy-vt or direct ANSI:
- Block cursor: `\x1b[2 q`
- Underline: `\x1b[4 q`
- Beam: `\x1b[6 q`

## 2. Mouse Support in Interactive Commands

**Current:** Mouse events not handled in most commands.

**Proposed:** Add mouse support similar to bubbletea:
- Click to position cursor in choose/filter
- Click to select in choose
- Scroll wheel in pager

**Source:** `charmbracelet/bubbletea` mouse handling (SGR extended mode)

## 3. Focus Events

**Current:** No focus tracking.

**Proposed:** Handle terminal focus gained/lost for:
- Clearing sensitive input on focus loss
- Refreshing stale content on focus regain

---

# Testing / Reliability Improvements

## 1. Snapshot Testing for Renderers

**Current:** Some golden tests exist.

**Proposed:** Expand snapshot tests for:
- Each command's `--help` output
- ANSI rendering across themes
- Error message formatting

## 2. Fuzz Testing

**Current:** Limited fuzzing of input parsing.

**Proposed:** Add fuzz testing for:
- Unicode input handling
- Large input files (filter/pager)
- Malformed CSV/TSV in table command

## 3. Integration Tests for Process Commands

**Current:** `FakeProcess` exists for unit tests.

**Proposed:** Add integration tests that actually spawn processes:
- Spin command with real and fake processes
- Signal handling (SIGINT → exit code 130)
- Timeout behavior

---

# Ecosystem / Integration Opportunities

## 1. Integration with sugar-bits

candy-shell currently uses candy-forms directly but could also expose sugar-bits components:
- `TreeView` for hierarchical file picker
- `SelectionList` with custom renderers

## 2. Integration with sugar-charts

Could add new command: `candyshell chart` that renders data via sugar-charts:
```sh
candyshell chart --type bar --data "a:10 b:20 c:30"
```

## 3. Integration with candy-palette

Could add `--theme` flag to more commands that respects terminal color profile detection.

---

# Notable PRs / Issues / Discussions

## charmbracelet/gum Issues

### Fuzzy Matching Implementation
- gum uses `sahilm/fuzzy` for real fuzzy matching
- candy-shell's `--fuzzy` maps to substring matching (known gap)
- **Lesson:** Porting surface API is easier than porting algorithms

### External Editor Integration
- gum's write uses `charmbracelet/x/editor` for `$EDITOR` integration
- This is a Go library port that would need to be recreated in PHP
- **Lesson:** Some gaps are due to upstream Go-specific dependencies

### Grid Choose vs 1D List
- gum's choose uses paginator with 2D grid layout
- Requires `bubbles/paginator` which maps to a future sugar-bits component
- **Lesson:** Some features require upstream component ports first

## p-gen/smenu Insights

### TST-Based Search
- TST provides O(k) prefix search and O(n*k) fuzzy search
- Bitmap tracking for highlighting is memory-efficient
- **Lesson:** Could enhance filter with TST indexing for large datasets

### Display Modes
- smenu supports line/column/tabulate modes
- candy-shell filter could benefit from similar display flexibility
- **Lesson:** Multi-mode display is a useful pattern for selection tools

## textualize/textual Insights

### Command Palette
- textual's command palette is extensive with provider pattern
- Fuzzy search over commands with live filtering
- **Lesson:** Could enhance candy-shell with optional palette mode

### CSS Layout
- textual's TCSS implementation is comprehensive
- Could inspire enhanced layout in candy-shell's non-interactive commands
- **Lesson:** Web developer familiarity is a major DX win

---

# Recommended Roadmap

## Immediate Wins (0-2 weeks)

1. **Wire per-element style flags** — `SubStyleParser` already exists, just needs wiring across commands
2. **Fix CommandScanner** — Use `spl_autoload_functions()` to trigger autoloading before scanning
3. **Add enum validation** — Post-process options before command execution
4. **Enhance logfmt formatter** — Better escaping and key ordering

## Medium-Term Improvements (1-3 months)

5. **Implement fuzzy matching** — Port or implement fuzzy scoring algorithm for filter
6. **Add external editor to write** — Integrate `$EDITOR` via temp file + process spawning
7. **Implement subscriptions()** — Add Tick/Every subscription types to Program loop
8. **Add mouse support** — Basic click handling in choose/filter commands
9. **Create GridItemList component** — 2D grid navigation for choose (future dependency)

## Major Architectural Upgrades (3-6 months)

10. **Buffer diffing in renderer** — Only send changed cells to terminal (advanced optimization)
11. **Cassowary constraint solver** — For responsive layouts (draws from php-tui)
12. **Command palette** — Optional fuzzy command lookup mode
13. **Layer compositing** — For modal overlays and complex layouts

## Experimental Ideas (6+ months)

14. **WebAssembly target** — Serve candy-shell in browser (draws from textual-web)
15. **Tree-sitter integration** — Syntax highlighting for code formatting
16. **Async process pipeline** — Pipe commands together with proper stream handling

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|---|---|---|---|---|
| Wire per-element style flags | Medium | Low | Low | Immediate |
| Fix CommandScanner autoloading | High | Medium | Low | Immediate |
| Enum option validation | Medium | Low | Low | Immediate |
| Enhance logfmt formatter | Low | Low | Low | Immediate |
| Fuzzy matching in filter | High | Medium | Medium | High |
| External editor for write | Medium | Medium | Low | High |
| subscriptions() in models | Medium | High | Medium | Medium |
| Mouse support | Medium | Medium | Medium | Medium |
| GridItemList for choose | High | High | High | Low |
| Buffer diffing/delta render | Medium | High | High | Low |
| Cassowary constraints | Medium | High | Medium | Low |
| Command palette | Medium | Medium | Low | Low |

---

# Final Strategic Assessment

candy-shell represents a well-executed port of charmbracelet/gum to PHP, achieving ~85% functional parity while introducing PHP-specific innovations (attributes, Levenshtein typo suggestion, env var global prefix). The architecture is clean — Symfony Console surface over TEA models over candy-forms components — and the codebase demonstrates good PHP 8.3+ practices throughout.

**Key differentiators vs upstream gum:**
- Composer-installable PHP library (vs Go binary)
- Attribute-based discovery (vs struct tags)
- Levenshtein typo correction (vs shell completion only)
- Global env var prefix (vs per-command)

**Key gaps vs upstream:**
- Fuzzy matching (algorithm, not just API)
- 2D grid navigation (requires new component)
- External editor integration (requires upstream port of x/editor)
- Subscriptions (requires Program loop enhancement)

**Ecosystem positioning:**
candy-shell sits at the application framework tier, serving as the **CLI entry point** for the SugarCraft TUI ecosystem. It depends on foundation libs (candy-core, candy-forms, candy-sprinkles, candy-shine, candy-pty) and provides no components itself — only command wrappers. This is the correct architecture: the foundation libs are reusable in custom TUI applications, while candy-shell provides the gum-compatible CLI surface for shell scripts.

**Comparison with other PHP CLI frameworks:**
- **vs c9s/CLIFramework:** candy-shell is more modern (PHP 8.3+ strict typing, attributes), has better TUI integration, but lacks CLIFramework's hierarchical command groups and extension system
- **vs php-tui:** Different focus (php-tui is a widget library for building TUIs; candy-shell is a ready-made CLI tool)

**Recommended focus areas:**
1. **Fuzzy matching** — highest impact gap for real-world usability
2. **CommandScanner fix** — makes auto-discovery actually work with Composer
3. **Mouse support** — modern terminals expect click-to-select
4. **subscriptions()** — enables timeouts and concurrent async operations

The package is production-ready for shell script use cases. The remaining gaps are clearly documented and prioritized, with clear paths to resolution drawing from the upstream Go ecosystem and the other repos analyzed in this report.
