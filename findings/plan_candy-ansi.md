# Implementation Plan: candy-ansi

## Goal

Address all findings from the candy-ansi code review, fixing critical/high-severity issues (HandlerAdapter C0 control handling, async/stream support), medium-severity gaps (OSC limitations, Transitions::reset), and code-quality items (magic numbers, string buffer limits, redundant mb_check_encoding) while maintaining backward compatibility and test coverage.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Add `Transitions::reset()` for testing | Static cache needs clearing for deterministic test runs | `src/Parser/Transitions.php:27` |
| HandlerAdapter must handle all C0 controls | Handler interface contract says execute() handles C0/C1 but implementation only handles 3 bytes | `src/Parser/HandlerAdapter.php:33-41` |
| Extract magic number 65535 to named constant | VT500 spec defines maximum parameter value; clarity over magic numbers | `src/Parser/Parser.php:258` |
| Remove redundant mb_check_encoding | Manual UTF-8 validation is comprehensive; mb_check_encoding adds function-call overhead | `src/Parser/Parser.php:138-139` |
| Add MAX_STRING_BUFFER constant | Prevent memory exhaustion from malicious OSC/DCS strings | `src/Parser/Parser.php:32` |
| Defer async/stream support to future phase | Requires ReactPHP integration design; not blocking current consumers | `src/Parser/Parser.php:50-56` |
| OSC hyperlink deferred to v2 | OSC 8 requires terminal-specific CellGrid wiring | `src/Parser/OscHandlerImpl.php:23-25` |

---

## Phase 1: Critical/High Severity

### 1.1 HandlerAdapter drops unhandled C0 control characters

**Status:** [ ]  
**Severity:** High  
**Category:** Missing Functionality  
**File:** `src/Parser/HandlerAdapter.php:33-41`

**What is expected:**
`HandlerAdapter::execute()` must handle ALL C0 control characters per the `Handler` interface contract, not just HT (0x09), CR (0x0D), and BS (0x08). The missing controls are:
- LF (0x0A) — Line Feed → add `lf()` method to CsiHandler
- VT (0x0B) — Vertical Tab → add `vt()` method to CsiHandler
- FF (0x0C) — Form Feed → add `ff()` method to CsiHandler
- BEL (0x07) — Bell/Alert → add `bel()` method to CsiHandler
- CAN (0x18) — Cancel → no-op (handled by state machine already)
- SUB (0x1A) — Substitute → no-op (handled by state machine already)
- Other C0 controls (ENQ, ACK, NAK, EM, DLE, DC1-DC4, FS, GS, RS, US) → document as no-op

**Why the change should be done:**
The `Handler` interface docblock at `src/Parser/Handler.php:27-30` explicitly states `execute()` handles "C0 or C1 control character (HT, LF, CR, BEL, BS, IND, RI, …)". HandlerAdapter violates this contract by silently dropping all controls except 3 bytes. Terminal output with newlines, vertical tabs, form feeds, or bell characters will not be processed correctly.

**Conditions for success:**
1. All C0 controls (0x00-0x1F except ESC) trigger `execute()` action in the state machine
2. HandlerAdapter::execute() has explicit handling (or documented no-op) for each C0 byte
3. Tests cover LF, VT, FF, BEL behavior via HandlerAdapter
4. Existing tests still pass (no regression in CSI/ESC handling)

**Implementation approach:**
1. Add `bel()`, `lf()`, `vt()`, `ff()` methods to `CsiHandler` interface
2. Update `CsiHandlerImpl` to implement these (as no-ops, since terminal state is deferred)
3. Update `HandlerAdapter::execute()` to call appropriate handler methods
4. Update `DebugHandler::execute()` to log bell/lf/vt/ff as execute events
5. Add tests for each control character

**Related code locations:**
- `src/Parser/HandlerAdapter.php:33-41` — execute() method needing expansion
- `src/Parser/Handler.php:27-30` — interface contract
- `src/Parser/CsiHandler.php` — needs new methods
- `tests/HandlerAdapterTest.php:296-318` — existing test showing LF is dropped
- `src/Parser/Transitions.php:111-113` — Ground state executes C0 bytes

---

### 1.2 Async/Stream Support for ReactPHP Integration

**Status:** [ ]  
**Severity:** High  
**Category:** Missing Features / Async Patterns  
**File:** `src/Parser/Parser.php:50-56`

**What is expected:**
A `parseStream(ReadableStreamInterface $stream): Promise` method or async parser wrapper that processes `ReadableStreamInterface` chunks without blocking the event loop.

**Why the change should be done:**
The monorepo is built on ReactPHP (per AGENTS.md description), yet the Parser is entirely synchronous. Integration with other SugarCraft libs requires non-blocking async parsing for streaming terminal output.

**Conditions for success:**
1. New method `parseStream(ReadableStreamInterface $stream): \React\Promise\PromiseInterface` exists
2. Parser can process a ReactPHP stream in non-blocking chunks
3. Existing `feed()` behavior is unchanged (backward compatible)
4. Tests cover async parsing with mock ReadableStream

**Implementation approach:**
1. Add `react/stream` as a dependency to composer.json
2. Create `src/Parser/AsyncParser.php` class that wraps the sync Parser
3. Implement `parseStream()` using ReactPHP event loop
4. Add tests with mock ReadableStream

**Related code locations:**
- `src/Parser/Parser.php:50-56` — synchronous feed() method
- `composer.json:29-30` — no ReactPHP dependency currently

---

## Phase 2: Medium Severity

### 2.1 OscHandlerImpl::hyperlink() is stub/no-op

**Status:** [ ]  
**Severity:** Medium  
**Category:** Missing Functionality (by design)  
**File:** `src/Parser/OscHandlerImpl.php:23-25`

**What is expected:**
OSC 8 hyperlink support documented as deferred to v2 with clear reference.

**Why the change should be done:**
OSC 8 hyperlinks are supported by modern terminal emulators and commonly used. A no-op implementation silently loses hyperlink data.

**Conditions for success:**
1. Docblock explicitly states "Deferred to v2" with reference to step-12 candy-vt migration
2. Issue or TODO comment links to tracking issue

**Implementation approach:**
1. Update docblock: `// hyperlink support deferred to v2 — requires CellGrid + Cursor from terminal state (step-12)`
2. Add `@see` reference to CALIBER_LEARNINGS.md step-12 dependency

---

### 2.2 CsiHandlerImpl is all no-ops (acknowledged — no action needed)

**Status:** [x]  
**Severity:** Medium  
**Category:** Known/Expected  
**File:** `src/Parser/CsiHandlerImpl.php`

**Notes:**
Deferred to candy-vt migration in step-12 per CALIBER_LEARNINGS.md. Interface must remain stable for the migration. No action needed except ensuring interface stability.

---

### 2.3 No support for OSC 1 (Icon Name) / OSC 4 (Color Palette)

**Status:** [ ]  
**Severity:** Medium  
**Category:** Missing Features  
**File:** `src/Parser/HandlerAdapter.php:79-85`

**What is expected:**
Either extend OSC handling to support commands 0 and 1 (window title and icon name), or document the limitation clearly.

**Why the change should be done:**
The regex `^([0-2]);(.*)$` only calls `title()` for command 2. Commands 0 (reset title) and 1 (icon name) are silently ignored despite matching the regex.

**Conditions for success:**
1. OSC 0 and OSC 1 are handled consistently (both set title/icon)
2. Unknown OSC commands are logged or documented as unsupported
3. Tests cover OSC 0 and OSC 1 sequences

**Implementation approach:**
1. Change OSC regex to handle command 0 and 1 as well
2. Add `iconName()` method to OscHandler interface (or merge with title)
3. Update OscHandlerImpl to store icon name separately
4. Add tests

**Related code locations:**
- `src/Parser/HandlerAdapter.php:79-85` — limited OSC regex
- `src/Parser/OscHandler.php` — interface only has `title()` and `hyperlink()`

---

### 2.4 Transition table static state without reset capability

**Status:** [ ]  
**Severity:** Medium  
**Category:** Architectural / Design  
**File:** `src/Parser/Transitions.php:27`

**What is expected:**
Add `Transitions::reset(): void` method to clear the static `$table` cache for testing.

**Why the change should be done:**
The transition table is built once lazily and cached in a static variable. For deterministic test isolation, a reset method is needed.

**Conditions for success:**
1. `Transitions::reset()` method exists and sets `$table = null`
2. After reset, next `Transitions::get()` call rebuilds the table
3. Tests verify reset functionality

**Implementation approach:**
```php
public static function reset(): void
{
    self::$table = null;
}
```

**Related code locations:**
- `src/Parser/Transitions.php:27` — `private static ?string $table = null;`
- `src/Parser/Transitions.php:35-38` — `get()` method with lazy build

---

## Phase 3: Low Severity / Code Quality

### 3.1 `mb_check_encoding` redundancy in UTF-8 validation

**Status:** [ ]  
**Severity:** Low  
**Category:** Performance / Code Quality  
**File:** `src/Parser/Parser.php:108-140`

**What is expected:**
Remove the redundant `mb_check_encoding()` call at line 139, or document why it's necessary.

**Why the change should be done:**
The manual UTF-8 validation (overlong checks, surrogate check, max codepoint check) is comprehensive. The `mb_check_encoding()` call adds function call overhead for every multi-byte UTF-8 rune.

**Conditions for success:**
1. `mb_check_encoding` call removed from `isValidUtf8Rune()`
2. All UTF-8 validation tests still pass
3. No regression in handling malformed UTF-8

**Implementation approach:**
1. Benchmark before/after to confirm no regression
2. Remove line 138-139 (comment + mb_check_encoding call)
3. Verify tests pass

**Related code locations:**
- `src/Parser/Parser.php:138-139` — redundant check
- `tests/Utf8PolicyTest.php` — UTF-8 validation tests

---

### 3.2 `currentState()` exposes internal State enum

**Status:** [ ]  
**Severity:** Low  
**Category:** API Design  
**File:** `src/Parser/Parser.php:142-146`

**What is expected:**
Consider providing a stable public API for state inspection instead of returning the internal State enum.

**Why the change should be done:**
`currentState()` returns the internal `State` enum, leaking implementation details. If internal representation changes, consumers break.

**Conditions for success:**
1. `@internal` annotation is present and visible
2. Alternative: return string representation instead for API stability
3. Tests continue to work

**Implementation approach:**
Option A: Keep as-is with `@internal` annotation (used in tests appropriately)
Option B: Return string representation: `return $this->state->name;`

**Related code locations:**
- `src/Parser/Parser.php:142-146`
- `tests/ParserTest.php:46` — usage in tests

---

### 3.3 HandlerAdapter doesn't validate params for unknown CSI commands

**Status:** [ ]  
**Severity:** Low  
**Category:** Robustness  
**File:** `src/Parser/HandlerAdapter.php:43-73`

**What is expected:**
Add optional debug logging or documented behavior for unknown CSI final bytes.

**Why the change should be done:**
For unknown CSI final bytes, `HandlerAdapter::csiDispatch()` silently does nothing. No way for consumer to know an unrecognized CSI was received.

**Conditions for success:**
1. Unknown CSI final bytes are documented as silently ignored (or logged in debug mode)
2. Tests verify unknown CSI does nothing (existing test at line 238-243)

**Implementation approach:**
Add optional debug logging via a constructor flag or environment variable:
```php
public function __construct(
    private CsiHandler $csi,
    private OscHandler $osc,
    private bool $debug = false,
) {}
```

---

### 3.4 No support for subparameters beyond validation

**Status:** [ ]  
**Severity:** Low  
**Category:** Completeness  
**File:** `src/Parser/Parser.php:231-259`

**What is expected:**
Subparameter handling is documented as dependent on CsiHandler implementation.

**Why the change should be done:**
The params array correctly contains subparameters. Whether they work depends on CsiHandler.

**Conditions for success:**
1. Docblock in HandlerAdapter mentions subparameter handling is CsiHandler-dependent
2. Tests verify subparameter sequences parse correctly (already exist in ParserOverflowTest)

**Related code locations:**
- `src/Parser/Parser.php:234` — subparameter separator
- `tests/ParserOverflowTest.php:66-77` — test for `38;2;255;128;0` parsing

---

### 3.5 No memory limit consideration for string buffers

**Status:** [ ]  
**Severity:** Medium (Security)  
**Category:** Robustness  
**File:** `src/Parser/Parser.php:32, 269-272`

**What is expected:**
Add `MAX_STRING_BUFFER` constant (65536) and enforce it in `put()` method.

**Why the change should be done:**
OSC/DCS strings accumulated without limit could cause memory exhaustion from malicious input.

**Conditions for success:**
1. `MAX_STRING_BUFFER = 65536` constant added to Parser
2. `put()` method checks buffer size and stops accumulating at limit
3. Tests verify buffer overflow is handled gracefully
4. Flush at limit triggers dispatch

**Implementation approach:**
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

**Related code locations:**
- `src/Parser/Parser.php:32` — `$stringBuffer` declaration
- `src/Parser/Parser.php:269-272` — `put()` method

---

### 3.6 Hardcoded 65535 clamp value magic number

**Status:** [ ]  
**Severity:** Low  
**Category:** Code Quality  
**File:** `src/Parser/Parser.php:229, 258`

**What is expected:**
Extract `65535` to a named constant `MAX_PARAM_VALUE`.

**Why the change should be done:**
The value 65535 appears without explanation. VT500 spec likely defines this maximum.

**Conditions for success:**
1. `private const MAX_PARAM_VALUE = 65535;` added near `MAX_PARAMS`
2. Line 258 uses constant instead of magic number
3. All tests pass

**Implementation approach:**
```php
private const MAX_PARAMS = 32;
private const MAX_PARAM_VALUE = 65535;

// Line 258:
$this->params[$last] = min($this->params[$last] * 10 + $digit, self::MAX_PARAM_VALUE);
```

**Related code locations:**
- `src/Parser/Parser.php:229` — `MAX_PARAMS = 32` already named
- `src/Parser/Parser.php:258` — magic number 65535

---

## Phase 4: Test Coverage Gaps

### 4.1 Missing tests for HandlerAdapter edge cases

**Status:** [ ]  
**Severity:** Medium  
**Category:** Testing  
**File:** `tests/HandlerAdapterTest.php`

**What is expected:**
Add tests for:
- Malformed OSC strings (invalid UTF-8, wrong terminator)
- Very long OSC payloads (overflow handling)
- OSC commands other than 2 (commands 0, 1, 4, etc.)
- Garbage bytes in various states

**Why the change should be done:**
Current HandlerAdapterTest has good CSI coverage but lacks edge cases for OSC.

**Conditions for success:**
1. Tests for malformed OSC strings exist and pass
2. Tests for OSC with other command numbers (not just 2)
3. Tests for very long OSC payloads
4. Coverage report shows increased coverage

---

### 4.2 No tests for DCS passthrough completeness

**Status:** [ ]  
**Severity:** Low  
**Category:** Testing  
**File:** `tests/HandlerAdapterTest.php:224-229`

**What is expected:**
Add test of full DCS sequence with params and data flowing through the parser.

**Why the change should be done:**
`testDcsDispatchDoesNothing()` only verifies the adapter does nothing. A fuller test would verify the parser correctly constructs the DCS dispatch.

**Conditions for success:**
1. Test feeds a DCS sequence (e.g., `\x1bP1;2;3;4;mystring\x1b\\`)
2. Verifies parser calls handler with correct final, params, prefix, intermediate, data

---

### 4.3 No tests for stress/overflow scenarios

**Status:** [ ]  
**Severity:** Low  
**Category:** Testing  
**File:** `tests/ParserOverflowTest.php`

**What is expected:**
Add tests for:
- Maximum string buffer length (when MAX_STRING_BUFFER is added)
- Many concurrent escape sequences
- Mixed partial input with many interleaved sequences

**Why the change should be done:**
Overflow tests cover param value clamping and param count capping, but miss string buffer overflow and mixed-sequence scenarios.

**Conditions for success:**
1. Tests for maximum string buffer length exist
2. Tests for mixed partial input exist
3. All overflow tests pass

---

## Phase 5: Performance Considerations (Low Priority)

### 5.1 Transitions table micro-optimization

**Status:** [ ]  
**Severity:** Low  
**Category:** Performance  
**File:** `src/Parser/Transitions.php:50-238`

**Notes:**
The build() method runs once per PHP process lifetime. Optimization priority is low. Not recommended for immediate action. Document as "runs once, not worth optimizing" if skipped.

---

### 5.2 ord() called in tight loop

**Status:** [ ]  
**Severity:** Low  
**Category:** Performance  
**File:** `src/Parser/Parser.php:54`

**Notes:**
PHP 8.1+ requires ord() for single-character string offsets. This is a micro-optimization concern, not a practical bottleneck. Not recommended for immediate action.

---

### 6.2 preg_match in hot path

**Status:** [ ]  
**Severity:** Low  
**Category:** Performance  
**File:** `src/Parser/HandlerAdapter.php:82`

**Notes:**
For the common case of OSC 2, the regex could be replaced with `strpos` + `substr`. However, this is micro-optimization. Not recommended for immediate action.

---

## Phase 6: Known/Expected (No Action)

### CsiHandlerImpl all no-ops
Deferred to candy-vt migration in step-12 per CALIBER_LEARNINGS.md.

### Synchronous-only design
Future async work (Phase 1.2 addresses this as deferred item).

---

## Implementation Order

**Immediate (Phase 1 + Security items):**
1. 1.1 — Fix HandlerAdapter C0 control handling (HIGH)
2. 3.5 — Add MAX_STRING_BUFFER (Security)
3. 3.6 — Extract 65535 magic number to constant
4. 2.4 — Add Transitions::reset()

**Soon (Phase 2-3):**
5. 2.1 — Document OSC hyperlink deferral
6. 2.3 — Extend OSC handling for commands 0 and 1
7. 3.1 — Remove mb_check_encoding redundancy (if benchmarks confirm safe)
8. 3.3 — Add debug logging for unknown CSI

**Later (Phase 4 + future work):**
9. 1.2 — Async/stream support (requires ReactPHP design)
10. 4.1-4.3 — Expand test coverage
11. 5.1, 5.2, 6.2 — Performance optimizations (low priority)

---

## Notes

- All changes must maintain backward compatibility with existing Handler interface
- Any new methods added to interfaces require backward-compatible default implementations
- Tests must pass `vendor/bin/phpunit` for candy-ansi before any PR
- Refer to `candy-ansi/CALIBER_LEARNINGS.md` for historical context on parser state machine bugs
- Review date: 2026-06-29, Reviewer: Automated Code Review
