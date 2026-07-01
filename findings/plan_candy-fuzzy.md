# Implementation Plan: candy-fuzzy Code Audit

**Status:** not-started
**Phase:** 1
**Updated:** 2026-06-30

## Goal

Address all findings from the candy-fuzzy code audit dated 2026-06-29, ranging from dead code removal to new feature additions, organized by priority and dependency order.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Remove dead code in Highlighter (lines 31-33) | Unreachable code - after `isEmpty()` check returns early, `$indices` cannot be empty | `findings/candy-fuzzy.md:17-35` |
| Document SahilmMatcher case-sensitive bonus behavior | Bonuses fire based on original case even in case-sensitive mode - this is inconsistent but documented | `findings/candy-fuzzy.md:39-56` |
| Rename `$prevCandidateLower` → `$prevCharLower` | Variable holds previous char, not "lower candidate" | `findings/candy-fuzzy.md:59-69` |
| Add memory warning to SmithWatermanMatcher docs | Full matrix required for traceback - cannot optimize to two-row | `findings/candy-fuzzy.md:73-89` |
| Full-sort-then-slice is acceptable for TUI use | Noted as correct in findings - typical list sizes don't need heap/partial sort | `findings/candy-fuzzy.md:92-110` |
| Add `matchAllGenerator()` for large datasets | Memory efficiency for large candidate lists | `findings/candy-fuzzy.md:249-261` |
| Add `FuzzyMatcherFactory` for convenient instantiation | No matcher registry currently exists | `findings/candy-fuzzy.md:299-319` |
| Extract `MatchResultSorter` utility | Duplicated sorting logic in both matchers | `findings/candy-fuzzy.md:325-354` |
| Add configurable scoring to SahilmMatcher | Some callers may want to adjust scoring weights | `findings/candy-fuzzy.md:228-244` |
| Use `\Closure` type for Highlighter styler | Current implementation always uses closures | `findings/candy-fuzzy.md:194-210` |

---

## Phase 1: Critical Fixes [PENDING]

### 1.1 Remove Dead Code in Highlighter::highlight() [PENDING]

**File:** `candy-fuzzy/src/Highlighter.php:31-33`

**What is expected:**
Remove the unreachable code block at lines 31-33:
```php
if ($indices === []) {
    return $candidate;
}
```

**Why the change should be done:**
This check is unreachable. After line 24-26 returns early if `$result->isEmpty()` is true, line 29 assigns `$indices = $result->indices()`. If `isEmpty()` returned false (non-empty indices exist), `$indices` cannot be empty. This is dead code that adds noise and confusion.

**Severity:** Critical (dead code)

**Conditions for success:**
- Run `vendor/bin/phpunit` - all 97 tests must pass
- Run `php -l src/Highlighter.php` - no syntax errors
- Code review confirms lines 31-33 are removed

**Related code locations:**
- `candy-fuzzy/src/Highlighter.php:24-26` (first isEmpty() check)
- `candy-fuzzy/src/Highlighter.php:29` ($indices assignment)
- `candy-fuzzy/src/Highlighter.php:31-33` (dead code to remove)
- `candy-fuzzy/src/MatchResult.php:30-33` (isEmpty() definition)

**Investigation notes:**
The code flow analysis confirms:
1. Line 24-26: Early return if `$result->isEmpty()` (empty indices)
2. Line 29: `$indices = $result->indices()`
3. Lines 31-33: Second check for empty indices

If `isEmpty()` returned false (non-empty indices), then `$indices` cannot be empty at line 31. If `isEmpty()` returned true, we already returned at line 25. The second check can never trigger.

---

## Phase 2: High Priority Changes [PENDING]

### 2.1 Document SahilmMatcher Case-Sensitive Bonus Behavior [PENDING]

**File:** `candy-fuzzy/src/Matcher/SahilmMatcher.php:37-40` (constructor) and/or `compute()` method

**What is expected:**
Add inline documentation explaining that bonuses (LOWER_CASE_BONUS, FIRST_CHAR_BONUS, CAMEL_BONUS, SEPARATOR_BONUS) fire based on the original character case regardless of the `caseSensitive` flag. The `caseSensitive` flag only affects whether strings are lowercased for matching comparison, not whether case-based bonuses apply.

**Why the change should be done:**
This creates an inconsistency where `SahilmMatcher(true)->match('HELLO', 'HELLO')` gets FIRST_CHAR_BONUS but not LOWER_CASE_BONUS (matched char is uppercase), while `match('hello', 'HELLO')` returns null (case-sensitive no-match). Documentation prevents user confusion about expected behavior.

**Severity:** High (correctness)

**Conditions for success:**
- Add docblock explaining the behavior in constructor and/or compute() method
- Existing test `testCaseSensitiveMatchStillScores` at `tests/SahilmMatcherTest.php:197-206` documents this behavior
- All 97 tests pass

**Related code locations:**
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:37-40` (constructor with caseSensitive flag)
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:114-115` (lowercase transformation)
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:136-162` (bonus calculations use original case)
- `candy-fuzzy/tests/SahilmMatcherTest.php:139-145` (case-sensitive test)
- `candy-fuzzy/tests/SahilmMatcherTest.php:197-206` (case-sensitive still scores test)

**Investigation notes:**
The bonuses fire based on `$cOrig` (original case candidate) and `$cLow` (lowercased), not based on whether case-sensitive mode is enabled. When `caseSensitive=true`, the matching uses original case, but bonuses still apply based on character case analysis.

---

### 2.2 Rename `$prevCandidateLower` Variable [PENDING]

**File:** `candy-fuzzy/src/Matcher/SahilmMatcher.php:127, 147, 148, 152, 173`

**What is expected:**
Rename variable `$prevCandidateLower` to `$prevCharLower` throughout the `compute()` method.

**Why the change should be done:**
The variable holds the **previous character** (lowercased), not a "lower candidate." The name is misleading and violates the naming convention that prefers descriptive, accurate names. At line 127 it's initialized to empty string, then at line 173 it's assigned `$candidateChar` (the current character) for the next iteration.

**Severity:** High (code clarity)

**Conditions for success:**
- Rename variable throughout `compute()` method
- Run `vendor/bin/phpunit` - all tests pass
- Code review confirms variable name is accurate

**Related code locations:**
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:127` (declaration)
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:147` (first use in separator check)
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:148` (array lookup)
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:152` (prevCandidateLower used)
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:173` (assignment)

**Investigation notes:**
Looking at the loop (lines 129-175), `$prevCandidateLower` is assigned `$candidateChar` at line 173 at the end of each iteration. In the next iteration, it's the previous character. The variable name incorrectly suggests it holds a "lower candidate" string rather than a single character.

---

### 2.3 Add Memory Warning to SmithWatermanMatcher [PENDING]

**File:** `candy-fuzzy/src/Matcher/SmithWatermanMatcher.php:1-18` (class docblock)

**What is expected:**
Add a performance note in the class docblock documenting that:
1. The full scoring matrix is required for traceback (cannot be optimized to two-row)
2. For a 2000-char candidate with 100-char query, matrix size is ~201×2001 = ~402,000 elements
3. Very large candidates (10,000+ chars) may consume significant memory

**Why the change should be done:**
CALIBER_LEARNINGS.md:19-21 already documents this limitation, but exposing it in the source code PHPDoc ensures developers are aware before encountering OOM issues in production.

**Severity:** High (robustness)

**Conditions for success:**
- PHPDoc updated with memory consideration note
- All 97 tests pass

**Related code locations:**
- `candy-fuzzy/src/Matcher/SmithWatermanMatcher.php:10-15` (existing docblock)
- `candy-fuzzy/src/Matcher/SmithWatermanMatcher.php:112-115` (matrix allocation)
- `candy-fuzzy/CALIBER_LEARNINGS.md:19-21` (existing documentation)

**Investigation notes:**
The CALIBER_LEARNINGS.md correctly notes: "Smith-Waterman uses two-row matrix optimization for memory efficiency, but traceback requires the full matrix." This design decision is fundamental and cannot be changed without removing the matched-indices feature.

---

## Phase 3: Medium Priority - New Features [PENDING]

### 3.1 Add `matchAllGenerator()` Method [PENDING]

**Files:**
- `candy-fuzzy/src/FuzzyMatcher.php:43` (interface)
- `candy-fuzzy/src/Matcher/SmithWatermanMatcher.php:85` (implementation)
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:100` (implementation)

**What is expected:**
Add generator-based iteration for memory efficiency with large candidate lists:

```php
/**
 * Match a query against an iterable of candidates, yielding results as they are found.
 *
 * @param string    $query      The search query
 * @param iterable<string> $candidates Candidate strings to score
 * @param int|null  $limit      Maximum number of results to return (null = unlimited)
 * @param int       $minScore   Minimum score threshold
 * @return \Generator<MatchResult> Yields MatchResult as they are computed
 */
public function matchAllGenerator(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): \Generator;
```

**Why the change should be done:**
Current `matchAll()` returns `array<MatchResult>`, requiring all results in memory. For millions of items, this is problematic. Generator allows streaming results without holding all in memory.

**Severity:** Medium (new feature)

**Conditions for success:**
- New method added to `FuzzyMatcher` interface
- Implementation in both `SmithWatermanMatcher` and `SahilmMatcher`
- New unit tests for generator behavior
- All 97 existing tests pass + new tests pass

**Related code locations:**
- `candy-fuzzy/src/FuzzyMatcher.php:43` (existing matchAll declaration)
- `candy-fuzzy/src/Matcher/SmithWatermanMatcher.php:57-85` (matchAll implementation)
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:72-100` (matchAll implementation)

**Investigation notes:**
The implementation would yield individual MatchResults as they're computed, allowing callers to process results without waiting for all candidates to be evaluated. The limit parameter would still require storing up to `limit` results for sorting, but memory for the full candidate→result mapping would be freed after each yield.

---

### 3.2 Add FuzzyMatcherFactory [PENDING]

**File:** `candy-fuzzy/src/Matcher/FuzzyMatcherFactory.php` (new file)

**What is expected:**
Create a factory class for convenient matcher instantiation by name:

```php
final class FuzzyMatcherFactory
{
    /**
     * Create a matcher by name.
     *
     * @param string $type 'smith-waterman' or 'sahilm'
     * @return FuzzyMatcher
     * @throws \InvalidArgumentException If type is unknown
     */
    public static function create(string $type): FuzzyMatcher
    {
        return match ($type) {
            'smith-waterman' => new SmithWatermanMatcher(),
            'sahilm' => new SahilmMatcher(),
            default => throw new \InvalidArgumentException("Unknown matcher: $type"),
        };
    }
}
```

**Why the change should be done:**
No convenient way exists to instantiate a matcher by name. Factory pattern enables runtime matcher selection without coupling callers to concrete classes.

**Severity:** Medium (convenience)

**Conditions for success:**
- New factory class created in `src/Matcher/FuzzyMatcherFactory.php`
- Factory registered in composer.json autoload if needed
- Unit tests for factory
- All 97 existing tests pass

**Related code locations:**
- `candy-fuzzy/src/FuzzyMatcher.php` (interface)
- `candy-fuzzy/src/Matcher/SmithWatermanMatcher.php` (implementation)
- `candy-fuzzy/src/Matcher/SahilmMatcher.php` (implementation)

**Investigation notes:**
The factory would be placed in the `SugarCraft\Fuzzy\Matcher` namespace alongside the matchers. The autoload PSR-4 already maps `SugarCraft\Fuzzy\Matcher\` to `src/Matcher/`.

---

### 3.3 Extract MatchResultSorter Utility [PENDING]

**Files:**
- `candy-fuzzy/src/MatchResultSorter.php` (new)
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:89-91`
- `candy-fuzzy/src/Matcher/SmithWatermanMatcher.php:74-76`

**What is expected:**
Extract duplicated sorting logic into a shared utility:

```php
final class MatchResultSorter
{
    /**
     * Sort by score descending, then haystack ascending as tiebreak.
     *
     * @param array<MatchResult> $results
     * @return array<MatchResult>
     */
    public static function sort(array $results): array
    {
        usort($results, static fn(MatchResult $a, MatchResult $b) =>
            ($b->score <=> $a->score) ?: ($a->haystack <=> $b->haystack)
        );
        return $results;
    }
}
```

**Why the change should be done:**
Sorting logic is duplicated in `SahilmMatcher.php:89-91` and `SmithWatermanMatcher.php:74-76`. Extract to single source of truth for maintainability.

**Severity:** Medium (DRY)

**Conditions for success:**
- New utility class created in `src/MatchResultSorter.php`
- Both matchers updated to use `MatchResultSorter::sort()`
- All 97 tests pass

**Related code locations:**
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:86-91` (duplicated sort)
- `candy-fuzzy/src/Matcher/SmithWatermanMatcher.php:71-76` (duplicated sort)
- `candy-fuzzy/src/MatchResult.php` (MatchResult class)

**Investigation notes:**
The sorting logic is identical in both matchers. The comment in both locations says "Sort by score descending, then candidate ascending as tiebreak." The `?:` (not `??`) ensures equal scores fall through to the haystack tiebreak.

---

## Phase 4: Low Priority Improvements [PENDING]

### 4.1 Add Configurable Scoring to SahilmMatcher [PENDING]

**File:** `candy-fuzzy/src/Matcher/SahilmMatcher.php:26-31` (constants) and constructor

**What is expected:**
Consider adding a `SahilmMatcherConfig` class or constructor parameters to allow tuning scoring constants:
- MATCH_SCORE = 1
- CONSECUTIVE_BONUS = 5
- SEPARATOR_BONUS = 10
- CAMEL_BONUS = 10
- FIRST_CHAR_BONUS = 15
- LOWER_CASE_BONUS = 1

**Why the change should be done:**
Some callers may want to disable the camelCase bonus or adjust weights for their use case. Currently these are hardcoded private constants with no way to customize.

**Severity:** Low (enhancement)

**Conditions for success:**
- If implemented: new config class with tests
- All 97 existing tests pass (backward compatible)

**Related code locations:**
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:26-31` (scoring constants)
- `candy-fuzzy/src/Matcher/SahilmMatcher.php:37-40` (constructor)

**Investigation notes:**
This is a design decision. Options include:
1. Constructor parameter for each bonus (many parameters)
2. Config object/class (additional class)
3. Named constructors with preset configurations

The finding recommends either a config class or constructor parameters.

---

### 4.2 Use `\Closure` Type for Highlighter Styler [PENDING]

**File:** `candy-fuzzy/src/Highlighter.php:22`

**What is expected:**
Change `callable` type hint to `\Closure` for better type safety:

```php
public function highlight(MatchResult $result, \Closure $styler): string
```

**Why the change should be done:**
The current implementation always uses closures (arrow functions in tests). Using `\Closure` provides better type specificity. PHP 8.3+ supports `\Closure` as a type hint.

**Severity:** Low (type safety)

**Conditions for success:**
- Type hint updated in Highlighter
- All 97 tests pass

**Related code locations:**
- `candy-fuzzy/src/Highlighter.php:22` (styler parameter)
- `candy-fuzzy/tests/HighlighterTest.php` (all tests use closures)

**Investigation notes:**
All tests use arrow functions (`fn($m) => ...`) which are `\Closure` instances. The `callable` type is broader, but `\Closure` is more specific and appropriate here.

---

### 4.3 Document Local Alignment Constraint [PENDING]

**File:** `candy-fuzzy/src/Matcher/SmithWatermanMatcher.php:88-103` (compute method docblock)

**What is expected:**
Add documentation clarifying that while Smith-Waterman is designed for local alignment, the current implementation requires ALL query characters to match (queryLen <= candidateLen at line 101-103). True partial-match local alignment is not exposed.

**Why the change should be done:**
Prevents user confusion about expected behavior. CALIBER_LEARNINGS.md:19-21 explains why traceback requires full matrix, but the alignment constraint should be documented in source.

**Severity:** Low (documentation)

**Conditions for success:**
- PHPDoc updated with alignment constraint note
- All 97 tests pass

**Related code locations:**
- `candy-fuzzy/src/Matcher/SmithWatermanMatcher.php:99-103` (query length check)
- `candy-fuzzy/CALIBER_LEARNINGS.md` (existing documentation)

**Investigation notes:**
The check at lines 101-103 returns null if queryLen > candidateLen. This is correct for the library's use case (filter matching where the query should appear in order), but true local alignment would allow partial matches.

---

## Phase 5: Not Recommended / Out of Scope [PENDING]

### 5.1 Async/ReactPHP Support [NOT RECOMMENDED]

**Files:** N/A

**What was proposed:**
Add `matchAllAsync()` returning `\React\Promise\PromiseInterface`

**Status:** Not recommended for current scope. Typical TUI use cases involve thousands of items, not millions. The synchronous implementation is appropriate for the library's intended use case.

**Rationale:** The findings note "for typical TUI use cases (thousands of items), this is likely overkill."

---

### 5.2 Extract MatchResult Construction to Factory [NOT RECOMMENDED]

**Files:** `SahilmMatcher.php:182-187`, `SmithWatermanMatcher.php:170-175`

**What was proposed:**
Add static factory method on `MatchResult` to reduce duplication

**Status:** Minor duplication, not worth added complexity. The construction pattern is straightforward and both locations are well-documented.

---

### 5.3 Extract Empty Query/Candidate Guards to Base Class [NOT RECOMMENDED]

**Files:** `SahilmMatcher.php:51-53, 74-76`, `SmithWatermanMatcher.php:36-38, 59-61`

**What was proposed:**
Extract guards to base class or trait

**Status:** Simple guards, duplication is minor. Extracting to a base class would add complexity without significant benefit.

---

## Summary of Changes

| Priority | Item | Files Affected | New Files |
|----------|------|----------------|-----------|
| Critical | Remove dead code (lines 31-33) | `Highlighter.php` | - |
| High | Document case-sensitive bonus behavior | `SahilmMatcher.php` | - |
| High | Rename `$prevCandidateLower` → `$prevCharLower` | `SahilmMatcher.php` | - |
| High | Add memory warning to docs | `SmithWatermanMatcher.php` | - |
| Medium | Add `matchAllGenerator()` | `FuzzyMatcher.php`, `SmithWatermanMatcher.php`, `SahilmMatcher.php` | - |
| Medium | Add `FuzzyMatcherFactory` | - | `FuzzyMatcherFactory.php` |
| Medium | Extract `MatchResultSorter` | `SahilmMatcher.php`, `SmithWatermanMatcher.php` | `MatchResultSorter.php` |
| Low | Configurable scoring constants | `SahilmMatcher.php` | optional `SahilmMatcherConfig.php` |
| Low | Use `\Closure` type | `Highlighter.php` | - |
| Low | Document local alignment constraint | `SmithWatermanMatcher.php` | - |

---

## Notes

- 2026-06-30: Plan created based on audit findings from `findings/candy-fuzzy.md`
- All changes should maintain the 97 tests / 4216 assertions passing
- No changes to public API of `FuzzyMatcher` interface without careful consideration (except new method additions)
- Zero runtime dependencies (PHP 8.3 only) - no composer changes needed for most items
- The library has excellent test coverage and no memory leaks or security issues
