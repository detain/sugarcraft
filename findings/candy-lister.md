# Code Review: candy-lister

**Library:** sugarcraft/candy-lister (PHP port of treilik/bubblelister)  
**Review date:** 2026-06-29  
**Files reviewed:** src/Model.php, src/FuzzyMatch.php, src/DefaultPrefixer.php, src/DefaultSuffixer.php, src/Item.php, src/StringItem.php, src/Prefixer.php, src/Suffixer.php, src/FilterState.php, src/Lang.php, tests/*.php, examples/*.php, lang/en.php

---

## Critical Issues

### 1. Exception swallowing in `View()` silently masks render failures
**File:** `src/Model.php:474-476`

```php
} catch (\RuntimeException $e) {
    return $e->getMessage() . "\n";
}
```

`View()` catches only `\RuntimeException` and returns the message as a string. Any `\Error` (e.g. `TypeError`, `ArgumentCountError`) thrown by `bufferFromOutput()` or `DiffEncoder::encode()` propagates uncaught. More critically, callers cannot distinguish a successful render from a failed one — both return a string. The caught exceptions from `lines()` produce error messages like `"NoItems: list has no items\n"` rather than failing loudly.

**Recommendation:** Catch `\Throwable` instead of `\RuntimeException`, or re-throw after logging. Consider a dedicated result type instead of string-for-error.

---

### 2. `FilterState::filtered` is a dead enum case
**File:** `src/FilterState.php:25`

The `filtered` case is documented in the README's state-transition table (`filtering → filtered`) but is never assigned anywhere in the codebase. The only values ever set are `filtering` (`Model::withFilterFn()` at line 185) and `unfiltered` (`Model::withoutFilter()` at line 213). This makes the `filtered` state unreachable and the enum misleading.

**Recommendation:** Either remove `FilterState::filtered`, or implement it (e.g., a `markFiltered()` call after `withFilterFn()` completes its work).

---

### 3. `lineOffset` is a public property with no fluent setter
**File:** `src/Model.php:46`

`$lineOffset` is declared public and passed to `initPrefixer()`/`initSuffixer()`, but there is no `setLineOffset()` method. Users must directly mutate `$model->lineOffset`, inconsistent with every other configurable property (`setWidth()`, `setHeight()`, `setCursorOffset()`, `setWrap()`, etc.) which are all fluent. The property defaults to 5 and is passed to prefixer/suffixer but its semantic effect is unclear.

**Recommendation:** Add a fluent `setLineOffset(int $n): self` method, or remove the property if it is not semantically meaningful.

---

### 4. `View()` only catches `RuntimeException`, not `Error`
**File:** `src/Model.php:474`

If `bufferFromOutput()` or `DiffEncoder::encode()` throws an `\Error` (e.g., from type violations), it is not caught. Only `\RuntimeException` is caught. This creates inconsistent error handling where some failures propagate and others are silently converted to strings.

**Recommendation:** Catch `\Throwable` at line 474, or at minimum document why only `RuntimeException` is caught.

---

## Performance Issues

### 5. `sort()` uses O(n) linear search to relocate cursor after sorting
**File:** `src/Model.php:269-281`

```php
$selected = $this->items[$this->cursorIndex] ?? null;
// ... usort($items, ...) ...
if ($selected !== null) {
    foreach ($items as $i => $item) {
        if ($item === $selected) {
            $cursorIndex = $i;
            break;
        }
    }
}
```

After `usort` (O(n log n)), a separate O(n) linear scan finds the cursor item by object identity (`===`). For large lists this is redundant — a map from `Item::$id` to new index would make this O(1).

**Recommendation:** Build an `array_flip` map of `Item::$id => newIndex` after sorting, then look up in O(1).

---

### 6. `splitOverWidth()` splits by byte count, not grapheme/character count
**File:** `src/Model.php:593-600`

```php
$len = \strlen($word);
for ($i = 0; $i < $len; $i += $maxWidth) {
    $chunks[] = \substr($word, $i, $maxWidth);
}
```

Uses `strlen()` and `substr()` which operate on bytes, not Unicode graphemes. For UTF-8 strings containing multi-byte characters (e.g., CJK, emoji), this splits mid-character, producing invalid output. This inconsistency is notable because `Model::hardWrap()` uses `preg_split('/\s+/u', ...)` (with the `u` flag) which is UTF-8 aware.

**Recommendation:** Use `Width::string()` to measure display width and split at grapheme boundaries using `grapheme_extract()` or `mb_substr()`.

---

### 7. No negative input validation on setters
**File:** `src/Model.php:127-169`

Setters like `setWidth()`, `setHeight()`, `setCursorOffset()`, `setWrap()` accept any `int`, including negative values. `lines()` at line 376 checks for zero (`$this->width <= 0 || $this->height <= 0`) but negative values would pass that check and cause issues in loops and array indexing.

**Recommendation:** Add validation (e.g., `\max(1, $width)` clamping or explicit guards) in setters or at the top of `lines()`.

---

## Security Issues

### 8. No sanitization of item values — ANSI injection possible
**File:** `src/Model.php:513`

```php
$rawLines = $this->hardWrap((string) $item->value, $contentWidth);
```

Item values are cast to string and rendered verbatim. If items contain ANSI escape sequences (e.g., `\x1b[2J` to clear the screen) from untrusted sources (database, user input, network), they are embedded directly into the output. The README acknowledges this ("sanitize it before adding to the model") but there is no built-in protection.

**Recommendation:** Consider stripping or validating ANSI sequences in item values. At minimum, document the requirement prominently and provide a helper method.

---

## Design / API Issues

### 9. `lessFunc` and `equalsFunc` are set directly on the model, not via fluent setters
**File:** `src/Model.php:49-52`

All other configuration uses fluent setters (`setWidth()`, `setPrefixer()`, etc.). These two closures are assigned directly (`$model->lessFunc = fn(...)`), breaking the fluent API pattern. There is no `setLessFunc()` / `setEqualsFunc()`.

**Recommendation:** Add `setLessFunc(\Closure $fn): self` and `setEqualsFunc(\Closure $fn): self` for API consistency.

---

### 10. `withFilterFn()` doesn't use `mutate()` — inconsistent mutation pattern
**File:** `src/Model.php:181-198`

All other state-changing methods use the `mutate()` helper. `withFilterFn()` does its own `clone $this` and direct property mutation inline. This is inconsistent with the established pattern established by `setWidth()`, `addItem()`, `removeItem()`, etc.

**Recommendation:** Refactor `withFilterFn()` to use `mutate()` internally, or extract a `filterItems()` private method that `withFilterFn()` calls via `mutate()`.

---

### 11. `withoutFilter()` has an inconsistent early-return guard
**File:** `src/Model.php:208-210`

```php
if ($this->filterFn === null) {
    return $this;
}
```

Returns `$this` directly (not via `mutate()`), inconsistent with the rest of the API. The early return is correct but the pattern differs.

**Recommendation:** Use `mutate()` for the guard clause too, or document why direct `$this` return is intentional here.

---

### 12. `sort()` mutates items via `usort` then wraps in `mutate()`
**File:** `src/Model.php:264-284`

```php
$items = $this->items;  // local copy
\usort($items, fn(Item $a, Item $b) => ...);
return $this->mutate(fn($m) => [$m->items, $m->cursorIndex] = [$items, $cursorIndex]);
```

The mutation pattern here is confusing: `usort` works on a local copy, then `mutate()` assigns it back. This is not truly "immutable" since the `$items` array is sorted in-place before being wrapped. The result is correct but the pattern differs from the simpler `addItem()` approach which just pushes into the cloned array inside `mutate()`.

**Recommendation:** Consider a more uniform pattern: build a new items array via `array_map` or `usort` on the clone, then assign inside `mutate()`.

---

### 13. `cursorItem()` throws but `View()` catches and returns the message
**File:** `src/Model.php:302-308`, `src/Model.php:450-476`

`cursorItem()` throws `\RuntimeException` on empty list. But `View()` catches `\RuntimeException` and returns the message string. This means:
- `$model->cursorItem()` → throws on empty
- `$model->View()` on empty model → returns `"NoItems: list has no items\n"` (not an exception)

The inconsistency makes it hard to predict behavior.

**Recommendation:** Make error handling consistent. Either both throw, or both return error strings.

---

### 14. `resetPreviousFrame()` mutates in place, not via `mutate()`
**File:** `src/Model.php:655-658`

```php
public function resetPreviousFrame(): void
{
    $this->previousFrame = null;
}
```

This is a mutating operation on a nominally immutable model. All other mutations return new instances. `resetPreviousFrame()` modifies `$this` directly.

**Recommendation:** Consider `withResetPreviousFrame(): self` returning a new instance, or document that this method intentionally violates immutability for performance/event-stream reasons.

---

### 15. `removeItem()` returns `$this` on invalid index instead of a new instance
**File:** `src/Model.php:242-251`

```php
public function removeItem(int $index): self
{
    if ($index < 0 || $index >= \count($this->items)) {
        return $this;
    }
    return $this->mutate(...);
}
```

On invalid index, returns `$this` (same instance). On valid index, returns a new instance. The method is therefore not referentially transparent for invalid inputs — calling code cannot assume a new instance is always returned.

**Recommendation:** Always return a new instance (clone with no-op) for consistency, or use a `?self` return type to indicate the no-op case.

---

## Missing Features

### 16. No keyboard navigation beyond `cursorUp()` / `cursorDown()`
**File:** `src/Model.php:315-323`

Only `cursorUp(int $n = 1)` and `cursorDown(int $n = 1)` exist. Missing:
- `cursorPageUp()` / `cursorPageDown()` (jump by viewport height)
- `cursorToStart()` / `cursorToEnd()`
- No key constant definitions (unlike the Go upstream which defines keys)

**Recommendation:** Add `cursorPageUp(int $pages = 1): self`, `cursorPageDown(int $pages = 1): self`, `cursorToStart(): self`, `cursorToEnd(): self`.

---

### 17. No mouse interaction support
**Files:** `src/Model.php`, `src/Prefixer.php`, `src/Suffixer.php`

No click-to-select, no hover detection, no mouse event handling. The CALIBER_LEARNINGS.md mentions `candy-mouse` integration intent but no mouse-related code exists in the library.

**Recommendation:** Add `setMouseEnabled(bool)`, `onMouseClick(callable)`, and `onMouseHover(callable)` hooks, or integrate with `candy-mouse` for hit-testing.

---

### 18. No `itemAt(int $index)` accessor
**File:** `src/Model.php`

`find(\Stringable $value)` searches by value. `cursorItem()` returns only the current item. There is no way to get the item at a specific index without accessing private `$items` directly.

**Recommendation:** Add `itemAt(int $index): \Stringable` (throws on out-of-bounds) and `tryItemAt(int $index): ?\Stringable`.

---

### 19. No batch item addition
**File:** `src/Model.php:233-237`

Only `addItem(\Stringable $value): self` exists. For bulk loading (e.g., populating from a database), adding items one-by-one is inefficient due to repeated `mutate()` clone overhead.

**Recommendation:** Add `addItems(\Stringable ...$values): self` and/or `addItemsFromArray(array $values): self`.

---

### 20. No scroll position / offset API
**File:** `src/Model.php`

The `lines()` method computes the visible window dynamically from cursor position. There is no way to programmatically scroll the viewport without moving the cursor. Some use cases need independent scroll and cursor positions.

**Recommendation:** Consider adding `scrollOffset(int $n): self` to shift the visible window independently of cursor.

---

## Async Patterns (ReactPHP Ecosystem)

### 21. Entirely synchronous — no streaming or generator-based rendering
**File:** `src/Model.php:371-439`

`lines()` and `View()` return complete arrays/strings synchronously. No `\Generator`-based incremental rendering, no `yield` for large lists. For large item lists (thousands of items), the entire `lines()` computation blocks.

**Recommendation:** Consider a `linesStream(): \Generator` that `yield`s lines one at a time, allowing callers to interleave with event loop ticks.

---

### 22. `FuzzyMatch::match()` is fully synchronous — no async variant
**File:** `src/FuzzyMatch.php:99-117`

Scoring all N candidates in a single synchronous loop blocks for large lists. No `Promise`-based or `\Generator`-based scoring for time-slicing.

**Recommendation:** Add `matchAsync(string $query, array $items): \React\Promise\Promise` that scores in batches using `loop()->futureTick()` or similar.

---

### 23. No integration with `candy-async` cancellation tokens
**File:** `src/Model.php`

`candy-async` is listed as a dependency in `composer.json` but none of the rendering methods accept `CancellationToken`. Long-running renders (large lists, complex prefixers) cannot be cancelled.

**Recommendation:** Add optional `CancellationToken $token = null` parameters to `View()`, `lines()`, `sort()`, and `FuzzyMatch::match()`.

---

## Code Quality / Refactoring

### 24. `DefaultPrefixer::ansiWidth()` duplicates `Width::string()` from candy-core
**File:** `src/DefaultPrefixer.php:104-107`

```php
public static function ansiWidth(string $s): int
{
    return Width::string($s);
}
```

This is a direct passthrough to `Width::string()` with no additional logic. Dead code duplication.

**Recommendation:** Remove `DefaultPrefixer::ansiWidth()` and use `Width::string()` directly everywhere.

---

### 25. `FuzzyMatch::score()` uses `strtolower()` which is not Unicode-aware
**File:** `src/FuzzyMatch.php:40-41`

```php
$q = strtolower($query);
$c = strtolower($candidate);
```

`strtolower()` in PHP is locale-dependent and does not correctly lowercase non-ASCII Unicode characters (e.g., `É → é`, `Σ → σ` in Greek). Should use `\mb_strtolower($s, 'UTF-8')`.

**Recommendation:** Replace `strtolower()` with `\mb_strtolower(..., 'UTF-8')` for correct Unicode case-folding.

---

### 26. `FuzzyMatch` scoring constants are private with no configuration API
**File:** `src/FuzzyMatch.php:15-19`

```php
private const MATCH_SCORE = 3;
private const MISMATCH_PENALTY = -3;
private const GAP_OPEN = -5;
private const GAP_EXTEND = -1;
private const ADJACENT_BONUS = 5;
```

All scoring parameters are hardcoded private constants. There is no way to tune the algorithm for different use cases (e.g., tighter matching, more gap tolerance).

**Recommendation:** Consider making these configurable via constructor or setter, at least as a protected `ScoringProfile` class or enum.

---

### 27. `View()` silently converts exception messages to strings — no logging
**File:** `src/Model.php:474-476`

When a render fails, the error message is silently returned as a string. There is no logging, no way for calling code to distinguish errors from successful output without parsing the string.

**Recommendation:** Add optional `LoggerInterface $logger = null` parameter to `View()`, or return a `Result` object.

---

### 28. `FilterState::filtered` docblock describes a transition that never happens
**File:** `src/FilterState.php:14`, `src/FilterState.php:24-25`

```php
/** Filtering produced results; filter remains active. */
case filtered;
```

The docblock says `filtering → filtered` is a valid transition, but the code never sets `filtered`. The README documents it too. This is misleading API documentation.

**Recommendation:** Either implement the `filtered` state transition, or remove the case and update the README.

---

### 29. `bufferFromOutput()` uses mixed `mb_substr()` and direct string indexing
**File:** `src/Model.php:642`

```php
$char = isset($line[$col]) ? \mb_substr($line, $col, 1) : ' ';
```

The ternary uses direct string offset check (`isset($line[$col])`) but then uses `mb_substr()` for extraction. These can behave differently with multi-byte characters — `isset` checks byte offset while `mb_substr` operates on character offset. However, since `$line` comes from `$lines = \explode("\n", $output)` and the output is plain text, this may not manifest in practice.

**Recommendation:** Use consistent access — either `mb_substr` with bounds checking, or iterate grapheme-by-grapheme.

---

### 30. `idCounter` is per-instance — cloned models restart from 0
**File:** `src/Model.php:79`, `src/Model.php:235`

Each `Model` instance has its own `$idCounter` starting at 0. When `mutate()` clones the model (line 103), the clone's `$idCounter` is also 0. This means two independent model instances can have items with the same ID, which is fine for display but could cause issues if IDs are used for cross-model identity comparison.

This is by design (clones are independent) but worth noting.

---

### 31. Test uses reflection to access private `$items` property
**File:** `tests/ModelTest.php:716-719`

```php
$reflection = new \ReflectionClass($modelForItems);
$itemsProp = $reflection->getProperty('items');
$itemsProp->setAccessible(true);
$items = $itemsProp->getValue($modelForItems);
```

The test `testItemIdsAreUniqueAndIncreasing` needs to inspect private state. This is a test-only workaround. Consider making `Item::$id` accessible via a public getter on `Model` (e.g., `getItemIds(): array`) to avoid reflection in tests.

---

## Compatibility Issues

### 32. `FuzzyMatch` depends on locale settings for `strtolower()`
**File:** `src/FuzzyMatch.php:40`

`strtolower()` respects the current PHP locale. The same input may produce different results depending on `setlocale()` state. This means fuzzy matching behavior is environment-dependent.

**Recommendation:** Use `\mb_strtolower(..., 'UTF-8')` which is always UTF-8 aware and locale-independent.

---

### 33. `splitOverWidth()` will corrupt multi-byte character data
**File:** `src/Model.php:593-600`

Using byte-level `strlen`/`substr` splitting at `$maxWidth` byte boundaries will produce invalid UTF-8 sequences when the input contains characters larger than 1 byte. Since `Width::string()` from `candy-core` correctly handles grapheme boundaries, this function should use `grapheme_extract()` or `Width::truncate()` approach instead.

**Recommendation:** Replace with a grapheme-aware splitting loop using `grapheme_str_split()` or `mb_substr()` with character counting.

---

### 34. No PHP 8.4+ explicit nullable parameter types compatibility note
**File:** `src/Model.php`

The `?Buffer $previousFrame = null` at line 82 and `?\Closure $lessFunc = null` at line 49 use the older nullable syntax. PHP 8.4+ supports `?Type` as deprecated in favor of `?Type` (already fine) or union types. This is not an error but worth noting for future migration.

**Recommendation:** Already using the correct nullable `?Type` syntax. No action needed.

---

## Positive Findings

The following are well-implemented and worth preserving:

- **Immutable `mutate()` pattern** (`src/Model.php:101-106`): Correctly implements clone-and-mutate for fluent setters. Clean and efficient.
- **Smith-Waterman two-row DP** (`src/FuzzyMatch.php:46-83`): Memory-efficient O(c) scoring where c is candidate length. Well-commented algorithm.
- **Filter state machine** (`src/FilterState.php`, `src/Model.php:181-224`): The `originalItems` preservation for `withoutFilter()` is a solid pattern.
- **Diff-based `View()` output** (`src/Model.php:448-477`): The first-frame full emit + subsequent delta approach correctly handles resize and filter boundary resets.
- **`Width` grapheme-aware width calculation**: Correctly handles CJK, emoji, and zero-width combiners via `candy-core`.
- **Good test coverage**: 6 test files covering unit, integration, edge cases. Immutable operations are well-tested.
- **Clean interface segregation**: `Prefixer` and `Suffixer` interfaces are minimal and focused.
- **`Item::$id` for stable identity**: Using a counter-based ID rather than object identity allows items to remain identifiable even after transformation.

---

## Summary

| Category | Count |
|----------|-------|
| Critical issues | 4 |
| Performance issues | 3 |
| Security issues | 1 |
| Design/API issues | 7 |
| Missing features | 5 |
| Async patterns | 3 |
| Code quality/refactoring | 7 |
| Compatibility issues | 2 |
| **Total** | **32** |

**Priority recommendations:**
1. Fix exception swallowing in `View()` (critical — silent failures)
2. Remove or implement `FilterState::filtered` (dead API surface)
3. Add `setLineOffset()` or remove the property (incomplete API)
4. Fix `splitOverWidth()` for UTF-8 (data corruption)
5. Replace `strtolower()` with `\mb_strtolower()` in `FuzzyMatch` (Unicode correctness)
6. Add missing navigation methods (`cursorPageUp/Down`, `cursorToStart/End`)
7. Add fluent setters for `lessFunc` / `equalsFunc` (API consistency)
8. Add input validation on dimension setters (defensive programming)
