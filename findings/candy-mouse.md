# candy-mouse Code Review

**Library:** `sugarcraft/candy-mouse`  
**Review scope:** `src/` (9 classes) + `tests/` (5 test files) + `composer.json` + `phpunit.xml` + `CALIBER_LEARNINGS.md` + `README.md`  
**Reviewer:** Automated Code Review  
**Date:** 2026-06-30  

---

## Summary

candy-mouse provides self-contained Mark/Scan/Get mouse hit-testing for terminal TUI applications, porting bubblezone's zone-sentinel pattern. The library is well-structured with `final` classes, `declare(strict_types=1)`, immutable value objects (`Zone`, `MouseEvent`, `ClickResult`), and a proper PSR-4 autoload. Tests are comprehensive covering happy paths, edge cases, and multi-byte/CJK character handling. The most significant issue is a state machine bug in `ZoneClickTracker` where releases on mismatched zones don't clear the pending press state, contradicting the documented state transition diagram. The library is synchronous and has no ReactPHP async integration, which is a design limitation worth noting for this ecosystem.

---

## Critical Issues

### 1. ZoneClickTracker releases on different zones do NOT clear pending state (contradicts state machine documentation)

**File:** `src/ZoneClickTracker.php:80-84`

The class-level docstring at lines 12-18 defines the state machine:
```
waiting    → Release on different zone → clear state, idle
```

However, the actual implementation at lines 80-84:
```php
// Release on a different zone — return null but keep pending
// so the next release on the correct zone can still emit.
if (!$pending['zone']->inBounds($event)) {
    return null;   // <-- does NOT unset($this->pending[$btn])
}
```

The pending state is **not cleared** when a release occurs on a different zone. The code returns `null` and keeps the old zone pending. A subsequent release (without any new press) at the original zone would incorrectly emit a phantom click.

**Scenario demonstrating the bug:**
1. Press at zone A → `pending[btn] = {zone: A}`
2. Release at zone B (different zone) → returns `null`, pending unchanged (`pending[btn] = {zone: A}`)
3. No new press occurs
4. Release at zone A → `pending['zone']->inBounds(event)` is `true` (zone A bounds), emits a **phantom click**

**Impact:** Moderate — in typical TUI usage, new presses usually precede new releases, so this edge case is rarely hit. But the bug means the state machine doesn't match the documented behavior, which could cause subtle issues in applications that handle complex mouse interactions.

**Fix:** Add `unset($this->pending[$btn]);` before the `return null` at line 83, or after it. This would make behavior match the state diagram. Note: this would break the test `testPressOnDifferentZonesEmitsNoClick` (ZoneClickTrackerTest.php:79-96) which tests the current buggy behavior.

**Confidence:** 95%

---

## High Severity Issues

### 2. Fragile array bounds access in OSC sequence terminator check

**File:** `src/Scan.php:133`

```php
if ($rendered[$j] === "\x1b" && ($rendered[$j + 1] ?? '') === '\\') { $j += 2; break; }
```

When `$j` is at position `$len - 1` (the last character of the string), `$rendered[$j + 1]` accesses offset `$len` — one past the end of the string. While PHP's `??` operator treats undefined string offsets as `null` (making `null === '\\'` evaluate to `false`), the pattern is fragile. If `$rendered[$j + 1]` somehow returns a non-null non-string value, the comparison could behave unexpectedly.

**Safe version:**
```php
if ($j + 1 < $len && $rendered[$j] === "\x1b" && $rendered[$j + 1] === '\\') { $j += 2; break; }
```

**Impact:** Low — currently works due to PHP's string offset semantics, but could break silently if PHP's behavior changes or if the code is ported to a different context.

**Confidence:** 85%

### 3. Scanner::hit() is O(n) with no spatial index for many zones

**File:** `src/Scanner.php:87-97`

```php
public function hit(int $col, int $row): ?Zone
{
    foreach ($this->zones as $zone) {   // O(n) linear scan
        if ($col >= $zone->startCol && $col <= $zone->endCol
            && $row >= $zone->startRow && $row <= $zone->endRow
        ) {
            return $zone;
        }
    }
    return null;
}
```

The comment at lines 83-86 acknowledges this: "O(n) scan of the zone list — adequate for n < 100; callers with many zones should sort by area and consider a spatial index."

For TUIs with many interactive zones (e.g., a table with many cells, a list with many items), this O(n) lookup could become a bottleneck if `hit()` is called frequently (e.g., on every mouse move event).

**Recommendations:**
1. Add an optional spatial index (e.g., a simple grid-based index partitioning the terminal into cells) for O(1) average-case lookups
2. At minimum, sort zones by area and check smaller zones first (heuristic)
3. Document the O(n) complexity and threshold recommendation

**Impact:** Performance for large zone counts (>100)

**Confidence:** 90%

### 4. Scan is not reentrant — mutable instance state

**File:** `src/Scan.php:28-31`

```php
/** @var array<string, array{int,int,int,int}> id => [startCol,startRow,maxCol,maxRow] */
private array $open = [];

/** @var array<string, Zone> */
private array $zones = [];
```

These instance properties are mutated during `parse()`. While `parse()` resets them at lines 47-48, the class is not reentrant: if `parse()` is called recursively or from multiple threads concurrently, the state would be corrupted.

**Alternative design:** A pure static `Scan::parse(string, ?int): array` that returns all zones without mutating instance state, combined with a `ScanResults` object that can be queried. This would make the parsing reentrant and thread-safe.

**Impact:** Limitations on concurrent/reentrant use cases

**Confidence:** 80%

---

## Medium Severity Issues

### 5. MouseAction enum lacks button constants

**File:** `src/MouseAction.php:13-26`

```php
enum MouseAction: string
{
    case Press = 'press';
    case Release = 'release';
    case Drag = 'drag';
    case Scroll = 'scroll';
}
```

Button values (0, 1, 2) are hardcoded throughout the codebase without symbolic constants. The convention is:
- Button 0 = left click
- Button 1 = middle click  
- Button 2 = right click

But these are never named. Consumers must either know the convention or hardcode integers.

**Recommendation:** Add button constants to the enum or as class constants:
```php
public const BUTTON_LEFT   = 0;
public const BUTTON_MIDDLE = 1;
public const BUTTON_RIGHT  = 2;
```

**Impact:** API usability — forces consumers to use magic numbers

**Confidence:** 90%

### 6. nextGrapheme fallback path is triggered by an undocumented edge case

**File:** `src/Scan.php:168-174`

```php
if (function_exists('grapheme_extract')) {
    $next = 0;
    $cluster = grapheme_extract($s, 1, GRAPHEME_EXTR_COUNT, $i, $next);
    if (is_string($cluster) && $cluster !== '') {
        return $cluster;
    }
}
// Fallthrough: grapheme_extract returned false or empty string
```

When `grapheme_extract` returns an empty string (which can happen with certain combining character sequences at specific offsets), the code falls through to the simple UTF-8 byte-by-byte fallback. This fallback path works but is only tested by one edge-case test (`testNextGraphemeFallbackOnEmptyGraphemeExtractResult` at ScanTest.php:177-195).

**Recommendation:** Document the combining-character edge case in the code, and ensure the fallback is well-tested across PHP versions.

**Impact:** Potential edge-case inconsistency across PHP versions

**Confidence:** 75%

### 7. No streaming or chunked parsing for large inputs

**File:** `src/Scan.php:45-161`

The `parse()` method processes the entire rendered string in one pass, holding all state in memory. For very large rendered outputs (e.g., a large terminal buffer with many zones), this could be memory-intensive.

**Recommendation:** Consider a `ScanIterator` class that implements `IteratorAggregate` or yields zones as they are discovered, allowing incremental scanning of streamed output.

**Impact:** Memory usage for large outputs, inability to scan streaming content

**Confidence:** 60%

---

## Low Severity Issues

### 8. Scanner::hit() documentation does not suggest spatial indexing alternatives

**File:** `src/Scanner.php:83-85` (comment)

The comment acknowledges O(n) performance but doesn't suggest any mitigation strategies beyond "consider a spatial index" without implementation guidance.

### 9. Duplicate `$col - 1` pattern for end-of-line column accounting

**File:** `src/Scan.php:88` and `src/Scan.php:145`

Both compute `col - 1` as the last column of the previous content. This is not a bug, just a DRY observation.

### 10. Unused repository entries in composer.json

**File:** `composer.json:39-58`

```json
"repositories": [
    {"type": "path", "url": "../candy-core", ...},
    {"type": "path", "url": "../candy-async", ...},   // not in require
    {"type": "path", "url": "../candy-ansi", ...},    // not in require
    {"type": "path", "url": "../candy-input", ...}   // not in require
]
```

Only `candy-core` is in the `require` section. The other three path repositories (`candy-async`, `candy-ansi`, `candy-input`) are listed but not required.

**Recommendation:** Remove unused repository entries or add a comment explaining why they are present.

---

## Missing Features

### 1. No async/ReactPHP integration

candy-mouse is entirely synchronous. The `ZoneClickTracker::track()` method and `Scanner::hit()` are blocking operations. In a ReactPHP TUI ecosystem where events arrive asynchronously via an event loop, the library provides no async-friendly APIs.

### 2. No spatial index for Scanner with many zones

The O(n) hit testing is the most significant performance limitation for large zone counts. A grid-based spatial index or R-tree would provide O(1) average-case lookups.

### 3. No button constants in MouseAction

Described in issue #5 above.

### 4. No cache for repeated scans of same content

If the same rendered string is scanned multiple times (e.g., a static UI that doesn't change but is redrawn), the parsing is repeated from scratch. A simple memoization wrapper could avoid redundant parsing.

### 5. No event-based ZoneClickTracker variant

The current `ZoneClickTracker` is pull-based: the consumer calls `track()` on each mouse event. An push-based alternative that accepts a stream of mouse events and emits click results via a callback or Promise would be more ergonomic in async contexts.

---

## Duplicated Logic / Refactoring Opportunities

### 1. Sentinel definitions are duplicated across Mark and Scan

**File:** `src/Mark.php:28-31` vs `src/Scan.php:24-25`

**Mark.php:**
```php
private const SENTINEL_OPEN = "\u{E000}";
private const SENTINEL_CLOSE = "\u{E001}";
```

**Scan.php:**
```php
private const SENTINEL_OPEN  = "\xEE\x80\x80";
private const SENTINEL_CLOSE = "\xEE\x80\x81";
```

These are the same codepoints expressed differently (Unicode escape vs UTF-8 bytes). If a `Sentinel` utility class were extracted, both could import from it, ensuring consistency.

### 2. Escape sequence (CSI/OSC) handling could be extracted

**File:** `src/Scan.php:115-138`

The CSI and OSC pass-through logic is specific to the scanner's column-tracking state machine. While not easily extractable to a general utility (it depends on `$col` and `$row` state), it could be encapsulated in a private helper method like `skipEscapeSequence()` for readability.

---

## Compatibility Issues

### 1. candy-async in repositories but not in require

**File:** `composer.json:41-45`

`candy-async` is listed as a path repository but is not in the `require` section. This means candy-mouse does not actually depend on candy-async at runtime. The repository entry may be leftover from an earlier design.

### 2. No known compatibility issues with other SugarCraft libs

The library uses only `candy-core` (for `Width::string()`) and has no other external dependencies. It is a standalone library that other SugarCraft components can consume without conflict.

---

## Async Pattern Improvements

### 1. ZoneClickTracker is purely synchronous

The `track()` method processes events immediately and returns results synchronously. In an async TUI (e.g., one using `candy-async` event loops), mouse events arrive asynchronously, but the tracker must still be called synchronously to update state.

**Potential async alternative:**
```php
// Pseudocode for a reactive variant
$tracker->onClick(function (ClickResult $result) {
    // handle click asynchronously
});
$tracker->push($event); // or await $tracker->trackAsync($event)
```

### 2. Scanner::scan() is blocking and memory-intensive

For streaming renderers (e.g., those using ReactPHP's streaming output), the entire rendered string must be buffered before `scan()` can process it. An `AsyncIterator`-based scanner that yields zones as they are discovered would be more compatible with streaming architectures.

### 3. No Promise or coroutine support

candy-mouse has no async patterns at all — no `Generator`-based coroutines, no `React\Promise`, no async iterators. If the library were to integrate with the ReactPHP ecosystem more deeply, consider making `Scanner::scan()` return a `Generator` that can be consumed incrementally.

---

## Recommendations Summary (priority table)

| Priority | Issue                                                                                | Location                         | Recommendation                                                                                                                                                   |
| -------- | ------------------------------------------------------------------------------------ | -------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **HIGH**     | ZoneClickTracker state machine bug — releases on different zones don't clear pending | `ZoneClickTracker.php:82-83`       | Add `unset($this->pending[$btn])` before `return null`; update test `testPressOnDifferentZonesEmitsNoClick` to expect null when release follows different-zone release |
| **HIGH**     | Scanner::hit() O(n) linear scan for zone lookup                                      | `Scanner.php:87-97`                | Add spatial index or at minimum document O(n) threshold; consider sorting zones by area                                                                          |
| **MEDIUM**   | Scan not reentrant — mutable instance state                                          | `Scan.php:28-31`                   | Consider immutable static parse or re-entrant design for concurrent use                                                                                          |
| **MEDIUM**   | MouseAction lacks button constants                                                   | `MouseAction.php:13-26`            | Add `BUTTON_LEFT=0`, `BUTTON_MIDDLE=1`, `BUTTON_RIGHT=2` constants                                                                                             |
| **LOW**      | Fragile OSC bounds check                                                             | `Scan.php:133`                     | Add explicit `$j + 1 < $len` guard                                                                                                                             |
| **LOW**      | nextGrapheme combining-character edge case not well documented                       | `Scan.php:168-174`                 | Document when grapheme_extract returns empty string                                                                                                              |
| **LOW**      | No streaming/chunked parsing for large inputs                                        | `Scan.php`                         | Consider `ScanIterator` implementing `IteratorAggregate`                                                                                                          |
| **LOW**      | Unused repository entries in composer.json                                           | `composer.json:41-58`              | Remove or document why candy-async/ansi/input are present                                                                                                      |
| **LOW**      | No async/ReactPHP integration                                                        | Library-wide                     | Consider async event handling patterns if async TUI use cases emerge                                                                                              |
| **LOW**      | Sentinel definitions duplicated (Unicode vs UTF-8)                                   | `Mark.php:28-31` vs `Scan.php:24-25` | Extract to shared `Sentinel` class                                                                                                                               |

---

## Test Coverage Assessment

The test suite is comprehensive:
- `ZoneClickTrackerTest.php`: 11 tests covering all state machine transitions including multi-button, drag, scroll, inline zone form, setPressZone form, and edge cases
- `ScannerTest.php`: 25 tests covering zone discovery, hit testing, CJK wide chars, CSI/OSC pass-through, prefixed lookups, clear, and edge cases
- `ScanTest.php`: 15 tests covering parsing, CJK, escape sequences, combining characters, width clamping, and edge cases
- `MouseEventTest.php`: 9 tests covering factory methods and default values
- `MarkTest.php`: 13 tests covering sentinel insertion, static shortcut, round-trip via Scanner, and enabled/disabled states

**Coverage gaps:**
1. No integration tests with real terminal mouse event sequences
2. No performance tests for Scanner::hit() with large zone counts
3. No concurrency/reentrancy tests for Scan
4. No async behavior tests

---

## Conclusion

candy-mouse is a well-implemented, well-tested library with a clean API. The critical bug in `ZoneClickTracker` is the most pressing issue — it causes the implementation to diverge from its own documented state machine. The other issues are mostly medium-to-low severity: performance limitations for large zone counts, lack of async integration, and minor API usability gaps. The code quality is generally high with proper use of `final`, immutability, strict typing, and comprehensive docstrings.
