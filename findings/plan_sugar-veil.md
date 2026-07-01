# Implementation Plan: sugar-veil Library Audit Fixes

**Library:** sugarcraft/sugar-veil (port of rmhubbert/bubbletea-overlay)  
**Plan Date:** 2026-06-30  
**PHP Version Target:** ^8.3

---

## Executive Summary

This plan addresses all findings from the sugar-veil audit. The library is well-structured with excellent immutability patterns. No critical bugs or security issues were found. The plan addresses 3 medium-severity issues and 8 low-severity issues.

---

## Finding 1: MEDIUM — Duplicate Docblock on dimLine()

### What is Expected

Remove the first docblock (lines 539-544) from `src/Veil.php`, keeping only the second docblock (lines 545-561) that accurately describes the truecolor implementation.

### Why the Change Should Be Done

Two PHP docblocks on the same method describing different implementations creates confusion for developers reading the code. The first docblock describes an old "nested FAINT" approach that is no longer accurate. The second docblock correctly describes the current truecolor opacity blend implementation.

### Severity

**MEDIUM** — Code clarity/maintenance issue that could mislead developers.

### Conditions for Success

1. The `dimLine()` method has exactly one docblock after the fix
2. The remaining docblock accurately describes the truecolor implementation
3. All existing tests pass
4. The method behavior is unchanged (verified by existing tests)

### Related Code Locations

**File:** `src/Veil.php`
**Lines:** 539-561 (original)

```php
// Lines 539-544: FIRST DOCBLOCK (to be removed)
/**
 * Dim a single background line by wrapping it in SGR FAINT, repeated per the
 * backdrop opacity (0–100 → 0–3 passes). An empty line or a zero backdrop is
 * returned unchanged so the overlay footprint (empty prefix/suffix) and the
 * no-backdrop path stay free of stray escape codes.
 */

// Lines 545-561: SECOND DOCBLOCK (to be kept)
/**
 * Dim a single background line using truecolor opacity blend toward black.
 * ...
 */
```

### Investigation Notes

The `dimLine()` method (lines 562-585) uses truecolor `\e[38;2;R;G;Bm` SGR codes to blend the default terminal foreground color toward black. The first docblock incorrectly describes an old "nested FAINT" approach with 0-3 passes that is no longer implemented. The second docblock (lines 546-561) accurately describes the current truecolor implementation.

---

## Finding 2: MEDIUM — Fade Animation is Functionally a No-Op

### What is Expected

**Option A (Recommended):** Document prominently in the class-level docblock and `apply()` method that FADE is a visual placeholder and the `opacity()` method exists for external callers to implement their own visual feedback.

**Option B:** If terminal support allows, implement an alternative feedback mechanism using SGR codes that terminals DO support (e.g., underline, blink, or inverted colors as a visual cue that fade is in progress).

### Why the Change Should Be Done

The `Fade::apply()` method returns the foreground unchanged due to terminal limitations. The `opacity()` method (lines 56-66) exists and correctly calculates 0-100 opacity values using the easing function, but this value is never used by `apply()`. Users expecting visual fade feedback get nothing, which could be confusing without clear documentation.

### Severity

**MEDIUM** — User expectation mismatch. The animation appears broken even though the library correctly tracks animation progress.

### Conditions for Success

1. The Fade class has clear documentation explaining:
   - Terminal emulators don't support true alpha blending
   - `apply()` returns unchanged foreground
   - `opacity()` is available for external implementations
   - Alternative visual feedback is possible (Option B only)
2. All existing tests pass
3. A new test confirms `opacity()` is correctly calculated at various progress values

### Related Code Locations

**File:** `src/Animation/Fade.php`
**Lines:** 1-67 (entire file)

```php
// Line 9-19: Class-level docblock (needs enhancement)
/**
 * Fade animation — foreground opacity increases from 0 to 1.
 *
 * Note: True per-character alpha blending is not widely supported in
 * terminal emulators. This implementation is a best-effort that degrades
 * gracefully. The animation state is tracked but the visual effect may
 * not be visible in all terminals.
 *
 * To use the fade effect, the composite() call would need to be wrapped
 * by the caller to render at different progress values.
 */

// Lines 43-49: apply() method (returns unchanged)
/**
 * Apply fade animation to the foreground at the given progress.
 * ...
 */
public function apply(string $foreground, float $progress): string
{
    return $foreground;
}

// Lines 56-66: opacity() method (never called internally)
public function opacity(float $progress): int
{
    // ...
}
```

### Investigation Notes

The `Slide` and `Scale` animations both return modified content or offsets. The `Fade` animation is unique in that it returns content unchanged. The `opacity()` method correctly calculates opacity values (0-100) using the easing function but is never called by `apply()`. This suggests the design intent was for external callers to use `opacity()` to implement their own visual feedback.

The CALIBER_LEARNINGS.md (lines 28-32) already documents this limitation but the class-level and method-level docblocks don't fully communicate it.

---

## Finding 3: MEDIUM — isClickOutside() Returns False for Unscanned State

### What is Expected

Modify `isClickOutside()` to throw a `RuntimeException` when `lastRendered === null` (no scan data available), instead of silently returning `false`. This makes the indeterminate state explicit rather than hidden.

**Alternative:** Return a sentinel value or introduce an enum return type.

### Why the Change Should Be Done

When `lastRendered === null`, calling `isClickOutside()` returns `false`, which makes an indeterminate state appear as a definitive "inside" result. This is dangerous because:
- A click that is actually indeterminate is treated as "inside"
- If the developer forgot to call `scan()`, they won't know
- The indeterminate state should be explicitly handled, not silently converted to `false`

### Severity

**MEDIUM** — Silent failure mode that could cause incorrect UI behavior.

### Conditions for Success

1. `isClickOutside()` throws `RuntimeException` when called without prior `scan()`
2. New test verifies exception is thrown for unscanned state
3. Existing tests continue to pass (testIsClickOutsideReturnsFalseWhenManagerNotSet and testIsClickOutsideReturnsFalseWhenDismissDisabled must be updated)
4. Documentation is updated to explain the requirement to call `scan()` before `isClickOutside()`

### Related Code Locations

**File:** `src/Veil.php`
**Lines:** 316-336

```php
/**
 * Check if a mouse message is outside the veil zone.
 *
 * Requires scan($renderedOutput) to be called first with the
 * rendered veil output so the scanner knows the zone bounds.
 * Returns false if no scan data is available (nothing has been scanned yet).
 *
 * @see scan()
 * @see hit()
 */
public function isClickOutside(\SugarCraft\Core\Msg\MouseMsg $mouse): bool
{
    if (!$this->clickOutsideDismiss) {
        return false;
    }
    // If no rendered output has been scanned, cannot determine click position
    if ($this->lastRendered === null) {
        return false;  // <-- Should throw exception instead
    }
    return $this->hit($mouse->x, $mouse->y) === null;
}
```

**File:** `tests/VeilTest.php`
**Lines:** 326-337

```php
public function testIsClickOutsideReturnsFalseWhenManagerNotSet(): void
{
    $v = $this->veil->withClickOutsideDismiss(true);
    $mouse = new MouseMsg(999, 999, MouseButton::Left, MouseAction::Press);
    $this->assertFalse($v->isClickOutside($mouse));  // <-- Would need updating
}
```

### Investigation Notes

The `isClickOutside()` method has three possible return paths:
1. `clickOutsideDismiss` is false → returns false
2. `lastRendered === null` → returns false (THE PROBLEM)
3. `hit()` returns null → returns true (outside)

The problem is path 2 returns false like path 1, but path 1 is a legitimate "outside" result, while path 2 is an indeterminate state. The test at line 332-337 (`testIsClickOutsideReturnsFalseWhenManagerNotSet`) tests exactly this scenario and would need to be updated to expect an exception.

---

## Finding 4: LOW — RenderSession Accumulates State Forever

### What is Expected

Add a maximum history limit to `RenderSession` or provide a `release()` method to clear accumulated state. This prevents potential memory growth in long-running applications.

### Why the Change Should Be Done

`RenderSession` holds `previousFrame` Buffer and `previousOutput` string for the lifetime of the object. In long-running TUI applications with many frame transitions, this could accumulate memory. While the current implementation is reasonable for typical TUI usage (which has bounded frame counts), adding a safeguard is good defensive programming.

### Severity

**LOW** — Memory concern only affects edge cases with very long-running applications.

### Conditions for Success

1. Either:
   - `RenderSession::release()` method is added to clear all state, OR
   - `RenderSession` tracks frame count and auto-resets after N frames (e.g., 1000)
2. Existing tests pass
3. New test verifies the safeguard works

### Related Code Locations

**File:** `src/RenderSession.php`
**Lines:** 20-91 (entire file)

```php
final class RenderSession
{
    /** @var Buffer|null Lazily-built previous frame buffer for diff-based emission */
    private ?Buffer $previousFrame = null;

    /** @var string|null Previous full composite output (string), kept so the diff buffer can be built lazily on frame 2 */
    private ?string $previousOutput = null;

    /** @var int|null Previous output width for resize detection */
    private ?int $prevWidth = null;

    /** @var int|null Previous output height for resize detection */
    private ?int $prevHeight = null;
    // ...
}
```

### Investigation Notes

Looking at the existing code, `RenderSession::reset()` already exists (lines 85-91) and clears all state. The `Veil::resetPreviousFrame()` method (Veil.php:724-727) already calls `$this->session->reset()`. So the mechanism to release state exists, but there's no automatic safeguard for long-running apps. Adding a frame counter with auto-reset or a `release()` method would address this.

---

## Finding 5: LOW — Scanner State Persists Across Frames

### What is Expected

Document clearly that `resetPreviousFrame()` does NOT reset scanner state. The scanner mutation in `scan()` modifies the Scanner in-place via `mutate()`, so old zone data persists.

### Why the Change Should Be Done

`scan()` calls `$this->scanner->scan($rendered)` which mutates the scanner in-place, then passes the same scanner instance to `mutate()`. This means scanner state accumulates across frames in long-running applications.

### Severity

**LOW** — Documentation issue; scanner state accumulation is typically desired behavior (zones persist).

### Conditions for Success

1. `resetPreviousFrame()` docblock documents that scanner state is NOT reset
2. Consider adding a `resetScanner()` method if reset is needed
3. Existing tests pass

### Related Code Locations

**File:** `src/Veil.php`
**Lines:** 348-352

```php
public function scan(string $rendered): self
{
    $this->scanner->scan($rendered);  // Mutates scanner in-place
    return $this->mutate(scanner: $this->scanner, lastRendered: $rendered);
}
```

**File:** `src/Veil.php`
**Lines:** 724-727

```php
public function resetPreviousFrame(): void
{
    $this->session->reset();  // Only resets session, NOT scanner
}
```

### Investigation Notes

The `scan()` method mutates the scanner in-place (line 350) then passes it to `mutate()`. Since the scanner is passed by reference internally, scanner state persists across calls. This is likely intentional since zones from previous renders should be preserved for hit-testing. The `resetPreviousFrame()` only clears the session state for diff emission, not the scanner state.

---

## Finding 6: LOW — Deprecated Manager Parameter Serves No Function

### What is Expected

Either:
- **Option A:** Remove `withManager()` and `manager()` methods entirely if back-compat is not needed
- **Option B:** Add documentation explaining the preserved back-compat functionality

### Why the Change Should Be Done

The `$manager` property is stored but never used for hit-testing (hit-testing uses the self-contained `Scanner`). If this is truly dead code with no BC needed, it should be removed. If there's a reason to preserve it, it should be documented.

### Severity

**LOW** — Dead code that increases maintenance burden.

### Conditions for Success

1. If removed:
   - `withManager()` and `manager()` methods are removed from Veil.php
   - `$manager` property and constructor parameter are removed
   - Tests for manager are removed (testManagerDefaultsToNull, testWithManager, testManagerIsImmutable, testWithManagerReturnsNewInstance, testWithManagerBackCompatDoesNotThrow)
2. If preserved:
   - Documentation explains why it's kept
3. All other tests pass

### Related Code Locations

**File:** `src/Veil.php`
**Lines:** 62-63, 284-298

```php
// Lines 62-63: Property declaration
/** @var Manager|null Stored manager for back-compat only (deprecated) */
private readonly ?Manager $manager;

// Lines 277-287: withManager() method (deprecated)
/**
 * Set the zone manager for click-outside hit testing.
 *
 * @deprecated Self-contained candy-mouse Scanner replaces external Manager.
 *   A self-contained Scanner is always used for hit-testing. The Manager
 *   parameter is stored for back-compat only. Prefer scan()/hit() instead.
 */
public function withManager(Manager $manager): self
{
    return $this->mutate(manager: $manager);
}

// Lines 289-298: manager() accessor (deprecated)
/**
 * Zone manager for click-outside hit testing.
 *
 * @deprecated Self-contained candy-mouse Scanner replaces external Manager.
 *   Use the self-contained scanner via scan()/hit() instead.
 */
public function manager(): ?Manager
{
    return $this->manager;
}
```

### Investigation Notes

The `$manager` property is only stored, never read for actual hit-testing. The `isClickOutside()` method uses `hit()` which calls `$this->scanner->hit()`, not `$this->manager`. The CALIBER_LEARNINGS.md (lines 57-61) states: "Multiple veils can share the same Manager instance for shared spatial hit testing" but the actual implementation doesn't use it. The tests (VeilTest.php:411-442, 613-628) confirm it stores but doesn't use the manager.

---

## Finding 7: LOW — VeilStack::compositeAll() Comment Describes Non-Behavior

### What is Expected

Update the docblock for `compositeAll()` to accurately describe what it DOES, rather than what it does NOT do.

### Why the Change Should Be Done

The current docblock says "NOTE: This method is currently a pass-through — each veil composites the accumulated result as foreground over the original background." This describes what is NOT happening (pass-through), but doesn't explain the actual use case (per-veil positioning at fixed TOP,LEFT positions).

### Severity

**LOW** — Documentation clarity issue.

### Conditions for Success

1. `compositeAll()` docblock accurately describes the per-veil positioning behavior
2. Existing tests pass

### Related Code Locations

**File:** `src/VeilStack.php`
**Lines:** 95-106

```php
/**
 * Composite all veils at fixed TOP,LEFT positions.
 *
 * NOTE: This method is currently a pass-through — each veil composites
 * the accumulated result as foreground over the original background.
 * Since Veil stores no per-veil content, the practical effect is that
 * the original background is returned unchanged when all veils have
 * empty content. Use composite() directly for per-veil positioning.
 *
 * @param string $background The base content
 * @return string The composited output
 */
public function compositeAll(string $background): string
```

### Investigation Notes

The `compositeAll()` method actually does use `veil->vPosition()` and `veil->hPosition()` (lines 117-118) to composite each veil at its specified position. The docblock seems to be describing an older implementation or misunderstanding. The method name suggests it composites "all" veils, and it does do that at each veil's individual position.

---

## Finding 8: LOW — isClickOutside() Returns False for Unscanned State

### What is Expected

**This is the same issue as Finding 3 (MEDIUM), but marked LOW in the audit.** This appears to be a duplicate entry. See Finding 3 for the recommended fix.

### Why the Change Should Be Done

See Finding 3.

### Severity

**LOW** (inconsistent with Finding 3, likely a duplicate entry)

### Conditions for Success

See Finding 3.

### Related Code Locations

See Finding 3.

### Investigation Notes

Finding 3 and Finding 8 both reference the same issue (`isClickOutside()` returning false for unscanned state). Finding 3 is marked MEDIUM while Finding 8 is marked LOW. This is likely a duplicate in the audit. The fix should address Finding 3 which has the higher severity.

---

## Finding 9: LOW — isset() on String Offset Unusual

### What is Expected

Simplify the check at line 573 from `isset($line[0]) && $line[0] === "\e"` to `$line[0] === "\e"` after confirming non-empty, OR use a more idiomatic approach.

### Why the Change Should Be Done

`isset($line[0])` to check string boundaries is valid but unusual. A cleaner approach would be to check if the string is empty first, then check if it starts with `\e`.

### Severity

**LOW** — Code style/clarity issue.

### Conditions for Success

1. The `dimLine()` method's ANSI detection works identically after the change
2. Existing tests pass

### Related Code Locations

**File:** `src/Veil.php`
**Line:** 573

```php
if (isset($line[0]) && $line[0] === "\e" && isset($line[1]) && $line[1] === '[') {
    return $line;
}
```

### Investigation Notes

The check `isset($line[0])` is used because accessing `$line[0]` on an empty string in PHP 8.0+ generates a warning. However, looking at line 565-567, the method already checks `$line === ''` and returns early, so `$line[0]` would never be accessed on an empty string at this point. The `isset($line[0])` check is therefore redundant given the earlier empty-string guard.

---

## Recommendations Priority Summary

| Priority | Finding | Severity | File | Lines |
|----------|---------|----------|------|-------|
| 1 | Fade animation documentation | MEDIUM | src/Animation/Fade.php | 1-67 |
| 2 | isClickOutside() exception | MEDIUM | src/Veil.php | 326-336 |
| 3 | RenderSession memory safeguard | LOW | src/RenderSession.php | 20-91 |
| 4 | Duplicate docblock on dimLine() | MEDIUM | src/Veil.php | 539-561 |

---

## Implementation Notes

### Test Changes Required

1. **Finding 3:** `testIsClickOutsideReturnsFalseWhenManagerNotSet` (VeilTest.php:332-337) needs to be updated to expect an exception OR the test scenario changes

2. **Finding 6:** If manager methods are removed:
   - Remove `testManagerDefaultsToNull` (VeilTest.php:412-415)
   - Remove `testWithManager` (VeilTest.php:417-422)
   - Remove `testManagerIsImmutable` (VeilTest.php:424-432)
   - Remove `testWithManagerReturnsNewInstance` (VeilTest.php:434-442)
   - Remove `testWithManagerBackCompatDoesNotThrow` (VeilTest.php:615-621)
   - Remove `testWithManagerPreservesManager` (VeilTest.php:623-628)

3. **Finding 4:** Add new test for RenderSession frame limit or release()

### Dependencies

- All changes are internal to sugar-veil
- No new dependencies required
- No breaking changes to public API (except Finding 3 which changes exception behavior)

---

## Verification Commands

```bash
cd sugar-veil && composer install && vendor/bin/phpunit
```

All tests should pass after implementation.
