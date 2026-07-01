---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-mouse

## Goal

Address all findings from the candy-mouse code review, fixing the critical state machine bug in `ZoneClickTracker`, improving code quality across `Scan`, `Scanner`, `MouseAction`, and `Mark`, and aligning with existing SugarCraft conventions (e.g., button constants from `candy-input`).

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Fix ZoneClickTracker state machine bug by adding `unset($this->pending[$btn])` before `return null` | The documented state machine says "Release on different zone → clear state, idle" but code returns null without clearing, causing phantom clicks | `ref:candy-mouse:80-84` |
| Update test `testPressOnDifferentZonesEmitsNoClick` to expect null from first release (documented behavior) | The test currently validates buggy behavior; fixing the bug requires updating the test | `ZoneClickTrackerTest.php:79-96` |
| Add explicit `$j + 1 < $len` guard in OSC terminator check | Prevents fragile reliance on PHP's `??` string offset semantics | `Scan.php:133` |
| Add button constants to `MouseAction` as class constants, matching `candy-input` convention | `candy-input` already defines `BUTTON_LEFT=0`, `BUTTON_MIDDLE=1`, `BUTTON_RIGHT=2` | `candy-input/src/Event/MouseEvent.php:21-23` |
| Add `$j + 1 < $len` guard before the OSC escape sequence terminator check | Current code uses `($rendered[$j + 1] ?? '')` which works but is fragile | `Scan.php:133` |
| Normalize sentinel constants to use UTF-8 bytes in both Mark and Scan | Scan.php uses UTF-8 bytes (`\xEE\x80\x80`); Mark.php uses Unicode escape (`\u{E000}`) — both are valid but inconsistent | `Mark.php:28-31` vs `Scan.php:24-25` |
| Add `unset($this->pending[$btn])` in the "different zone" release branch | Makes implementation match documented state machine | `ZoneClickTracker.php:82-83` |

---

## Phase 1: Critical Bug Fix — ZoneClickTracker State Machine [PENDING]

### 1.1 Fix ZoneClickTracker pending-state leak on different-zone release

**File:** `src/ZoneClickTracker.php:80-84`

**What is expected:**
- Add `unset($this->pending[$btn]);` before `return null;` in the release-on-different-zone branch (lines 82-84)
- This makes the implementation match the documented state machine: "waiting → Release on different zone → clear state, idle"

**Why:**
- The bug allows a phantom click when: (1) press at zone A, (2) release at zone B, (3) release at zone A without a new press → emits click for zone A
- The state machine documentation explicitly says the pending state should be cleared on different-zone release
- Without this fix, the library's documented contract is violated

**Severity:** Critical

**Conditions for success:**
- `vendor/bin/phpunit` passes for `ZoneClickTrackerTest`
- The scenario "press A → release B → release A" emits exactly 0 ClickResults (not 1)
- State machine documentation and code behavior are consistent

**Related code locations:**
- `src/ZoneClickTracker.php:12-18` (state machine documentation)
- `src/ZoneClickTracker.php:80-84` (the bug — returns null without unsetting pending)
- `src/ZoneClickTracker.php:67-89` (full release handling)
- `tests/ZoneClickTrackerTest.php:79-96` (`testPressOnDifferentZonesEmitsNoClick` — tests buggy behavior)

**Investigation notes:**
- The documented state machine (lines 12-18 of ZoneClickTracker.php) says: "waiting → Release on different zone → clear state, idle"
- The actual implementation at lines 80-84 returns `null` but keeps `pending[$btn]` intact
- The comment at lines 80-81 explicitly says "keep pending so the next release on the correct zone can still emit" — this is intentional but contradicts the state machine docs
- This was likely a design decision to enable "retry" on different-zone releases, but the docs don't mention this behavior
- The test `testPressOnDifferentZonesEmitsNoClick` at ZoneClickTrackerTest.php:79-96 tests the current buggy behavior and will need updating

---

### 1.2 Update testPressOnDifferentZonesEmitsNoClick to expect correct behavior

**File:** `tests/ZoneClickTrackerTest.php:79-96`

**What is expected:**
- After fixing the bug in 1.1, the first release (at zone B) still returns null, but the pending state is cleared
- The test must be updated so that a subsequent release at zone A (without a new press) returns null (not a ClickResult)
- The new scenario should be: press zone A → release zone B (null, state cleared) → release zone A (null, no click)

**Why:**
- The current test validates the buggy behavior (pending state is kept, so new press/release on zone B works)
- After fixing the bug, the test must validate the correct behavior (pending state is cleared, phantom click is prevented)

**Severity:** Critical

**Conditions for success:**
- `vendor/bin/phpunit` passes with the fix applied
- The test name remains `testPressOnDifferentZonesEmitsNoClick` but tests correct behavior

**Related code locations:**
- `tests/ZoneClickTrackerTest.php:79-96`

**Investigation notes:**
- The test currently does: press A → release B → press B → release B → expects click for B
- After fix: press A → release B (null, state cleared) → press B → release B → expects click for B
- Alternatively: press A → release B (null, state cleared) → release A (null, no phantom click)

---

## Phase 2: High Severity Fixes [PENDING]

### 2.1 Fix fragile OSC sequence terminator bounds check

**File:** `src/Scan.php:133`

**What is expected:**
Replace:
```php
if ($rendered[$j] === "\x1b" && ($rendered[$j + 1] ?? '') === '\\') { $j += 2; break; }
```
With:
```php
if ($j + 1 < $len && $rendered[$j] === "\x1b" && $rendered[$j + 1] === '\\') { $j += 2; break; }
```

**Why:**
- Currently relies on PHP's `??` operator treating undefined string offsets as `null`
- If `$rendered[$j + 1]` somehow returns a non-null non-string value, the comparison could behave unexpectedly
- The explicit `$j + 1 < $len` guard is clearer and more defensive

**Severity:** High

**Conditions for success:**
- All existing `ScanTest` tests pass
- No performance regression (the `$j + 1 < $len` check is one integer comparison)

**Related code locations:**
- `src/Scan.php:128-137` (OSC sequence handling loop)
- `src/Scan.php:133` (the fragile check)
- `tests/ScanTest.php` (all OSC-related tests should pass)

**Investigation notes:**
- The same pattern appears at line 115 for CSI sequences: `if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === '[')` — may also need the same fix
- However, the findings only flag the OSC check at line 133

---

### 2.2 Document O(n) complexity threshold and suggest spatial index strategies

**File:** `src/Scanner.php:83-86` (comment)

**What is expected:**
- Enhance the comment in `Scanner::hit()` to suggest concrete mitigation strategies
- Add a note about sorting zones by area as a simple heuristic
- Recommend a grid-based spatial index for n > 100

**Why:**
- The current comment acknowledges O(n) but provides no actionable guidance
- For TUIs with many interactive zones (tables, lists), this is a real performance concern
- Documentation helps consumers make informed architecture decisions

**Severity:** High

**Conditions for success:**
- The comment in `Scanner::hit()` provides actionable guidance
- No code changes (documentation only)

**Related code locations:**
- `src/Scanner.php:87-97` (hit method)
- `src/Scanner.php:83-85` (the O(n) comment)

---

### 2.3 Document Scan reentrancy limitation and potential thread-safety concern

**File:** `src/Scan.php` (class docstring or method docstring)

**What is expected:**
- Add a note in the class docstring or `parse()` method docstring that Scan is not reentrant
- Note that concurrent calls to `parse()` on the same instance would corrupt state
- Recommend creating a new `Scan` instance per parse pass

**Why:**
- The `$open` and `$zones` instance properties are mutated during `parse()`
- If `parse()` were called recursively or from multiple threads, state would be corrupted
- This is a known limitation; documenting it prevents misuse

**Severity:** High

**Conditions for success:**
- Documentation added to class or method docstring
- No behavioral changes

**Related code locations:**
- `src/Scan.php:28-31` (mutable instance state)
- `src/Scan.php:45-48` (parse() resets state)
- `src/Scan.php:9-18` (class docstring)

---

## Phase 3: Medium Severity Fixes [PENDING]

### 3.1 Add button constants to MouseAction

**File:** `src/MouseAction.php`

**What is expected:**
Add class constants to the `MouseAction` enum:
```php
public const BUTTON_LEFT   = 0;
public const BUTTON_MIDDLE = 1;
public const BUTTON_RIGHT  = 2;
```

**Why:**
- Button values (0, 1, 2) are hardcoded throughout the codebase without symbolic constants
- `candy-input` already defines these constants in `MouseEvent` — consistency across the SugarCraft ecosystem
- Consumers must currently know the convention or hardcode integers

**Severity:** Medium

**Conditions for success:**
- `MouseAction::BUTTON_LEFT === 0`, `MouseAction::BUTTON_MIDDLE === 1`, `MouseAction::BUTTON_RIGHT === 2`
- All existing tests pass
- No breaking changes to consumers (constants are additive)

**Related code locations:**
- `src/MouseAction.php:13-26` (current enum definition)
- `candy-input/src/Event/MouseEvent.php:21-23` (existing button constants pattern)
- `tests/ZoneClickTrackerTest.php:41` (hardcoded `0` for button)
- `tests/ZoneClickTrackerTest.php:44` (hardcoded `0` for button)
- `tests/ScannerTest.php:264, 279, 292` (hardcoded button values in MouseEvent::press calls)

---

### 3.2 Document nextGrapheme combining-character edge case

**File:** `src/Scan.php:168-174` (comment)

**What is expected:**
Add documentation explaining when `grapheme_extract` returns an empty string:
- The fallback is triggered when `grapheme_extract` returns `false` or an empty string
- This can happen with certain combining character sequences at specific offsets
- The fallback UTF-8 byte-by-byte handling is safe across all PHP versions

**Why:**
- The current code has a fallback but doesn't explain when or why it's triggered
- This helps future maintainers understand the edge case
- The fallback is tested by one edge-case test (`testNextGraphemeFallbackOnEmptyGraphemeExtractResult` at ScanTest.php:177-195)

**Severity:** Medium

**Conditions for success:**
- Documentation added above the `nextGrapheme()` method or inline with the fallback
- No behavioral changes

**Related code locations:**
- `src/Scan.php:166-184` (nextGrapheme method)
- `src/Scan.php:168-174` (the grapheme_extract logic with fallback)
- `tests/ScanTest.php:177-195` (test for the fallback)

---

### 3.3 Consider ScanIterator for streaming/chunked parsing (design note)

**File:** `src/Scan.php` (method docstring or class docstring)

**What is expected:**
- Add a design note that `ScanIterator` (implementing `IteratorAggregate`) could yield zones incrementally
- This would support streaming renderers and avoid buffering entire large outputs in memory

**Why:**
- Currently `parse()` processes the entire rendered string in one pass
- For very large outputs (large terminal buffers with many zones), this could be memory-intensive
- A streaming variant would be more compatible with ReactPHP streaming architectures

**Severity:** Medium

**Conditions for success:**
- Design note added to class docstring or as a TODO comment
- No code changes

**Related code locations:**
- `src/Scan.php:45-161` (parse method — processes entire string)

---

## Phase 4: Low Severity Fixes [PENDING]

### 4.1 Improve Scanner::hit() documentation with spatial index guidance

**File:** `src/Scanner.php:83-86` (comment — addressed in 2.2, consolidate here)

**What is expected:**
- The comment at lines 83-86 should suggest concrete mitigation strategies for the O(n) lookup

**Severity:** Low

**Conditions for success:**
- Comment improved with actionable guidance

**Related code locations:**
- `src/Scanner.php:83-86`

---

### 4.2 Extract Sentinel utility class to eliminate duplication

**Files:** `src/Mark.php:28-31` and `src/Scan.php:24-25`

**What is expected:**
- Create a `src/Sentinel.php` class (or use an enum) with `OPEN` and `CLOSE` constants
- Both `Mark` and `Scan` import from the shared `Sentinel`
- Mark uses `Sentinel::OPEN` (Unicode escape `\u{E000}`) — currently Mark uses Unicode escape, Scan uses UTF-8 bytes
- Decide on a canonical encoding (UTF-8 bytes `\xEE\x80\x80` / `\xEE\x80\x81` is more explicit and consistent with the byte-level scanning)

**Why:**
- Sentinel definitions are duplicated across Mark and Scan
- Mark uses Unicode escape (`\u{E000}`); Scan uses UTF-8 bytes (`\xEE\x80\x80`)
- These are the same codepoints expressed differently
- Extracting to a shared class ensures consistency

**Severity:** Low

**Conditions for success:**
- New `src/Sentinel.php` file created with constants
- `Mark.php` and `Scan.php` both import from `Sentinel`
- All existing tests pass (sentinel output must be identical)

**Related code locations:**
- `src/Mark.php:28-31` (currently `private const SENTINEL_OPEN = "\u{E000}"`)
- `src/Scan.php:24-25` (currently `private const SENTINEL_OPEN = "\xEE\x80\x80"`)

**Investigation notes:**
- Both represent U+E000 and U+E001 (Private Use Area codepoints)
- Mark uses the Unicode escape syntax `\u{E000}` which PHP interprets as the UTF-8 encoding at string literal parse time
- Scan uses the explicit UTF-8 bytes `\xEE\x80\x80` which is equivalent
- The actual bytes in memory are identical; this is purely a source-code representation difference

---

### 4.3 Remove or document unused repository entries in composer.json

**File:** `composer.json:39-58`

**What is expected:**
- Remove `candy-async`, `candy-ansi`, and `candy-input` from the `repositories` array (only `candy-core` is actually required)
- OR add a comment explaining why they are present (e.g., "reserved for future async integration")

**Why:**
- Only `candy-core` is in the `require` section
- The other three path repositories (`candy-async`, `candy-ansi`, `candy-input`) are listed but unused
- This is leftover from earlier design and creates confusion

**Severity:** Low

**Conditions for success:**
- `composer.json` only contains `candy-core` in repositories
- `composer validate` passes without errors

**Related code locations:**
- `composer.json:27-30` (require section — only `candy-core`)
- `composer.json:39-58` (repositories — only `candy-core` is actually used)

**Investigation notes:**
- The findings file notes that candy-async is listed in repositories but not in require, which may be leftover from an earlier design where async support was planned
- These repositories don't cause functional issues but create confusion about actual dependencies

---

## Phase 5: Missing Features (Design Notes) [PENDING]

### 5.1 Document async/ReactPHP integration gap

**What is expected:**
- Add a note in `CALIBER_LEARNINGS.md` that candy-mouse is synchronous and has no async/ReactPHP integration
- ZoneClickTracker::track() and Scanner::hit() are blocking operations
- If async TUI use cases emerge, consider an async variant

**Severity:** Low (design note)

**Related code locations:**
- `CALIBER_LEARNINGS.md` (existing file — add note)
- `src/ZoneClickTracker.php` (track method — synchronous)
- `src/Scanner.php` (hit method — synchronous)

---

### 5.2 Document spatial index gap for large zone counts

**What is expected:**
- Add a design note in `CALIBER_LEARNINGS.md` that Scanner::hit() is O(n) and a spatial index could be added for n > 100

**Severity:** Low (design note)

---

### 5.3 Document memoization opportunity for repeated scans

**What is expected:**
- Note in `CALIBER_LEARNINGS.md` that caching/memoization of scan results could avoid redundant parsing for static UIs

**Severity:** Low (design note)

---

## Phase 6: Integration & Cleanup [PENDING]

### 6.1 Run full test suite

**Command:** `cd /home/sites/sugarcraft/candy-mouse && composer install && vendor/bin/phpunit`

**Expected:**
- All tests pass
- No warnings (phpunit.xml has `failOnWarning="true"`)

**Conditions for success:**
- Exit code 0 from phpunit
- All 73 tests pass (11 ZoneClickTracker + 25 Scanner + 15 Scan + 9 MouseEvent + 13 Mark = 73)

---

### 6.2 Validate composer.json

**Command:** `cd /home/sites/sugarcraft/candy-mouse && composer validate`

**Expected:**
- No errors (note: `--strict` would flag `sugarcraft/*: "@dev"` which is expected)

**Conditions for success:**
- composer.json is valid

---

## Notes

- 2026-06-30: Implementation plan created based on candy-mouse code review findings
- Phase ordering follows severity: Critical (Phase 1) → High (Phase 2) → Medium (Phase 3) → Low (Phase 4) → Design notes (Phase 5) → Integration (Phase 6)
- The ZoneClickTracker state machine bug is the most pressing issue — fix first
- Button constants in MouseAction should follow the established pattern in `candy-input`
- Sentinel extraction (4.2) is optional but improves code organization; consider for a separate PR
