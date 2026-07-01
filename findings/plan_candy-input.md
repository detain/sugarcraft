# Implementation Plan: candy-input Code Audit Fixes

**Status:** not-started  
**Phase:** 1  
**Updated:** 2026-06-30

---

## Goal

Address all actionable findings from the candy-input code audit, implementing bug fixes, performance improvements, documentation corrections, and refactoring while respecting the library's architectural constraints.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Fix scroll event modifiers at source | Scroll events with modifiers (Shift+scroll) are silently downgraded - this is a correctness bug | `candy-input.md:20-61` |
| Move stream_set_blocking to constructor | stream_set_blocking is a syscall that modifies kernel state; calling it on every read is unnecessary and wasteful | `candy-input.md:64-90` |
| Clarify comment not change behavior | Tests expect lowercase output; changing formula would break tests and change established API behavior | `candy-input.md:93-116` |
| Keep equals() method | KeyModifierTest.php lines 40-72 show equals() IS tested; audit claim of "never called" was incorrect | `KeyModifierTest.php:40-72` |
| ResizeEvent is architectural gap, not a bug | SIGWINCH is a signal, not stream input; EscapeDecoder correctly does not generate it | `candy-input.md:176-186` |

---

## Phase 1: Critical & High Severity Bug Fixes [PENDING]

### 1.1 Fix scroll events losing modifier information ← CURRENT

**Severity:** Critical  
**File:** `src/EscapeDecoder.php:297-308`  
**What is expected:** Scroll events (buttons 96/97) should use extracted modifier bits instead of hardcoded `KeyModifier::none()`

**Current code (lines 296-308):**
```php
// Scroll events: button 96 = scroll up, 97 = scroll down
if ($button === 96) {
    return [
        'events' => [MouseEvent::scrollUp((int)$x, (int)$y, KeyModifier::none())],
        'remaining' => $remaining,
    ];
}
if ($button === 97) {
    return [
        'events' => [MouseEvent::scrollDown((int)$x, (int)$y, KeyModifier::none())],
        'remaining' => $remaining,
    ];
}
```

**Problem:** Modifier extraction at lines 312-317 happens AFTER scroll return, so modifiers are discarded for scroll events.

**Fix:** Move modifier extraction before scroll check, apply `$modifiers` to scroll events:
```php
// Extract modifiers from SGR button field (bit 2=Shift, bit 3=Alt, bit 4=Ctrl)
$modifierBits = 0;
if ($button & 4)  { $modifierBits |= 1; } // Shift → SGR bit 0
if ($button & 8)  { $modifierBits |= 2; } // Alt   → SGR bit 1
if ($button & 16) { $modifierBits |= 4; } // Ctrl  → SGR bit 2

// Scroll events: button 96 = scroll up, 97 = scroll down
$modifiers = KeyModifier::fromSgrMouse($modifierBits);
if ($button === 96 || $button === 97) {
    $action = ($button === 96) ? MouseEvent::scrollUp((int)$x, (int)$y, $modifiers) : MouseEvent::scrollDown((int)$x, (int)$y, $modifiers);
    return ['events' => [$action], 'remaining' => $remaining];
}
```

**Why the change should be done:** Applications cannot distinguish Shift+scrollUp from plain scrollUp - this breaks basic modifier support for scroll events.

**Conditions for success:**
- Add test for Shift+scrollUp (`\x1b[<100;10;5M` = button 96+4 shift) expecting SHIFT modifier
- Add test for Shift+scrollDown (`\x1b[<101;10;5M` = button 97+4 shift) expecting SHIFT modifier
- Existing scroll tests still pass

**Investigation notes:** Modifier bits 4,8,16 are added to base button in SGR encoding. For scroll (96/97), adding Shift (4) gives 100/101. Current code only checks exact 96/97, so modified scroll events would fail to match. Need to extract modifiers BEFORE checking for scroll.

**Related code locations:**
- `src/EscapeDecoder.php:296-308` - scroll event return
- `src/EscapeDecoder.php:310-317` - modifier extraction (currently unreachable for scroll)
- `src/Event/MouseEvent.php:54-64` - scrollUp/scrollDown factories
- `tests/EscapeDecoderTest.php:358-376` - existing scroll tests (no modifier tests)

---

### 1.2 Move stream_set_blocking() to constructor

**Severity:** High  
**File:** `src/Driver/StreamInputDriver.php:27-31, 77-78`  
**What is expected:** `stream_set_blocking($this->stream, false)` called once in constructor, not on every `readNonBlocking()`

**Current code:**
```php
// Constructor (line 27-31)
public function __construct(
    private readonly mixed $stream,
) {
    $this->decoder = new EscapeDecoder();
    // stream_set_blocking NOT called here
}

// readNonBlocking (line 75-78)
private function readNonBlocking(): string|false
{
    stream_set_blocking($this->stream, false);  // Called EVERY read!
    // ...
}
```

**Fix:** Move to constructor:
```php
public function __construct(
    private readonly mixed $stream,
) {
    $this->decoder = new EscapeDecoder();
    stream_set_blocking($this->stream, false);  // ← Once at construction
}
```

**Why the change should be done:** `stream_set_blocking()` is a kernel system call that modifies stream state. Calling it on every read in a TUI event loop is wasteful and can cause performance degradation.

**Conditions for success:**
- `vendor/bin/phpunit tests/StreamInputDriverTest.php` passes
- Confirm `stream_set_blocking` not called in `readNonBlocking()`
- Stream remains non-blocking throughout driver lifetime

**Investigation notes:** `StreamInputDriverTest.php` creates pipe pairs with `stream_set_blocking($pair[0], false)` at construction (line 149-150), then passes to driver. Driver's redundant call is harmless but unnecessary.

**Related code locations:**
- `src/Driver/StreamInputDriver.php:27-31` - constructor
- `src/Driver/StreamInputDriver.php:75-78` - readNonBlocking (remove call here)
- `tests/StreamInputDriverTest.php:141-153` - test pipe setup

---

## Phase 2: Medium Severity Fixes [PENDING]

### 2.1 Clarify misleading Ctrl+letter comment

**Severity:** Medium  
**File:** `src/EscapeDecoder.php:619-623`  
**What is expected:** Comment accurately describes that the formula produces lowercase a-z

**Current code:**
```php
// Ctrl + letter (0x01-0x1a)
if ($ord >= 0x01 && $ord <= 0x1a) {
    $letter = chr($ord + 0x60);
    return new KeyEvent($letter, KeyModifier::ctrl(), $byte);
}
```

**Comment problem:** "letter" implies lowercase a-z but the comment header says "0x01-0x1a" which is the control code range. The formula `chr($ord + 0x60)` produces lowercase (0x01 + 0x60 = 0x61 = 'a').

**Fix:** Update comment to clarify:
```php
// Ctrl + letter (0x01-0x1a) — produces lowercase a-z per Unix terminal convention
// e.g., Ctrl+A (0x01) → 'a', Ctrl+C (0x03) → 'c'
if ($ord >= 0x01 && $ord <= 0x1a) {
    $letter = chr($ord + 0x60);
    return new KeyEvent($letter, KeyModifier::ctrl(), $byte);
}
```

**Why the change should be done:** Documentation should clearly explain that Ctrl+letter produces lowercase output, avoiding confusion for developers expecting uppercase.

**Conditions for success:** Comment clearly explains lowercase output; no behavior change

**Investigation notes:** Traditional Unix convention maps Ctrl+A (0x01) to uppercase 'A'. This implementation produces lowercase 'a'. The tests expect lowercase ('c' for Ctrl+C), so changing behavior would break tests.

**Related code locations:**
- `src/EscapeDecoder.php:619-623` - decodeControlChar
- `tests/EscapeDecoderTest.php` - Ctrl+letter tests expect lowercase

---

### 2.2 Extract findFinalByte() private method for Unknown-CSI handling

**Severity:** Medium  
**File:** `src/EscapeDecoder.php:472-528`  
**What is expected:** Extract the 56-line unknown-CSI handling block into smaller, testable methods

**What is expected:** Create `findFinalByte(string $csi): int|null` method that encapsulates final-byte scanning logic (lines 483-518 with $altFinalBytePos rescan logic).

**Why the change should be done:** The two-stage rescan loop (finding final byte, then alternate final byte if trailing byte detected) is a maintenance hazard. Extracting it makes the flow in `handleCsiKey()` much clearer and allows unit testing the logic in isolation.

**Conditions for success:**
- New `findFinalByte()` method created with clear interface
- `handleCsiKey()` calls `findFinalByte()` instead of inline logic
- Unit test for `findFinalByte()` covers edge cases: no final byte, alternate final byte, trailing bytes

**Investigation notes:** The complexity exists because CSI sequences can have: parameters (digits/semicolons), intermediates (0x20-0x2F), and a final byte (0x40-0x7E). The code tries to find the "real" final byte when the last byte might be trailing.

**Related code locations:**
- `src/EscapeDecoder.php:472-528` - handleCsiKey unknown-CSI block
- `src/EscapeDecoder.php:483-518` - final byte scanning logic to extract

---

### 2.3 Keep equals() method (retain, document rationale)

**Severity:** Medium (but finding was incorrect)  
**File:** `src/KeyModifier.php:159-162`  
**What is expected:** Keep equals() method, add documentation explaining its purpose

**Audit finding said:** "equals() is defined but never called anywhere in the codebase (tests or source)"

**Investigation shows:** equals() IS tested in `KeyModifierTest.php:40-72` with multiple test cases:
- `testEqualsReturnsTrueForSameMask()`
- `testEqualsReturnsFalseForDifferentMask()`
- `testEqualsReturnsTrueForCombinedModifiers()`
- `testEqualsReturnsFalseForDifferentCombinations()`

**Fix:** Add docblock explaining why equals() exists despite PHP's === working for value comparison:
```php
/**
 * Compare modifier masks for equality.
 * 
 * Unlike === which compares object identity, this method compares the
 * actual mask values. This is useful when distinct KeyModifier instances
 * may exist for the same mask (e.g., from different factory calls or
 * deserialization).
 */
public function equals(self $other): bool
{
    return $this->mask === $other->mask;
}
```

**Why the change should be done:** The audit was incorrect - equals() has tests and serves a legitimate purpose for cases where object identity would differ despite same mask value.

**Conditions for success:** equals() method retained with clear documentation; all existing tests pass

**Related code locations:**
- `src/KeyModifier.php:159-162` - equals method
- `tests/KeyModifierTest.php:40-72` - equals tests

---

## Phase 3: Low Severity / Missing Tests & Documentation [PENDING]

### 3.1 Add FocusEvent edge case tests

**Severity:** Low  
**File:** `tests/EscapeDecoderTest.php`  
**What is expected:** Test focus events with intermediate bytes and edge cases

**Current coverage:** Only `testFocusGained` and `testFocusLost` at lines 400-414 test basic `\x1b[I` and `\x1b[O`

**Missing tests:**
- Focus events with intermediate bytes (e.g., `\x1b[1I`)
- Private mode prefix focus events (e.g., `\x1b[?1I` or `\x1b[?1004I`)
- Focus events followed by other sequences

**Why the change should be done:** Edge case coverage ensures decoder handles all valid focus event formats terminals may send.

**Conditions for success:** New tests pass alongside existing focus tests

**Related code locations:**
- `src/EscapeDecoder.php:240-246` - focus event handling
- `tests/EscapeDecoderTest.php:400-414` - existing focus tests

---

### 3.2 Document ResizeEvent architectural limitation

**Severity:** Low  
**File:** `src/Event/ResizeEvent.php`  
**What is expected:** Clear documentation that ResizeEvent requires signal handling, not stream decoding

**Audit finding:** ResizeEvent is defined but EscapeDecoder never generates it.

**Explanation:** Terminal resize detection requires catching SIGWINCH signals, which cannot be done through a stream decoder. SIGWINCH is delivered as a Unix signal, not as stream input.

**Fix:** Add comprehensive docblock:
```php
/**
 * A terminal resize event (SIGWINCH).
 *
 * NOTE: ResizeEvent is NOT generated by EscapeDecoder. EscapeDecoder only
 * processes stream input (keyboard/mouse/focus/paste events). Terminal resize
 * detection requires a SIGWINCH signal handler in the application.
 *
 * To generate ResizeEvent objects, install a signal handler:
 *   pcntl_async_signals(true);
 *   pcntl_signal(SIGWINCH, function(int $sig, $info) use ($decoder) {
 *       // Get terminal size via `tput cols` or `ncurses`
 *       $decoder->emitResizeEvent($cols, $rows);
 *   });
 *
 * @see Mirrors charmbracelet/bubbletea (input handling).
 * @readonly
 */
```

**Why the change should be done:** Prevents confusion about why ResizeEvent is never emitted; provides migration path for applications needing resize detection.

**Conditions for success:** Documentation clearly explains resize detection requires signal handling; no behavior change

**Related code locations:**
- `src/Event/ResizeEvent.php` - class definition
- `src/EscapeDecoder.php` - does NOT handle SIGWINCH (correctly)

---

### 3.3 Simplify InputDriver::read() return type annotation

**Severity:** Low  
**File:** `src/InputDriver.php:24`  
**What is expected:** Union type simplified to `@return Event|null`

**Current code:**
```php
@return Event|KeyEvent|MouseEvent|FocusEvent|PasteEvent|ResizeEvent|null
```

**Fix:**
```php
@return Event|null
```

**Why the change should be done:** All listed types implement Event interface; the union is redundant and harder to maintain.

**Conditions for success:** phpstan level 8+ passes with simplified type

**Related code locations:**
- `src/InputDriver.php:24` - interface method

---

### 3.4 Document SGR 1003/1015/1000 non-support

**Severity:** Low  
**File:** `README.md`  
**What is expected:** Document which SGR modes are supported

**Current state:** Only SGR 1006 is supported. Other common modes include:
- SGR 1000: basic mouse mode
- SGR 1003: highlight (mouse highlight mode)  
- SGR 1015: urxvt mouse encoding

**Fix:** Add "Limitations" section to README:
```markdown
## Limitations

- **Mouse protocols:** Only SGR 1006 mouse mode is supported. Basic mouse (SGR 1000), 
  highlight mode (SGR 1003), and urxvt encoding (SGR 1015) are not implemented.
- **Resize detection:** ResizeEvent requires SIGWINCH signal handling, not stream input.
```

**Why the change should be done:** Developers need to know what's not supported to avoid confusion.

**Conditions for success:** README clearly documents limitations

**Related code locations:**
- `README.md` - documentation
- `src/EscapeDecoder.php:235-238` - SGR 1006 only detection

---

### 3.5 Add MAX_SIZE configuration to PasteEvent

**Severity:** Low  
**File:** `src/Event/PasteEvent.php`  
**What is expected:** Make MAX_SIZE configurable

**Current code:**
```php
public const MAX_SIZE = 1 << 20; // 1 MiB
public static function truncate(string $content): self
{
    if (strlen($content) > self::MAX_SIZE) {
        $content = substr($content, 0, self::MAX_SIZE);
    }
    return new self($content);
}
```

**Fix:** Add options class or constructor parameter for configuration:
```php
final readonly class PasteEvent implements Event
{
    public const MAX_SIZE = 1 << 20; // 1 MiB default

    public function __construct(
        public string $content,
    ) {}

    public static function truncate(string $content, int $maxSize = self::MAX_SIZE): self
    {
        if (strlen($content) > $maxSize) {
            $content = substr($content, 0, $maxSize);
        }
        return new self($content);
    }
}
```

Or via EscapeDecoderOptions class (see 3.6).

**Why the change should be done:** Applications may need different paste limits based on memory constraints.

**Conditions for success:** Applications can override paste size limit; existing tests pass

**Related code locations:**
- `src/Event/PasteEvent.php:20-33` - MAX_SIZE and truncate
- `src/EscapeDecoder.php:548-554` - paste size check

---

## Phase 4: Missing Features [PENDING]

### 4.1 Add EscapeDecoderOptions for protocol filtering

**Severity:** Feature  
**File:** New file `src/EscapeDecoderOptions.php`  
**What is expected:** Options class allowing applications to disable unused protocol parsing

**Proposed interface:**
```php
final class EscapeDecoderOptions
{
    public bool $enableMouse = true;
    public bool $enableKitty = true;
    public bool $enableFocus = true;
    public bool $enablePaste = true;
}
```

**Why the change should be done:** Applications that only need keyboard input pay parsing overhead for mouse sequences. Disabling unused protocols improves performance.

**Conditions for success:** Tests confirm disabled protocols skip parsing overhead

**Related code locations:**
- `src/EscapeDecoder.php` - needs options parameter in constructor
- `src/EscapeDecoder.php:235-238` - mouse detection (guard with option)
- `src/EscapeDecoder.php:240-246` - focus detection (guard with option)

---

### 4.2 Create ReactInputDriver implementing ReadableStreamInterface

**Severity:** Feature  
**File:** New file `src/Driver/ReactInputDriver.php`  
**What is expected:** ReactPHP-compatible input driver

**Proposed interface:**
```php
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;

final class ReactInputDriver implements ReadableStreamInterface
{
    use Util\HasRemoteStreamCapability;

    private EscapeDecoder $decoder;
    private EventEmitter $emitter;
    private bool $closed = false;

    public function __construct(ReadableStreamInterface $stream)
    {
        $this->decoder = new EscapeDecoder();
        $this->emitter = new EventEmitter();
        $stream->on('data', fn($chunk) => $this->handleChunk($chunk));
        $stream->on('close', fn() => $this->close());
    }

    // Implement ReadableStreamInterface...
}
```

**Why the change should be done:** This is a ReactPHP-based ecosystem; StreamInputDriver uses synchronous stream_select() which is not ReactPHP-friendly.

**Conditions for success:** Can be used in ReactPHP async context; existing tests still pass

**Related code locations:**
- `src/Driver/StreamInputDriver.php` - existing synchronous implementation
- `composer.json:31-32` - requires sugarcraft/candy-core (ReactPHP)

---

### 4.3 Create SignalResizeDriver for SIGWINCH handling

**Severity:** Feature  
**File:** New file `src/Driver/SignalResizeDriver.php`  
**What is expected:** Driver that wraps signal handler and emits ResizeEvent

**Why the change should be done:** Applications need a way to get ResizeEvent objects, and this requires SIGWINCH signal handling.

**Conditions for success:** Driver correctly emits ResizeEvent on SIGWINCH

**Related code locations:**
- `src/Event/ResizeEvent.php` - class definition
- `src/InputDriver.php` - interface that could be extended

---

## Phase 5: Refactoring Opportunities [PENDING]

### 5.1 Extract shared key maps as private class constants

**Severity:** Refactor  
**File:** `src/EscapeDecoder.php`  
**What is expected:** Consolidate duplicate key maps into private class constants

**Duplications found:**
- `handleSS3()` lines 196-208: `$ss3Map` (F1-F4 + arrows + Home/End)
- `handleCsiKey()` lines 416-417: `$arrowMap` (arrows only)
- `handleCsiKey()` lines 441-462: `$specialKeys` and `$fKeys` arrays
- `kittyKeyCodeToName()` lines 649-675: `$arrowCodes`, `$fKeys`, `$special` arrays (same content, different format)

**Proposed constants:**
```php
private const ARROW_KEYS = [
    'A' => 'ArrowUp', 'B' => 'ArrowDown', 'C' => 'ArrowRight', 'D' => 'ArrowLeft',
];
private const CSI_SPECIAL_KEYS = [
    '1' => 'Home', '2' => 'Insert', '3' => 'Delete', '4' => 'End',
    '5' => 'PageUp', '6' => 'PageDown',
];
private const CSI_FKEYS = [
    '11' => 'F1', '12' => 'F2', // ...
];
private const SS3_FKEYS = [
    'P' => 'F1', 'Q' => 'F2', 'R' => 'F3', 'S' => 'F4',
];
private const SS3_ARROWS_HOME_END = [
    'A' => 'ArrowUp', 'B' => 'ArrowDown', 'C' => 'ArrowRight', 'D' => 'ArrowLeft',
    'H' => 'Home', 'F' => 'End',
];
```

**Why the change should be done:** DRY principle - same key mappings defined in multiple places, risk of inconsistency.

**Conditions for success:** Refactored code passes all existing tests

**Related code locations:**
- `src/EscapeDecoder.php:188-220` - handleSS3
- `src/EscapeDecoder.php:376-470` - handleCsiKey
- `src/EscapeDecoder.php:633-678` - kittyKeyCodeToName

---

### 5.2 Simplify SGR modifier bit extraction

**Severity:** Refactor  
**File:** `src/EscapeDecoder.php:311-317`  
**What is expected:** Use direct bit extraction instead of conditional chains

**Current code:**
```php
$modifierBits = 0;
if ($button & 4)  { $modifierBits |= 1; } // Shift → SGR bit 0
if ($button & 8)  { $modifierBits |= 2; } // Alt   → SGR bit 1
if ($button & 16) { $modifierBits |= 4; } // Ctrl  → SGR bit 2
```

**Proposed fix:**
```php
$modifierBits = ($button >> 2) & 0x07;  // Extract bits 2-4 as 0-5 range
```

**Why the change should be done:** Simpler formula avoids the double-mapping confusion. The current code maps button bits (4,8,16) to modifier bits (1,2,4) - this works but is hard to reason about.

**Conditions for success:** Tests confirm same modifier behavior with simpler formula

**Related code locations:**
- `src/EscapeDecoder.php:311-317` - modifier extraction
- `src/KeyModifier.php:114-122` - fromSgrMouse which reverses the mapping

---

## Phase 6: Compatibility & Ecosystem [PENDING]

### 6.1 Document sugar-readline migration path

**Severity:** Compatibility  
**File:** `README.md`  
**What is expected:** Explain relationship between candy-input and future sugar-readline

**Current state:** README says "Unblocks sugar-readline migration to real-TTY input" but no sugar-readline library exists yet.

**Fix:** Add section explaining the migration path:
```markdown
## Integration with sugar-readline

candy-input provides the low-level TTY input decoding layer. When sugar-readline is 
available, it will use candy-input as its input source, providing line editing 
capabilities built on top of candy-input's event stream.

See [sugar-readline](./sugar-readline/README.md) for the readline integration.
```

**Why the change should be done:** Documents the stated goal of the library and provides migration path.

**Conditions for success:** README clearly explains relationship

**Related code locations:**
- `README.md:3` - description mentions "Unblocks sugar-readline"
- `composer.json:3` - description mentions "Unblocks sugar-readline migration"

---

### 6.2 Document sugar-bits integration example

**Severity:** Compatibility  
**File:** `README.md`  
**What is expected:** Show example of using candy-input with sugar-bits TUI components

**Why the change should be done:** sugar-bits (component library) likely needs keyboard/mouse input but there's no integration example.

**Conditions for success:** Example code works with sugar-bits

**Related code locations:**
- candy-bits library (not yet integrated with candy-input per audit)

---

### 6.3 Update composer.json PHP version constraint

**Severity:** Compatibility  
**File:** `composer.json:31`  
**What is expected:** Either update to `^8.4` for Windows FFI support, or document why `^8.3` is acceptable

**Current code:** `"php": "^8.3"`

**AGENTS.md states:** "PHP 8.3+ (8.4+ for Windows FFI)"

**Fix:** Update if Windows FFI support is planned:
```json
"php": "^8.4"
```

Or add comment if ^8.3 is intentional:
```json
"php": "^8.3",  // Note: Windows FFI support requires ^8.4
```

**Why the change should be done:** Aligns with project-wide standard for Windows FFI support.

**Conditions for success:** composer validate passes

**Related code locations:**
- `composer.json:31` - PHP version requirement

---

## Summary Table

| Priority | Category | Item | Location | Status |
|----------|----------|------|----------|--------|
| **Critical** | Bug | Scroll events lose modifier information | `EscapeDecoder.php:297-308` | PENDING |
| **High** | Performance | stream_set_blocking() called on every read | `StreamInputDriver.php:78` | PENDING |
| **Medium** | Docs | Misleading Ctrl+letter comment | `EscapeDecoder.php:619-623` | PENDING |
| **Medium** | Complexity | Unknown-CSI handling block too complex | `EscapeDecoder.php:472-528` | PENDING |
| **Medium** | Retention | KeyModifier::equals() documented (not removed) | `KeyModifier.php:159-162` | PENDING |
| **Low** | Missing Tests | FocusEvent edge case tests | `EscapeDecoderTest.php` | PENDING |
| **Low** | Documentation | ResizeEvent architectural gap documented | `ResizeEvent.php` | PENDING |
| **Low** | Cleanup | InputDriver::read() redundant union type | `InputDriver.php:24` | PENDING |
| **Low** | Documentation | SGR 1003/1015/1000 non-support documented | `README.md` | PENDING |
| **Low** | Feature | PasteEvent MAX_SIZE configurable | `PasteEvent.php` | PENDING |
| **Feature** | Missing | EscapeDecoderOptions for protocol filtering | New file | PENDING |
| **Feature** | Missing | ReactInputDriver (ReadableStreamInterface) | New file | PENDING |
| **Feature** | Missing | SignalResizeDriver for SIGWINCH | New file | PENDING |
| **Refactor** | DRY | Duplicate key name maps across methods | `EscapeDecoder.php` | PENDING |
| **Refactor** | DRY | SGR modifier bit extraction formula | `EscapeDecoder.php:311-317` | PENDING |
| **Compat** | Migration | sugar-readline integration path documented | `README.md` | PENDING |
| **Compat** | Ecosystem | sugar-bits integration example | `README.md` | PENDING |
| **Compat** | PHP Version | composer.json PHP constraint | `composer.json:31` | PENDING |

---

*End of implementation plan.*
