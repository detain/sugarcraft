# sugar-spark Research: ANSI Escape Sequence Inspector Tools

**Date:** 2026-05-13
**Upstream:** [charmbracelet/sequin](https://github.com/charmbracelet/sequin)
**Context:** PHP 8.3+ port of sequin for SugarCraft monorepo

---

## Executive Summary

The current sugar-spark implementation is a solid direct port of sequin's functionality. However, several architectural improvements and feature additions from upstream and other ecosystems could significantly enhance the library. This research identifies **5 high-priority improvements** with estimated effort.

---

## 1. Current Implementation Analysis

### Architecture
The current sugar-spark uses a **byte-by-byte parsing loop** with explicit sequence type detection based on escape character prefixes:

```php
// Inspector.php lines 38-151 - Main parsing loop
while ($i < $len) {
    $b = $input[$i];
    if ($b !== "\x1b") {
        $textBuf .= $b;
        $i++;
        continue;
    }
    // Sequence detection via $next character
    if ($next === '[') { /* CSI */ }
    elseif ($next === ']') { /* OSC */ }
    // ...
}
```

### Strengths
- **Comprehensive sequence support:** CSI, OSC, DCS, APC, SS3, two-byte ESC
- **Detailed human-readable labels:** Full SGR color/attribute descriptions
- **Robust fallback:** Unknown sequences get generic `CSI param final` labels
- **Good test coverage:** 670 lines of tests covering all major branches

### Weaknesses
- **Manual byte-by-byte parsing:** No state machine, harder to extend
- **No incremental parsing:** Must buffer all text before `describe()`
- **Limited visual representation:** Only `printable()` method for raw byte display
- **No structured output format:** Only human-readable text descriptions

---

## 2. Comparison with Other Implementations

### 2.1 Go: charmbracelet/sequin (Upstream)

**Source:** [github.com/charmbracelet/sequin](https://github.com/charmbracelet/sequin)

**Architecture:**
- Relies on `charmbracelet/x/ansi` state-machine parser
- Handler-based architecture with dispatch maps:
```go
var csiHandlers = map[int]handlerFn{
    'm': handleSgr,
    'c': printf("Request primary device attributes"),
    'A': handleCursor, 'B': handleCursor, /* ... */
}
```

**Key Differences:**

| Aspect | sugar-spark | sequin (Go) |
|--------|-------------|-------------|
| Parser | Manual byte loop | State machine (`ansi.Parser`) |
| Handler dispatch | `match` statements | Map-based `handlerFn` |
| Sequence detection | `substr()` + byte checks | `ansi.HasCsiPrefix()`, `ansi.HasOscPrefix()` |
| Color types | Basic only | Full `ansi.Color` interface (Basic, Indexed, RGB) |
| Visual output | `printable()` | Syntax-highlighted with themes |
| Raw mode | Not available | `--raw` flag for inline highlighting |
| C0/C1 control codes | Not handled | Full map of 0x00-0x1F, 0x80-0x9F |
| Control string support | None | SOS (`\x98`), PM (`\x9e`) |

**Unique sequin features:**
- **Syntax highlighting:** `--raw` mode with theme support (`SEQUIN_THEME` env var)
- **Command execution:** `sequin -- some command` runs and inspects output
- **Control code table:** Full C0 (0x00-0x1F) and C1 (0x80-0x9F) descriptions
- **Handler registry:** Extensible via handler maps

**Source:** [sequin/main.go](https://github.com/charmbracelet/sequin/blob/main/main.go) lines 95-200

### 2.2 Go: charmbracelet/x/ansi (Parser Foundation)

**Source:** [github.com/charmbracelet/x/ansi/parser.go](https://github.com/charmbracelet/x/blob/main/ansi/parser.go)

The sequin parser is built on a state-machine approach:

```go
type Parser struct {
    handler Handler
    params  []int    // CSI/DCS parameters
    data    []byte   // OSC/DCS/APdata
    cmd     int      // Packed command + prefix + intermediate
    state   byte     // Current state
}

func (p *Parser) Advance(b byte) parser.Action {
    state, action := parser.Table.Transition(p.state, b)
    // State machine handles all transition logic
}
```

**State transitions:** Ground → Escape → (CsiEntry/DcsEntry/OscString/ApcString/SosString/PmString) → dispatch

**Benefits for PHP port:**
- Clean separation between parsing and handling
- Extensible via `Handler` interface
- Handles all edge cases (UTF-8, incomplete sequences, two-byte ESC)

### 2.3 Rust: anstream

**Source:** [docs.rs/anstream](https://docs.rs/anstream/latest/anstream/)

**Focus:** Auto-adapting stdout/stderr streams, not inspection.

```rust
pub struct AutoStream<S: RawStream> { /* ... */ }
pub fn strip_str(data: &str) -> StrippedStr<'_>  // Strip ANSI from string
```

**Relevant patterns:**
- `StripStr` / `StripBytes` for incremental stripping
- `StripStrIter` for iterator-based processing
- Well-tested UTF-8 handling via `utf8parse::Receiver`

### 2.4 Python: blessed

**Source:** [blessed.readthedocs.io](https://blessed.readthedocs.io/en/stable/sequences.html)

**Inspection approach:**
```python
>>> term.split_seqs(term.bold('bbq'))
['\x1b[1m', 'b', 'b', 'q', '\x1b(B', '\x1b[m']

>>> term.strip_seqs(phrase)  # Remove all sequences
'coffee'
```

**Unique features:**
- `Sequence` class: sequence-aware string subclass
- `SequenceTextWrapper`: text wrapping with sequence awareness
- Grapheme cluster support for emoji width calculation

---

## 3. Specific Improvements for sugar-spark

### Priority 1: Add Control Code (C0/C1) Support

**Why:** sequin handles 0x00-0x1F (C0) and 0x80-0x9F (C1) control codes. sugar-spark ignores them.

**Current state:** Control codes pass through as text.

**Implementation:**
```php
// Add to Inspector.php
private static function describeControlCode(string $byte): string
{
    return match (ord($byte)) {
        0x00 => 'Null',
        0x01 => 'Start of heading',
        // ... C0 codes 0x02-0x1F
        // C1 codes 0x80-0x9F
        0x7F => 'Delete',
        default => "Control 0x" . strtoupper(dechex(ord($byte))),
    };
}
```

**Effort:** Low (1-2 hours)
**Impact:** High - completes upstream parity

---

### Priority 2: Incremental Parsing Interface

**Why:** Current implementation requires buffering entire input. For streaming/chunked input, need incremental approach.

**Current state:**
```php
public static function parse(string $input): array  // All-or-nothing
```

**Proposed:**
```php
// New: Streaming parser
final class StreamingInspector
{
    private State $state;
    private string $textBuf = '';

    public function __construct(
        private readonly callable $onSequence,
        private readonly callable $onText,
    ) {}

    public function feed(string $chunk): void
    {
        // Parse chunk, emit sequences/text via callbacks
    }

    public function finalize(): void
    {
        // Flush any remaining text buffer
    }
}
```

**Use case:** Inspecting live terminal output, processing large files

**Effort:** Medium (4-6 hours)
**Impact:** Medium - enables new use cases

---

### Priority 3: State Machine Parser

**Why:** Manual byte-by-byte parsing is harder to extend and maintain. State machine approach (like `charmbracelet/x/ansi`) is more robust.

**Current:** 468 lines of explicit branching logic

**Proposed states:**
```
Ground → Escape → CsiEntry → CsiParam → CsiIntermediate → CsiDispatch
                  → OscString → OscDispatch  
                  → DcsEntry → DcsParam → DcsString → DcsDispatch
                  → ApcString → ApcDispatch
                  → SosString → SosDispatch
                  → PmString → PmDispatch
```

**Benefits:**
- Clear state transition rules
- Easier to add new sequence types
- Standardized handling of edge cases

**Effort:** High (8-12 hours)
**Impact:** Medium - maintainability improvement

---

### Priority 4: Structured Output Format

**Why:** Currently only human-readable text. Need machine-parseable output for tooling.

**Current:**
```php
echo Inspector::report("\x1b[31mred\x1b[0m");
// Output:
// ESC[31m  SGR foreground red
// red
// ESC[0m   SGR reset
```

**Proposed JSON output:**
```php
public static function reportAsJson(string $input): string
{
    $segments = self::parse($input);
    return json_encode(array_map(fn($s) => [
        'type' => $s instanceof SequenceSegment ? 'sequence' : 'text',
        'raw' => $s->raw(),  // Raw bytes
        'label' => $s->describe(),
        // For sequences:
        'sequence_type' => $s instanceof SequenceSegment ? self::classify($s) : null,
        'params' => $s instanceof SequenceSegment ? self::extractParams($s) : null,
    ], $segments));
}
```

**Output:**
```json
[
  {"type": "sequence", "raw": "\x1b[31m", "label": "SGR foreground red", "sequence_type": "CSI_SGR", "params": {"foreground": {"type": "basic", "color": "red"}}},
  {"type": "text", "raw": "red", "label": "red"},
  {"type": "sequence", "raw": "\x1b[0m", "label": "SGR reset", "sequence_type": "CSI_SGR", "params": {}}
]
```

**Effort:** Medium (4-5 hours)
**Impact:** High - enables tooling integration

---

### Priority 5: Visual Representation Enhancements

**Why:** Improve debugging DX with syntax highlighting and inline visualization.

**Features from sequin not in sugar-spark:**

1. **Raw mode with inline highlighting:**
```bash
$ git -c status.color=always status -sb | sequin -r
```

2. **Theme support:** Multiple color schemes via `SEQUIN_THEME` env var

3. **Byte visualization:** Show `\x1b[` as `ESC[` (already done via `printable()`)

**Proposed implementation:**
```php
public static function highlight(string $input, HighlightTheme $theme = null): string
{
    $theme = $theme ?? new DefaultTheme();
    $out = '';
    foreach (self::parse($input) as $seg) {
        if ($seg instanceof SequenceSegment) {
            $out .= $theme->highlightSequence($seg);
        } else {
            $out .= $theme->highlightText($seg);
        }
    }
    return $out;
}
```

**Effort:** Medium (5-7 hours)
**Impact:** Medium - improves debugging experience

---

## 4. Missing Sequence Types

### From upstream sequin:

| Sequence | sequin | sugar-spark | Notes |
|----------|--------|-------------|-------|
| SOS (Start of String) | ✅ | ❌ | `\x98` ... `\x9c` |
| PM (Privacy Message) | ✅ | ❌ | `\x9e` ... `\x9c` |
| DECRQSS (Request Status String) | ✅ | Partial | Only DECRPSS reply handled |
| XTGETTC / XTSETTC | ❌ | ❌ | Terminal emulators |
| SGR 4 (double underline) | ✅ | ❌ | 4;1 single, 4;2 double, etc. |

### SGR 4 underline styles (from sequin sgr.go):
```php
// Current (sugar-spark):
$code === 4 => 'underline'

// Should be (from sequin):
case 4:
    if (param.HasMore()) {
        // Handle 4;1, 4;2, 4;3, 4;4, 4;5
    }
```

**Effort:** Low (2-3 hours)
**Impact:** Medium - completes SGR coverage

---

## 5. Recommendations (Prioritized)

| Priority | Improvement | Effort | Impact | 
|----------|-------------|--------|--------|
| **1** | Add C0/C1 control code support | 1-2h | High |
| **2** | Add SGR underline styles (4;1-4;5) | 1h | Medium |
| **3** | Add SOS/PM sequence support | 2h | Medium |
| **4** | Structured JSON output format | 4-5h | High |
| **5** | Incremental/streaming parser | 4-6h | Medium |
| **6** | State machine refactor | 8-12h | Medium |
| **7** | Visual highlighting/theme support | 5-7h | Medium |

---

## 6. Implementation Plan

### Phase 1: Quick Wins (1-2 sessions)
1. Add C0/C1 control code descriptions
2. Add SGR underline style variants
3. Add SOS/PM sequence handling
4. Add missing DECRQSS variants

### Phase 2: Output Formats (1 session)
1. Add `reportAsJson()` method
2. Add `reportAsDebug()` for var_dump-style output

### Phase 3: Streaming (2 sessions)
1. Implement `StreamingInspector` class
2. Add CLI `--stream` flag for chunked processing

### Phase 4: Refactoring (3-4 sessions)
1. Design state machine
2. Migrate parsing logic
3. Maintain backward compatibility via adapter

---

## 7. References

- **Upstream sequin:** https://github.com/charmbracelet/sequin
- **Parser foundation:** https://github.com/charmbracelet/x/tree/main/ansi
- **Rust anstream:** https://docs.rs/anstream/latest/anstream/
- **Python blessed:** https://blessed.readthedocs.io/en/stable/sequences.html
- **xterm control sequences:** https://invisible-island.net/xterm/ctlseqs/ctlseqs.html

---

## 8. Appendix: sequin Handler Map Reference

```go
var csiHandlers = map[int]handlerFn{
    'm': handleSgr,
    'c': printf("Request primary device attributes"),

    // kitty
    'u' | '?'<<markerShift: handleKitty,
    'u' | '>'<<markerShift: handleKitty,
    'u' | '<'<<markerShift: handleKitty,
    'u' | '='<<markerShift: handleKitty,

    // cursor
    'A': handleCursor, 'B': handleCursor, 'C': handleCursor,
    'D': handleCursor, 'E': handleCursor, 'F': handleCursor,
    'H': handleCursor,
    'n' | '?'<<markerShift: handleCursor,
    'n': handleCursor,
    's': handleCursor,
    'u': handleCursor,
    'q' | ' '<<intermedShift: handleCursor,

    // screen
    'r': handleScreen,
    'J': handleScreen,
    'K': handleLine,
    'L': handleLine,
    'M': handleLine,
    'S': handleLine,
    'T': handleLine,

    // modes
    'p' | '$'<<intermedShift: handleMode,
    'p' | '?'<<markerShift | '$'<<intermedShift: handleMode,
    'h' | '?'<<markerShift: handleMode,
    'l' | '?'<<markerShift: handleMode,
    'h': handleMode,
    'l': handleMode,

    'q' | '>'<<markerShift: handleXT,
}
```

**Source:** [sequin/handlers.go](https://github.com/charmbracelet/sequin/blob/main/handlers.go)
