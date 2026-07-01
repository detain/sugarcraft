---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-lister Code Review Findings

## Goal

Address all 32 findings from the candy-lister code review, organized into 8 phases by category and dependency order, ensuring critical issues (silent failures, dead API surface, UTF-8 data corruption) are resolved first.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Fix exception swallowing before adding features | Silent failures are more dangerous than loud ones — callers cannot distinguish success from failure when `View()` returns a string on error | findings/candy-lister.md (#1, #4) |
| Remove `FilterState::filtered` dead case | A documented state transition that never occurs is actively misleading; removing it simplifies the enum | findings/candy-lister.md (#2) |
| Use `Width::string()` for grapheme-aware splitting | `candy-core`'s `Width` class already handles CJK/emoji/grapheme boundaries correctly; `splitOverWidth()` should use it | findings/candy-lister.md (#6, #33) |
| Use `mutate()` consistently across all state-changing methods | The immutable fluent pattern is established throughout the codebase; deviations cause unpredictable behavior | findings/candy-lister.md (#10, #11, #14) |
| Add `setLineOffset()` instead of removing the property | `lineOffset` has semantic meaning (passed to prefixer/suffixer init); removing it would be a breaking change | findings/candy-lister.md (#3) |
| Add `cursorPageUp/Down/ToStart/ToEnd` for keyboard navigation | Upstream `bubblelister` defines key constants; missing navigation is a significant usability gap | findings/candy-lister.md (#16) |
| Use `\mb_strtolower(..., 'UTF-8')` in `FuzzyMatch` | PHP `strtolower()` is locale-dependent and corrupts non-ASCII Unicode; mb_* is always UTF-8; also fixes Finding #32 (locale dependency) | findings/candy-lister.md (#25, #32) |

---

## Phase 1: Critical Bug Fixes [PENDING]

### 1.1 Fix `View()` exception handling — catch `\Throwable` not just `\RuntimeException`

**File:** `src/Model.php:474-476`

**What is expected:**
Change `catch (\RuntimeException $e)` to `catch (\Throwable $e)` to prevent `\Error` (TypeError, ArgumentCountError) from propagating uncaught from `bufferFromOutput()` or `DiffEncoder::encode()`. Callers cannot distinguish a failed render from a successful one — both return strings.

**Why:** Silent failure masks data corruption. If `bufferFromOutput()` throws a `\TypeError` due to incorrect types or `DiffEncoder::encode()` throws an `\Error`, it propagates uncaught and crashes the application. Meanwhile `\RuntimeException` from `lines()` is caught and returned as a string message — inconsistent.

**Severity:** critical

**Conditions for success:**
1. `Model::new()->View()` on empty list returns `"NoItems: list has no items\n"` (not an exception bubbling up)
2. `bufferFromOutput()` throwing `\TypeError` is caught and returned as a string, not propagated
3. Verify with a test that passes an invalid object to `addItem()` and calls `View()` — no uncaught exception

**Related code:**
- `src/Model.php:448-477` — `View()` method
- `src/Model.php:634-648` — `bufferFromOutput()` uses `Buffer::new()`, `Cell::new()`, `buffer->withCellAt()`
- `src/Model.php:472-473` — `DiffEncoder::encode()`

**Investigation notes:**
`bufferFromOutput()` uses `Buffer::new()`, `Cell::new()`, and `buffer->withCellAt()` — if types are wrong, could throw `\TypeError`. The issue is that `lines()` throws `\RuntimeException` (caught) but `bufferFromOutput()` or `DiffEncoder::encode()` could throw `\Error` (not caught). The fix is to catch `\Throwable` instead of `\RuntimeException`.

---

### 1.2 Remove dead `FilterState::filtered` enum case

**File:** `src/FilterState.php:25`

**What is expected:**
Remove `case filtered;` from the `FilterState` enum and update the docblock transitions. The only states ever set are `filtering` (from `withFilterFn()`) and `unfiltered` (from `withoutFilter()`).

**Why:** A documented state transition that never executes is actively misleading and constitutes dead API surface. The `filtered` case is documented in the README's state-transition table but is never assigned anywhere in the codebase.

**Severity:** critical

**Conditions for success:**
1. `FilterState` has only `unfiltered` and `filtering` cases
2. All references to `FilterState::filtered` are removed from code and docs
3. README state-transition table is updated to remove `filtering → filtered`

**Related code:**
- `src/FilterState.php:16-26` — enum definition with 3 cases
- `src/Model.php:185` — sets `FilterState::filtering` in `withFilterFn()`
- `src/Model.php:213` — sets `FilterState::unfiltered` in `withoutFilter()`

**Investigation notes:**
No other file assigns `FilterState::filtered`. The `filtered` case is purely decorative documentation that never actually occurs in the state machine.

---

### 1.3 Add fluent `setLineOffset(int $n): self` method

**File:** `src/Model.php:45`, `src/Model.php` (after line 169)

**What is expected:**
Add a fluent `setLineOffset(int $n): self` method that uses `mutate()`, consistent with `setCursorOffset()`, `setWidth()`, etc. Currently `lineOffset` is public and mutable directly (`$model->lineOffset = 5`), breaking the immutable fluent API pattern.

**Why:** All other configuration properties have fluent setters. `lineOffset` is passed to `initPrefixer()`/`initSuffixer()` and has semantic meaning — it should not be directly mutated.

**Severity:** critical

**Conditions for success:**
1. `Model::new()->setLineOffset(10)` returns a new Model with `lineOffset === 10`
2. Original model is unchanged (immutability verified)
3. `setLineOffset()` chains with other fluent setters

**Related code:**
- `src/Model.php:45` — `public int $lineOffset = 5;`
- `src/Model.php:142-145` — `setCursorOffset()` as fluent setter template
- `src/Model.php:496-498` — `initPrefixer()` receives `$this->lineOffset`

**Investigation notes:**
`lineOffset` is passed to `initPrefixer()` and `initSuffixer()` at lines 496-505 to tell them the "gap between cursor and viewport edge". It is distinct from `cursorOffset` (the offset FROM the cursor to the edge of the viewport). `setCursorOffset()` at line 142 shows the correct pattern: `return $this->mutate(fn($m) => $m->cursorOffset = $n);`

---

### 1.4 Fix `splitOverWidth()` for UTF-8 grapheme boundaries

**File:** `src/Model.php:593-600`

**What is expected:**
Replace byte-level `strlen()`/`substr()` with grapheme-aware splitting. Use `Width::string()` to measure display width and `grapheme_extract()` or `mb_substr()` to split at character boundaries, not byte boundaries.

**Why:** Using `strlen()`/`substr()` on multi-byte UTF-8 (CJK, emoji) splits mid-character, producing invalid UTF-8 output. Meanwhile `hardWrap()` at line 552 uses `preg_split('/\s+/u', ...)` with the `u` flag, which is UTF-8 aware and correct.

**Severity:** critical

**Conditions for success:**
1. A word containing CJK characters (e.g., "日本語") at `$maxWidth = 3` splits correctly without corrupting characters
2. An emoji like "👍🏻" (5 bytes) is not split mid-grapheme when splitting at `$maxWidth = 2`
3. Existing test `testLinesWithVeryLongWordExercisingSplitOverWidth` still passes

**Related code:**
- `src/Model.php:593-600` — uses `\strlen($word)` and `\substr($word, $i, $maxWidth)` — byte-level
- `src/Model.php:552-586` — `hardWrap()` uses `preg_split('/\s+/u', ...)` with `u` flag (UTF-8 aware)
- `candy-core/src/Util/Width.php:19-52` — `Width::string()` uses `grapheme_extract()` for proper grapheme handling

**Investigation notes:**
`Width::truncate()` at lines 72-88 of `Width.php` shows the pattern: iterate graphemes using `grapheme_extract()`, accumulate width using `graphemeWidth()`, break when over budget. `splitOverWidth()` should use a similar approach — iterate graphemes, accumulate display width, split when over `$maxWidth` display width.

---

## Phase 2: Performance Fixes [PENDING]

### 2.1 Optimize `sort()` cursor relocation from O(n) to O(1)

**File:** `src/Model.php:269-281`

**What is expected:**
After `usort()`, build an `array_flip` map of `Item::$id => newIndex` instead of the O(n) linear scan. The `Item::$id` field (public readonly) provides stable identity.

**Why:** For large lists (thousands of items), the separate `foreach` loop is redundant work after the O(n log n) sort. O(1) lookup is trivially achievable using the stable item ID.

**Severity:** high

**Conditions for success:**
1. After sorting, the cursor correctly lands on the same logical item (verified by item ID, not object identity)
2. No O(n) linear scan after sorting (verified by code inspection)
3. Existing test `testSortWithLessFunc` still passes — cursor stays on 'z' item

**Related code:**
- `src/Model.php:264-284` — `sort()` method
- `src/Model.php:269` — `$selected = $this->items[$this->cursorIndex] ?? null` — object identity preserved
- `src/Model.php:275-281` — O(n) foreach linear search by object identity `===`
- `src/Item.php:15-16` — `public readonly int $id` — stable identity across mutations

**Investigation notes:**
The current pattern finds `$selected` (the item at cursor) BEFORE sorting, then after `usort` finds it again by iterating and comparing `===` (object identity). But after `usort`, the items array is reordered — the `$selected` object identity is preserved but we need to find its new index. The O(n) scan is `foreach ($items as $i => $item) if ($item === $selected)`. Instead, build `$idMap = array_flip(array_map(fn($item) => $item->id, $items))` which is O(n) once, then look up `$idMap[$selected->id]` in O(1).

---

### 2.2 Add negative value validation to dimension setters

**File:** `src/Model.php:127-169`

**What is expected:**
Add `\max(1, $width)` clamping (or explicit guards) in `setWidth()`, `setHeight()`, `setCursorOffset()`, `setWrap()`, and `setLineOffset()`. The `lines()` method at line 376 checks for zero but negative values would pass that check.

**Why:** Negative dimension values can cause issues in loops, array indexing, and division operations. Clamping at the setter is defensive and mirrors common TUI library patterns.

**Severity:** high

**Conditions for success:**
1. `setWidth(-10)` returns a model with `width === 1` (clamped)
2. `setHeight(-5)` returns a model with `height === 1`
3. `setCursorOffset(-3)` returns a model with `cursorOffset === 1`
4. Existing tests that rely on zero/negative values (if any) still pass

**Related code:**
- `src/Model.php:127-169` — all dimension setters
- `src/Model.php:376-378` — `lines()` checks `$this->width <= 0 || $this->height <= 0` but negative values pass this check
- `src/Model.php:312` — `setCursor()` uses `\max(0, \min($index, ...))` — shows the clamping pattern

**Investigation notes:**
`setCursor()` at line 312 already uses the pattern `\max(0, \min($index, ...))` for clamping cursor position. The dimension setters should similarly clamp to `\max(1, $value)` for width/height/wrap and `\max(0, $value)` for cursorOffset/lineOffset.

---

## Phase 3: Security Fixes [PENDING]

### 3.1 Add ANSI injection protection / documentation

**File:** `src/Model.php:513`

**What is expected:**
Add a `Sanitizer` interface or helper method that strips dangerous ANSI sequences (e.g., `\x1b[2J` screen-clear, `\x1b[?1049h` alternate buffer, `\x1b[0m` resets). Document the requirement prominently in the class docblock. The README already acknowledges this ("sanitize it before adding to the model") but there's no built-in protection.

**Why:** Item values are cast to string and rendered verbatim. ANSI sequences from untrusted sources (database, user input) can corrupt terminal display or enable social engineering attacks via invisible characters.

**Severity:** high

**Conditions for success:**
1. A `Model` with item containing `\x1b[2J` (screen clear) renders safely (the sequence is stripped or escaped)
2. `\x1b[?1049h` (alternate buffer switch) is stripped
3. Invisible/ambiguous ANSI sequences are handled
4. Documentation in class docblock explicitly notes the sanitization requirement

**Related code:**
- `src/Model.php:513` — `$rawLines = $this->hardWrap((string) $item->value, $contentWidth);`
- `candy-core/src/Util/Ansi.php` — likely already has ANSI stripping utilities

**Investigation notes:**
Dangerous sequences to strip: `\x1b[2J` (clear screen), `\x1b[?1049h` (alt buffer), `\x1b[3J` (clear saved lines), BEL/7 (bell). The `Ansi::strip()` function in `candy-core` is already used by `Width::string()` — verify if it handles these dangerous sequences or if additional filtering is needed.

---

## Phase 4: API Consistency Refactoring [PENDING]

### 4.1 Add fluent `setLessFunc()` and `setEqualsFunc()` methods

**File:** `src/Model.php` (after line 169)

**What is expected:**
Add `setLessFunc(\Closure $fn): self` and `setEqualsFunc(\Closure $fn): self` methods using the `mutate()` pattern. Currently these closures are assigned directly (`$model->lessFunc = fn(...)`), breaking the fluent API pattern.

**Why:** All other configuration uses fluent setters (`setWidth()`, `setPrefixer()`, etc.). These two closures should follow the same pattern for API consistency.

**Severity:** medium

**Conditions for success:**
1. `Model::new()->setLessFunc(fn($a, $b) => ...)` returns a new model with `lessFunc` set
2. `Model::new()->setEqualsFunc(fn($a, $b) => ...)` returns a new model with `equalsFunc` set
3. Chaining works: `->setLessFunc(...)->setEqualsFunc(...)`
4. Existing tests that assign directly (`$model->lessFunc = ...`) continue to work (backward compat)

**Related code:**
- `src/Model.php:49-52` — `public ?\Closure $lessFunc = null;` and `public ?\Closure $equalsFunc = null;`
- `tests/ModelTest.php:105` — `$this->model->lessFunc = fn($a, $b) => ...`
- `tests/ModelTest.php:130` — `$this->model->equalsFunc = fn($a, $b) => ...`
- `src/Model.php:162-170` — `setLineStyle()` and `setCurrentStyle()` as templates

**Investigation notes:**
The existing property assignment pattern in tests (`$model->lessFunc = ...`) is test-only and not the public API. Adding fluent setters would be the proper API; the test assignments are a workaround for the missing setters.

---

### 4.2 Refactor `withFilterFn()` to use `mutate()`

**File:** `src/Model.php:181-198`

**What is expected:**
Refactor `withFilterFn()` to use the `mutate()` helper internally instead of its own `clone $this` + direct property mutation.

**Why:** Inconsistent with the established pattern used by `setWidth()`, `addItem()`, `removeItem()`, etc. The current inline clone-mutate pattern is harder to maintain and reason about.

**Severity:** medium

**Conditions for success:**
1. `withFilterFn()` returns a new model (not `$this` when unchanged)
2. `withFilterFn()` uses `mutate()` or delegates to a private `filterItems()` method that uses `mutate()`
3. Existing tests pass, especially `testWithFilterFnReturnsNewInstance`

**Related code:**
- `src/Model.php:181-198` — current implementation does `clone $this` then direct mutation
- `src/Model.php:101-106` — `mutate()` helper pattern
- `src/Model.php:233-237` — `addItem()` as correct `mutate()` usage example

**Investigation notes:**
The current implementation clones, then does multiple property mutations on the clone (filterFn, filterState, originalItems, items, cursorIndex, previousFrame, prevWidth, prevHeight) all inline. The `mutate()` pattern would make each step cleaner and more maintainable.

---

### 4.3 Refactor `withoutFilter()` early-return to use `mutate()`

**File:** `src/Model.php:208-224`

**What is expected:**
When `filterFn === null`, return via `mutate()` for consistency, or document why direct `$this` return is intentional.

**Why:** The early-return guard at lines 208-210 returns `$this` directly, inconsistent with the rest of the API.

**Severity:** low

**Conditions for success:**
1. Without filter: same behavior (returns `$this` early) but via `mutate()` with identity function, OR
2. With filter: returns new instance via `mutate()`
3. Behavior unchanged; pattern consistent

**Related code:**
- `src/Model.php:208-210` — `if ($this->filterFn === null) { return $this; }`

**Investigation notes:**
The early return at line 208-210 is `if ($this->filterFn === null) { return $this; }` — when no filter is active, `withoutFilter()` is a no-op. The behavior is correct (same model returned), but the mechanism differs from other methods that use `mutate()`. The options are: (a) wrap in `return $this->mutate(fn($m) => null)` which is a no-op clone, or (b) keep direct return and add a docblock explaining this is intentional for the no-op case. Option (b) may be preferred since the `mutate()` wrapper for a true no-op would be unnecessary overhead.

---

### 4.4 Refactor `sort()` to use more uniform mutation pattern

**File:** `src/Model.php:264-284`

**What is expected:**
Build the new items array via `array_map` or `usort` on the clone, then assign inside `mutate()`. Avoid in-place `usort` on a local copy before wrapping.

**Why:** The current pattern (`usort` in-place on local copy, then `mutate()` assignment) is confusing — it looks like mutation but is on a local. The `addItem()` pattern (push into clone inside `mutate()`) is cleaner.

**Severity:** low

**Conditions for success:**
1. `sort()` still returns the correctly sorted items with correct cursor
2. Uses `mutate()` consistently with other methods
3. Existing tests pass

**Related code:**
- `src/Model.php:264-284` — current pattern with local `$items` copy + `usort` + `mutate()`
- `src/Model.php:256-259` — `clear()` as a cleaner mutation pattern example

---

### 4.5 Make `resetPreviousFrame()` consistent with immutable pattern

**File:** `src/Model.php:655-658`

**What is expected:**
Either (a) add `withResetPreviousFrame(): self` returning a new instance, or (b) document that this method intentionally violates immutability for performance/event-stream reasons.

**Why:** `resetPreviousFrame()` is a mutating operation on a nominally immutable model. All other mutations return new instances.

**Severity:** medium

**Conditions for success:**
1. Either `withResetPreviousFrame(): self` exists and is used instead, OR
2. The method is documented as intentionally mutable with rationale

**Related code:**
- `src/Model.php:655-658` — `public function resetPreviousFrame(): void { $this->previousFrame = null; }`
- `tests/ModelTest.php:698` — test calls `$m2->resetPreviousFrame()` on a model instance
- `tests/ModelTest.php:681-701` — `testResetPreviousFrameForcesFullFrame`

**Investigation notes:**
The `resetPreviousFrame()` method is explicitly documented (line 651-653) as being for "window resize or cursor-position-lost events". It's a deliberate mutable operation for event-stream efficiency. Option (b) — documenting this as intentional — is likely the right call rather than changing the behavior.

---

### 4.6 Fix `removeItem()` to always return new instance

**File:** `src/Model.php:242-251`

**What is expected:**
On invalid index, return a cloned model (a no-op clone) instead of `$this`. Alternatively, use `?self` return type.

**Why:** The method is not referentially transparent — valid index returns a new instance, invalid index returns the same instance. Calling code cannot assume a new instance is always returned.

**Severity:** medium

**Conditions for success:**
1. `removeItem(99)` returns a new Model instance (clone with no-op mutation), not `$this`
2. `removeItem(-1)` returns a new Model instance
3. `removeItem(validIndex)` returns a new Model instance with item removed
4. All existing tests pass

**Related code:**
- `src/Model.php:242-251` — `if ($index < 0 || $index >= \count($this->items)) { return $this; }`
- `tests/ModelTest.php:354-366` — tests `assertSame($m, $result)` which is the behavior to change

**Investigation notes:**
The behavior at line 244-245 (`return $this`) means calling code cannot rely on a new instance being returned. This is inconsistent with every other model method that returns `self`. The tests at ModelTest.php lines 354 and 361 explicitly test `assertSame($m, $result)` — this assertion will need to change to `assertNotSame($m, $result)` after the fix. The recommended fix is to return a clone: `return $this->mutate(fn($m) => null);` which creates a new instance with no changes — effectively a no-op clone.

---

### 4.7 Make error handling consistent between `cursorItem()` and `View()`

**File:** `src/Model.php:302-308`, `src/Model.php:448-477`

**What is expected:**
Choose one error handling strategy: either both throw exceptions, or both return error strings. Document the decision in the method docblocks.

**Why:** Currently `cursorItem()` throws on empty list, but `View()` catches the exception and returns the message as a string. This inconsistency makes behavior unpredictable.

**Severity:** medium

**Conditions for success:**
1. Either `View()` on empty model throws (not catches), OR `cursorItem()` returns a string/on-empty indicator
2. Docblocks clearly document the error handling strategy for each method

**Related code:**
- `src/Model.php:302-308` — `cursorItem()` throws `\RuntimeException(Lang::t('list.no_items'))` on empty
- `src/Model.php:474-476` — `View()` catches `\RuntimeException` and returns `$e->getMessage() . "\n"`
- `lang/en.php:12` — `'list.no_items' => 'NoItems: list has no items'`

---

## Phase 5: Missing Features [PENDING]

### 5.1 Add `cursorPageUp()`, `cursorPageDown()`, `cursorToStart()`, `cursorToEnd()`

**File:** `src/Model.php` (after line 323)

**What is expected:**
Add navigation methods:
- `cursorPageUp(int $pages = 1): self` — jump up by `$pages * $this->height` items (viewport height)
- `cursorPageDown(int $pages = 1): self` — jump down by viewport height
- `cursorToStart(): self` — jump to index 0
- `cursorToEnd(): self` — jump to last item

**Why:** Only `cursorUp(int $n = 1)` and `cursorDown(int $n = 1)` exist. Page-level navigation and jump-to-start/end are standard TUI list features.

**Severity:** medium

**Conditions for success:**
1. `cursorToStart()` moves cursor to index 0
2. `cursorToEnd()` moves cursor to `length() - 1`
3. `cursorPageUp()` moves up by viewport height (clamps at 0)
4. `cursorPageDown()` moves down by viewport height (clamps at last)
5. All methods are fluent and return new instances

**Related code:**
- `src/Model.php:315-323` — `cursorUp()` and `cursorDown()` as implementation templates

**Investigation notes:**
`cursorUp()` and `cursorDown()` use `setCursor()` internally. `cursorPageUp/Down` should use `setCursor()` with offset by `$this->height`. `cursorToStart` uses `setCursor(0)`. `cursorToEnd` uses `setCursor($this->length() - 1)`.

---

### 5.2 Add `itemAt(int $index)` and `tryItemAt(int $index)` accessors

**File:** `src/Model.php` (after line 323)

**What is expected:**
Add:
- `itemAt(int $index): \Stringable` — returns item at index, throws on out-of-bounds
- `tryItemAt(int $index): ?\Stringable` — returns null on out-of-bounds

**Why:** No way to get the item at a specific index without accessing private `$items` directly. `find()` searches by value, `cursorItem()` returns only current item.

**Severity:** low

**Conditions for success:**
1. `itemAt(0)` returns the first item's value
2. `itemAt(-1)` or `itemAt(999)` throws `\OutOfBoundsException`
3. `tryItemAt(-1)` returns null
4. `tryItemAt(0)` returns `?\Stringable`

**Related code:**
- `src/Model.php:343-359` — `find()` as a model method that queries items
- `src/Model.php:302-308` — `cursorItem()` as a template for throwing on empty

---

### 5.3 Add batch item addition `addItems(\Stringable ...$values)` and `addItemsFromArray(array $values)`

**File:** `src/Model.php` (after `addItem()` at line 237)

**What is expected:**
Add:
- `addItems(\Stringable ...$values): self` — add multiple items in one call
- `addItemsFromArray(array $values): self` — add from a plain array

**Why:** For bulk loading (database results, file lines), adding items one-by-one is inefficient due to repeated `mutate()` clone overhead.

**Severity:** low

**Conditions for success:**
1. `addItems(new StringItem('a'), new StringItem('b'), new StringItem('c'))` adds 3 items
2. `addItemsFromArray(['a', 'b', 'c'])` wraps strings in `StringItem` automatically
3. Returns a single new instance (one clone, multiple pushes)

**Related code:**
- `src/Model.php:233-237` — `addItem()` for reference

---

### 5.4 Consider adding `scrollOffset(int $n): self` for independent scroll control

**File:** `src/Model.php`

**What is expected:**
Add a `scrollOffset(int $n): self` method to shift the visible viewport window independently of cursor position. This is a "consider" item — the `lineOffset` property already exists as the scroll anchor.

**Why:** `lines()` computes the visible window dynamically from cursor position. Some use cases need independent scroll and cursor positions.

**Severity:** low

**Conditions for success:**
1. If implemented: `scrollOffset(5)` returns a model with a viewport shifted down by 5 lines
2. Chaining with cursor movements works correctly
3. The implementation does not break existing `cursorOffset` behavior

**Related code:**
- `src/Model.php:382-436` — `lines()` computes viewport dynamically from cursor + cursorOffset
- `src/Model.php:45` — `public int $lineOffset = 5;` — the existing scroll anchor

**Investigation notes:**
The `lineOffset` property is described as "how many lines before cursor to show" in the class docblock. It controls the "gap between cursor and viewport edge". The `lines()` method uses `cursorOffset` (not `lineOffset`) for its viewport calculations at lines 385-393. Actually, `lineOffset` is passed only to `initPrefixer()`/`initSuffixer()`, not used within `lines()` itself. This means the scroll/offset behavior may already be handled by `cursorOffset` alone. This "consider" item may not be needed if `cursorOffset` already provides the desired functionality.

---

## Phase 6: Async Patterns [PENDING]

### 6.1 Add optional `CancellationToken` support to `View()`, `lines()`, `sort()`

**File:** `src/Model.php`, `src/FuzzyMatch.php`

**What is expected:**
Add optional `CancellationToken $token = null` parameters to `View()`, `lines()`, `sort()`, and `FuzzyMatch::match()`. Check `$token?->isCancelled()` at loop boundaries and throw if cancelled.

**Why:** `candy-async` is listed as a dependency but none of the rendering methods accept `CancellationToken`. Long-running renders (large lists, complex prefixers) cannot be cancelled.

**Severity:** low

**Conditions for success:**
1. `View()` accepts `?CancellationToken $token = null`
2. `lines()` accepts `?CancellationToken $token = null`
3. `sort()` accepts `?CancellationToken $token = null`
4. `FuzzyMatch::match()` accepts `?CancellationToken $token = null`
5. When token is cancelled mid-render, throws `OperationCancelledException` (or similar)
6. When token is null, behavior is unchanged

**Related code:**
- `composer.json:70-73` — `"sugarcraft/candy-async"` in repositories
- `candy-async/src/CancellationToken.php:37-40` — `isCancelled()` method
- `src/Model.php:371-439` — `lines()` — long-running loops at lines 384-393, 403-410, 426-434
- `src/FuzzyMatch.php:99-117` — `match()` — synchronous loop at lines 105-111

**Investigation notes:**
`CancellationToken::isCancelled()` at line 37 returns a boolean. The pattern for integration would be to check `$token?->isCancelled()` at the start of each loop iteration in `lines()`, `sort()`, and `FuzzyMatch::match()`, throwing `OperationCancelledException` if true. This follows the Go context cancellation pattern.

---

### 6.2 Consider generator-based `linesStream(): \Generator` for incremental rendering

**File:** `src/Model.php`

**What is expected:**
Consider adding a `linesStream(): \Generator` that `yield`s lines one at a time, allowing callers to interleave with event loop ticks.

**Why:** `lines()` and `View()` return complete arrays/strings synchronously. For large item lists (thousands of items), the entire `lines()` computation blocks.

**Severity:** low

**Conditions for success:**
1. If implemented: `linesStream()` yields lines one at a time as `\Generator`
2. Each yield point is a safe cancellation/interleaving point with the event loop
3. Output is identical to `lines()` when consumed fully

**Related code:**
- `src/Model.php:371-439` — `lines()`

**Investigation notes:**
PHP generators (`\Generator`) yield values one at a time and automatically yield control back to the caller/loop on each `yield`. This makes them ideal for time-slicing long computations. A `linesStream()` would replace the `for` loops in `lines()` with `yield` statements. However, ReactPHP's event loop integration would require callers to use `React\Promise\Deferred` and resolve from the generator, which adds complexity. This is a "consider" item for future enhancement if async rendering becomes a priority.

---

### 6.3 Consider async `FuzzyMatch::matchAsync()` for time-slicing

**File:** `src/FuzzyMatch.php`

**What is expected:**
Consider adding `matchAsync(string $query, array $items, ?CancellationToken $token = null): \React\Promise\Promise` that scores candidates in batches using `loop()->futureTick()` for time-slicing.

**Why:** Scoring all N candidates in a single synchronous loop blocks for large lists.

**Severity:** low

**Conditions for success:**
1. If implemented: `matchAsync()` returns a `React\Promise\Promise`
2. Scoring is batched (e.g., 100 candidates per `futureTick`) to avoid blocking
3. Cancellation via `CancellationToken` works mid-scoring

**Investigation notes:**
The `loop()->futureTick()` pattern in ReactPHP schedules a callback to run on the next event loop iteration. By chunking the scoring into batches of ~100 candidates per tick, the event loop stays responsive. The `matchAsync()` would return a `React\Promise\Deferred` promise that resolves when all scoring is complete or cancelled. This is a "consider" item since `FuzzyMatch` is typically used for small-to-medium lists where synchronous scoring is fast enough.

---

## Phase 7: Code Quality [PENDING]

### 7.1 Remove `DefaultPrefixer::ansiWidth()` — use `Width::string()` directly

**File:** `src/DefaultPrefixer.php:103-107`, `src/DefaultPrefixer.php:59-60`

**What is expected:**
Remove `public static function ansiWidth(string $s): int` — it's a direct passthrough to `Width::string()` with no additional logic. Update all call sites to use `Width::string()` directly.

**Why:** Dead code duplication. `DefaultPrefixer::ansiWidth()` wraps `Width::string()` which is already imported.

**Severity:** low

**Conditions for success:**
1. `DefaultPrefixer::ansiWidth()` is removed
2. All call sites (`$this->sepWidth = self::ansiWidth($this->separator)` at line 59, etc.) use `Width::string()` directly
3. `Width` is imported in `DefaultPrefixer.php`

**Related code:**
- `src/DefaultPrefixer.php:104-107` — `public static function ansiWidth(string $s): int { return Width::string($s); }`
- `src/DefaultPrefixer.php:7` — `use SugarCraft\Core\Util\Width;` already imported

---

### 7.2 Replace `strtolower()` with `\mb_strtolower(..., 'UTF-8')` in `FuzzyMatch`

**File:** `src/FuzzyMatch.php:40-41`

**What is expected:**
Replace `strtolower($query)` and `strtolower($candidate)` with `\mb_strtolower(..., 'UTF-8')`.

**Why:** `strtolower()` is locale-dependent and does not correctly lowercase non-ASCII Unicode characters (e.g., `É → é`, `Σ → σ` in Greek). `\mb_strtolower(..., 'UTF-8')` is always UTF-8 aware and locale-independent.

**Severity:** medium

**Conditions for success:**
1. `FuzzyMatch::score('ÉCLAIR', 'éclair')` returns a positive match score (case-insensitive match works)
2. `FuzzyMatch::score('ΣΠΑ', 'σπα')` returns a positive match score (Greek letters work)
3. Existing tests pass with ASCII input

**Related code:**
- `src/FuzzyMatch.php:40-41` — `$q = strtolower($query); $c = strtolower($candidate);`

**Investigation notes:**
Note: `score()` uses string indexing `$q[$i - 1]` which operates on bytes. For full Unicode support, the entire algorithm would need grapheme-level iteration. This fix at minimum makes the case-folding correct for the Latin script.

---

### 7.3 Make FuzzyMatch scoring constants configurable

**File:** `src/FuzzyMatch.php:15-19`

**What is expected:**
Extract scoring constants into a `ScoringProfile` class or pass as constructor parameters. At minimum make them `protected` with a setter method.

**Why:** All scoring parameters are hardcoded private constants. There is no way to tune the algorithm for different use cases (e.g., tighter matching, more gap tolerance).

**Severity:** low

**Conditions for success:**
1. A `ScoringProfile` class or enum exists with the 5 constants
2. `FuzzyMatch` can be constructed with a custom profile
3. Default behavior unchanged with sensible defaults

**Related code:**
- `src/FuzzyMatch.php:15-19` — 5 private constants

---

### 7.4 Add optional `LoggerInterface` parameter to `View()` for error logging

**File:** `src/Model.php:448-477`

**What is expected:**
Add optional `LoggerInterface $logger = null` parameter to `View()`. When an exception is caught, log it before returning the error string.

**Why:** Silent error conversion to strings with no logging makes debugging difficult.

**Severity:** low

**Conditions for success:**
1. `View()` accepts `?LoggerInterface $logger = null`
2. When an exception is caught and `$logger` is provided, the error is logged at WARNING level
3. When `$logger` is null, behavior unchanged (no logging)

---

### 7.5 Fix `bufferFromOutput()` mixed string access patterns

**File:** `src/Model.php:642`

**What is expected:**
Use consistent access — either `mb_substr` with bounds checking, or iterate grapheme-by-grapheme. The current code uses `isset($line[$col])` (byte offset check) then `mb_substr($line, $col, 1)` (character-level extraction).

**Why:** These can behave differently with multi-byte characters. However, since `$line` comes from `explode("\n", $output)` and output is plain text, it may not manifest in practice.

**Severity:** low

**Conditions for success:**
1. Code inspection confirms consistency
2. Test with CJK/emoji content in items renders correctly

**Related code:**
- `src/Model.php:642` — `$char = isset($line[$col]) ? \mb_substr($line, $col, 1) : ' ';`
- `src/Model.php:637` — `$lines = \explode("\n", $output);` — output is plain text

---

### 7.6 Add `getItemIds(): array` accessor to avoid reflection in tests

**File:** `src/Model.php`

**What is expected:**
Add `getItemIds(): array` public method that returns the list of item IDs in order. This allows tests to verify ID uniqueness without reflection.

**Why:** `tests/ModelTest.php:716-719` uses reflection to access private `$items` property for the `testItemIdsAreUniqueAndIncreasing` test. A public accessor would be cleaner.

**Severity:** low

**Conditions for success:**
1. `getItemIds()` returns `array<int>` of item IDs in order
2. `testItemIdsAreUniqueAndIncreasing` can use the public method instead of reflection

**Related code:**
- `tests/ModelTest.php:707-727` — `testItemIdsAreUniqueAndIncreasing` uses reflection
- `src/Item.php:16` — `public readonly int $id;`

---

## Phase 8: Compatibility & Documentation [PENDING]

### 8.1 Document `lineOffset` semantic in class docblock

**File:** `src/Model.php`

**What is expected:**
Add a detailed docblock explanation for `$lineOffset` clarifying its semantic effect on rendering. The property is passed to prefixer/suffixer but its effect on the viewport is unclear.

**Why:** The property defaults to 5 and is passed to `initPrefixer()`/`initSuffixer()`, but its semantic effect is not documented. Users must read the code to understand it.

**Severity:** low

**Conditions for success:**
1. `src/Model.php:45` — `public int $lineOffset = 5;` has a detailed docblock
2. Class docblock at line 14-35 mentions `lineOffset` and explains its role

---

### 8.2 Document PHP 8.4 nullable parameter deprecation note

**File:** `src/Model.php`

**What is expected:**
Add a README or inline note about PHP 8.4 nullable parameter syntax. Current `?Type $param = null` is fine; future union types `?Type` will be deprecated in favor of `?Type`. This is forward-looking documentation.

**Why:** The finding notes that `?Buffer $previousFrame = null` and `?\Closure $lessFunc = null` use the older nullable syntax. PHP 8.4 deprecates `?Type` in favor of explicit union types.

**Severity:** low

**Conditions for success:**
1. A note in CALIBER_LEARNINGS.md or inline docblock clarifies the migration path for PHP 8.4 compatibility

---

### 8.3 Verify `idCounter` per-instance behavior is documented

**File:** `src/Model.php`

**What is expected:**
Document that each Model instance has its own `$idCounter` starting at 0. Cloned models have independent counters. This is by design but worth noting.

**Why:** When `mutate()` clones the model, the clone's `$idCounter` is also 0. Two independent model instances can have items with the same ID. This is fine for display but could cause issues if IDs are used for cross-model identity.

**Severity:** informational

**Conditions for success:**
1. Either a docblock on `$idCounter` clarifies this, or CALIBER_LEARNINGS.md notes it

**Related code:**
- `src/Model.php:79` — `private int $idCounter = 0;`
- `src/Model.php:103` — `mutate()` does `clone $this` — counter is also cloned (0)
- `src/Model.php:235` — `$id = $this->idCounter++;`

---

### 8.4 Document candy-mouse integration intent for Finding #17

**File:** `candy-lister/CALIBER_LEARNINGS.md`

**What is expected:**
Add an explicit note that mouse interaction (Finding #17) is deferred to `candy-mouse` integration per project conventions, with a link to the candy-mouse library. This makes the decision explicit and traceable.

**Why:** Finding #17 (no mouse interaction support) was noted as being handled by `candy-mouse` per CALIBER_LEARNINGS.md: "Mouse hit-testing self-contained via candy-mouse." However, there is no formal task tracking this decision in the implementation plan.

**Severity:** informational

**Conditions for success:**
1. CALIBER_LEARNINGS.md has a note explicitly linking Finding #17 to candy-mouse integration intent
2. The note documents the expected integration points (hit-testing, click handlers)

**Related code:**
- `CALIBER_LEARNINGS.md:11-13` — existing mouse integration note
- `candy-mouse/src/ZoneClickTracker.php` — zone-based click tracking
- `candy-mouse/src/Scanner.php` — mouse event scanning
- `src/Model.php` — current mouse event handling (none)

**Investigation notes:**
The CALIBER_LEARNINGS.md already states "Mouse hit-testing self-contained via candy-mouse. Don't pass Managers around for new code." This is the correct architectural decision — mouse hit-testing is a separate concern that should be handled by `candy-mouse` which owns the `ZoneClickTracker`, `Scanner`, and `MouseEvent` classes. The integration would involve calling `candy-mouse`'s `Scanner` to convert ANSI coordinates to item indices, then calling `Model::setCursor()` or `Model::withFilterFn()` accordingly. This finding requires no code changes in `candy-lister` itself, only documentation of the integration intent.

---

## Verification

### Pre-flight
```bash
cd /home/sites/sugarcraft/candy-lister && composer install
```

### Run tests
```bash
cd /home/sites/sugarcraft/candy-lister && vendor/bin/phpunit
```

### Specific test files
```bash
cd /home/sites/sugarcraft/candy-lister && vendor/bin/phpunit tests/ModelTest.php
cd /home/sites/sugarcraft/candy-lister && vendor/bin/phpunit tests/FuzzyMatchTest.php
cd /home/sites/sugarcraft/candy-lister && vendor/bin/phpunit tests/FilterStateTest.php
cd /home/sites/sugarcraft/candy-lister && vendor/bin/phpunit tests/DefaultPrefixerTest.php
cd /home/sites/sugarcraft/candy-lister && vendor/bin/phpunit tests/ItemTest.php
```

### Composer validation
```bash
cd /home/sites/sugarcraft/candy-lister && composer validate
```

### Path repo checker
```bash
cd /home/sites/sugarcraft && php tools/check-path-repos.php
```

---

## Notes

- **2026-06-30:** Plan created from code review findings (`findings/candy-lister.md`). Investigation covered: `src/Model.php`, `src/FilterState.php`, `src/FuzzyMatch.php`, `src/DefaultPrefixer.php`, `src/DefaultSuffixer.php`, `src/Item.php`, `src/StringItem.php`, `src/Prefixer.php`, `src/Suffixer.php`, `src/Lang.php`, `tests/ModelTest.php`, `composer.json`, `CALIBER_LEARNINGS.md`, `candy-core/src/Util/Width.php`, `candy-async/src/CancellationToken.php`, `lang/en.php`.
- **Finding #17 (no mouse interaction)** is tracked as a `candy-mouse` integration concern per CALIBER_LEARNINGS.md: "Mouse hit-testing self-contained via candy-mouse. Don't pass Managers around for new code." This is a separate library integration, not an in-lib implementation item.
- **Finding #4 is a duplicate of finding #1** (both about exception handling in `View()`). They are consolidated in Phase 1, task 1.1.
- **Finding #33 is a duplicate of finding #6** (`splitOverWidth()` UTF-8). They are consolidated in Phase 1, task 1.4.
- **Total: 32 findings, consolidated to 28 actionable tasks across 8 phases.**
  - Phase 1: 4 critical bugs
  - Phase 2: 2 performance issues
  - Phase 3: 1 security issue
  - Phase 4: 7 API consistency items
  - Phase 5: 4 missing features (1 "consider")
  - Phase 6: 3 async patterns (2 "consider")
  - Phase 7: 6 code quality items
  - Phase 8: 3 compatibility/documentation items (1 informational)
