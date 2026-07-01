---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-focus

## Goal

Address all findings from the `candy-focus` code review — fixing code duplication, completing API symmetry, adding missing interface implementations, and clarifying misleading comments.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Extract `enabledPositions(): ?array` helper | Reduces ~30 duplicated lines between `next()` and `previous()`, centralizes invariant check | `src/FocusRing.php:214-229, 259-274` |
| Extract `private static function unique(array): array` | Eliminates triplicated `in_array` deduplication loop; uses `array_flip` for O(n) performance | `src/FocusRing.php:53-58, 75-84, 179-184` |
| Add `disabledIds(): array` method | Completes API symmetry with `enabledIds()`; enables debugging/UI indication of disabled regions | `src/FocusRing.php:328-334` |
| Add `enabledCount(): int` and `disabledCount(): int` | Zero-cost alternative to `count($ring->enabledIds())` which allocates a new array | `src/FocusRing.php` |
| Add `IteratorAggregate` interface | Natural extension for a "ring" of regions; matches existing pattern in `sugar-glow/src/Pager.php` | `sugar-glow/src/Pager.php:12` |
| Add `JsonSerializable` interface | Enables `json_encode($ring)` for session restore / process restarts | `candy-vcr/tests/Msg/UserJsonableMsg.php:14` |
| Fix `ids()` to return `array_values($this->ids)` | Prevents callers from mutating internal `readonly` array; matches immutable design philosophy | `src/FocusRing.php:359-362`, CALIBER_LEARNINGS |
| Clarify misleading comment | "wrap would land on self" mischaracterizes the sole-enabled guard | `src/FocusRing.php:226, 272` |
| Use `array_flip` for O(n) deduplication | Replaces O(n²) `in_array` loop; performance win for rings with 100+ regions | `src/FocusRing.php:53-58, 75-84, 179-184` |
| Accept assertion in production | Sub-microsecond cost is negligible for TUI hot path; would require `zend.assertions=0` in prod | `src/FocusRing.php:38` |

---

## Phase 1: Critical Fixes [PENDING]

- [ ] **1.1 Fix `ids()` to return a copy** ← CURRENT
  - **File:** `src/FocusRing.php:359-362`
  - **What:** Change `return $this->ids;` to `return array_values($this->ids);`
  - **Why:** Returning direct reference exposes internal `readonly` array to mutation by callers, violating immutability contract
  - **Severity:** critical
  - **Success:** `FocusRing::of('a','b')->ids()[] = 'c';` does not modify the ring; existing test `testMutatorsDoNotMutateTheReceiver` at `tests/FocusRingTest.php:230` asserts this
  - **Tests:** Run all 48 existing tests after change

- [ ] **1.2 Add `disabledIds(): array` method**
  - **File:** `src/FocusRing.php` (after `enabledIds()` at line 334)
  - **What:**
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
  - **Why:** Completes API symmetry with `enabledIds()`; callers may need to inspect full disabled set for debugging/UI
  - **Severity:** high
  - **Success:** `FocusRing::of('a','b','c')->disable('b')->disabledIds()` returns `['b']`; empty ring returns `[]`
  - **Tests:** Add `testDisabledIdsReturnsDisabledRegions` and `testDisabledIdsEmptyWhenNoneDisabled`

---

## Phase 2: Code Deduplication Refactoring [PENDING]

- [ ] **2.1 Extract `enabledPositions(): ?array` helper**
  - **File:** `src/FocusRing.php` (after `enable()` method, around line 320)
  - **What:** Add private helper:
    ```php
    /**
     * Collect enabled positions. Returns null when ring has 0 or 1 enabled
     * region (signals no-op to caller).
     *
     * @return ?list<int> null when all-disabled or sole-enabled (no-op signal)
     */
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
  - **Why:** Eliminates ~30 duplicated lines across `next()` and `previous()`; centralizes the all-disabled/sole-enabled guard
  - **Severity:** medium
  - **Success:** `next()` and `previous()` each reduce to ~12 lines; behavior identical to before
  - **Tests:** All 48 tests pass unchanged

- [ ] **2.2 Extract `private static function unique(array): array`**
  - **File:** `src/FocusRing.php` (static helper method)
  - **What:** Add static deduplication helper using `array_flip` for O(n):
    ```php
    /**
     * Deduplicate ids preserving first-occurrence order.
     * Uses array_flip for O(n) vs O(n²) in_array loop.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    private static function unique(array $ids): array
    {
        return array_values(array_flip(array_flip($ids)));
    }
    ```
  - **Why:** Eliminates triplicated `in_array` deduplication loop; O(n) vs O(n²)
  - **Note:** `array_flip(array_flip($ids))` correctly restores first-occurrence order for string arrays
  - **Severity:** medium
  - **Success:** `of()`, `ofStrict()`, `reorder()` each use the helper; behavior identical to before
  - **Tests:** Existing `testOfDropsDuplicatesKeepingFirstOccurrence`, `testOfStrictThrowsOnDuplicate`, `testReorderDropsDuplicates` all pass

- [ ] **2.3 Update `next()` to use helper**
  - **File:** `src/FocusRing.php:207-249`
  - **What:** Refactor `next()` to call `enabledPositions()`; update comment at line 226
  - **Comment change:** "Sole enabled: wrap would land on self — noOp" → "Only one enabled region — cannot move to a different one, noOp"
  - **Severity:** medium
  - **Success:** All traversal tests pass

- [ ] **2.4 Update `previous()` to use helper**
  - **File:** `src/FocusRing.php:251-293`
  - **What:** Refactor `previous()` to call `enabledPositions()`; update comment at line 272
  - **Comment change:** Same clarification as 2.3
  - **Severity:** medium
  - **Success:** All traversal tests pass

- [ ] **2.5 Update comment in disabled-current search loops**
  - **File:** `src/FocusRing.php:234` (next), `src/FocusRing.php:278` (previous)
  - **What:**
    - Line 234: "Current region is disabled — find the first enabled after current" → "find the first enabled from current moving forward (wrapping)"
    - Line 278: "Current region is disabled — find the first enabled before current" → "find the first enabled from current moving backward (wrapping)"
  - **Why:** Comments currently suggest one-directional search but modulo arithmetic searches in all directions
  - **Severity:** low (clarity only, no behavior change)

---

## Phase 3: Missing Feature Additions [PENDING]

- [ ] **3.1 Add `enabledCount(): int`** ← CURRENT
  - **File:** `src/FocusRing.php` (after `disabledIds()`)
  - **What:**
    ```php
    /** Zero-cost enabled region count (avoids array allocation of enabledIds()). */
    public function enabledCount(): int
    {
        return count($this->ids) - count($this->disabled);
    }
    ```
  - **Why:** Direct accessor avoids `count($ring->enabledIds())` which allocates a new array
  - **Severity:** low
  - **Success:** `enabledCount()` returns correct count; equals `count(enabledIds())` for all states
  - **Tests:** Add `testEnabledCountReturnsCorrectCount`

- [ ] **3.2 Add `disabledCount(): int`**
  - **File:** `src/FocusRing.php`
  - **What:**
    ```php
    /** Zero-cost disabled region count. */
    public function disabledCount(): int
    {
        return count($this->disabled);
    }
    ```
  - **Why:** Complements `enabledCount()` for symmetry
  - **Severity:** low
  - **Success:** `disabledCount()` returns correct count
  - **Tests:** Add `testDisabledCountReturnsCorrectCount`

- [ ] **3.3 Add `IteratorAggregate` interface**
  - **File:** `src/FocusRing.php:25`
  - **What:** Add `\IteratorAggregate` to class declaration; implement `getIterator()`:
    ```php
    final class FocusRing implements \IteratorAggregate
    {
        // ... existing code ...

        /** @return \Traversable<int, string> */
        public function getIterator(): \Traversable
        {
            yield from $this->ids;
        }
    }
    ```
  - **Why:** Natural extension allowing `foreach ($ring as $id)`; matches existing pattern in `sugar-glow/src/Pager.php:12`
  - **Severity:** low
  - **Success:** `foreach (FocusRing::of('a','b','c') as $i => $id)` works correctly
  - **Tests:** Add `testIterateOverRingYieldsIdsInOrder`

- [ ] **3.4 Add `JsonSerializable` interface**
  - **File:** `src/FocusRing.php:25`
  - **What:** Add `\JsonSerializable` to class declaration; implement `jsonSerialize()`:
    ```php
    final class FocusRing implements \JsonSerializable, \IteratorAggregate
    {
        // ... existing code ...

        public function jsonSerialize(): array
        {
            return [
                'ids' => $this->ids,
                'index' => $this->index,
                'disabled' => array_keys($this->disabled),
            ];
        }
    }
    ```
  - **Why:** Enables `json_encode($ring)` for session restore / process restarts
  - **Severity:** low
  - **Success:** `json_encode(FocusRing::of('a','b')->disable('a'))` returns valid JSON with ids, index, disabled keys
  - **Tests:** Add `testJsonSerializeReturnsCorrectStructure`

- [ ] **3.5 Add new methods to README API table**
  - **File:** `candy-focus/README.md:68-81`
  - **What:** Add `disabledIds(): list<string>`, `enabledCount(): int`, `disabledCount(): int` to API table
  - **Why:** Documentation must reflect added methods
  - **Severity:** low
  - **Success:** README accurately documents all public methods

---

## Phase 4: Performance Optimization [PENDING]

- [ ] **4.1 Replace O(n²) deduplication with `array_flip`** ← CURRENT
  - **Files:** `src/FocusRing.php:of()` (line 53-58), `ofStrict()` (line 75-84), `reorder()` (line 179-184)
  - **What:** Use the `unique()` helper added in Phase 2 to replace all three `in_array` loops
  - **Why:** O(n) vs O(n²); significant for rings with 100+ regions
  - **Severity:** low (negligible for typical ≤10 region rings)
  - **Success:** Existing deduplication tests all pass; benchmark shows improvement for large rings

---

## Phase 5: Final Verification [PENDING]

- [ ] **5.1 Run full test suite**
  - **Command:** `cd candy-focus && composer install && vendor/bin/phpunit`
  - **Expected:** All 48 tests pass (104 assertions), plus any new tests added in Phases 1-4
  - **Success:** 100% pass rate

- [ ] **5.2 Verify immutability is preserved**
  - **Test:** `testMutatorsDoNotMutateTheReceiver` at `tests/FocusRingTest.php:230` — specifically tests that `ids()` returns the same array before and after mutations
  - **Success:** After Phase 1.1 fix, this test verifies the fix works

- [ ] **5.3 Update CALIBER_LEARNINGS.md if needed**
  - **File:** `candy-focus/CALIBER_LEARNINGS.md`
  - **What:** Add notes about new interface implementations if relevant to future maintainers
  - **Why:** Keep learning file current with implementation decisions

---

## Notes

- **2026-06-30:** Plan created based on `findings/candy-focus.md` review with code investigation of `src/FocusRing.php`, `tests/FocusRingTest.php`, `CALIBER_LEARNINGS.md`, `composer.json`, and similar implementations (`sugar-glow/src/Pager.php`, `candy-vcr/src/Render/FrameStream.php`)
- **Assertion in production:** The `assert()` at `src/FocusRing.php:38` runs in production (PHP 8+ default). For a TUI focus ring this is sub-microsecond overhead and acceptable. Document that production should set `zend.assertions=0` if profiling shows it matters.
- **ArrayAccess not planned:** `ArrayAccess` support (`$ring['grid']`) was listed as lower-priority in findings (5.5). Not included in this plan — revisit if concrete use case arises.
- **Phases 1 and 2 must be completed before Phase 3** because the refactoring in Phase 2 modifies `next()` and `previous()` which are used by the new features in Phase 3.
- **Phase 4 (performance) can be combined with Phase 2** since the `unique()` helper uses `array_flip` by default.
