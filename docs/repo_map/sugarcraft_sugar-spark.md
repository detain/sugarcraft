# sugar-spark: ANSI Escape-Sequence Inspector

## Metadata
- **Type:** PHP TUI Library (SugarCraft monorepo)
- **Upstream:** [charmbracelet/sequin](https://github.com/charmbracelet/sequin) (Go, 804 stars)
- **Status:** 🟢 v1 ready
- **Location:** `sugar-spark/`
- **Package:** `sugarcraft/sugar-spark`
- **Namespace:** `SugarCraft\Spark`
- **PHP:** ^8.3
- **License:** MIT
- **CI:** GitHub Actions + Codecov

---

## 1. Overview

sugar-spark is a PHP port of `charmbracelet/sequin` — a human-readable ANSI escape-sequence inspector/debugger for TUI development. Pipe styled terminal output through it and each escape sequence becomes a labelled, descriptive line. It serves as the SugarCraft ecosystem's dedicated **ANSI debugging tool**.

### Purpose
When building TUI applications, developers often struggle to understand what ANSI escape sequences their code actually generates. sugar-spark decodes raw bytes into human-readable descriptions (e.g., `\x1b[31m` → `ESC[31m  SGR foreground red`).

### Quick Start
```php
use SugarCraft\Spark\Inspector;

foreach (Inspector::parse("\e[1;31mboom\e[0m") as $segment) {
    echo $segment->describe(), "\n";
}

// CLI
$ printf '\e[31mhello\e[0m world\n' | sugarspark
```

---

## 2. Architecture

### 2.1 Source Files

| File | Lines | Purpose |
|------|-------|---------|
| `src/Inspector.php` | 551 | Main parsing engine |
| `src/StreamingInspector.php` | 238 | Incremental chunked parsing |
| `src/SequenceSegment.php` | 34 | ANSI sequence segment |
| `src/TextSegment.php` | 17 | Plain text segment |
| `src/Segment.php` | 21 | Abstract base class |
| `src/C0C1.php` | 94 | C0 (0x00-0x1F) and C1 (0x80-0x9F) control code lookup tables |
| `src/Lang.php` | 22 | i18n translation facade |
| **Total** | **~977** | |

### 2.2 Core Parsing Loop (Inspector::parse)

The parsing uses a **manual byte-by-byte loop** rather than a state machine. The approach (lines 38-174 of `Inspector.php`):

1. Accumulate plain text into `$textBuf`
2. When `\x1b` (ESC) encountered, detect sequence type by next byte:
   - `[` → CSI (Control Sequence Introducer)
   - `]` → OSC (Operating System Command)
   - `O` → SS3 (Single Shift 3)
   - `P` → DCS (Device Control String)
   - `_` → APC (Application Program Command)
   - Other → Two-byte ESC sequence
3. Scan forward for sequence terminator
4. Flush text buffer, emit SequenceSegment with human-readable label

```php
// From Inspector.php lines 38-80 (simplified)
while ($i < $len) {
    $b = $input[$i];
    if ($b !== "\x1b") {
        $byte = ord($b);
        if ($byte <= 0x1F) {
            // C0 control codes 0x00-0x1F
            $flushText();
            $out[] = new SequenceSegment($b, 'C0 ' . C0C1::c0Name($byte));
        } else {
            $textBuf .= $b;
        }
        $i++;
        continue;
    }
    // ESC seen - detect sequence type
    $next = $input[$i + 1] ?? null;
    if ($next === '[') {
        // CSI: ESC [ params final
        // ... scan for final byte 0x40-0x7E
    } elseif ($next === ']') {
        // OSC: ESC ] payload (BEL | ESC \)
    }
    // ... etc
}
```

### 2.3 Segment Type Hierarchy

```
Segment (abstract)
├── TextSegment        — plain text between sequences
└── SequenceSegment     — ANSI sequence + decoded label
```

Both implement `raw()` (verbatim bytes) and `describe()` (human-readable).

### 2.4 Dependency on candy-core

The `composer.json` shows:
```json
"require": {
    "php": "^8.3",
    "sugarcraft/candy-core": "dev-master"
}
```

However, unlike Go sequin which uses `charmbracelet/x/ansi` for parsing, sugar-spark implements its own manual byte-parsing. The `candy-core` dependency appears to be for future integration rather than current parsing needs.

---

## 3. ANSI Parsing Architecture

### 3.1 Supported Sequence Types

| Type | Prefix | Description | Files |
|------|--------|-------------|-------|
| **CSI** | `ESC [` | Control Sequence Introducer — SGR, cursor, erase, modes | `Inspector.php` lines 63-79 |
| **OSC** | `ESC ]` | Operating System Command — title, clipboard, hyperlinks | `Inspector.php` lines 82-99 |
| **DCS** | `ESC P` | Device Control String — XTVERSION, sixel, DECRPSS | `Inspector.php` lines 114-129 |
| **APC** | `ESC _` | Application Program Command — CandyZone, kitty graphics | `Inspector.php` lines 133-148 |
| **SS3** | `ESC O` | Single Shift 3 — F1-F4, cursor keys | `Inspector.php` lines 101-110 |
| **ESC** | `ESC <c>` | Two-byte ESC sequences — save/restore cursor, keypad mode | `Inspector.php` lines 150-171 |
| **C0** | `0x00-0x1F` | Control codes — NUL, BEL, LF, CR, etc. | `C0C1.php` lines 14-47 |
| **C1** | `0x80-0x9F` | Control codes in 8-bit form | `C0C1.php` lines 50-83 |

### 3.2 CSI Handler Dispatch (describeCsi)

The `describeCsi()` method (lines 208-300) handles all CSI sequences via a large `match` statement on the final byte:

```php
return match ($final) {
    'm' => 'SGR ' . self::describeSgr($params),
    'A' => 'cursor up '    . ($params === '' ? '1' : $params),
    'B' => 'cursor down '  . ($params === '' ? '1' : $params),
    'C' => 'cursor right ' . ($params === '' ? '1' : $params),
    // ... ~30 more final bytes
    default => 'CSI ' . ($params === '' ? '' : $params . ' ') . $final,
};
```

Key CSI sub-handlers:
- **SGR** (`'m'`) — `describeSgr()` handles 38;2;r;g;b (truecolor), 38;5;n (256-color), 4:N (underline styles)
- **DEC private modes** (`'?2026h'`, `'?2004h'`, etc.) — via `decPrivateName()` match
- **Kitty keyboard** (`'?u'`, `'>5u'`, `'<3u'`) — `describeCsi()` lines 245-256
- **Cursor shape** (`'2 q'`) — lines 231-243

### 3.3 SGR Handler (describeSgr)

From `Inspector.php` lines 303-359:

```php
private static function describeSgr(string $params): string
{
    // Handles:
    // - Basic: 30-37 (fg), 40-47 (bg), 90-97 (bright fg), 100-107 (bright bg)
    // - Truecolor: 38;2;r;g;b
    // - 256-color: 38;5;n
    // - Underline styles: 4:1 (single), 4:2 (double), 4:3 (curly), 4:4 (dotted), 4:5 (dashed)
    // - Attributes: 1 (bold), 2 (faint), 3 (italic), 4 (underline), 5 (blink), 7 (reverse), etc.
    // - Reset: 0, 22, 23, 24, etc.
}
```

### 3.4 OSC Handler (describeOsc)

From `Inspector.php` lines 449-479. Handles OSC commands 0-112 including:
- **0, 2** — window title
- **1** — icon name
- **4** — palette
- **7** — cwd (file:// URL)
- **8** — hyperlink (URI parsing)
- **9** — iTerm2 notifications / progress bars
- **10, 11, 12** — terminal foreground/background/cursor color
- **52** — clipboard (base64)
- **110, 111, 112** — reset colors

### 3.5 C0/C1 Control Code Support

The `C0C1.php` class provides lookup tables for all C0 (0x00-0x1F) and C1 (0x80-0x9F) control codes. These are emitted as `SequenceSegment` when encountered (not passed through as text).

**Note:** In 7-bit environments, C1 codes use ESC + character (e.g., SOS = ESC X). Due to PHP's UTF-8 string handling, characters with ord < 0x80 (like 'X' = 0x58) cannot be reliably detected as C1. The `SosPmTest.php` documents this limitation at lines 14-20.

---

## 4. Debugging Capabilities

### 4.1 Primary APIs

| Method | Output | Use Case |
|--------|--------|----------|
| `Inspector::parse(string $input): list<Segment>` | Array of Segment objects | Programmatic inspection |
| `Inspector::report(string $input): string` | Human-readable lines | CLI pipe inspection |
| `Inspector::reportAsJson(string $input): string` | JSON array | Machine-readable output |
| `StreamingInspector::feed(string $data): list<Segment>` | Array of complete segments | Streaming/chunked input |

### 4.2 Inspector::report Output Format

```
$ printf '\e[31mhello\e[0m world\n' | sugarspark
ESC[31m  SGR foreground red
hello
ESC[0m   SGR reset
 world
```

### 4.3 StreamingInspector

Unlike the all-at-once `Inspector::parse()`, `StreamingInspector` handles chunked input:

```php
$inspector = new StreamingInspector();
$segs = $inspector->feed("pre \x1b[31m");  // Returns nothing yet (buffering text)
$segs = $inspector->feed(" red \x1b[0m "); // Completes CSI, yields 4 segments
$segs = $inspector->finish();               // Flushes trailing text
```

The streaming approach (lines 54-73 of `StreamingInspector.php`):
1. Accumulate text in `$textBuf` until sequence or finish
2. When sequence complete, flush text + yield sequence
3. Return incomplete array if more data needed

### 4.4 Sequence Label Generation

The `describe()` methods produce human-readable labels:
- `SequenceSegment::describe()` → `printable($bytes) . '  ' . $label`
- `TextSegment::describe()` → verbatim text
- `SequenceSegment::printable()` → replaces `\x1b` with `ESC` token

### 4.5 JSON Output Format

```php
$json = Inspector::reportAsJson("\x1b[1mbold\x1b[0m");
```

Output:
```json
[
    {"type": "sequence", "content": "\u001b[1m", "description": "ESC[1m  SGR bold"},
    {"type": "text", "content": "bold", "description": "bold"},
    {"type": "sequence", "content": "\u001b[0m", "description": "ESC[0m  SGR reset"}
]
```

---

## 5. Theme System

### 5.1 Internationalization (i18n)

sugar-spark uses SugarCraft's centralized i18n system:
- `Lang.php` extends `SugarCraft\Core\I18n\Lang`
- Namespace: `'spark'`
- Translation files in `lang/`

Currently ships with 16 locales:
`en`, `fr`, `de`, `es`, `pt`, `pt-br`, `zh-cn`, `zh-tw`, `ja`, `ru`, `it`, `ko`, `pl`, `nl`, `tr`, `cs`, `ar`

The only translation key currently defined:
```php
// lang/en.php
return [
    'cli.usage' => "usage: sugarspark [file]\n  or:  cmd | sugarspark",
];
```

### 5.2 Theme Philosophy

Unlike Go sequin which uses `lipgloss` for syntax-highlighted output with adaptive dark/light themes, sugar-spark produces **plain text output only**. There is no visual/color theme system — descriptions are text labels only.

This is a deliberate design choice for simplicity and portability. Users pipe output to view in their terminal, which provides its own styling.

---

## 6. PTY Integration

### 6.1 Current State

**sugar-spark does NOT have PTY execution.**

The Go sequin includes `exec.go` which creates a PTY to execute commands and capture their raw output:
```go
func executeCommand(ctx context.Context, args []string) ([]byte, error) {
    pty, err := xpty.NewPty(width, height)
    // ... execute command, capture output
}
```

This enables:
```bash
$ sequin -- some command
```

### 6.2 Implications

sugar-spark can only inspect:
1. Explicit strings passed to `parse()`/`report()`
2. File contents read via CLI argument
3. Stdin piped data

It cannot execute a command and inspect its live output because PHP lacks a native PTY library in the monorepo (though `candy-pty` may eventually provide this).

---

## 7. Golden File Testing

### 7.1 Current Test Approach

sugar-spark does **NOT** use golden file testing. Tests are unit-based, asserting specific labels for known inputs.

### 7.2 Test Coverage

| Test File | Tests | Coverage |
|----------|-------|---------|
| `InspectorTest.php` | 58 | All major parse paths |
| `StreamingInspectorTest.php` | 14 | Streaming incremental parsing |
| `SegmentTest.php` | 6 | Segment types and printable() |
| `UnderlineStylesTest.php` | 8 | SGR underline variants |
| `C0C1Test.php` | 5 | Control code lookup tables |
| `C0InTextTest.php` | 7 | C0 codes in text context |
| `SosPmTest.php` | 10 | C1/SOS/PM edge cases |
| `InspectorReportAsJsonTest.php` | 8 | JSON output format |
| **Total** | **116 tests** | |

### 7.3 Go sequin Golden Files

Go sequin uses `charmbracelet/x/exp/golden` for snapshot testing:
```
testdata/
├── c0c1.golden
├── ascii.golden
├── cursor.golden
├── screen.golden
├── line.golden
├── mode.golden
├── kitty.golden
├── sgr.golden
├── title.golden
├── cwd.golden
├── hyperlink.golden
├── notify.golden
├── termcolor.golden
├── clipboard.golden
├── finalterm.golden
└── keypad.golden
```

This is a notable architectural difference — Go sequin's golden files ensure output stability across changes.

---

## 8. How It Uses candy-vt (for parsing)

### 8.1 Relationship to candy-vt

sugar-spark and candy-vt serve **different purposes** despite both parsing ANSI:

| Aspect | sugar-spark | candy-vt |
|--------|-------------|----------|
| **Purpose** | Debugging/explaining sequences | Terminal emulation |
| **Output** | Human-readable labels | Rendered cell grid |
| **State** | Stateless (no terminal state) | Stateful (cursor, cells, scrollback) |
| **Input** | Byte stream | Byte stream |
| **Output** | Text descriptions | Screen buffer |

### 8.2 candy-vt Comparison

From `repo_map/sugarcraft_candy-vt.md` section 10.2:

> **Sequin has features candy-vt lacks:**
> - Sequence explanation/human-readable output
> - PTY execution for capturing real output
> - Theme support (Charm adaptive dark/light)
> - Golden file testing infrastructure
>
> **candy-vt has features sequin lacks:**
> - Terminal state emulation
> - Cell grid output
> - Scrollback buffer
> - Screen diffing

### 8.3 Parsing Approach Differences

- **Go sequin** → Uses `charmbracelet/x/ansi` state-machine parser with handler dispatch maps
- **sugar-spark** → Manual byte-by-byte loop with explicit sequence detection
- **candy-vt** → Paul Williams VT500 state machine directly ported from `charmbracelet/x/ansi/parser`

sugar-spark does NOT use candy-vt for parsing — it implements its own lightweight parser optimized for explanation rather than emulation.

---

## 9. CLI Interface

### 9.1 sugarspark Binary

Location: `bin/sugarspark` (33 lines)

```php
#!/usr/bin/env php
<?php
// Reads: file argument OR stdin
// Output: Inspector::report($input)
```

```bash
# From file
$ sugarspark dump.txt

# From stdin
$ printf '\e[31mred\e[0m' | sugarspark

# Usage (when no input)
$ sugarspark
usage: sugarspark [file]
  or:  cmd | sugarspark
```

### 9.2 inspect.sh Example Script

```bash
#!/usr/bin/env bash
# Demonstrates various sequence types

echo "── SGR (foreground colours) ────────────────────"
printf '\e[31mred\e[32m green\e[34m blue\e[0m default\n' | sugarspark

echo "── DEC private modes ────────────────────────────"
printf '\e[?2026h\e[?2027h\e[?1004h' | sugarspark

echo "── OSC (clipboard write) ────────────────────────"
printf '\e]52;c;dGVzdA==\e\\' | sugarspark

echo "── OSC 8 hyperlink ──────────────────────────────"
printf '\e]8;;https://charm.sh\e\\Charm\e]8;;\e\\' | sugarspark

echo "── DCS (XTVERSION reply) ────────────────────────"
printf '\eP>|xterm(367)\e\\' | sugarspark

echo "── APC (CandyZone marker) ───────────────────────"
printf '\e_candyzone:S:btn:ok\e\\OK\e_candyzone:E:btn:ok\e\\' | sugarspark
```

---

## 10. Comparison with Upstream (charmbracelet/sequin)

### 10.1 Feature Parity Matrix

| Feature | sequin (Go) | sugar-spark (PHP) | Notes |
|---------|-------------|-------------------|-------|
| CSI parsing | ✅ | ✅ | Full support |
| OSC parsing | ✅ | ✅ | Full support |
| DCS parsing | ✅ | ✅ | Full support |
| APC parsing | ✅ | ✅ | Full support |
| SS3 parsing | ✅ | ✅ | Full support |
| Two-byte ESC | ✅ | ✅ | Full support |
| C0 control codes | ✅ | ✅ | Full table |
| C1 control codes | ✅ | ✅ | Via 8-bit detection |
| SOS/PM sequences | ✅ | ✅ | C0C1 lookup table |
| SGR basic colors | ✅ | ✅ | 30-37, 40-47, 90-97, 100-107 |
| SGR 256-color | ✅ | ✅ | 38;5;n |
| SGR truecolor | ✅ | ✅ | 38;2;r;g;b |
| SGR underline styles | ✅ | ✅ | 4:1-4:5 |
| Cursor movement | ✅ | ✅ | A, B, C, D, H, etc. |
| DEC private modes | ✅ | ✅ | 1000, 1006, 2004, 2026, etc. |
| Kitty keyboard | ✅ | ✅ | ?, >, < prefixes |
| OSC 8 hyperlinks | ✅ | ✅ | URI parsing |
| OSC 52 clipboard | ✅ | ✅ | Base64 display |
| OSC 7 cwd | ✅ | ✅ | file:// URL |
| Streaming parse | ❌ | ✅ | **Unique to sugar-spark** |
| JSON output | ❌ | ✅ | **Unique to sugar-spark** |
| PTY execution | ✅ | ❌ | Go sequin only |
| Raw mode (syntax highlight) | ✅ | ❌ | Go sequin only |
| Theme support | ✅ | ❌ | Go sequin only |
| Golden file tests | ✅ | ❌ | Go sequin only |
| State machine parser | ✅ | ❌ | Go sequin uses x/ansi |

### 10.2 Architectural Differences

| Aspect | Go sequin | PHP sugar-spark |
|--------|-----------|-----------------|
| **Parser** | `ansi.Parser` state machine from `charmbracelet/x/ansi` | Manual byte-by-byte loop |
| **Handler dispatch** | Map-based `handlerFn` registry | `match` statements per sequence type |
| **Sequence detection** | `ansi.HasCsiPrefix()`, `ansi.HasOscPrefix()` | `substr()` + byte comparisons |
| **Color types** | Full `ansi.Color` interface (Basic, Indexed, RGB) | Basic string labels only |
| **Output** | Syntax-highlighted with themes | Plain text labels |
| **Raw mode** | `--raw` flag for inline highlighting | Not available |
| **C0/C1** | Full ctrlCodes map | Full C0C1 lookup table |

### 10.3 Unique sugar-spark Contributions

Two features not present in Go sequin:

1. **StreamingInspector** — Incremental/chunked parsing for live terminal output inspection
2. **JSON output** — Machine-readable format for tooling integration

### 10.4 Missing from sugar-spark

1. **PTY execution** — Cannot run commands and inspect output
2. **Raw mode** — No syntax highlighting
3. **Theme support** — No colorized output
4. **Golden file tests** — No snapshot testing infrastructure
5. **State machine parser** — Manual parsing is harder to extend

---

## 11. VHS Demo

Location: `.vhs/inspect.tape` and `.vhs/inspect.gif`

The demo runs `./examples/inspect.sh` showing:
- SGR foreground colors
- DEC private modes (synchronized output, unicode mode, focus reporting)
- OSC clipboard write
- OSC 8 hyperlink
- DCS XTVERSION reply
- APC CandyZone markers

```bash
# Dimensions: 900x620, FontSize 13, TokyoNight theme
Type "./examples/inspect.sh"
Enter
Sleep 5s
```

---

## 12. Innovation Points

### 12.1 What sugar-spark Does Well

1. **Comprehensive sequence coverage** — Handles CSI, OSC, DCS, APC, SS3, C0/C1, and two-byte ESC
2. **Detailed SGR descriptions** — Full color (basic/256/truecolor) and underline styles
3. **Robust fallback** — Unknown sequences get generic labels, nothing silently swallowed
4. **Streaming support** — Unique incremental parsing for chunked input
5. **JSON output** — Machine-readable format for tooling
6. **i18n ready** — 16 locales via SugarCraft i18n system
7. **Clean API** — `parse()`, `report()`, `reportAsJson()` are simple, composable

### 12.2 Architectural Strengths

1. **Lightweight** — No external dependencies beyond candy-core
2. **Pure PHP** — No FFI, no native extensions required
3. **Immutable segments** — `readonly` properties, `final` classes
4. **Testable** — 116 unit tests covering all major paths
5. **Well-documented** — Docblocks on all public methods

### 12.3 Opportunities for Enhancement

1. **State machine parser** — Port from `charmbracelet/x/ansi/parser` for robustness
2. **PTY execution** — Integrate with `candy-pty` when available
3. **Golden file tests** — Add snapshot tests for regression prevention
4. **Theme/colorized output** — Optional syntax highlighting mode
5. **Extended handler registry** — Make parsing extensible without modifying core

---

## 13. Conclusion

sugar-spark is a mature, well-tested PHP port of Go sequin that provides comprehensive ANSI escape sequence inspection for TUI debugging. It covers virtually all sequence types with detailed human-readable labels, and adds unique value through streaming incremental parsing and JSON output.

The implementation is clean and portable (pure PHP, no native deps), though it lacks some upstream features (PTY execution, theme support, golden file tests). The manual byte-by-byte parsing approach, while functional, differs from the state-machine approach used by Go sequin and candy-vt.

As a debugging tool within the SugarCraft ecosystem, sugar-spark plays a crucial role in understanding what ANSI sequences the TUI libraries generate — serving as a mirror to the raw output that `candy-core` and other libraries produce.

---

## 14. File Index

| File | Purpose |
|------|---------|
| `src/Inspector.php` | Main parsing engine (551 lines) |
| `src/StreamingInspector.php` | Incremental parsing (238 lines) |
| `src/SequenceSegment.php` | Sequence segment (34 lines) |
| `src/TextSegment.php` | Text segment (17 lines) |
| `src/Segment.php` | Abstract base (21 lines) |
| `src/C0C1.php` | Control code tables (94 lines) |
| `src/Lang.php` | i18n facade (22 lines) |
| `bin/sugarspark` | CLI binary (33 lines) |
| `tests/InspectorTest.php` | 58 tests |
| `tests/StreamingInspectorTest.php` | 14 tests |
| `tests/SegmentTest.php` | 6 tests |
| `tests/UnderlineStylesTest.php` | 8 tests |
| `tests/C0C1Test.php` | 5 tests |
| `tests/C0InTextTest.php` | 7 tests |
| `tests/SosPmTest.php` | 10 tests |
| `tests/InspectorReportAsJsonTest.php` | 8 tests |
| `examples/inspect.sh` | Demo script |
| `.vhs/inspect.tape` / `.vhs/inspect.gif` | VHS demo |
| `lang/en.php` + 15 other locales | Translations |

---

*Report generated: 2026-05-27*
