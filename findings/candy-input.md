# Code Audit: sugarcraft/candy-input

**Library:** Terminal escape sequence decoder for keyboard and mouse  
**Date:** Tue Jun 30 2026  
**Reviewer:** Code Audit  
**Files Reviewed:** src/Event.php, src/Event/KeyEvent.php, src/Event/MouseEvent.php, src/Event/PasteEvent.php, src/Event/ResizeEvent.php, src/Event/FocusEvent.php, src/KeyModifier.php, src/EscapeDecoder.php, src/InputDriver.php, src/Driver/StreamInputDriver.php, src/Lang.php + all 6 test files + lang/en.php + composer.json + README.md

---

## Summary

`candy-input` is a well-structured PHP 8.3+ library providing terminal input decoding for TUI applications. It correctly implements legacy CSI sequences, the Kitty keyboard protocol, SGR 1006 mouse events, bracketed paste, and focus events. The codebase is `final readonly` throughout with immutable value objects and a clean separation of concerns (decoder, driver, events). Test coverage is thorough at ~960 lines for the EscapeDecoder alone. There are no memory leaks, no security vulnerabilities, and no blocking I/O issues in the async-aware stream driver.

The most significant issue is the **hardcoded `KeyModifier::none()` for scroll events in `handleSgrMouse`** — scroll events with modifiers are silently downgraded to no modifiers. Secondary concerns are: redundant `stream_set_blocking()` calls on every `read()`, a misleading Ctrl-letter mapping comment, and the absence of `ResizeEvent` generation in the decoder despite the event type existing.

---

## Critical Issues

### 1. Scroll Events Always Report No Modifiers (SGR Mouse)

**File:** `src/EscapeDecoder.php:297-308`

```php
if ($button === 96) {
    return [
        'events' => [MouseEvent::scrollUp((int)$x, (int)$y, KeyModifier::none())],
        //                              ^^^^^^^^^^^^^^^^^^^^^^^^ hardcoded!
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

**Problem:** When a terminal sends a scroll event with modifier bits (e.g., Shift+scroll = wheel emulation with Shift flag), the modifier bits are extracted at lines 312-317 but then **discarded** — only the raw button number (96 or 97) is checked. The modifiers extracted from the SGR button field are thrown away for scroll events.

**Impact:** High — application cannot distinguish Shift+scrollUp from plain scrollUp, for example.

**Fix:** Apply the already-extracted `$modifiers` variable to scroll events:

```php
$modifiers = KeyModifier::fromSgrMouse($modifierBits);
if ($button === 96) {
    return [
        'events' => [MouseEvent::scrollUp((int)$x, (int)$y, $modifiers)],
        'remaining' => $remaining,
    ];
}
if ($button === 97) {
    return [
        'events' => [MouseEvent::scrollDown((int)$x, (int)$y, $modifiers)],
        'remaining' => $remaining,
    ];
}
```

---

## High Severity Issues

### 2. Repeated `stream_set_blocking()` System Call

**File:** `src/Driver/StreamInputDriver.php:77-78`

```php
private function readNonBlocking(): string|false
{
    stream_set_blocking($this->stream, false);  // Called EVERY read!
```

**Problem:** `stream_set_blocking()` is a **system call** that modifies kernel stream state. Calling it on every `read()` is unnecessary — the stream only needs to be set non-blocking once at construction time.

**Impact:** Medium — performance degradation under high-frequency read loops (common in TUI applications with async event loops).

**Fix:** Move `stream_set_blocking($this->stream, false)` to the constructor:

```php
public function __construct(
    private readonly mixed $stream,
) {
    $this->decoder = new EscapeDecoder();
    stream_set_blocking($this->stream, false);  // ← Once at construction
}
```

---

## Medium Severity Issues

### 3. Misleading Comment in `decodeControlChar` — Ctrl Letter Mapping

**File:** `src/EscapeDecoder.php:619-623`

```php
// Ctrl + letter (0x01-0x1a)
if ($ord >= 0x01 && $ord <= 0x1a) {
    $letter = chr($ord + 0x60);
    return new KeyEvent($letter, KeyModifier::ctrl(), $byte);
}
```

**Comment says:** `// Ctrl + letter (0x01-0x1a)` — "letter" implies lowercase a-z (0x61-0x7a)  
**Formula produces:** lowercase a-z (0x61-0x7a) via `chr($ord + 0x60)`  
**Traditional Unix Ctrl+letter convention:** uppercase A-Z (0x41-0x5A), e.g., Ctrl+A = 0x01 → 'A'

**Problem:** The comment describes the intent ("letter") but the formula produces lowercase. The traditional Unix terminal convention maps Ctrl+letter (0x01-0x1A) to uppercase A-Z. The current formula (0x01 + 0x60 = 0x61 = 'a') gives lowercase. The test suite passes with the current formula (Ctrl+C = 'c'), so this is **internally consistent** but the comment is misleading about what "letter" means.

**Impact:** Low — behavior is consistent with tests, but documentation is confusing.

**Fix:** Either update the comment to clarify the mapping produces lowercase, or change the formula to produce uppercase A-Z (which would require updating tests). Recommended: clarify comment.

---

### 4. Complex Unknown-CSI Handling Block is Difficult to Follow

**File:** `src/EscapeDecoder.php:472-528`

The block handling "CSI number without known suffix" spans **56 lines** with nested loops, alternate byte position rescanning, and trailing-byte extraction. The logic attempts to find the "final byte" (0x40-0x7E) in an unknown CSI sequence to identify what to skip.

**Problem:** This complexity is a maintenance hazard. The two-stage rescan loop (lines 483-518) with `$altFinalBytePos` alternate-finding is especially hard to reason about.

**Impact:** Medium — maintenance and extension difficulty; hard to add new CSI sequences correctly.

**Recommendation:** Extract the final-byte scanning into a small private method `findFinalByte(string $csi): int|null`. This would make the flow in `handleCsiKey` much clearer.

---

### 5. Dead Code — `KeyModifier::equals()` is Never Called

**File:** `src/KeyModifier.php:159-162`

```php
public function equals(self $other): bool
{
    return $this->mask === $other->mask;
}
```

**Problem:** This method is defined but never called anywhere in the codebase (tests or source). The standard PHP equality `==` or identity `===` would work directly on `KeyModifier` instances since the class has no `__get`/`__set` and only a private `$mask`.

**Impact:** Low — unused code is a minor maintenance burden.

**Fix:** Either add a test that demonstrates its necessity (e.g., in a scenario where `==` would give wrong results due to object identity) or remove it. The `===` operator works correctly for value comparison of these objects.

---

## Low Severity Issues

### 6. No Tests for `FocusEvent` via `EscapeDecoder`

`EscapeDecoderTest` tests `testFocusGained` and `testFocusLost` at lines 400-414 using the full decode path (correct), but tests only basic DECSET 1004 format (`\x1b[I` and `\x1b[O`). There are no tests for focus events with intermediate bytes or edge cases (e.g., `\x1b[1I`, `\x1b[?1I`).

### 7. No Tests for `ResizeEvent`

`ResizeEvent` is defined but `EscapeDecoder` never generates it — SIGWINCH is a signal, not a stream input event. There are no tests for `ResizeEvent` creation. This is acceptable but should be documented that resize detection requires SIGWINCH signal handling, not `EscapeDecoder`.

### 8. `InputDriver::read()` Return Type Annotation is Ugly

**File:** `src/InputDriver.php:24`

```php
@return Event|KeyEvent|MouseEvent|FocusEvent|PasteEvent|ResizeEvent|null
```

**Problem:** This union is redundant since all of these implement `Event`. Just `@return Event|null` is cleaner.

**Fix:** Change to `@return Event|null`.

---

## Missing Features

### 1. `ResizeEvent` Is Never Generated by `EscapeDecoder`

`ResizeEvent` exists in `src/Event/ResizeEvent.php` but `EscapeDecoder` never emits it. Terminal resize detection requires catching `SIGWINCH` signals, which cannot be done through a stream decoder. This is a fundamental architectural gap — the README describes ResizeEvent but it cannot actually be generated.

**Recommendation:** Either:
- Implement a `SignalResizeDriver` that wraps a signal handler and emits `ResizeEvent` objects on SIGWINCH
- Document clearly that resize detection is out of scope for `EscapeDecoder` and requires application-level SIGWINCH handling
- Remove `ResizeEvent` from the library until resize support is implemented

### 2. No ReactPHP/Async Streaming Integration

This is a ReactPHP-based ecosystem (`php: ^8.3` with `candy-core` which uses ReactPHP), yet `StreamInputDriver` is purely blocking I/O via `stream_select()`. There is no:
- `ReadableStreamInterface` implementation from ReactPHP
- Async event loop integration
- Async iterable of events

**Recommendation:** Add a `ReactInputDriver` that implements `React\Stream\ReadableStreamInterface`, allowing integration with ReactPHP's event loop.

### 3. No Way to Enable/Disable Specific Input Protocols

`EscapeDecoder` always attempts to decode all protocols (legacy CSI, Kitty, SGR mouse, focus, paste). Applications that only need keyboard input pay the parsing cost for mouse sequences. There is no way to filter or configure which protocols are active.

**Recommendation:** Add an `EscapeDecoderOptions` class with flags like `$enableMouse`, `$enableKitty`, `$enableFocus`, `$enablePaste`, allowing applications to disable unused protocol parsing.

### 4. No Support for SGR 1003 (highlight mouse) or SGR 1015 (urxvt mouse)

The library only supports SGR 1006. Terminal emulators also commonly send:
- **SGR 1003**: highlight (mouse highlight mode)
- **SGR 1015**: urxvt mouse encoding
- **SGR 1000**: basic mouse mode

**Recommendation:** Document the limitation (only SGR 1006 is supported) and/or implement SGR 1003/1015 support.

### 5. No Way to Query Terminal for Supported Protocols

The library cannot query the terminal's supported input modes (DECRQSS queries). Applications must blindly assume or configure.

### 6. No Clipboard/Paste Configuration

`PasteEvent::MAX_SIZE` is hardcoded to 1 MiB. There is no way for an application to override this limit.

---

## Duplicated Logic / Refactoring Opportunities

### 1. Duplicate Key Name Maps are Duplicated Across Multiple Methods

**File:** `src/EscapeDecoder.php`

- `handleSS3()` lines 196-208: `$ss3Map` for F1-F4 + arrows
- `handleCsiKey()` lines 416-417: `$arrowMap` for arrows (duplicates part of SS3)
- `handleCsiKey()` lines 441-462: `$specialKeys` (Home, Insert, Delete, etc.) and `$fKeys` arrays
- `kittyKeyCodeToName()` lines 649-675: duplicate `$arrowCodes`, `$fKeys`, and `$special` arrays

**Example duplication:**
```php
// handleSS3
$ss3Map = [
    'P' => 'F1', 'Q' => 'F2', 'R' => 'F3', 'S' => 'F4',
    'A' => 'ArrowUp', 'B' => 'ArrowDown', 'C' => 'ArrowRight', 'D' => 'ArrowLeft',
    'H' => 'Home', 'F' => 'End',
];

// handleCsiKey
$arrowMap = ['A' => 'ArrowUp', 'B' => 'ArrowDown', 'C' => 'ArrowRight', 'D' => 'ArrowLeft'];
```

**Recommendation:** Extract shared key maps as private class constants:
```php
private const ARROW_KEYS = [
    'A' => 'ArrowUp', 'B' => 'ArrowDown', 'C' => 'ArrowRight', 'D' => 'ArrowLeft',
];
private const CSI_FKEYS = [
    '11' => 'F1', '12' => 'F2', // ...
];
```

### 2. `PasteEvent::truncate()` Uses `substr()` for Byte Truncation

**File:** `src/Event/PasteEvent.php:28-29`

```php
if (strlen($content) > self::MAX_SIZE) {
    $content = substr($content, 0, self::MAX_SIZE);
}
```

This is correct for raw byte truncation, but `strlen()` is O(n) on the full string before the substr. For very large pastes, this could be optimized by checking the length during accumulation rather than after.

**Impact:** Low — only affects >1MiB pastes which force-close anyway.

### 3. `$modifierBits` Extraction in `handleSgrMouse` Duplicates SGR Bit-Shift Logic

**File:** `src/EscapeDecoder.php:311-317`

```php
$modifierBits = 0;
if ($button & 4)  { $modifierBits |= 1; } // Shift → SGR bit 0
if ($button & 8)  { $modifierBits |= 2; } // Alt   → SGR bit 1
if ($button & 16) { $modifierBits |= 4; } // Ctrl  → SGR bit 2
```

This maps SGR button bits (4, 8, 16) to SGR mouse modifier bits (1, 2, 4). This is then immediately passed to `KeyModifier::fromSgrMouse()` which does the reverse mapping. This double-mapping is confusing and error-prone.

**Recommendation:** Simplify by passing the raw modifier bits directly to `KeyModifier::fromSgrMouse()`:

```php
// Just pass bits 2-4 directly as SGR modifier bits
$modifierBits = ($button >> 2) & 0x07;  // Extract bits 2-4 as 0-5 range
$modifiers = KeyModifier::fromSgrMouse($modifierBits);
```

### 4. The `Event` Marker Interface is Empty

**File:** `src/Event.php`

```php
interface Event
{
}
```

An empty marker interface provides minimal value beyond using a docblock tag. However, it does enforce that event classes implement it and allows `?Event` type hints. This is acceptable.

---

## Compatibility Issues

### 1. No `candy-readline` Integration Path

**Problem:** The README mentions this library "Unblocks sugar-readline migration to real-TTY input" but there is no `candy-readline` library in the monorepo to integrate with. `sugar-readline` does not appear in `MATCHUPS.md`.

**Impact:** High for migration — applications currently using readline have no upgrade path documented.

### 2. No `sugar-bits` Compatibility

**Problem:** `sugar-bits` (component library) has no dependency on `candy-input`. The TUI component ecosystem (`sugar-bits`, `candy-shell`) likely needs keyboard/mouse input but there is no integration example.

### 3. Hard Dependency on `sugarcraft/candy-core`

`composer.json:31` requires `"sugarcraft/candy-core": "dev-master"` with a path repository. This is a hard development dependency that makes the library unusable without the full monorepo. This is by design per AGENTS.md but worth noting for users who want to use it standalone.

### 4. No Windows/FFI Support Path

`composer.json` requires `php: ^8.3` but `^8.4+` is needed for Windows FFI per AGENTS.md. The library uses `stream_select()` which works on Windows (since PHP 5.3), but PTY/signal handling needed for full TTY support does not work on Windows.

---

## Async Pattern Improvements

### 1. `StreamInputDriver` Uses Synchronous `stream_select()` — Not ReactPHP-Friendly

**File:** `src/Driver/StreamInputDriver.php:75-88`

```php
private function readNonBlocking(): string|false
{
    stream_set_blocking($this->stream, false);
    $read = [$this->stream];
    $write = null;
    $except = null;
    $changed = @stream_select($read, $write, $except, 0, 0);
    // ...
}
```

**Problem:** `stream_select()` is a blocking call even with 0 timeout. When integrated with ReactPHP's event loop, this will still busy-poll if called repeatedly. The proper ReactPHP pattern uses `React\Stream\ReadableStreamInterface` with `addListener()` callbacks.

**Recommendation:** Create `ReactInputDriver` implementing `React\Stream\ReadableStreamInterface`:

```php
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;

final class ReactInputDriver implements ReadableStreamInterface
{
    use Util\HasRemoteStreamCapability;

    private EscapeDecoder $decoder;
    private EventEmitter $ emitter;
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

### 2. No Async Event Enqueueing / Batch Decoding

`EscapeDecoder::decode()` returns `list<Event>` synchronously. In a high-throughput scenario (e.g., rapid mouse movements generating many events), there is no way to process events asynchronously or in batches without blocking the event loop.

### 3. No Support for `React\Async\Iterator` Pattern

There is no `getIterator()` implementation that could be used with `React\Async\cheduler` for coroutine-based event handling.

### 4. `stream_set_blocking()` in Read Path — `readNonBlocking` is a Misnomer

The `readNonBlocking()` method calls `stream_set_blocking($this->stream, false)` internally. The method name suggests it returns data when available without blocking, but the actual non-blocking behavior depends on this side effect. If called on a stream already in non-blocking mode, this is harmless; if called on a blocking stream, it permanently changes the stream's mode.

---

## Recommendations Summary

| Priority | Category | Item | Location |
|----------|----------|------|----------|
| **Critical** | Bug | Scroll events lose modifier information | `EscapeDecoder.php:297-308` |
| **High** | Performance | `stream_set_blocking()` called on every read | `StreamInputDriver.php:78` |
| **Medium** | Docs | Misleading Ctrl+letter comment | `EscapeDecoder.php:619-623` |
| **Medium** | Complexity | Unknown-CSI handling block too complex | `EscapeDecoder.php:472-528` |
| **Medium** | Dead Code | `KeyModifier::equals()` unused | `KeyModifier.php:159-162` |
| **Low** | Missing Tests | No FocusEvent edge case tests | `EscapeDecoderTest.php` |
| **Low** | Missing Tests | No ResizeEvent tests | `EscapeDecoderTest.php` |
| **Low** | Cleanup | `InputDriver::read()` redundant union type | `InputDriver.php:24` |
| **Feature** | Missing | ResizeEvent never generated / no SIGWINCH driver | Architecture gap |
| **Feature** | Missing | No ReactPHP `ReadableStreamInterface` | `StreamInputDriver.php` |
| **Feature** | Missing | No protocol enable/disable configuration | `EscapeDecoder.php` |
| **Feature** | Missing | No SGR 1003/1015 mouse support | `EscapeDecoder.php` |
| **Refactor** | DRY | Duplicate key name maps across methods | `EscapeDecoder.php` |
| **Refactor** | DRY | Double-mapping of SGR modifier bits | `EscapeDecoder.php:311-319` |
| **Compat** | Migration | No `sugar-readline` integration path | Architecture |
| **Compat** | Ecosystem | No `sugar-bits` integration | Architecture |

---

*End of audit.*
