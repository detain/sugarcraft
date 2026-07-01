# Code Audit: candy-fuzzy

**Library:** `sugarcraft/candy-fuzzy`  
**Date:** 2026-06-29  
**Tests:** 97 tests, 4216 assertions — all passing

---

## Summary

`candy-fuzzy` provides fuzzy string matching with two algorithms (Smith-Waterman and Sahilm-style) and matched character index extraction for UI highlighting. The codebase is generally well-structured with solid UTF-8 handling, good test coverage, and performance guards against O(n³) regressions. However, several issues ranging from dead code to missing features were identified.

---

## 1. Issues & Problems

### 1.1 Dead Code in `Highlighter::highlight()`

**File:** `src/Highlighter.php:31-33`

```php
if ($indices === []) {
    return $candidate;
}
```

This check is **redundant**. The code flow is:

1. Line 24-26: Early return if `$result->isEmpty()` (empty indices)
2. Line 29: `$indices = $result->indices()`
3. Line 31-33: Second check for empty indices

If `isEmpty()` returned false (non-empty indices), then `$indices` cannot be empty at line 31. If `isEmpty()` returned true, we already returned at line 25. The second check can never trigger.

**Recommendation:** Remove lines 31-33.

---

### 1.2 SahilmMatcher Case-Sensitivity Doesn't Disable Bonuses

**File:** `src/Matcher/SahilmMatcher.php:37-40`

The `caseSensitive` flag only controls whether strings are lowercased for matching purposes. The LOWER_CASE_BONUS, FIRST_CHAR_BONUS, CAMEL_BONUS, and SEPARATOR_BONUS are **always applied** regardless of the `caseSensitive` flag.

**Example:** `SahilmMatcher(true)->match('HELLO', 'HELLO')`:
- FIRST_CHAR_BONUS fires (first char matches)
- LOWER_CASE_BONUS does NOT fire (matched char is uppercase)
- But `match('hello', 'HELLO')` (case-sensitive no-match) returns `null`

**Problem:** This creates an inconsistency where case-sensitive mode still uses case information for scoring bonuses, but doesn't use case for the actual matching comparison.

**Recommendation:** Either:
- Document this behavior clearly, or
- Add a `useBonuses` constructor parameter, or
- Disable bonuses in case-sensitive mode

---

### 1.3 SahilmMatcher: Ambiguous Variable Naming

**File:** `src/Matcher/SahilmMatcher.php:127`

```php
$prevCandidateLower = '';
```

This variable holds the **previous character** (lowercased), not a "lower candidate." The name is misleading. It should be `$prevCharLower` or similar.

**Recommendation:** Rename to `$prevCharLower`.

---

## 2. Performance Bottlenecks

### 2.1 SmithWatermanMatcher: O(n²) Memory for Full Matrix

**File:** `src/Matcher/SmithWatermanMatcher.php:112-115`

```php
$matrix = array_fill(0, $queryLen + 1, array_fill(0, $candidateLen + 1, 0));
$traceback = array_fill(0, $queryLen + 1, array_fill(0, $candidateLen + 1, 0));
```

For a 2000-character candidate with a 100-character query, this creates a ~201×2001 = ~402,000 element matrix. For very large candidates (e.g., 10,000 chars), memory usage becomes significant.

**Current mitigation:** CALIBER_LEARNINGS.md correctly notes that traceback requires the full matrix, so this cannot be optimized to two-row as in classic Smith-Waterman.

**Recommendation:** Add a warning in documentation about memory usage for very large candidates. Consider adding an optional max candidate length check.

---

### 2.2 Full Sort When Limit is Specified

**File:** `src/Matcher/SahilmMatcher.php:89-97`  
**File:** `src/Matcher/SmithWatermanMatcher.php:74-82`

```php
usort($results, static fn(MatchResult $a, MatchResult $b) =>
    ($b->score <=> $a->score) ?: ($a->haystack <=> $b->haystack)
);

if ($limit !== null && $limit >= 0) {
    $results = array_slice($results, 0, $limit);
}
```

When `$limit` is specified and the candidate list is large, this sorts ALL results before slicing. For a list of 10,000 candidates returning 5 results, this is wasteful.

**Recommendation:** For large datasets with small limits, consider using a k-select algorithm or heap-based partial sort. However, the current note in the code is correct: "Simple full-sort-then-slice — no heap/partial-sort needed for typical TUI list sizes." This is a minor issue for typical use cases.

---

### 2.3 mb_str_split Called Twice Per match()

**File:** `src/Matcher/SmithWatermanMatcher.php:107-108`

```php
$q = mb_str_split(mb_strtolower($query, 'UTF-8'));
$c = mb_str_split(mb_strtolower($candidate, 'UTF-8'));
```

`mb_strtolower` already returns a string, which is then split. Fine for single match operations, but in `matchAll()`, this is called once per candidate.

**Current status:** This is the expected pattern and is noted as a performance optimization in the comments. No change needed.

---

## 3. Memory Leaks

### 3.1 No Memory Issues Detected

The library uses value types (arrays, strings) correctly. No object references are retained longer than necessary. The `MatchResult` class is properly immutable.

**Status:** No memory leaks found.

---

## 4. Overly Complex Logic Blocks

### 4.1 Highlighter::applyRuns() — Reverse Iteration

**File:** `src/Highlighter.php:96-103`

```php
for ($i = count($runs) - 1; $i >= 0; $i--) {
    $run = $runs[$i];
    $matched = mb_substr($candidate, $run['start'], $run['end'] - $run['start'] + 1, 'UTF-8');
    $styled = $styler($matched);
    $result = mb_substr($result, 0, $run['start'], 'UTF-8')
        . $styled
        . mb_substr($result, $run['end'] + 1, null, 'UTF-8');
}
```

The reverse iteration is **necessary and correct** — without it, inserting styled content at earlier positions would shift the indices of later runs. The comment at line 92-93 correctly explains this: "Build the result by iterating through runs in reverse order (to preserve string positions when inserting)."

**Status:** Not overly complex — just needs a clearer comment.

---

### 4.2 SmithWatermanMatcher Traceback Loop

**File:** `src/Matcher/SmithWatermanMatcher.php:187-211`

The traceback loop is straightforward with clear comments explaining each case (diag=match, up=gap in query, left=gap in candidate).

**Status:** Well-structured and documented.

---

## 5. Security Issues

### 5.1 No Direct Security Issues Found

The library performs string matching only. It does not:
- Execute code
- Access filesystems (beyond what PHP arrays require)
- Process user-provided formulas or code
- Handle authentication/authorization

### 5.2 Security Note in README (Correct)

**File:** `README.md:86-88`

The README correctly notes:
> "Callers **must** sanitize candidate text (strip `\x1b`/control bytes) before display — the styler callback receives raw matched substrings only and does not sanitize."

This is the correct responsibility division. No change needed.

---

## 6. Code Improvements

### 6.1 Add Type Declaration to Highlighter::$styler

**File:** `src/Highlighter.php:22`

```php
public function highlight(MatchResult $result, callable $styler): string
```

The styler parameter could use a more specific type:

```php
public function highlight(MatchResult $result, \Closure|string[] $styler): string
```

Or use PHP 8.3's first-class callable syntax for better type safety.

**Recommendation:** Consider using `\Closure` instead of `callable` for the styler parameter, as the current implementation always uses closures.

---

### 6.2 FuzzyMatcher Interface: Add Return Type for matchAll

**File:** `src/FuzzyMatcher.php:43`

```php
public function matchAll(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): array;
```

The return type `array` is correct but untyped contents. Consider using `array<MatchResult>` as a PHPDoc annotation for IDE support (PHP 8.3 doesn't support generics in native types).

**Status:** This is already documented correctly in the PHPDoc. No change required.

---

### 6.3 SahilmMatcher: Extract Scoring Constants to Config

**File:** `src/Matcher/SahilmMatcher.php:26-31`

```php
private const MATCH_SCORE = 1;
private const CONSECUTIVE_BONUS = 5;
private const SEPARATOR_BONUS = 10;
private const CAMEL_BONUS = 10;
private const FIRST_CHAR_BONUS = 15;
private const LOWER_CASE_BONUS = 1;
```

For some use cases, callers may want to adjust scoring weights (e.g., disable the camelCase bonus).

**Recommendation:** Consider adding a `SahilmMatcherConfig` class or constructor parameters to allow tuning scoring constants.

---

## 7. Missing Features

### 7.1 No Streaming/Generator Support for matchAll()

**File:** `src/FuzzyMatcher.php:43`

The current `matchAll()` returns `array<MatchResult>`, which requires all results to be in memory. For very large candidate lists (e.g., millions of items), this is problematic.

**Recommendation:** Add a `matchAllGenerator()` method:

```php
public function matchAllGenerator(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): \Generator;
```

This would allow consumers to stream results without holding all in memory.

---

### 7.2 No Support for Local Alignment in SmithWatermanMatcher

**File:** `src/Matcher/SmithWatermanMatcher.php`

Smith-Waterman is designed for **local alignment** (finding the best substring match), but the current implementation requires ALL query characters to match (line 101-103):

```php
if ($queryLen > $candidateLen) {
    return null;
}
```

This is actually the correct behavior for this library's use case (filter matching where the query should appear in order), but the option for true local alignment (query may partially match) is not exposed.

**Recommendation:** Document this constraint clearly. If true local alignment is needed, consider adding a parameter.

---

### 7.3 No Async/ReactPHP Support

**File:** `src/FuzzyMatcher.php`

The library has no async variants. In a ReactPHP-based ecosystem (per AGENTS.md), matchers could potentially block on very large datasets.

**Recommendation:** For future consideration, add:

```php
public function matchAllAsync(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): \React\Promise\PromiseInterface;
```

However, for typical TUI use cases (thousands of items), this is likely overkill.

---

### 7.4 No MatcherRegistry or Factory

**File:** `src/FuzzyMatcher.php`

There's no convenient way to instantiate a matcher by name or to list available matchers.

**Recommendation:** Consider adding a simple factory:

```php
final class FuzzyMatcherFactory
{
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

---

## 8. Duplicated Logic

### 8.1 matchAll() Sorting Logic Duplicated

**File:** `src/Matcher/SahilmMatcher.php:86-91`  
**File:** `src/Matcher/SmithWatermanMatcher.php:71-76`

Both matchers have identical sorting logic:

```php
usort($results, static fn(MatchResult $a, MatchResult $b) =>
    ($b->score <=> $a->score) ?: ($a->haystack <=> $b->haystack)
);
```

**Recommendation:** Extract to a shared utility:

```php
final class MatchResultSorter
{
    /**
     * Sort by score descending, then haystack ascending as tiebreak.
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

---

### 8.2 Empty Query/Empty Candidate Guards Duplicated

**File:** `src/Matcher/SahilmMatcher.php:51-53, 74-76`  
**File:** `src/Matcher/SmithWatermanMatcher.php:36-38, 59-61`

Both matchers check for empty query and empty candidate in both `match()` and `matchAll()`.

**Status:** These are simple guards and the duplication is minor. Extracting to a base class or trait would add complexity without significant benefit.

---

### 8.3 MatchResult Construction Pattern Duplicated

**File:** `src/Matcher/SahilmMatcher.php:182-187`  
**File:** `src/Matcher/SmithWatermanMatcher.php:170-175`

Both matchers construct `MatchResult` identically at the end of `compute()`.

**Status:** Minor duplication. Could be addressed by having a static factory method on `MatchResult`, but not worth the added complexity.

---

## 9. Compatibility with Other SugarCraft Libs

### 9.1 Interface Compliance is Correct

**File:** `src/FuzzyMatcher.php`

The `FuzzyMatcher` interface is well-designed and could be implemented by any matcher. No compatibility issues found with other SugarCraft libraries.

### 9.2 No ReactPHP/Async Conflicts

**File:** `composer.json:28-30`

```json
"require": {
    "php": "^8.3"
}
```

No async dependencies. The library is synchronous and would not conflict with async implementations.

### 9.3 PHP Version Compatibility

**File:** `composer.json`

Requires PHP 8.3+, which is consistent with the AGENTS.md requirement for the ecosystem.

### 9.4 No External Dependencies

The library has zero runtime dependencies (only dev dependency on PHPUnit). This is excellent for integration with other SugarCraft libs.

---

## 10. Async Pattern Improvements

### 10.1 Current State

The library is purely synchronous. The `matchAll()` method accepts `iterable<string>` which is flexible but doesn't support streaming/generation patterns well.

### 10.2 Recommendations for Async Support

For ReactPHP ecosystem integration, consider:

1. **Generator-based iteration for memory efficiency:**
   ```php
   public function matchAllGenerator(string $query, iterable $candidates): \Generator;
   ```

2. **Parallel processing for large datasets:**
   ```php
   public function matchAllParallel(string $query, iterable $candidates, int $limit = null): \React\Promise\FulfilledPromise;
   ```

However, these are **advanced features** that may not be needed for typical TUI use cases. The current synchronous implementation is appropriate for the library's scope.

---

## Priority Recommendations

### High Priority
1. **Remove dead code** in `Highlighter::highlight()` (lines 31-33)

### Medium Priority
2. **Document SahilmMatcher case-sensitive bonus behavior** or add `useBonuses` flag
3. **Add async/generator variant** of `matchAll()` for large datasets
4. **Add MatcherFactory** for convenient instantiation by name

### Low Priority
5. Extract shared `MatchResultSorter` utility
6. Add configurable scoring constants to SahilmMatcher
7. Rename `$prevCandidateLower` to `$prevCharLower`
8. Consider `\Closure` type hint for styler parameter

---

## Test Coverage Assessment

The test suite is **excellent**:
- 97 tests covering all public methods
- UTF-8 character tests
- Performance regression guards
- Scoring characterization tests for exact output verification
- Edge case tests (empty strings, no match, etc.)

No testing gaps identified.

---

## Conclusion

`candy-fuzzy` is a well-implemented library with good UTF-8 handling, solid performance characteristics (with guards against O(n³) regressions), and comprehensive test coverage. The main issues are minor: dead code in Highlighter, duplicated sorting logic, and missing async/generator support for very large datasets. The library is suitable for its intended use case in the SugarCraft TUI ecosystem.
