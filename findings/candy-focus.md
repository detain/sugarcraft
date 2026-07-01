# Code Review: candy-focus

**Library:** sugarcraft/candy-focus  
**FocusRing:** Focus ring state machine — an ordered set of focusable regions with wrap-around Tab/Shift-Tab traversal  
**Files Reviewed:**
- `src/FocusRing.php` (373 lines)
- `tests/FocusRingTest.php` (435 lines, 48 tests)
- `composer.json`, `phpunit.xml`, `CALIBER_LEARNINGS.md`, `README.md`

**Test Status:** All 48 tests pass (104 assertions).

---

## 1. Code Quality & Logic Issues

### 1.1 `next()` and `previous()` share ~30 lines of nearly identical logic

**File:** `src/FocusRing.php:207–293`

Both methods repeat the same block:

```php
// Collect enabled positions
$enabledPositions = [];
foreach ($this->ids as $i => $id) {
    if (!isset($this->disabled[$id])) {
        $enabledPositions[] = $i;
    }
}

// All-disabled: noOp
if ($enabledPositions === []) {
    return $this;
}

// Sole enabled: wrap would land on self — noOp
if (count($enabledPositions) === 1) {
    return $this;
}
```

This ~15-line block appears nearly verbatim in both `next()` (lines 214–229) and `previous()` (lines 259–274). The only difference is in the final wrap computation.

**Recommendation:** Extract the enabled-position collection and the all-disabled/sole-enabled guard into a private helper:

```php
private function enabledPositions(): ?array
{
    $positions = [];
    foreach ($this->ids as $i => $id) {
        if (!isset($this->disabled[$id])) {
            $positions[] = $i;
        }
    }
    if ($positions === [] || count($positions) === 1) {
        return null; // null signals "no-op" case
    }
    return $positions;
}
```

This reduces both methods to ~12 lines each and centralizes the invariant check.

---

### 1.2 Deduplication logic is triplicated across `of()`, `ofStrict()`, and `reorder()`

**File:** `src/FocusRing.php`

- `of()` (lines 53–58)
- `ofStrict()` (lines 75–84)
- `reorder()` (lines 179–184)

All three use the same `in_array($id, $unique, true)` loop pattern for first-wins deduplication. While each has slightly different termination logic (one collects, one throws, one continues), the core loop is identical.

**Recommendation:** Extract to a small private static helper:

```php
private static function unique(array $ids): array
{
    $unique = [];
    foreach ($ids as $id) {
        if (!in_array($id, $unique, true)) {
            $unique[] = $id;
        }
    }
    return $unique;
}
```

Then `of()` becomes 2 lines, `reorder()` deduplication becomes 1 line, and `ofStrict()` only differs by the throw.

---

### 1.3 Misleading comment in `next()` and `previous()`

**File:** `src/FocusRing.php:226–229`

```php
// Sole enabled: wrap would land on self — noOp
if (count($enabledPositions) === 1) {
    return $this;
}
```

The comment says "wrap would land on self" but the actual reason is "there is only one enabled region, so traversal cannot move to a different region" — it has nothing to do with wrapping. If there were 2 enabled regions and you were on the second-to-last one, wrapping would correctly land on a different region.

**Recommendation:** Rewrite comment to:

```php
// Only one enabled region — cannot move to a different one, noOp
```

Same issue in `previous()` at line 272.

---

## 2. Missing Features

### 2.1 No `disabledIds(): array` method

The class has `enabledIds()` (line 328) but no counterpart to retrieve which regions are disabled. This asymmetry is surprising — a caller may want to inspect the full disabled set, particularly for debugging or UI indication of "dimmed" regions.

**Recommendation:** Add:

```php
/** @return list<string> ids of all disabled regions in traversal order */
public function disabledIds(): array
{
    return array_values(array_filter(
        $this->ids,
        fn (string $id): bool => isset($this->disabled[$id]),
    ));
}
```

### 2.2 No `disabledCount(): int` or `enabledCount(): int`

Currently to get enabled count, you must call `enabledIds()` which allocates a new array. A direct accessor would be zero-cost:

```php
public function enabledCount(): int
{
    return count($this->ids) - count($this->disabled);
}
```

### 2.3 No Iterator / Traversable support

The class has `ids()`, `count()`, `current()`, `index()` — it is naturally an iterable focus ring but cannot be used in `foreach`. Implementing `IteratorAggregate` would allow:

```php
foreach ($ring as $id) { ... }
```

This is a natural extension for a "ring" of regions.

### 2.4 No `JsonSerializable` implementation

Focus ring state often needs to survive process restarts (e.g., session restore). Implementing `jsonSerialize()` would make this trivial:

```php
public function jsonSerialize(): array
{
    return [
        'ids' => $this->ids,
        'index' => $this->index,
        'disabled' => array_keys($this->disabled),
    ];
}
```

### 2.5 No `ArrayAccess` support for region lookup

`$ring['grid']` syntax would be natural for checking `has()` or `isFocused()`. But this is lower priority.

---

## 3. Performance Considerations

### 3.1 O(n²) deduplication in `of()`, `ofStrict()`, `reorder()`

All three use `in_array($id, $unique, true)` which is O(n) per element, making the overall deduplication O(n²).

**File:** `src/FocusRing.php:53–58`, `76–83`, `180–184`

For typical focus rings (≤10 regions), this is negligible. But if the ring grows to hundreds of regions (e.g., a spreadsheet with many cells), this becomes measurable.

**Recommendation (low priority):** Use `array_flip` for O(n) deduplication:

```php
$unique = array_keys(array_flip($ids)); // preserves order, O(n)
```

Or maintain a `array<string, true>` seen map for the same O(n) result with cleaner semantics.

### 3.2 `enabledIds()` allocates a new array on every call

**File:** `src/FocusRing.php:328–334`

```php
public function enabledIds(): array
{
    return array_values(array_filter(
        $this->ids,
        fn (string $id): bool => !isset($this->disabled[$id]),
    ));
}
```

This is fine since `enabledIds()` is likely called only on render (once per frame), but it could be cached if the disabled set changes rarely. However, immutability means each state change produces a new ring, so caching wouldn't persist across frames anyway. This is a **low-priority concern** — the current approach is correct for the TEA pattern.

---

## 4. Security Assessment

**No security issues found.** The class performs pure data transformation on its own internal arrays. There is:
- No external input parsing
- No file I/O
- No eval or dynamic code execution
- No user-provided format strings (the one sprintf at line 79 is for an internally-generated error message)
- No SQL operations
- No path traversal or file access

The `\InvalidArgumentException` thrown by `ofStrict()` on duplicate ids is appropriate validation, not a security boundary.

---

## 5. API Design & Usability

### 5.1 `ids()` returns direct reference to internal array

**File:** `src/FocusRing.php:359–362`

```php
public function ids(): array
{
    return $this->ids;
}
```

In PHP, returning a `readonly` array property via a method still exposes the array by reference. Callers could theoretically mutate `ids` directly. This is inconsistent with the immutable design philosophy stated in the class docblock and CALIBER_LEARNINGS.

**Recommendation:** Return a copy:

```php
public function ids(): array
{
    return array_values($this->ids);
}
```

`array_values()` is cheap (just re-indexing) and guarantees the caller can't mutate internal state.

### 5.2 `enabledIds()` vs `ids()` naming asymmetry

- `ids()` — all ids
- `enabledIds()` — only enabled ids

But there is no `disabledIds()`. The API feels incomplete. See **2.1 Missing Features**.

### 5.3 No way to check "how many regions are enabled"

`enabledIds()` returns an array, requiring `count($ring->enabledIds())` which is wasteful if you only want the count. See **2.2 Missing Features**.

### 5.4 `isEmpty()` uses `=== []` comparison on array

**File:** `src/FocusRing.php:369–372`

```php
public function isEmpty(): bool
{
    return $this->ids === [];
}
```

This is fine and widely used in the codebase. For absolute strictness, `count($this->ids) === 0` is technically safer (handles edge cases if `readonly` array semantics change), but `=== []` is idiomatic in this codebase.

---

## 6. Async / ReactPHP Ecosystem Compatibility

**Not applicable — by design.** The library is intentionally dependency-free and synchronous. From CALIBER_LEARNINGS:

> **Dependency-free on purpose.** `FocusRing` is pure focus-ring *state*: no rendering, no key decoding, no candy-core/candy-sprinkles imports.

For async contexts (ReactPHP event loops), the focus ring would be used as plain state in an async model — the model itself would be async, but the focus ring data structure itself does not need to be. This is the correct design.

**If ReactPHP integration is ever desired**, the library could offer an async-aware variant with `Promise<FocusRing>` mutators, but this would be a separate class, not a change to this one.

---

## 7. Compatibility with Other SugarCraft Libs

### 7.1 No integration with candy-core `Model` contract

The README shows usage with a candy-core model:

```php
return [$this->withRing($ring), null];
```

But `FocusRing` itself has no `init()` / `update()` / `view()` / `subscriptions()` methods — it is purely state. This is correct and intentional per CALIBER_LEARNINGS.

### 7.2 Does not use `SugarCraft\Core\Concerns\Mutable` trait

The class opts for a private constructor with promoted `readonly` properties rather than using the `Mutable` trait pattern from `candy-core`. This is a **valid alternative approach** — the trait uses `get_object_vars()` reflection which has performance cost. The direct constructor approach in `FocusRing` is actually more performant and just as clear.

However, for consistency with other SugarCraft value objects (like `candy-sprinkles/src/Style.php`), it may be worth noting. The CALIBER_LEARNINGS explicitly documents the pattern used here, so this is an intentional deviation, not an oversight.

### 7.3 No `Width` or `Height` dependency

Some SugarCraft components use `SugarCraft\Core\Util\Width` — `FocusRing` has no such dependency, which is correct. The focus ring is purely logical, not geometric.

---

## 8. Constructor Assertion Runs in Production

**File:** `src/FocusRing.php:38`

```php
assert($ids === [] ? $index === -1 : ($index >= 0 && $index < count($ids)), 'FocusRing focus index out of range for ids');
```

`assert()` is active in PHP 8+ by default (when `zend.assertions=1`). This assertion runs on every state transition. For a hot path (Tab/Shift-Tab pressed multiple times per second), this is a micro-overhead.

**Recommendation:** Either:
1. Accept this as intended — assertions are meant to run in all environments and the cost is negligible for the use case
2. Gate with `assert(function() { ... })` or `PHP_ASSERT` compile-time flag
3. Document that production deployments should set `zend.assertions=0`

For a TUI focus ring, the assertion cost is negligible (sub-microsecond). This is a **low-priority concern** and arguably should stay.

---

## 9. Edge Cases — Behavior Analysis

### 9.1 `next()` when focused region is disabled but there are ≥2 enabled regions

**File:** `src/FocusRing.php:232–243`

```php
$currentEnabledIdx = array_search($this->index, $enabledPositions, true);
if ($currentEnabledIdx === false) {
    // Current region is disabled — find the first enabled after current
    $total = count($this->ids);
    for ($offset = 1; $offset <= $total; $offset++) {
        $candidate = ($this->index + $offset) % $total;
        if (!isset($this->disabled[$this->ids[$candidate]])) {
            return new self($this->ids, $candidate, $this->disabled);
        }
    }
    return $this; // Should not reach: we have ≥2 enabled, one must be findable
}
```

This block is correct but the comment is slightly misleading — it says "find the first enabled **after** current" but the loop actually searches in *all* directions (modulo arithmetic) — it will wrap around. So it's "find the first enabled from current moving forward." The comment should say "after current (wrapping)".

Same in `previous()` at lines 278–286 with "before current."

### 9.2 Empty-string region id is correctly handled as a real id

**File:** `src/FocusRing.php:220–228` (test)

The test `testEmptyStringIdIsADistinctRegionNotTheEmptySentinel()` confirms that `''` is a valid distinct region id, not confused with the empty-ring sentinel. This is correct behavior and well-tested.

---

## 10. Summary of Recommendations (Priority Order)

| Priority | Issue | File:Line | Recommendation |
|---|---|---|---|
| **High** | `ids()` returns direct internal array reference | `src/FocusRing.php:359` | Return `array_values($this->ids)` copy |
| **High** | No `disabledIds()` — asymmetry with `enabledIds()` | `src/FocusRing.php:328` | Add `disabledIds(): array` method |
| **Medium** | Duplicate enabled-position collection logic in `next()`/`previous()` | `src/FocusRing.php:214–229, 259–274` | Extract `enabledPositions(): ?array` helper |
| **Medium** | Triplicated deduplication loop in `of()`/`ofStrict()`/`reorder()` | `src/FocusRing.php:53–58, 75–84, 179–184` | Extract `private static function unique(array): array` |
| **Medium** | Misleading comment "wrap would land on self" | `src/FocusRing.php:226, 272` | Clarify: "only one enabled region — cannot move to different one" |
| **Low** | No `enabledCount(): int` | `src/FocusRing.php:364` | Add direct count accessor |
| **Low** | No `disabledCount(): int` | — | Add direct count accessor |
| **Low** | No `IteratorAggregate` | — | Allow `foreach ($ring as $id)` |
| **Low** | No `JsonSerializable` | — | Allow `json_encode($ring)` |
| **Low** | O(n²) deduplication via `in_array` | `src/FocusRing.php` | Use `array_flip` for O(n) if ring size grows |
| **Info** | Assertion runs in production | `src/FocusRing.php:38` | Acceptable; document `zend.assertions=0` for prod |

---

## Overall Assessment

**candy-focus is a well-designed, focused library** (pun intended). The implementation is correct (all 48 tests pass), the API is clean and intuitive, immutability is properly implemented, and the design decisions (dependency-free, no async coupling) are intentional and sound.

The main improvement opportunities are:
1. Reducing code duplication between `next()` and `previous()`
2. Adding `disabledIds()` to complete the API symmetry
3. Fixing the `ids()` return to prevent external mutation

The library's simplicity is its strength — it does one thing and does it well. Avoid the temptation to add features like async support or event hooks unless a concrete use case arises.
