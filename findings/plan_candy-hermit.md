---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-hermit

## Goal

Address all 20 findings from the candy-hermit code review, fixing 2 critical issues, 6 high-priority issues, 6 medium-priority issues, and 6 low-priority issues, plus implementing 3 refactoring suggestions and addressing test coverage gaps.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Use flock() for FileHistory locking | PHP's LOCK_EX on file_put_contents does not prevent concurrent reads; need explicit shared/exclusive locks | `ref:ses_0e6982305ffeezcRbMlhYWYZaK` |
| Static Highlighter singleton pattern | Reusing Highlighter instance avoids allocations per highlightFuzzy call | `ref:ses_0e6982305ffeezcRbMlhYWYZaK` |
| Defensive array copy in segments()/shortcuts() | Return array_values() to prevent callers from mutating internal state | `ref:ses_0e6982305ffeezcRbMlhYWYZaK` |
| foreach loop in applyFilter | Avoids array_filter + array_values creating two intermediate arrays | `ref:ses_0e6982305ffeezcRbMlhYWYZaK` |
| MAX_FILTER_LENGTH constant | Prevents unbounded filterText growth causing performance issues | `ref:ses_0e6982305ffeezcRbMlhYWYZaK` |

## Phase 1: Critical Issues [PENDING]

### 1.1 FileHistory Concurrent Access Race Condition

**File:** `src/History/FileHistory.php:28-32` and `:42-75`

**What is expected:**
- `all()` method needs shared lock (`LOCK_SH`) around the read operation
- `append()` method needs exclusive lock (`LOCK_EX`) around the write operation using `fopen()` with `'a'` mode instead of `file_put_contents()`
- Both methods must use proper flock() semantics with LOCK_UN before fclose()

**Why the change should be done:**
The current `FILE_APPEND | LOCK_EX` only provides atomic writes for the single `file_put_contents` call. Multiple processes reading `all()` while another appends can read partial/corrupt data.

**Severity:** critical

**Conditions for success:**
- Run concurrent read/write test: multiple processes writing while one reads simultaneously should never produce corrupt data
- All existing FileHistoryTest tests pass

**Related code locations:**
- `src/History/FileHistory.php:28-32` (append method)
- `src/History/FileHistory.php:42-75` (all method)

**Investigation notes:**
```php
// Current append (line 28-32):
public function append(Item $item): void
{
    $line = \json_encode(['n' => $item->number(), 'v' => $item->value()], \JSON_THROW_ON_ERROR);
    \file_put_contents($this->path, $line . "\n", \FILE_APPEND | \LOCK_EX);
}

// Current all (line 42-75): uses fopen/fgets but no locking
```

---

### 1.2 Duplicate Docblock on applyRankedFilter

**File:** `src/Hermit.php:610-623`

**What is expected:**
Remove the duplicate docblock at lines 617-623, keeping only one copy of the documentation.

**Why the change should be done:**
Duplicate documentation creates maintenance confusion and wastes lines. The docblock appears twice consecutively for the same method.

**Severity:** critical

**Conditions for success:**
- `src/Hermit.php` should have only one docblock for `applyRankedFilter()` at lines 610-616
- All existing tests pass

**Related code locations:**
- `src/Hermit.php:610-616` (first docblock - keep)
- `src/Hermit.php:617-623` (duplicate - remove)

**Investigation notes:**
```php
/**
 * Rank items by descending candy-fuzzy score for a non-empty filter text,
 * keeping only positive-scoring items that also pass the filterFn predicate.
 * Ties break on the items' original order so ranking is stable.
 *
 * @return list<Item>
 */
/**
 * Rank items by descending candy-fuzzy score for a non-empty filter text,  <-- DUPLICATE
 * keeping only positive-scoring items that also pass the filterFn predicate.
 * Ties break on the items' original order so ranking is stable.
 *
 * @return list<Item>
 */
private function applyRankedFilter(FuzzyMatcher $ranker, string $text): array
```

---

## Phase 2: High Priority Issues [PENDING]

### 2.1 Performance: New Highlighter Instance Per highlightFuzzy Call

**File:** `src/Hermit.php:817`

**What is expected:**
Add a static `$highlighter` property and a `getHighlighter()` method to reuse a single Highlighter instance:

```php
private static ?Highlighter $highlighter = null;

private static function getHighlighter(): Highlighter {
    return self::$highlighter ??= new Highlighter();
}
```

Then change line 817 from `return (new Highlighter())->highlight(...)` to `return self::getHighlighter()->highlight(...)`.

**Why the change should be done:**
For large item lists, creating a new Highlighter object on every call allocates many short-lived objects, causing GC pressure and performance degradation.

**Severity:** high

**Conditions for success:**
- Benchmark: highlightFuzzy called 1000 times should show measurable improvement
- All existing tests pass

**Related code locations:**
- `src/Hermit.php:10` (Highlighter import)
- `src/Hermit.php:817` (highlightFuzzy method)
- `src/Hermit.php:801-821` (highlightFuzzy full method)

**Investigation notes:**
```php
// Current line 817:
return (new Highlighter())->highlight(
    $result,
    static fn(string $matched): string => $style . $matched . Ansi::reset(),
);
```

---

### 2.2 Performance: New ANSI Parser Per printableText Call

**File:** `src/Hermit.php:711-760`

**What is expected:**
Cache the parser/handler or make them reusable. The anonymous class handler and Parser are created fresh on each call to `printableText()` which is called twice per visible item (for `highlightMatches` and `highlightFuzzy`).

Options:
1. Create a static reusable parser instance
2. Cache the result if text hasn't changed
3. Use a factory pattern

Recommended: Use a static factory approach similar to Highlighter:
```php
private static ?Parser $ansiParser = null;

private static function getAnsiParser(string &$charString): Parser {
    // Reset the string reference for each use
    $charString = '';
    // Return a parser with a fresh handler
}
```

**Why the change should be done:**
Creating anonymous class + Parser on every call is expensive for large item lists. The handler pattern with string reference is reusable.

**Severity:** high

**Conditions for success:**
- Benchmark render loop performance with 1000 items
- All existing tests pass

**Related code locations:**
- `src/Hermit.php:711-760` (printableText method)
- `src/Hermit.php:507-509` (calls from View - calls printableText twice per item)

**Investigation notes:**
The anonymous class at lines 715-753 implements the Handler interface and captures `$charString` by reference. The parser at line 755 is created fresh each time.

---

### 2.3 StatusBar::segments() Returns Internal Array by Reference

**File:** `src/StatusBar.php:92-96`

**What is expected:**
Return a defensive copy to prevent callers from mutating internal state:

```php
/** @return array<string, string> */
public function segments(): array
{
    return array_values($this->segments);
}
```

**Why the change should be done:**
Despite being typed as `array` (not `&array`), callers could mutate the internal `$this->segments` array directly, breaking immutability guarantees.

**Severity:** high

**Conditions for success:**
- Verify: calling `$bar->segments()['key'] = 'value'` does not affect the original StatusBar
- All existing tests pass

**Related code locations:**
- `src/StatusBar.php:92-96` (segments method)
- `src/StatusBar.php:19-20` (segments property)

---

### 2.4 HelpBar::shortcuts() Same Issue

**File:** `src/HelpBar.php:67-71`

**What is expected:**
Same defensive copy pattern as StatusBar:

```php
/** @return array<string, string> */
public function shortcuts(): array
{
    return array_values($this->shortcuts);
}
```

**Why the change should be done:**
Same mutation risk as StatusBar::segments(). Both store internal state that could be corrupted by external mutation.

**Severity:** high

**Conditions for success:**
- Verify: calling `$bar->shortcuts()['key'] = 'value'` does not affect the original HelpBar
- All existing tests pass

**Related code locations:**
- `src/HelpBar.php:67-71` (shortcuts method)
- `src/HelpBar.php:15-16` (shortcuts property)

---

## Phase 3: Medium Priority Issues [PENDING]

### 3.1 computeWidth() Called Redundantly in View() and compositeOver()

**File:** `src/Hermit.php:476, 830`

**What is expected:**
Compute `computeWidth()` once and pass as parameter to `compositeOver()`, or cache in a local variable before passing:

In `View()`:
```php
$winWidth = $this->windowWidth > 0 ? $this->windowWidth : $this->computeWidth();
// ...
return $this->compositeOver($lines, $backgroundView, $winWidth);
```

In `compositeOver()`:
```php
private function compositeOver(array $overlayLines, string $background, int $winWidth): string
{
    // ... use passed $winWidth instead of recomputing
}
```

**Why the change should be done:**
`computeWidth()` iterates through all visible items with `itemFormatter` + `Width::of()` calls. When `$windowWidth === 0`, it runs twice per render (once in `View()` line 476, once in `compositeOver()` line 830).

**Severity:** medium

**Conditions for success:**
- All existing tests pass
- No behavioral change

**Related code locations:**
- `src/Hermit.php:476` (View - computeWidth call)
- `src/Hermit.php:830` (compositeOver - computeWidth call)
- `src/Hermit.php:692-704` (computeWidth method)

---

### 3.2 withItems Applies Filter Redundantly

**File:** `src/Hermit.php:122-129`

**What is expected:**
When `filterText` is empty (most common case), simply assign `$clone->filteredItems = $clone->allItems` directly:

```php
public function withItems(array $items): self
{
    $clone = clone $this;
    $clone->allItems = $clone->coerceItems($items);
    $clone->filteredItems = $clone->filterText === ''
        ? $clone->allItems
        : $clone->applyFilter($clone->filterText);
    $clone->cursor = 0;
    return $clone;
}
```

**Why the change should be done:**
Re-applies the filter, but if `filterText` is empty (most common case), this still creates a full copy of all items via `array_filter`. When filterText is empty, `applyFilter` returns all items with filterFn applied, which is wasteful when filterFn is identity (the default).

**Severity:** medium

**Conditions for success:**
- All existing tests pass
- Verify empty filterText path returns allItems without extra filtering overhead

**Related code locations:**
- `src/Hermit.php:122-129` (withItems method)
- `src/Hermit.php:582-608` (applyFilter method)

---

### 3.3 applyFilter Creates Multiple Intermediate Arrays

**File:** `src/Hermit.php:586-591, 597-606`

**What is expected:**
Use a simple foreach loop to collect filtered items directly, avoiding `array_filter` + `array_values` creating two arrays:

```php
private function applyFilter(string $text): array
{
    $fn = $this->filterFn;
    if ($text === '') {
        $filtered = [];
        foreach ($this->allItems as $item) {
            if ($fn($item)) {
                $filtered[] = $item;
            }
        }
        return $filtered;
    }
    // ... rest of method
}
```

**Why the change should be done:**
`array_filter()` + `array_values()` creates two arrays when one would suffice. This pattern appears multiple times in the method, adding unnecessary memory allocations.

**Severity:** medium

**Conditions for success:**
- All existing tests pass
- No behavioral change

**Related code locations:**
- `src/Hermit.php:582-608` (applyFilter method)

---

### 3.4 replaceSegment Uses Grapheme Functions Exclusively

**File:** `src/Hermit.php:845-869`

**What is expected:**
Document that grapheme functions are intentional for CJK/emoji support, OR add a fast-path for pure ASCII:

```php
private function replaceSegment(string $line, int $x, int $width, string $replacement): string
{
    // Fast path: if string is pure ASCII, use byte operations
    if (\preg_match('//u', $line) && !\preg_match('/[^\x00-\x7F]/', $line)) {
        // Use faster byte operations
    }
    // ... existing grapheme logic
}
```

OR add a docblock noting the design decision:
```php
/**
 * Uses grapheme_* functions for proper Unicode (CJK/emoji) support.
 * This adds overhead for ASCII strings but is required for terminal
 * compatibility with wide characters.
 */
```

**Why the change should be done:**
`grapheme_strlen`, `grapheme_substr` are correct for Unicode but slower than byte operations for ASCII. Most terminal output is ASCII, so adding overhead may be unnecessary.

**Severity:** medium

**Conditions for success:**
- All existing tests pass
- CJK/emoji strings still work correctly

**Related code locations:**
- `src/Hermit.php:845-869` (replaceSegment method)

---

### 3.5 Missing Input Validation in View()

**File:** `src/Hermit.php:468-548`

**What is expected:**
Add validation that `backgroundView` has enough lines for the overlay. If `yOffset + windowHeight` exceeds background line count, overlay rendering silently skips lines.

Add an assertion or clamp:
```php
// After line 474 in View():
// Validate background has enough lines
$bgLineCount = \substr_count($backgroundView, "\n") + 1;
$requiredLines = $this->yOffset + $this->windowHeight;
if ($requiredLines > $bgLineCount) {
    // Either clamp windowHeight or log a warning
    // For now, document the behavior
}
```

**Why the change should be done:**
No validation that `backgroundView` has enough lines for the overlay. If `yOffset + windowHeight` exceeds background line count, overlay rendering silently skips lines, which could cause confusing behavior.

**Severity:** medium

**Conditions for success:**
- All existing tests pass
- Edge case behavior documented

**Related code locations:**
- `src/Hermit.php:468-548` (View method)
- `src/Hermit.php:823-843` (compositeOver method)

---

### 3.6 windowWidth=0 Triggers computeWidth on Every Render

**File:** `src/Hermit.php:476, 830`

**What is expected:**
Cache computed width, invalidating only when items, formatter, or filterText changes. Add a `$computedWidthCache` property and `$widthCacheValid` flag:

```php
private int $computedWidth = 0;
private bool $widthCacheValid = false;
private int $widthCacheHash = 0; // Hash of items + formatter + filterText
```

Or simpler: compute once in View() and pass to compositeOver() (addressed in 3.1).

**Why the change should be done:**
When `windowWidth` is not explicitly set (0), `computeWidth()` runs on every `View()` call. This iterates all items, applies itemFormatter, calls `Width::of()` for each.

**Severity:** medium

**Conditions for success:**
- All existing tests pass
- Same fix as 3.1 addresses this

**Related code locations:**
- `src/Hermit.php:476` (View - computeWidth call)
- `src/Hermit.php:830` (compositeOver - computeWidth call)
- `src/Hermit.php:692-704` (computeWidth method)

---

## Phase 4: Low Priority Issues [PENDING]

### 4.1 cursorBottom Edge Case Handling

**File:** `src/Hermit.php:380-386`

**What is expected:**
Use `\max(0, \count($clone->filteredItems) - 1)` for clarity instead of separate count-1 then clamp:

```php
public function cursorBottom(): self
{
    $clone = clone $this;
    $clone->cursor = \max(0, \count($clone->filteredItems) - 1);
    return $clone;
}
```

**Why the change should be done:**
The `count-1` could be -1 for empty list, then gets clamped to 0. This works but is slightly confusing. Using `\max()` directly is clearer.

**Severity:** low

**Conditions for success:**
- All existing tests pass
- Same behavior for empty list (cursor stays at 0)

**Related code locations:**
- `src/Hermit.php:380-386` (cursorBottom method)

---

### 4.2 No Maximum Filter Text Length Enforced

**File:** `src/Hermit.php:328-335`

**What is expected:**
Add a `MAX_FILTER_LENGTH` constant (e.g., 256) and check in `type()`:

```php
private const MAX_FILTER_LENGTH = 256;

public function type(string $char): self
{
    if (\strlen($this->filterText) >= self::MAX_FILTER_LENGTH) {
        return $this; // Reject further input
    }
    $clone = clone $this;
    $clone->filterText .= $char;
    // ...
}
```

**Why the change should be done:**
`type()` appends characters without limit. Very long `filterText` could cause performance issues in `applyFilter` and render oddly.

**Severity:** low

**Conditions for success:**
- All existing tests pass
- Long filter text is rejected at 256 characters

**Related code locations:**
- `src/Hermit.php:328-335` (type method)
- `src/Hermit.php:31-32` (where constants would be added)

---

### 4.3 Missing Null Check Doc Nuance in selected()

**File:** `src/Hermit.php:407-412`

**What is expected:**
Update docblock to document that this returns null when cursor is out of bounds (empty filtered list):

```php
/**
 * Returns the currently selected item, or null if the cursor is
 * out of bounds (e.g., empty filtered list).
 */
public function selected(): ?Item
```

**Why the change should be done:**
`public function selected(): ?Item` — the docblock doesn't mention that this also returns null when cursor is out of bounds (empty filtered list).

**Severity:** low

**Conditions for success:**
- All existing tests pass
- Docblock accurately describes behavior

**Related code locations:**
- `src/Hermit.php:407-412` (selected method)

---

### 4.4 attachSigwinch Closure Capture — Potential Confusion

**File:** `src/Hermit.php:276-285`

**What is expected:**
Add a comment explaining why `$hermit` captures the original instance, not `$this`:

```php
public function attachSigwinch(): bool
{
    if ($this->onResize === null) {
        return false;
    }

    // Capture $this (the original instance) because the signal handler
    // needs access to the original's callbacks, not the cloned copy
    // returned by withOnResize(). The clone is discarded here.
    $hermit = $this;
    return SignalForwarder::attachSigwinchToFd(
        // ...
    );
}
```

**Why the change should be done:**
The signal handler captures the original `$hermit` instance (before clone), not the cloned one returned by `withOnResize`. This is intentional but could be confusing for future maintainers.

**Severity:** low

**Conditions for success:**
- All existing tests pass
- Comment added explaining the capture pattern

**Related code locations:**
- `src/Hermit.php:269-286` (attachSigwinch method)

---

### 4.5 Constants for Magic Numbers

**File:** `src/Hermit.php:703`

**What is expected:**
Define named constants explaining what the padding values represent:

```php
private const WIDTH_PAD_HEADER = 5;  // Padding between prompt+filter and edge
private const WIDTH_PAD_ITEM = 2;    // Padding between item text and edge

// In computeWidth():
return \max($promptLen + $filterLen + self::WIDTH_PAD_HEADER, $itemMax + self::WIDTH_PAD_ITEM);
```

**Why the change should be done:**
Magic numbers like `5` in `Width::of($this->prompt) + Width::of($this->filterText) + 5` and `2` in width calculations lack context.

**Severity:** low

**Conditions for success:**
- All existing tests pass
- No behavioral change

**Related code locations:**
- `src/Hermit.php:692-704` (computeWidth method)
- `src/Hermit.php:31-32` (where constants would be added)

---

## Phase 5: Refactoring Suggestions [PENDING]

### 5.1 Extract Segment Rendering to Shared Helper

**Files:** `src/StatusBar.php`, `src/HelpBar.php`

**What is expected:**
Create a `SugarCraft\Hermit\Concerns\Visible` trait or `Renderable` base class:

```php
// src/Concerns/Visible.php
namespace SugarCraft\Hermit\Concerns;

trait Visible
{
    private bool $visible = true;

    public function show(): static
    {
        $clone = clone $this;
        $clone->visible = true;
        return $clone;
    }

    public function hide(): static
    {
        $clone = clone $this;
        $clone->visible = false;
        return $clone;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }
}
```

Then use the trait in both StatusBar and HelpBar.

**Why the change should be done:**
HelpBar and StatusBar have nearly identical patterns: both store `bool $visible`, both have `show()`/`hide()` returning cloned copies, both have `isVisible()` accessor, both have `render()` that checks visibility first.

**Severity:** low

**Conditions for success:**
- All existing tests pass
- StatusBar and HelpBar both use the trait

**Related code locations:**
- `src/StatusBar.php:13-120`
- `src/HelpBar.php:13-90`
- New: `src/Concerns/Visible.php` (to be created)

---

### 5.2 Clone-and-Mutate Pattern Is Repeated Everywhere

**Files:** `src/Hermit.php`, `src/StatusBar.php`, `src/HelpBar.php`, etc.

**What is expected:**
Use the existing mutable trait pattern from `candy-core` (`SugarCraft\Core\Concerns\Mutable`) instead of repeating clone-and-mutate in every setter.

However, this is a LARGE refactoring that would touch every method. Consider whether the benefit outweighs the risk pre-1.0. For now, document as a future consideration.

**Why the change should be done:**
Every setter does: `$clone = clone $this; $clone->property = $value; return $clone;`. The `Mutable` trait in candy-core provides a standardized `mutate()` method to reduce boilerplate.

**Severity:** low

**Conditions for success:**
- This is a refactoring suggestion, not a required fix
- Document as post-1.0 consideration

**Related code locations:**
- All `with*()` methods in `src/Hermit.php`, `src/StatusBar.php`, `src/HelpBar.php`
- `candy-core/src/Concerns/Mutable.php` (existing trait)

---

### 5.3 Coercion Logic in coerceItems Could Be Extracted

**File:** `src/Hermit.php:563-572`

**What is expected:**
Extract `coerceItems()` to a standalone `ItemFactory` class to make it reusable and testable in isolation:

```php
// src/ItemFactory.php
namespace SugarCraft\Hermit;

final class ItemFactory
{
    /**
     * @param array<Item|string> $items
     * @return list<Item>
     */
    public static function coerce(array $items): array
    {
        $result = [];
        foreach (\array_values($items) as $i => $item) {
            $result[] = $item instanceof Item
                ? $item
                : new FilteredItem($i + 1, (string) $item);
        }
        return $result;
    }
}
```

**Why the change should be done:**
`coerceItems()` could be a standalone `ItemFactory` or static factory to make it reusable and testable in isolation.

**Severity:** low

**Conditions for success:**
- All existing tests pass
- Hermit::coerceItems() delegates to ItemFactory

**Related code locations:**
- `src/Hermit.php:563-572` (coerceItems method)
- New: `src/ItemFactory.php` (to be created)

---

## Phase 6: Test Coverage Gaps [PENDING]

### 6.1 Add Tests for printableText() Method

**What is expected:**
Add tests in `tests/HermitTest.php` for `printableText()`:
- Strips ANSI escape sequences
- Returns original string when no ANSI
- Handles complex ANSI sequences

### 6.2 Add Tests for replaceSegment() Edge Cases

**What is expected:**
Add tests for `replaceSegment()`:
- Empty line
- xOffset > line length
- width > replacement length
- width < replacement length (spills over)

### 6.3 Add Tests for compositeOver() Edge Cases

**What is expected:**
Add tests for `compositeOver()`:
- destY out of bounds (negative, beyond background lines)
- Empty overlay
- Overlay wider than background

### 6.4 Add Tests for Concurrent FileHistory Access

**What is expected:**
Add tests for concurrent FileHistory access:
- Multiple appenders simultaneous
- Reader during append
- This may require forking or pcntl

### 6.5 Add Tests for Highlighter Integration in highlightFuzzy()

**What is expected:**
Add explicit tests for the Highlighter usage in `highlightFuzzy()`:
- Verifies highlighting is applied to matched characters
- Verifies style is correctly wrapping matched runes

---

## Phase 7: Verification [PENDING]

### 7.1 Run Full Test Suite

**Command:**
```bash
cd /home/sites/sugarcraft/candy-hermit && composer install && vendor/bin/phpunit
```

**Expected:** All 106 tests pass with 218 assertions.

### 7.2 Validate No Regression

- Hermit::View() produces same output format
- FileHistory persists and retrieves correctly
- StatusBar and HelpBar render correctly

---

## Notes

- 2026-06-30: Plan created based on code review findings in `findings/candy-hermit.md`
- Critical issues (FileHistory race, duplicate docblock) should be addressed first
- High priority performance issues (Highlighter, Parser) require care to not break functionality
- Defensive copies in StatusBar/HelpBar are simple, safe changes
- Medium/low priority items are incremental improvements
- Test coverage gaps should be addressed alongside each fix
