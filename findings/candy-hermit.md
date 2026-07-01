# Code Review Report: candy-hermit

**Library:** candy-hermit  
**Type:** Fuzzy finder / quick-fix overlay for terminal UIs  
**Upstream:** Genekkion/theHermit  
**Location:** `/home/sites/sugarcraft/candy-hermit`  
**Test Coverage:** 106 tests, 218 assertions  
**Overall Status:** Generally well-structured; addresses specific issues below

---

## Table of Contents

1. [Critical Issues](#critical-issues)
2. [High Priority Issues](#high-priority-issues)
3. [Medium Priority Issues](#medium-priority-issues)
4. [Low Priority Issues](#low-priority-issues)
5. [Refactoring Suggestions](#refactoring-suggestions)
6. [Compatibility with Other SugarCraft Libs](#compatibility-with-other-sugarcraft-libs)
7. [Async Patterns](#async-patterns-reactphp-ecosystem)
8. [Missing Features](#missing-features)
9. [Test Coverage Gaps](#test-coverage-gaps)
10. [Positive Findings](#positive-findings)

---

## Critical Issues

### 1. FileHistory Concurrent Access Race Condition

**File:** `src/History/FileHistory.php:28-32`

The `append()` method uses `FILE_APPEND | LOCK_EX` which only provides atomic writes for the single `file_put_contents` call. If multiple processes read (`all()`) while another appends, data corruption can occur.

**Problem Details:**
- `append()` uses `FILE_APPEND | LOCK_EX` — atomic for the write, but does not prevent concurrent reads
- `all()` has no file locking whatsoever — concurrent reads during a write can read partial data

**Recommended Fix:**

Wrap `all()` reads in `flock()` shared lock and `append()` in `flock()` exclusive lock, or use `fopen()` with `'c'` mode and proper locking:

```php
// all() - add shared lock
public function all(): array {
    $handle = fopen($this->path, 'r');
    flock($handle, LOCK_SH);
    // ... read and parse ...
    flock($handle, LOCK_UN);
    fclose($handle);
    return $items;
}

// append() - already has LOCK_EX but should also be exclusive locked
public function append(string $entry): void {
    $handle = fopen($this->path, 'a');
    flock($handle, LOCK_EX);
    fwrite($handle, $entry . PHP_EOL);
    flock($handle, LOCK_UN);
    fclose($handle);
}
```

---

### 2. Duplicate Docblock on applyRankedFilter

**File:** `src/Hermit.php:610-623`

Lines 610-615 and 617-623 contain identical docblocks for the same method. This is duplicate documentation that should be removed.

**Recommended Fix:**

Remove the duplicate docblock at lines 617-623.

---

## High Priority Issues

### 3. Performance: New Highlighter Instance Per highlightFuzzy Call

**File:** `src/Hermit.php:817`

```php
(new Highlighter())->highlight(...)
```

Creates a new Highlighter object on every call. For large item lists, this allocates many short-lived objects.

**Recommended Fix:**

Make Highlighter a static reusable instance or inject it as a dependency:

```php
private static ?Highlighter $highlighter = null;

private static function getHighlighter(): Highlighter {
    return self::$highlighter ??= new Highlighter();
}
```

---

### 4. Performance: New ANSI Parser Per printableText Call

**File:** `src/Hermit.php:715-757`

`printableText()` creates a new anonymous class handler + new Parser on every call. This happens twice per visible item in the render loop (for `highlightMatches` and `highlightFuzzy`).

**Recommended Fix:**

Consider caching the ANSI-stripped text, or use a static factory for the parser.

---

### 5. StatusBar::segments() Returns Internal Array by Reference

**File:** `src/StatusBar.php:93-96`

```php
public function segments(): array
```

Returns `$this->segments` directly. Despite being typed as `array` (not `&array`), callers could mutate the internal state.

**Recommended Fix:**

Return a defensive copy to prevent mutation:

```php
public function segments(): array {
    return array_values($this->segments);
}
```

---

### 6. HelpBar::shortcuts() Same Issue

**File:** `src/HelpBar.php:68-71`

Same pattern as `StatusBar::segments()`.

**Recommended Fix:**

Same as above.

---

## Medium Priority Issues

### 7. computeWidth() Called Redundantly in View() and compositeOver()

**File:** `src/Hermit.php:476, 830`

`computeWidth()` is called twice per render when `$windowWidth === 0` — once in `View()` and once in `compositeOver()`. `computeWidth()` iterates through all visible items with `itemFormatter` + `Width::of()` calls.

**Recommended Fix:**

Compute once and pass as parameter, or cache in a local variable.

---

### 8. withItems Applies Filter Redundantly

**File:** `src/Hermit.php:126`

```php
$clone->filteredItems = $clone->applyFilter($clone->filterText);
```

Re-applies the filter, but if `filterText` is empty (most common case), this still creates a full copy of all items via `array_filter`.

**Recommended Fix:**

When `filterText` is empty, simply assign `$clone->filteredItems = $clone->allItems` directly.

---

### 9. applyFilter Creates Multiple Intermediate Arrays

**File:** `src/Hermit.php:586-591, 597-606`

`array_filter()` + `array_values()` creates two arrays when one would suffice. This pattern appears multiple times.

**Recommended Fix:**

Use a simple foreach loop to collect filtered items directly:

```php
$filtered = [];
foreach ($items as $item) {
    if ($this->matches($item, $filterText)) {
        $filtered[] = $item;
    }
}
return $filtered;
```

---

### 10. replaceSegment Uses Grapheme Functions Exclusively

**File:** `src/Hermit.php:845-869`

`grapheme_strlen`, `grapheme_substr` are correct for Unicode but slower than byte operations for ASCII. Most terminal output is ASCII, so this adds overhead.

**Recommended Fix:**

Consider fast-path for pure ASCII strings, or document that this is intentional for CJK/emoji support.

---

### 11. Missing Input Validation in View()

**File:** `src/Hermit.php:468-548`

No validation that `backgroundView` has enough lines for the overlay. If `yOffset + windowHeight` exceeds background line count, overlay rendering silently skips lines.

**Recommended Fix:**

Add assertion or clamp to prevent undefined behavior.

---

### 12. windowWidth=0 Triggers computeWidth on Every Render

**File:** `src/Hermit.php:476, 830`

When `windowWidth` is not explicitly set (0), `computeWidth()` runs on every `View()` call. This iterates all items, applies itemFormatter, calls `Width::of()` for each.

**Recommended Fix:**

Cache computed width, invalidate only when items, formatter, or filterText changes.

---

## Low Priority Issues

### 13. cursorBottom Edge Case Handling

**File:** `src/Hermit.php:383-385`

```php
$clone->cursor = \count($clone->filteredItems) - 1;
if ($clone->cursor < 0) $clone->cursor = 0;
```

The `count-1` could be -1 for empty list, then gets clamped to 0. This works but is slightly confusing.

**Suggested Fix:**

Use `\max(0, \count($clone->filteredItems) - 1)` for clarity.

---

### 14. No Maximum Filter Text Length Enforced

**File:** `src/Hermit.php:331`

`type()` appends characters without limit. Very long `filterText` could cause performance issues in `applyFilter`.

**Recommended Fix:**

Add a `MAX_FILTER_LENGTH` constant and check in `type()`.

---

### 15. Missing Null Check Doc Nuance in selected()

**File:** `src/Hermit.php:407-412`

`public function selected(): ?Item` — the docblock doesn't mention that this also returns null when cursor is out of bounds (empty filtered list).

**Recommended Fix:**

Document this edge case behavior.

---

### 16. attachSigwinch Closure Capture — Potential Confusion

**File:** `src/Hermit.php:276-285`

The signal handler captures the original `$hermit` instance (before clone), not the cloned one returned by `withOnResize`. This is intentional (the original holds the callback), but could be confusing for future maintainers.

**Recommended Fix:**

Add a comment explaining why `$hermit` captures the original instance, not `$this`.

---

### 17. Constants for Magic Numbers

**Files:** `src/Hermit.php:703`, tests

Magic numbers like `5` in `Width::of($this->prompt) + Width::of($this->filterText) + 5` and `2` in width calculations lack context.

**Recommended Fix:**

Define named constants explaining what these padding values represent.

---

## Refactoring Suggestions

### 18. Extract Segment Rendering to Shared Helper

HelpBar and StatusBar have nearly identical patterns:
- Both store `bool $visible`
- Both have `show()` / `hide()` returning cloned copies
- Both have `isVisible()` accessor
- Both have `render()` that checks visibility first

**Suggestion:**

Extract a `SugarCraft\Hermit\Concerns\Visible` trait or a base `Renderable` class.

---

### 19. Clone-and-Mutate Pattern Is Repeated Everywhere

Every setter does: `$clone = clone $this; $clone->property = $value; return $clone;`

**Suggestion:**

Use the existing mutable trait pattern from `candy-core` (`SugarCraft\Core\Concerns\Mutable`).

---

### 20. Coercion Logic in coerceItems Could Be Extracted

**File:** `src/Hermit.php:563-572`

`coerceItems()` could be a standalone `ItemFactory` or static factory to make it reusable and testable in isolation.

---

## Compatibility with Other SugarCraft Libs

### Compatible

- Uses `SugarCraft\Fuzzy\FuzzyMatcher` interface correctly
- Uses `SugarCraft\Sprinkles\Border` and `Style` composition correctly
- Uses `SugarCraft\Pty\SignalForwarder` correctly
- Uses `SugarCraft\Core\Util\Width`, `Tty`, `Ansi` correctly
- `composer.json` has correct path repos for all sugarcraft dependencies
- Follows the immutable+fluent `with*()` pattern matching other libs

### Minor Concerns

- `FuzzyMatcher` interface contract: if `matchAll()` returns inconsistent results with `match()`, the fallback at lines 669-680 kicks in. This is defensive but adds complexity.
- The library relies on `candy-ansi` Parser which may have different parsing behavior than expected for complex ANSI sequences.

---

## Async Patterns (ReactPHP Ecosystem)

### Current State

The library is synchronous/rendering-focused, which is appropriate for a TUI component. `attachSigwinch()` uses pcntl signals via SignalForwarder, which works with ReactPHP's event loop. No direct use of ReactPHP Promises or async patterns.

### Improvement Suggestions

- Consider adding a `renderAsync(Stream $eventLoop): Promise<string>` variant that could defer rendering to a later tick.
- For very large item lists (1000+), filtering could be done in a coroutine to avoid blocking the main loop.
- The Highlighter and ANSI Parser instantiation could use a reusable object pool pattern.

---

## Missing Features

| # | Feature | Description |
|---|---------|-------------|
| 1 | Programmatic selection | No `selectItem(int $index)` or `selectItemByValue(string $value)` |
| 2 | Keyboard event handler | Consumer must wire keystrokes to Hermit methods manually; a `handleKey(string $key): self` would simplify integration |
| 3 | Item search/filter callback | `setFilterFn` is predicate-only; no way to transform items during filter |
| 4 | Scrolling API | Can't programmatically scroll the viewport without moving cursor |
| 5 | Separator customization | Separator is hardcoded as `─` (U+2500 Box Drawings Light Horizontal) |
| 6 | Header toggle | No way to disable the header line |
| 7 | Maximum items configuration | All items always kept in memory; no virtual scrolling or pagination |

---

## Test Coverage Gaps

The following scenarios are not tested in isolation:

1. **`printableText()` method** — not tested in isolation
2. **`replaceSegment()` edge cases** — empty line, xOffset > line length, etc.
3. **`compositeOver()` edge cases** — destY out of bounds, etc.
4. **Concurrent FileHistory access** — not tested
5. **Highlighter integration in `highlightFuzzy()`** — not explicitly tested

---

## Positive Findings

| # | Finding |
|---|---------|
| 1 | **Good test coverage:** 106 tests with 218 assertions — comprehensive coverage |
| 2 | **Correct immutable+fluent pattern:** All mutation methods return new instances |
| 3 | **Proper interface segregation:** Item interface enables custom implementations |
| 4 | **Clean separation of concerns:** View rendering, state management, and persistence are separate |
| 5 | **Good documentation:** CALIBER_LEARNINGS captures important patterns |
| 6 | **Width-aware rendering:** Correctly handles CJK and emoji in terminal output |
| 7 | **Defensive programming:** Fallback TTY size, exception handling in `ttySize()` |
| 8 | **No security issues:** No user input is executed, no SQL, no file inclusions beyond intended FileHistory |

---

## Summary

candy-hermit is a well-structured fuzzy finder overlay component that correctly implements immutable+fluent patterns with clone-on-mutate semantics. The code is generally clean and 106 tests pass. The critical issues to address are the FileHistory race condition and the duplicate docblock. High priority issues center on performance optimizations (reusing Highlighter and Parser instances) and defensive copies for `segments()`/`shortcuts()`. Multiple medium and low priority items offer incremental improvements. The library integrates well with other SugarCraft components and follows project conventions.

---

*Report generated from audit findings. Total issues: 20 (2 critical, 6 high, 6 medium, 6 low).*
