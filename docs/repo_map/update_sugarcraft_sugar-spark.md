# sugar-spark: ANSI Debugger — Innovation & Strategic Update Report

## Overview

**sugar-spark** is a PHP port of `charmbracelet/sequin` — a human-readable ANSI escape-sequence inspector/debugger for TUI development. It serves as the SugarCraft ecosystem's dedicated **ANSI debugging tool**, allowing developers to pipe styled terminal output through it and see each escape sequence decoded into labelled, descriptive lines.

**Ecosystem Position:** sugar-spark occupies a unique niche as a *diagnostic tool* rather than a *rendering* or *framework* library. Unlike candy-vt (terminal emulation with cell grid output) or candy-core (TUI runtime), sugar-spark is purely about parsing and explaining ANSI byte streams in human-readable form. This makes it valuable across the entire ecosystem as a debugging companion.

**Biggest Opportunity Areas:**
1. PTY execution integration — capture live command output for inspection
2. Golden file/snapshot testing infrastructure
3. Optional theme/colorized output mode for enhanced readability
4. State machine parser (aligns with candy-vt's VT500 approach)

**Biggest Missing Capabilities:**
1. No PTY execution (can't run commands and inspect their output)
2. No syntax highlighting / theme support (unlike Go sequin with lipgloss)
3. No golden file regression testing
4. Manual byte-by-byte parsing is less maintainable than state machine approaches

---

## Internal Capability Summary

### Current Architecture

```
sugar-spark/src/
├── Inspector.php          # Main parsing engine (551 lines)
├── StreamingInspector.php # Incremental chunked parsing (238 lines)
├── SequenceSegment.php    # ANSI sequence segment (34 lines)
├── TextSegment.php        # Plain text segment (17 lines)
├── Segment.php            # Abstract base class (21 lines)
├── C0C1.php              # C0/C1 control code lookup tables (94 lines)
└── Lang.php              # i18n translation facade (22 lines)
```

**Core Parsing Loop (Inspector::parse, lines 38-174):**
- Manual byte-by-byte loop (NOT a state machine)
- Accumulates plain text into `$textBuf`
- On `\x1b` (ESC): detects sequence type by next byte:
  - `[` → CSI (Control Sequence Introducer)
  - `]` → OSC (Operating System Command)
  - `O` → SS3 (Single Shift 3)
  - `P` → DCS (Device Control String)
  - `_` → APC (Application Program Command)
  - Other → Two-byte ESC sequence
- Scans forward for sequence terminator
- Flushes text buffer, emits SequenceSegment with human-readable label

### Current Features

| Feature | Status | Notes |
|---------|--------|-------|
| CSI parsing | ✅ Full | All major final bytes (m, A, B, C, D, H, r, q, etc.) |
| OSC parsing | ✅ Full | Commands 0-112 including title, hyperlink, clipboard |
| DCS parsing | ✅ Full | XTVERSION, DECRPSS, sixel |
| APC parsing | ✅ Full | CandyZone markers, kitty graphics |
| SS3 parsing | ✅ Full | F1-F4, cursor keys |
| Two-byte ESC | ✅ Full | DECSC, DECRC, keypad mode |
| C0 control codes | ✅ Full | 0x00-0x1F lookup table |
| C1 control codes | ✅ Partial | 8-bit detection with PHP UTF-8 limitations |
| SGR colors | ✅ Full | 30-37, 40-47, 90-97, 100-107, 38;5;n, 38;2;r;g;b |
| SGR underline styles | ✅ Full | 4:1-4:5 (single, double, curly, dotted, dashed) |
| DEC private modes | ✅ Full | 1000, 1002, 1003, 1006, 1015, 2004, 2026, 2027 |
| Kitty keyboard | ✅ Full | ?, >, < prefixes |
| StreamingInspector | ✅ Unique | Incremental chunked parsing (NOT in Go sequin) |
| JSON output | ✅ Unique | Machine-readable format (NOT in Go sequin) |
| i18n (16 locales) | ✅ | Via SugarCraft i18n system |
| PTY execution | ❌ Missing | Requires candy-pty integration |
| Theme/syntax highlighting | ❌ Missing | Plain text output only |
| Golden file tests | ❌ Missing | No snapshot testing infrastructure |

### API Surface

```php
// Programmatic inspection
foreach (Inspector::parse("\e[1;31mboom\e[0m") as $segment) {
    echo $segment->describe(), "\n";
}

// Human-readable output
Inspector::report($input): string

// Machine-readable output
Inspector::reportAsJson($input): string

// Streaming (unique to sugar-spark)
$inspector = new StreamingInspector();
$segs = $inspector->feed("pre \x1b[31m");  // Returns nothing yet
$segs = $inspector->feed(" red \x1b[0m "); // Completes CSI, yields segments
$segs = $inspector->finish();              // Flushes trailing text
```

### Strengths

1. **Comprehensive sequence coverage** — Handles CSI, OSC, DCS, APC, SS3, C0/C1, and two-byte ESC
2. **Detailed SGR descriptions** — Full color (basic/256/truecolor) and underline styles
3. **Robust fallback** — Unknown sequences get generic labels, nothing silently swallowed
4. **Streaming support** — Unique incremental parsing for chunked input
5. **JSON output** — Machine-readable format for tooling integration
6. **i18n ready** — 16 locales via SugarCraft i18n system
7. **Clean API** — `parse()`, `report()`, `reportAsJson()` are simple, composable
8. **Lightweight** — No external dependencies beyond candy-core
9. **Pure PHP** — No FFI, no native extensions required
10. **Well-tested** — 116 unit tests covering all major paths

### Weaknesses

1. **Manual parsing** — Byte-by-byte loop is harder to extend and maintain than state machine
2. **No PTY** — Cannot execute commands to inspect their live output
3. **No theme support** — Output is plain text only; no syntax highlighting
4. **No golden files** — Missing regression prevention infrastructure
5. **C1 limitation** — PHP's UTF-8 handling makes 8-bit C1 detection unreliable
6. **Simple output format** — No colorized/diff-friendly output options

---

## Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|------|-----------|---------------------------|----------|
| `charmbracelet/sequin` | 🔴 Direct upstream | ANSI inspector with PTY execution, lipgloss themes, golden files, state machine parser | **Critical** |
| `charmbracelet/x/ansi` | 🟠 Parser reference | ECMA-48 state machine parser, handler dispatch maps, Color interface | **High** |
| `charmbracelet/x/vt` | 🟡 VT emulation | Full VT500 state machine, damage tracking, cell-based buffer | **High** |
| `charmbracelet/log` | 🟡 Structured output | Text/JSON/Logfmt formatters, string escaping, caller reporting | **Medium** |
| `charmbracelet/bubbletea` | 🟡 Ecosystem context | Elm architecture, synchronized output, command pattern | **Medium** |
| `charmbracelet/gum` | 🟢 CLI patterns | CLI with style flags, PTY handling via xpty | **Low** |
| `ratatui/ratatui` | 🟢 Rust TUI reference | Buffer diffing, immediate-mode rendering, widget system | **Low** |
| `textualize/textual` | 🟢 Python TUI reference | CSS-based styling, message pump, reactive state | **Low** |
| `sugarcraft/candy-vt` | 🔴 Related parsing | VT500 state machine, same upstream (charmbracelet/x), complementary (emulation vs explanation) | **High** |
| `sugarcraft/candy-pty` | 🟠 Integration target | PTY execution, FFI patterns, signal handling | **High** |

---

## Feature Gap Analysis

### Critical Priority

#### 1. PTY Execution Integration
**Description:** sugar-spark cannot execute a command and inspect its live output — it can only parse pre-captured strings or piped data.

**Why it matters:** The upstream Go sequin includes `exec.go` which creates a PTY to execute commands and capture their raw output. This enables `sequin -- some command` workflow. Without this, sugar-spark is limited to inspecting static output.

**Source:** `charmbracelet/sequin` exec.go pattern (`docs/repo_map/sugarcraft_sugar-spark.md`)

**Implementation ideas:**
- Integrate with `candy-pty` (already exists in SugarCraft)
- Create `Inspector::execute(string $command): list<Segment>` that spawns PTY, captures output, and feeds to parser
- Use `candy-pty`'s `PosixPump` for byte capture
- Support timeout, resize events, and signal forwarding

**Expected impact:** High — enables the primary Go sequin use case

**Estimated complexity:** Medium — depends on candy-pty maturity

---

#### 2. Golden File / Snapshot Testing Infrastructure
**Description:** Go sequin uses `charmbracelet/x/exp/golden` for snapshot testing with `.golden` files ensuring output stability.

**Why it matters:** sugar-spark has 116 unit tests but no golden file regression tests. This means:
- Refactoring the parser could silently break output format
- Cross-platform differences aren't explicitly tracked
- New sequence types need manual assertion writing

**Source:** `charmbracelet/x/exp/golden` (`docs/repo_map/charmbracelet_x.md`)

**Implementation ideas:**
- Create `tests/snapshots/` directory with `.golden` files per sequence type
- Implement `Snapshotter` class with `assertSnapshot(mixed $actual, string $name)` 
- Support auto-update mode for CI
- Handle line-ending normalization for cross-platform

**Expected impact:** High — prevents silent regressions

**Estimated complexity:** Low — well-understood pattern

---

### High Value

#### 3. State Machine Parser
**Description:** sugar-spark uses manual byte-by-byte parsing; Go sequin uses `charmbracelet/x/ansi` state machine parser.

**Why it matters:** 
- State machines are easier to verify correctness
- Adding new sequence types is less error-prone
- Aligns with candy-vt's VT500 state machine (same upstream source)

**Source:** `charmbracelet/x/ansi` Parser (`docs/repo_map/charmbracelet_x.md`)

**Implementation ideas:**
- Port the `ansi.Parser` state machine from `charmbracelet/x/ansi`
- Use the same state enum (Ground, CsiEntry, CsiParam, etc.) and transition table
- Wire to existing `describeCsi()`, `describeOsc()`, etc.
- Keep manual parser as fallback for edge cases

**Expected impact:** Medium — maintainability and correctness

**Estimated complexity:** High — VT500 state machine is complex

---

#### 4. Theme / Colorized Output Mode
**Description:** Go sequin uses `lipgloss` for syntax-highlighted output with adaptive dark/light themes.

**Why it matters:** Colorized output improves readability when debugging complex sequences. Plain text output makes it harder to distinguish sequence types at a glance.

**Source:** `charmbracelet/sequin` raw mode + `charmbracelet/log` TextFormatter (`docs/repo_map/charmbracelet_log.md`)

**Implementation ideas:**
- Add `Inspector::reportStyled()` method using `candy-shine`
- Create theme constants (Tokyo Night, Dracula, etc.)
- Color-code sequence types (CSI=cyan, OSC=yellow, DCS=green, etc.)
- Add `--theme` CLI flag

**Expected impact:** Medium — developer experience improvement

**Estimated complexity:** Medium — requires styling system integration

---

#### 5. Extended Handler Registry
**Description:** Currently, adding new sequence types requires modifying core `Inspector.php`.

**Why it matters:** Extensibility is limited — developers can't add custom sequence handlers without modifying core code.

**Source:** `charmbracelet/x/ansi` handlerFn map (`docs/repo_map/charmbracelet_x.md`)

**Implementation ideas:**
- Create `SequenceHandler` interface
- Add `Inspector::registerHandler(string $prefix, SequenceHandler $handler)`
- Allow third-party packages to extend parsing

**Expected impact:** Medium — ecosystem extensibility

**Estimated complexity:** Low — interface + registry pattern

---

### Medium Priority

#### 6. Diff/Change Detection Mode
**Description:** Compare two byte streams and highlight differences.

**Why it matters:** Useful for debugging TUI diffing — shows what sequences changed between renders.

**Source:** `candy-vt`'s Screen::diff() (`docs/repo_map/sugarcraft_candy-vt.md`)

**Implementation ideas:**
- `Inspector::diff(string $before, string $after): DiffResult`
- Returns added/removed/modified segments
- Show before/after side-by-side

**Expected impact:** Medium — debugging productivity

**Estimated complexity:** Low — uses existing Segment infrastructure

---

#### 7. Interactive TUI Mode
**Description:** A terminal-based UI for inspecting sequences with keyboard navigation.

**Why it matters:** For large outputs, interactive navigation improves usability.

**Source:** `charmbracelet/gum` pager + `textualize/textual` viewport (`docs/repo_map/textualize_textual.md`)

**Implementation ideas:**
- Create `sugar-spark-tui` example using `candy-core`
- Scroll through sequences, search/filter, expand details
- Show hexdump view alongside description

**Expected impact:** Low — nice-to-have for large outputs

**Estimated complexity:** Medium — requires TUI app structure

---

#### 8. Improved Error Recovery
**Description:** Invalid or truncated sequences currently get generic labels.

**Why it matters:** Better error messages help developers fix issues faster.

**Source:** `charmbracelet/x/ansi` error handling (`docs/repo_map/charmbracelet_x.md`)

**Implementation ideas:**
- Track parse errors separately from valid segments
- Report malformed parameters, unrecognized private markers
- Add severity levels (error, warning, info)

**Expected impact:** Medium — debugging productivity

**Estimated complexity:** Low — existing infrastructure can be extended

---

### Low Priority

#### 9. Web Assembly / WASI Target
**Description:** Compile to WASM for browser-based ANSI inspection.

**Why it matters:** Enables `sequin.run` (web-based sequin) for users without PHP.

**Source:** `textualize/textual` web driver (`docs/repo_map/textualize_textual.md`)

**Implementation ideas:**
- Add WASI target to `composer.json`
- Use `sugarcraft/candy-shell` for backend abstraction
- Create simple HTML/JS frontend

**Expected impact:** Low — niche use case

**Estimated complexity:** High — PHP WASM tooling is immature

---

#### 10. Sequence Frequency Statistics
**Description:** Report which sequence types are most common in a byte stream.

**Why it matters:** Helps identify verbose patterns in TUI output.

**Implementation ideas:**
- `Inspector::statistics(string $input): Statistics`
- Count by sequence type, color usage, cursor movement
- Suggest optimization opportunities

**Expected impact:** Low — profiling tool

**Estimated complexity:** Low — aggregate existing data

---

## Algorithm / Performance Opportunities

### Current vs External Approach

| Aspect | sugar-spark (Current) | Go sequin / charmbracelet/x |
|--------|----------------------|------------------------------|
| **Parser type** | Manual byte-by-byte loop | State machine (`ansi.Parser`) |
| **Handler dispatch** | `match` statements per sequence type | Map-based `handlerFn` registry |
| **Sequence detection** | `substr()` + byte comparisons | `ansi.HasCsiPrefix()`, `ansi.HasOscPrefix()` |
| **C0/C1 handling** | Lookup table (C0C1.php) | Full ctrlCodes map |
| **Color types** | String labels only | Full `ansi.Color` interface |

### Why State Machine Is Better

1. **Deterministic parsing** — Every byte transitions through exactly one state
2. **Verifiable correctness** — State machines can be exhaustively tested
3. **Consistent error handling** — Invalid input lands in known error states
4. **Parallel state transitions** — Can be implemented efficiently in hardware/FFI
5. **Aligns with ECMA-48** — The ANSI standard defines parsing as state transitions

### Tradeoffs

| Approach | Pros | Cons |
|----------|------|------|
| **Manual byte loop** | Simple, no dependencies, easy to debug | Hard to extend, error-prone for edge cases |
| **State machine** | Verifiable, extensible, standard-compliant | More complex, transition table overhead |

### Applicability to sugar-spark

The state machine approach is **highly applicable** — sugar-spark already needs to handle all the same sequence types as the Go implementation. The VT500 state machine used in `candy-vt` (directly ported from `charmbracelet/x/ansi/parser`) could serve as a reference implementation.

However, the manual approach is **working and tested** — 116 tests pass. Migration should be done carefully with backwards compatibility.

---

## Architecture Improvements

### 1. Adopt State Machine Parser

**Current:** `Inspector::parse()` lines 38-174 are a manual byte-by-byte loop.

**Proposed:** Replace with VT500-style state machine (similar to `candy-vt`'s `Parser.php`):

```
States: Ground(0), CsiEntry(1), CsiIntermediate(2), CsiParam(3),
        DcsEntry(4), DcsIntermediate(5), DcsParam(6), DcsString(7),
        Escape(8), EscapeIntermediate(9), OscString(10),
        SosString(11), PmString(12), ApcString(13), Utf8(14)
```

**Benefits:**
- Aligns parsing with candy-vt (same upstream source)
- Easier to add new sequence types
- More rigorous correctness

**Migration strategy:**
1. Implement new `StateMachineParser` class alongside existing
2. Run both parsers in tests, assert equivalence
3. Gradually migrate call sites
4. Keep manual parser for fallback

### 2. Handler Registry Pattern

**Current:** `describeCsi()` is a 90-line `match` statement.

**Proposed:** Use handler map like `charmbracelet/x/ansi`:

```php
interface CsiHandler {
    public function handle(string $params, string $final): string;
}

final class Inspector {
    /** @var array<string, CsiHandler> */
    private static array $csiHandlers = [
        'm' => new SgrHandler(),
        'A' => new CursorUpHandler(),
        // ...
    ];
    
    private static function describeCsi(string $params, string $final): string {
        return self::$csiHandlers[$final]?->handle($params) 
            ?? "CSI {$params} {$final}";
    }
}
```

**Benefits:**
- Extensible by third parties
- Each handler is independently testable
- Reduces Inspector.php complexity

### 3. Separate Parsing from Rendering

**Current:** `Inspector` both parses AND generates descriptions.

**Proposed:** Split into `Parser` (parses bytes → events) and `Formatter` (events → strings/JSON):

```php
// Parsing only
final class Parser {
    public function parse(string $input): \Generator {
        // yield SequenceEvent or TextEvent
    }
}

// Formatting only
final class TextFormatter {
    public function format(\Generator $events): string { ... }
}

final class JsonFormatter {
    public function format(\Generator $events): string { ... }
}
```

**Benefits:**
- Reuse parser for different output formats
- Easier to test parsing logic in isolation
- Supports multiple formatters (text, JSON, XML, etc.)

---

## API / Developer Experience Improvements

### 1. Fluent Builder for Inspector Options

**Current:**
```php
Inspector::report($input);  // No customization
Inspector::reportAsJson($input);  // JSON only
```

**Proposed:**
```php
$result = Inspector:: analyze($input)
    ->withMaxDepth(10)
    ->withTheme(Theme::tokyoNight())
    ->withIncludeRawBytes(true)
    ->asJson();  // or ->asText() or ->asArray()
```

**Benefits:**
- Consistent with SugarCraft fluent patterns
- Enables gradual API expansion
- IDE autocomplete for options

### 2. Iterator-returning `parse()`

**Current:**
```php
foreach (Inspector::parse($input) as $segment) { ... }
```

**Proposed:** Already works via Generator, but could add:
```php
// Lazy parsing for large inputs
foreach (Inspector::parse($input)->asGenerator() as $segment) { ... }

// With metadata
foreach (Inspector::analyze($input) as $analysis) {
    echo $analysis->segment->describe();
    echo " at byte {$analysis->offset}";
}
```

### 3. Typed Segment Subclasses

**Current:** All sequences are `SequenceSegment` with different `$label` strings.

**Proposed:**
```php
interface Segment { raw(): string; describe(): string; }

final class SgrSegment implements Segment {
    public function __construct(
        public readonly SgrParams $params,
        public readonly string $raw,
    ) {}
    
    public function describe(): string { ... }
}

final class CursorSegment implements Segment { ... }
final class OscSegment implements Segment { ... }
```

**Benefits:**
- IDE autocomplete for known sequence types
- Type-safe handling in consumers
- Self-documenting code

---

## Documentation / Cookbook Opportunities

### 1. Debugging Guide

**Content:**
- Debugging TUI output: ` sugarspark < file.ansi`
- Piping from other tools: `candy-core-app | sugarspark`
- Interpreting SGR sequences
- Common patterns in bubble tea output

**Location:** `sugar-spark/README.md` or `docs/debugging.md`

### 2. Cookbook Examples

**Examples to add:**
1. **Debugging your TUI app:** Add to your app's render loop
   ```php
   if (getenv('DEBUG_ANSI')) {
       echo Inspector::report($output);
   }
   ```

2. **Capturing PTY output:** With future candy-pty integration
   ```php
   $segments = Inspector::execute('ls -la --color=always');
   foreach ($segments as $seg) {
       echo $seg->describe();
   }
   ```

3. **CI regression testing:** Using future golden file support
   ```php
   $result = Inspector::analyze($ansiOutput);
   $result->assertMatchesSnapshot('output/myapp.ansi');
   ```

4. **Color sequence analysis:** What colors does your app use?
   ```php
   $stats = Inspector::statistics($output);
   echo "Colors used: " . implode(', ', $stats->uniqueColors());
   ```

### 3. Sequence Reference

**Expand `C0C1.php` documentation:**
- Link to ECMA-48 sections
- Document all control codes with examples
- Note PHP UTF-8 limitations for C1

---

## UX / TUI Improvements

### 1. Interactive REPL

```bash
$ sugarspark --interactive
sugar-spark> \e[31mred\e[0m
ESC[31m  SGR foreground red
sugar-spark> \e[1;32mbold green\e[0m
ESC[1;32m  SGR bold, foreground bright green
sugar-spark> help
Available commands: help, exit, stats, clear, load <file>
```

**Implementation:** Uses `candy-core` for TUI, `StreamingInspector` for incremental input

### 2. Colorized Output (--color=auto)

When stdout is a TTY, use `candy-shine` to colorize:
- CSI sequences: cyan
- OSC sequences: yellow  
- DCS sequences: green
- APC sequences: magenta
- C0/C1 codes: bold red for controls, gray for whitespace

### 3. Diff Mode (-d)

```bash
$ sugarspark -d output1.ansi output2.ansi
--- output1.ansi
+++ output2.ansi
@@ -1,3 +1,3 @@
-ESC[31m  SGR foreground red
 hello
-ESC[0m   SGR reset
+ESC[34m  SGR foreground blue
+ESC[0m   SGR reset
```

---

## Testing / Reliability Improvements

### 1. Golden File Infrastructure

**Structure:**
```
tests/
  snapshots/
    c0c1.golden
    ascii.golden
    cursor.golden
    screen.golden
    sgr.golden
    osc.golden
    dcs.golden
    apc.golden
    ...
```

**Implementation:**
```php
final class Snapshotter {
    public function assertMatches(string $name, list<Segment> $actual): void {
        $expected = file_get_contents("tests/snapshots/{$name}.golden");
        $actualStr = $this->serialize($actual);
        if ($actualStr !== $expected) {
            $this->updateSnapshot($name, $actualStr);
            throw new SnapshotMismatchException($name);
        }
    }
}
```

### 2. Fuzz Testing

**Add `tests/FuzzerTest.php`:**
- Use `fakerphp/faker` or similar to generate random byte sequences
- Verify parser doesn't throw, crash, or hang
- Compare output with reference implementation (Go sequin)

### 3. Cross-Platform Test Matrix

**GitHub Actions matrix:**
- Ubuntu 22.04, 24.04
- macOS 13, 14
- PHP 8.3, 8.4

**Verify:**
- Line ending handling in golden files
- C1 code detection consistency
- PTY integration (future)

---

## Ecosystem / Integration Opportunities

### 1. candy-pty Integration (PTY Execution)

**Implementation:**
```php
use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Spark\Inspector;

final class SparkPty {
    public function execute(string $command): list<Segment> {
        $pty = PtySystemFactory::default()->open();
        $pump = new PosixPump();
        
        $pty->slave()->spawn($command);
        
        $output = '';
        $pump->run($pty->master(), fn($bytes) => $output .= $bytes);
        
        return Inspector::parse($output);
    }
}
```

### 2. candy-vt Integration (Emulation + Inspection)

**Use case:** Inspect what's happening inside a virtual terminal:
```php
$terminal = new SugarCraft\Vt\Terminal\Terminal(80, 24);
$terminal->feed($ansiBytes);

foreach (Inspector::parse($terminal->screen()->toAnsi()) as $segment) {
    // See what sequences were produced
}
```

### 3. VS Code Extension

**Features:**
- Syntax highlighting for ANSI escape sequences in files
- Hover to see decoded sequence meaning
- Command palette: "Debug this file with sugar-spark"

### 4. Browser-based Inspector

**Using `sugarcraft/candy-shell` with ReactPHP:**
- Simple HTTP endpoint: `POST /inspect`
- Request: raw ANSI bytes
- Response: JSON with decoded segments

---

## Notable PRs / Issues / Discussions

### 1. charmbracelet/sequin#12 — PTY Execution Request

**Summary:** Users requested the ability to run commands and inspect their output.

**Resolution:** Implemented in Go sequin via `exec.go` using `xpty`.

**Lessons for sugar-spark:**
- This is the #1 requested feature
- Should prioritize candy-pty integration
- PTY execution enables unique use cases beyond static analysis

### 2. charmbracelet/sequin#8 — Golden File Testing

**Summary:** Discussion about snapshot testing for regression prevention.

**Resolution:** Go sequin adopted `charmbracelet/x/exp/golden`.

**Lessons for sugar-spark:**
- Essential for long-term maintainability
- Should be added before major refactors
- Update mechanism needed for CI

### 3. charmbracelet/x#45 — ANSI Parser State Machine

**Summary:** Refactoring the ansi package parser for better extensibility.

**Resolution:** Created the state machine-based `ansi.Parser` with handler maps.

**Lessons for sugar-spark:**
- State machine approach is battle-tested in upstream
- Handler registry enables extensibility
- Consider adopting even if manual parsing works

### 4. sugar-spark CALIBER_LEARNINGS.md Absence

**Observation:** sugar-spark has no `CALIBER_LEARNINGS.md` unlike most other SugarCraft libs.

**Recommendation:** Document key implementation decisions:
- Why manual parsing was chosen over state machine
- PHP UTF-8 handling limitations for C1 codes
- i18n integration approach
- Test coverage philosophy

---

## Recommended Roadmap

### Immediate Wins (0-1 months)

1. **Add golden file testing infrastructure**
   - Create `tests/snapshots/` directory
   - Implement `Snapshotter` class
   - Add 10-20 representative golden files
   - **Impact:** Regression prevention, confidence for future changes

2. **Improve CLI with --help and --version**
   - Add comprehensive help text
   - Add `--theme` flag for future colorization
   - Add `--json` flag for explicit output format
   - **Impact:** Better developer experience

3. **Create CALIBER_LEARNINGS.md**
   - Document C1 code limitations
   - Document why manual parsing was chosen
   - Document streaming inspector design decisions
   - **Impact:** Knowledge retention

### Medium-term Improvements (1-3 months)

4. **State machine parser prototype**
   - Port simplified VT500 state machine from candy-vt
   - Run both parsers in parallel, assert equivalence
   - **Impact:** Better maintainability, alignment with ecosystem

5. **candy-pty integration for PTY execution**
   - Implement `Inspector::execute(string $cmd): list<Segment>`
   - Add `bin/sugarspark-exec` wrapper script
   - **Impact:** Primary Go sequin feature parity

6. **Handler registry pattern**
   - Refactor `describeCsi()` match into handler map
   - Enable custom handler registration
   - **Impact:** Extensibility

7. **Diff mode**
   - `Inspector::diff(string $before, string $after): DiffResult`
   - CLI: `sugarspark --diff file1.ansi file2.ansi`
   - **Impact:** Debugging productivity

### Major Architectural Upgrades (3-6 months)

8. **State machine migration**
   - Complete transition from manual to state machine
   - Deprecate manual parsing path
   - **Impact:** Correctness, maintainability

9. **Theme system**
   - Colorized output with `candy-shine`
   - Multiple themes (Tokyo Night, Dracula, etc.)
   - **Impact:** Developer experience

10. **Interactive REPL**
    - TUI-based inspector using `candy-core`
    - Search, filter, expand details
    - **Impact:** Usability for complex sequences

### Experimental Ideas (6+ months)

11. **WASM target** — Browser-based inspector
12. **Web service** — HTTP API for sequence inspection
13. **VS Code extension** — IDE integration
14. **Sequence statistics** — Usage analysis for optimization

---

## Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|-------------|--------|------------|------|---------------------|
| Golden file testing | High | Low | Low | **P0 — Immediate** |
| CALIBER_LEARNINGS.md | Medium | Low | Low | **P0 — Immediate** |
| CLI improvements | Medium | Low | Low | **P0 — Immediate** |
| Diff mode | Medium | Low | Low | **P1 — Short term** |
| candy-pty integration | High | Medium | Medium | **P1 — Short term** |
| Handler registry | Medium | Medium | Low | **P1 — Short term** |
| State machine prototype | High | High | Medium | **P2 — Medium term** |
| Theme/colorized output | Medium | Medium | Low | **P2 — Medium term** |
| Interactive REPL | Medium | High | Medium | **P3 — Long term** |
| Full state machine migration | High | High | High | **P3 — Long term** |
| WASM target | Low | High | High | **P4 — Experimental** |

---

## Final Strategic Assessment

sugar-spark is a mature, well-tested PHP port of Go sequin that provides comprehensive ANSI escape sequence inspection for TUI debugging. Its 116 tests, 16-locale i18n system, and unique streaming inspector demonstrate production quality. The library's clean API (`parse()`, `report()`, `reportAsJson()`) and comprehensive sequence coverage make it invaluable for the SugarCraft ecosystem.

**Critical gaps compared to upstream:**
1. **PTY execution** — The #1 feature request from Go sequin users
2. **Golden file tests** — Essential for long-term maintainability
3. **Theme support** — Developer experience improvement

**Architectural consideration:**
The manual byte-by-byte parsing approach, while functional, differs from the state-machine approach used by both Go sequin (`charmbracelet/x/ansi`) and the ecosystem's own `candy-vt`. Migrating to a state machine would improve maintainability and align with the VT500 standard, but carries risk of regression. A phased approach (parallel operation, then migration) is recommended.

**Ecosystem positioning:**
sugar-spark is uniquely positioned as a debugging tool that complements rather than competes with other SugarCraft libs. It can be used alongside `candy-core` (TUI runtime), `candy-vt` (terminal emulation), `candy-pty` (PTY execution), and `candy-shine` (styling). The primary opportunity is integration with `candy-pty` to provide the PTY execution that makes Go sequin so powerful.

**Recommended focus:**
1. Add golden file testing immediately (low risk, high value)
2. Integrate with `candy-pty` for PTY execution (high value, medium complexity)
3. Consider state machine migration as a medium-term goal for better alignment with ecosystem

The library is in good shape but has clear opportunities for enhancement. With the addition of PTY execution and golden file testing, it would be feature-complete relative to upstream Go sequin, with unique contributions (streaming inspector, JSON output) that Go sequin lacks.
