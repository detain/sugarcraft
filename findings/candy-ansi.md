# Code Review: candy-ansi

**Library**: sugarcraft/candy-ansi  
**Upstream**: charmbracelet/x/ansi (VT500/ECMA-48 ANSI parser state machine)  
**Review Date**: 2026-06-29  
**Reviewer**: Automated Code Review  

---

## Executive Summary

candy-ansi is a well-architected, faithful port of the charmbracelet/x/ansi parser state machine implementing the ECMA-48 VT500 specification. The code is generally clean, follows project conventions, and has good test coverage. However, several issues and improvements were identified across async patterns, completeness, edge case handling, and architectural concerns.

---

## 1. CRITICAL / HIGH SEVERITY

### 1.1 Missing Async/Stream Support in ReactPHP Ecosystem

**File**: `src/Parser/Parser.php:50-56` (`feed()` method)  
**Severity**: High  
**Category**: Missing Features / Async Patterns  

The `Parser` class is **entirely synchronous**. The `feed(string $bytes)` method processes bytes one at a time in a tight loop:

```php
public function feed(string $bytes): void
{
    $len = strlen($bytes);
    for ($i = 0; $i < $len; $i++) {
        $this->advance(ord($bytes[$i]));
    }
}
```

**Problem**: This library is part of a ReactPHP-based ecosystem (per AGENTS.md: "ReactPHP" is mentioned in the monorepo description), yet the Parser offers no async or streaming support. There is no:
- `feedStream(ReadableStreamInterface $stream): Promise` method
- Event-driven parsing with callbacks
- Ability to parse from a ReactPHP ReadableStream in non-blocking chunks
- `Parser` implementing `EventEmitterInterface` or similar

**Recommendation**: Add an async parser wrapper or stream parser that can process `ReadableStreamInterface` chunks without blocking the event loop. Consider:

```php
// Conceptual async interface
public function parseStream(ReadableStreamInterface $stream): \React\Promise\PromiseInterface;
```

This is essential for integration with other SugarCraft libs in the ReactPHP ecosystem.

### 1.2 HandlerAdapter Drops Unhandled Control Characters

**File**: `src/Parser/HandlerAdapter.php:33-41`  
**Severity**: High  
**Category**: Missing Functionality  

`HandlerAdapter::execute()` only handles 3 control bytes:

```php
public function execute(int $byte): void
{
    match ($byte) {
        0x09 => $this->csi->cht(1),   // Tab
        0x0D => null,                  // Carriage Return
        0x08 => $this->csi->cub(1),    // Backspace
        default => null,
    };
}
```

**Problem**: C0 controls like LF (0x0A), VT (0x0B), FF (0x0C), BEL (0x07) are silently dropped. The `Handler` interface contract says `execute()` handles "C0 or C1 control character (HT, LF, CR, BEL, BS, IND, RI, …)", but these are not translated to any handler method.

**Impact**: Terminal output with newlines, vertical tabs, form feeds, or bell characters will not be processed correctly when using `HandlerAdapter`.

**Recommendation**: Either:
1. Map these to appropriate `CsiHandler` methods (e.g., LF → line feed, BEL → bell/alert)
2. Or document clearly which control characters the adapter handles
3. Or throw an exception for unhandled control characters to make the gap visible

---

## 2. MEDIUM SEVERITY

### 2.1 OscHandlerImpl::hyperlink() Is Stub/No-Op

**File**: `src/Parser/OscHandlerImpl.php:23-25`  
**Severity**: Medium  
**Category**: Missing Functionality  

```php
public function hyperlink(string $uri, string $id): void
{
}
```

OSC 8 hyperlink support is deferred to "v2" per comments, but this is a significant missing feature for modern terminal emulators that support hyperlinks.

### 2.2 CsiHandlerImpl Is All No-Ops

**File**: `src/Parser/CsiHandlerImpl.php` (entire file)  
**Severity**: Medium  
**Category**: Missing Functionality  

`CsiHandlerImpl` is explicitly documented as a "minimal CSI handler stub" that does nothing. The real implementations require "CellGrid + Cursor from terminal state" that will be wired in step-12 when candy-vt migrates.

**Impact**: Any code trying to use `CsiHandlerImpl` as a real handler will get no-ops. This is by design but represents incomplete functionality.

**Note**: This is acknowledged in the README as "🟡 Initial port — consumer handlers remain in candy-vt".

### 2.3 No Support for OSC 1 (Icon Name) / OSC 4 (Color Palette)

**File**: `src/Parser/HandlerAdapter.php:79-85`  
**Severity**: Medium  
**Category**: Missing Features  

The OSC regex only handles OSC 2 (window title):

```php
if (preg_match('/^([0-2]);(.*)$/', $data, $m)) {
    $this->osc->title($m[2]);
}
```

Many terminals also support:
- OSC 1 — icon name
- OSC 4 — color palette changes
- OSC 10/11/12... — dynamic colors

**Recommendation**: Extend the OSC regex or add a more general OSC dispatch mechanism.

### 2.4 Transition Table Static State Without Reset Capability

**File**: `src/Parser/Transitions.php:27`  
**Severity**: Medium  
**Category**: Architectural / Design  

```php
private static ?string $table = null;
```

The transition table is built once lazily and cached in a static variable. There is **no way to reset this cache**. If for some reason the table needs to be rebuilt (e.g., testing, weird PHP edge cases with string mutation), it cannot be done without restarting PHP.

**Recommendation**: Add a `reset()` method for testing purposes:

```php
public static function reset(): void
{
    self::$table = null;
}
```

---

## 3. LOW SEVERITY / CODE QUALITY

### 3.1 `mb_check_encoding` Redundancy in UTF-8 Validation

**File**: `src/Parser/Parser.php:108-140` (`isValidUtf8Rune()`)  
**Severity**: Low  
**Category**: Performance / Code Quality  

The `isValidUtf8Rune()` method has manual overlong checks, surrogate checks, and max codepoint checks, then ALSO calls `mb_check_encoding()` as "additional safety":

```php
// Also verify with mb_check_encoding as additional safety
return mb_check_encoding($rune, 'UTF-8');
```

**Analysis**: The manual checks are comprehensive and correct. The `mb_check_encoding` call is redundant and adds function call overhead for every multi-byte UTF-8 rune. PHP's `mb_check_encoding` internally does essentially the same validation.

**Recommendation**: Remove the `mb_check_encoding` call since the manual validation is complete, or document why it's necessary if there's a subtle edge case being caught.

### 3.2 `currentState()` Exposes Internal State Enum

**File**: `src/Parser/Parser.php:142-146`  
**Severity**: Low  
**Category**: API Design  

```php
public function currentState(): State
{
    return $this->state;
}
```

**Observation**: This is marked `@internal` but is public. It leaks internal implementation details (the `State` enum) to consumers. If internal representation changes, this breaks consumers.

**Note**: This is used in tests to verify state transitions, which is a valid use case.

### 3.3 HandlerAdapter Doesn't Validate Params for Unknown CSI Commands

**File**: `src/Parser/HandlerAdapter.php:43-73`  
**Severity**: Low  
**Category**: Robustness  

For unknown CSI final bytes, `HandlerAdapter::csiDispatch()` silently does nothing (`default => null`). There is no logging, no warning, no way for a consumer to know an unrecognized CSI was received.

**Recommendation**: Add optional debug logging or a hook for unhandled CSI dispatch.

### 3.4 No Support for Subparameters Beyond Validation

**File**: `src/Parser/Parser.php:231-259` (`param()`)  
**Severity**: Low  
**Category**: Completeness  

The parser correctly parses subparameter separators (`:`) and stores them as separate params, but `HandlerAdapter` doesn't do anything with them. Subparameters are used in advanced SGR sequences like `38:2:255:128:0` for truecolor.

**Note**: The params array would contain `[38, 2, 255, 128, 0]` for that sequence, but `HandlerAdapter::csiDispatch()` just passes them to `csi->sgr()`. Whether subparameters work depends on the `CsiHandler` implementation.

### 3.5 No Memory Limit Consideration for String Buffers

**File**: `src/Parser/Parser.php:32`  
**Severity**: Low  
**Category**: Robustness  

```php
private string $stringBuffer = '';
```

OSC/DCS strings are accumulated without any size limit. A malicious or buggy terminal could send extremely long OSC strings (e.g., title with millions of characters) that could cause memory issues.

**Recommendation**: Add a maximum string buffer size check:

```php
private const MAX_STRING_BUFFER = 65536;

private function put(int $byte): void
{
    if (strlen($this->stringBuffer) >= self::MAX_STRING_BUFFER) {
        return;
    }
    $this->stringBuffer .= chr($byte);
}
```

### 3.6 Hardcoded 65535 Clamp Value Magic Number

**File**: `src/Parser/Parser.php:258`  
**Severity**: Low  
**Category**: Code Quality  

```php
$this->params[$last] = min($this->params[$last] * 10 + $digit, 65535);
```

The value 65535 appears twice in the file (also in `MAX_PARAMS` comment) without being a named constant. The VT500 spec may define this limit somewhere.

**Recommendation**: Extract to a named constant:

```php
private const MAX_PARAM_VALUE = 65535;
```

---

## 4. TEST COVERAGE GAPS

### 4.1 Missing Tests for HandlerAdapter Edge Cases

**Files**: `tests/HandlerAdapterTest.php`  

Good coverage for CSI dispatch, but:
- No tests for malformed OSC strings
- No tests for very long OSC payloads
- No tests for OSC with other command numbers (not just 2)
- No tests for garbage bytes in various states

### 4.2 No Tests for DCS Passthrough Completeness

**File**: `tests/HandlerAdapterTest.php:224-229`  

`testDcsDispatchDoesNothing()` only verifies the adapter does nothing with DCS, but there's no test of a full DCS sequence with params and data flowing through the parser to verify the Handler interface is correctly called.

### 4.3 No Tests for Stress/Overflow Scenarios

**File**: `tests/ParserOverflowTest.php`  

The overflow tests are good but limited to:
- Param value clamping
- Param count capping

Missing:
- Maximum string buffer length
- Many concurrent escape sequences
- Mixed partial input with many interleaved sequences

---

## 5. COMPATIBILITY OBSERVATIONS

### 5.1 No Deprecation Path for Future Changes

**File**: All source files  
**Severity**: Info  
**Category**: Compatibility  

As noted in CALIBER_LEARNINGS.md, there's an acknowledged future refactor when "candy-vt migrates onto candy-ansi" in step-12. There's no deprecation path or version compatibility strategy documented.

### 5.2 Missing Interface for CsiHandler Consumers

**File**: `src/Parser/CsiHandler.php`  

The `CsiHandler` interface has 15 methods. For a minimal implementation, many of these are unnecessary. There's no "CsiHandlerSubset" or similar for simpler use cases.

### 5.3 DebugHandler::filter() Uses array_values(array_filter())

**File**: `src/Parser/DebugHandler.php:73-76`  
**Severity**: Info  

```php
public function filter(string $type): array
{
    return array_values(array_filter($this->log, static fn ($e) => $e['type'] === $type));
}
```

This is fine but could use `ARRAY_FILTER_USE_KEY` or a different approach for large logs. Not a significant issue for test-scale usage.

---

## 6. PERFORMANCE CONSIDERATIONS

### 6.1 Transitions Table Built Once but Not Optimized

**File**: `src/Parser/Transitions.php:50-238`  

The `build()` method uses string manipulation with `$t[($state << 8) | $byte] = ...` which works but is not the most performant approach in PHP. For 4096 iterations with string key assignment, there's room for micro-optimization.

**Observation**: This runs once per PHP process lifetime, so optimization priority is low.

### 6.2 ord() Called in Tight Loop

**File**: `src/Parser/Parser.php:54`  

```php
$this->advance(ord($bytes[$i]));
```

For each byte, `ord()` is called. On PHP 8.1+, `ord()` on a single character string is fast, but for large inputs this adds up. Alternative: PHP 8.1+ can use `$bytes[$i]` directly as an int in some contexts (though string offset returns a string).

**Note**: This is a micro-optimization concern, not a practical bottleneck.

### 6.3 No Streaming Parser for Large Inputs

**File**: `src/Parser/Parser.php`  

For very large inputs (e.g., printing a million characters), the entire input is processed in one `feed()` call. There's no chunked/streaming processing that would allow progress reporting or cancellation.

---

## 7. SECURITY CONSIDERATIONS

### 7.1 No Maximum Length for OSC/DCS String Buffers

**File**: `src/Parser/Parser.php:32, 269-272`  
**Severity**: Medium (Potential DoS)  

As noted in 3.5, the string buffer has no maximum length. A malicious terminal or escaped sequence could allocate massive amounts of memory.

**Recommendation**: Add `MAX_STRING_BUFFER` constant and enforce it.

### 7.2 preg_match in Hot Path

**File**: `src/Parser/HandlerAdapter.php:82`  

```php
if (preg_match('/^([0-2]);(.*)$/', $data, $m)) {
```

This regex is called for every OSC dispatch. While OSC dispatch is not as frequent as CSI, it's still in the hot path for programs that use terminal titles frequently.

**Recommendation**: Consider a faster parsing approach (e.g., `strpos` + `substr`) for the common case.

---

## 8. SUMMARY OF RECOMMENDATIONS

### Must Fix (Before 1.0)
1. Add async/stream parsing support for ReactPHP integration
2. Handle all C0 control characters in HandlerAdapter, not just 3 bytes

### Should Fix
3. Add maximum string buffer length to prevent memory exhaustion
4. Add `Transitions::reset()` for testing
5. Remove redundant `mb_check_encoding` call or document why it's needed
6. Extract magic number 65535 to named constant

### Could Fix
7. Implement OSC hyperlink support
8. Support more OSC commands beyond just OSC 2
9. Add debug logging for unknown CSI commands
10. Extend test coverage for edge cases and stress scenarios

### Won't Fix (Known/Expected)
- CsiHandlerImpl being all no-ops (by design, deferred to candy-vt)
- Synchronous-only design for now (future async work)

---

## 9. POSITIVE OBSERVATIONS

The codebase is well-structured:

- **Clean separation of concerns**: Parser, Handler, Transitions, State, Action are all separate
- **Excellent docblock documentation** citing upstream sources and VT500 spec
- **Good test coverage** with 669 lines of test code covering major paths
- **Follows project conventions**: `final` classes, `declare(strict_types=1)`, PSR-4, proper namespacing
- **Immutable-ish design**: `Parser` is stateful but `with*()` pattern not needed here since it's a streaming parser
- **Correct UTF-8 handling**: The overlong, surrogate, and max codepoint checks are comprehensive
- **Proper partial input handling**: State is correctly maintained across multiple `feed()` calls
- **Well-designed Handler interface**: Clean abstraction that allows DebugHandler for testing and real handlers for production
- **CALIBER_LEARNINGS.md** tracking historical bugs shows good practices

---

## File-by-File Summary

| File | Lines | Assessment |
|------|-------|------------|
| `src/Parser/Parser.php` | 310 | Core state machine. Solid, well-documented. UTF-8 handling has minor redundancy. Missing async support. |
| `src/Parser/Transitions.php` | 245 | Transition table. Good. No reset capability. |
| `src/Parser/State.php` | 32 | Simple enum. Fine. |
| `src/Parser/Action.php` | 28 | Simple enum. Fine. |
| `src/Parser/Handler.php` | 73 | Clean interface. Good. |
| `src/Parser/HandlerAdapter.php` | 94 | Incomplete - drops most control chars. OSC regex limited. |
| `src/Parser/CsiHandler.php` | 115 | Good interface. Missing some commands. |
| `src/Parser/CsiHandlerImpl.php` | 98 | All no-ops (by design). |
| `src/Parser/OscHandler.php` | 29 | Minimal interface. Fine. |
| `src/Parser/OscHandlerImpl.php` | 31 | hyperlink() is no-op. |
| `src/Parser/DebugHandler.php` | 77 | Good test utility. Minor filter() inefficiency. |

---

*End of review*
