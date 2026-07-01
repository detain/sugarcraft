# Code Review Findings: candy-layout

**Library:** sugarcraft/candy-layout  
**Date:** 2026-06-29  
**Reviewer:** Code Review  
**PHP Version:** 8.3+  
**Test Status:** 116 tests, 356 assertions — all passing

---

## Summary

`candy-layout` is a constraint-based layout solver for terminal grid layouts, porting ratatui's layout system to PHP. It provides two solvers: `GreedySolver` (deterministic 5-phase fallback) and `CassowarySolver` (experimental simplex prototype). The library is a foundation package consumed by `candy-sprinkles`, `sugar-bits`, and `candy-forms`.

The code is generally well-structured with proper input validation on constraint types, but has several significant issues ranging from a known cycling bug in the CassowarySolver simplex implementation to interface design problems and async gaps.

---

## Critical Issues

### 1. CassowarySolver Known Cycling Bug (HIGH SEVERITY)

**File:** `src/CassowarySolver.php:306-319`

The CassowarySolver has a documented cycling bug where the simplex algorithm never converges within 1000 iterations for ANY constraint type:

```php
// NOTE: The CassowarySolver prototype has a known cycling bug — the simplex
// never converges (optimizeOneStep never returns false) within 1000 iterations
// for ANY constraint type, including pure Length constraints.
// ...
// if ($iterations > $maxIterations) {
//     throw new \RuntimeException(
//         'CassowarySolver failed to converge (constraint system may be infeasible)'
//     );
// }
```

**Impact:** The convergence guard is commented out because enabling it would fail all 21 Cassowary tests. The simplex implementation requires a full rewrite.

**Recommendation:** Either fix the simplex implementation or deprecate CassowarySolver in favor of GreedySolver until the prototype is production-ready. Document the experimental status prominently.

---

### 2. LayoutSolver Interface Design Issue (MEDIUM-HIGH SEVERITY)

**File:** `src/LayoutSolver.php:26-37`

The `LayoutSolver` interface defines factory methods (`greedy()`, `cassowary()`) that return concrete implementation types:

```php
interface LayoutSolver
{
    public function solve(Region $region, Direction $dir, array $constraints): array;
    public static function greedy(): GreedySolver;   // Returns concrete type
    public static function cassowary(): CassowarySolver; // Returns concrete type
}
```

**Problems:**
- Violates Interface Segregation Principle — callers must know about concrete implementations
- Factory methods on an interface are unusual ( factories are typically separate)
- `cassowary()` on GreedySolver returns CassowarySolver (跨-class factory)

**Recommendation:** Remove factory methods from the interface. Consider a separate `LayoutSolverFactory` class if factory access is needed, or keep factories on concrete classes only.

---

## Medium Severity Issues

### 3. GreedySolver Instance/Static Redundancy (MEDIUM)

**File:** `src/GreedySolver.php:57-60`

The instance `solve()` method simply delegates to a static method:

```php
public function solve(Region $region, Direction $dir, array $constraints): array
{
    return self::solveStatic($region, $constraints, $dir);
}
```

**Problem:** The instance method adds no value — it merely forwards to the static method. This suggests either the class should be fully static, or the design was unnecessarily split.

**Recommendation:** Decide on a design: either make `solveStatic` private/internal and have the instance method hold state, or remove the instance method entirely if no state is needed.

---

### 4. Expression Zero-Coefficient Terms Not Cleaned (MEDIUM)

**File:** `src/CassowarySolver.php:586-640` (Expression class)

The `Expression` class stores terms as a plain array but never removes zero-coefficient entries:

```php
public function times(float $scalar): self
{
    $result = new self();
    foreach ($this->terms as $name => $coef) {
        $result->terms[$name] = $coef * $scalar;  // Zero coefficients remain
    }
    // ...
}
```

**Impact:** Tableau can accumulate zero-coefficient entries, wasting memory and potentially causing division-by-zero in `getVariableValue()`.

**Recommendation:** Add a `removeZeroTerms()` method and call it in Expression operations, or use `array_filter` before returning.

---

### 5. Tableau Has Public Array Properties (MEDIUM)

**File:** `src/Tableau.php:10-36`

The `Tableau` class exposes all data structures as public arrays:

```php
final class Tableau
{
    public array $rows = [];
    public array $b = [];
    public array $colIndex = [];
    public array $externalVars = [];
    public array $slackVars = [];
    public array $artificialVars = [];
    // ...
}
```

**Problem:** Direct external access bypasses invariants and can corrupt solver state. Also prevents future changes to internal representation.

**Recommendation:** Make properties private and provide read-only accessors if needed for debugging.

---

### 6. Division-by-Zero Risk in getVariableValue (MEDIUM)

**File:** `src/CassowarySolver.php:552-561`

```php
private function getVariableValue(string $varName): float
{
    foreach ($this->tableau->rows as $rowVar => $row) {
        if (isset($row[$varName]) && $row[$varName] !== 0.0) {
            return $this->tableau->b[$rowVar] / $row[$varName];  // Could be PHP_FLOAT_MAX or inf
        }
    }
    return 0.0;
}
```

**Problem:** If `$row[$varName]` is extremely small (but non-zero due to floating point), the division produces a large/inf value. Also if `$tableau->b[$rowVar]` is PHP_FLOAT_MAX, dividing produces NaN or 1.

**Recommendation:** Add bounds checking: `if (abs($row[$varName]) < 1e-10) return 0.0;`

---

### 7. solveVertical Comment/Implementation Mismatch (LOW-MEDIUM)

**File:** `src/GreedySolver.php:327-343`

The comment at line 333 says:
```php
// Origin must be 0,0 — the flip-back at line 326 re-adds $area->x/$area->y.
```

But the flip-back at line 340 is:
```php
$rects[] = new Region($area->x + $r->y, $area->y + $r->x, $r->height, $r->width);
```

The comment references line 326 but the code is at line 340. Additionally, the comment implies the origin must be 0,0 for the horizontal solve, but the fakeArea is created with origin 0,0 regardless of the input area's origin. The actual re-addition of $area->x/$area->y is correct, but the comment is outdated and confusing.

**Recommendation:** Update the comment to accurately reference the code and explain the coordinate transformation logic.

---

## Low Severity Issues

### 8. Min(0) and Max(0) Are Valid But Semantically Strange (LOW)

**File:** `src/Constraint/Min.php:14-19`, `src/Constraint/Max.php:14-19`

Both `Min(0)` and `Max(0)` pass validation but represent degenerate constraints (always satisfied / always zero). While technically correct, these edge cases may indicate programming errors.

**Recommendation:** Consider whether to reject these at construction time with a warning, or document that they are intentionally allowed.

---

### 9. No `__toString()` or Debug Utilities for Expression (LOW)

**File:** `src/CassowarySolver.php:585-640`

The `Expression` class has no debugging aid. For troubleshooting solver issues, a simple `__toString()` method showing the expression as a string would be helpful.

**Recommendation:** Add `__toString()` method for debugging purposes.

---

### 10. BIG_M Constant May Cause Numerical Issues (LOW)

**File:** `src/CassowarySolver.php:55`

```php
private const BIG_M = 1000000.0;
```

Using a large constant like 1e6 in simplex can cause numerical instability when mixing with small-strength constraints. The strength hierarchy (1e9 >> 1.0 >> 0.001 >> 0.0001) means BIG_M dominates all actual constraint strengths.

**Recommendation:** Consider whether a different approach (e.g., two-phase simplex without Big-M) might be more numerically stable for production use.

---

## Async/Gap Analysis

### 11. No Async/Await Pattern for Streaming Constraints (GAP)

The library is built for the ReactPHP ecosystem but provides no async support:

- No `\React\Promise\PromiseInterface` return types
- No streaming constraint evaluation
- No cancellation tokens for long-running solves
- No progress callbacks for iterative solvers

**Impact:** Cannot be used effectively in async TUI applications where constraints might come from streaming sources or need cancellation.

**Recommendation:** Add async variants:
```php
public function solveAsync(Region $region, Direction $dir, array $constraints): \React\Promise\PromiseInterface;
// Or support streaming constraints:
public function solveStream(\Iterator $constraints): \React\Promise\PromiseInterface;
```

---

### 12. No Support for Constraint Change/Edit Variables (GAP)

**File:** `src/CassowarySolver.php:42-43, 544-547`

The `editVars` and `EditInfo` classes exist but are not functional:

```php
private function addEditVariable(string $varName, float $strength): void
{
    $this->editVars[$varName] = new EditInfo($varName, $strength);
}
```

The method populates the array but no `solveEdit()` method exists to actually use edit variables for interactive constraint editing (a key Cassowary feature).

**Recommendation:** Either implement edit variable support or remove the dead code to avoid confusion.

---

### 13. No Integration with TUI Components (GAP)

The library is positioned as part of a TUI ecosystem but has no direct integration:

- No `candy-core` dependency
- No output format for brick/Terminal libraries
- No ANSI SGR string output for rendering regions

**Recommendation:** Consider adding a simple `Region::toStyle()` method or integration helper for the TUI rendering pipeline.

---

## Code Quality Observations

### Positive Findings

1. **Good Input Validation**: All constraint types validate their inputs and throw `\InvalidArgumentException` with descriptive messages.

2. **Immutable Expression Class**: `Expression` operations return new instances, making tracking changes easier.

3. **Strong Type Declarations**: Most methods have proper type hints, though some private methods could use return types.

4. **Clean Separation of Concerns**: Constraint types are separate classes with a common base, making adding new constraint types straightforward.

5. **Comprehensive Test Coverage**: 116 tests covering all major paths, with data providers for tiling invariants.

### Patterns That Could Be Improved

1. **Duplicated Constraint Instanceof Logic**: `CassowarySolver::solve()` and `GreedySolver` both check constraint types with instanceof chains. Could use a Strategy pattern or visitor.

2. **Magic Number Proliferation**: Magic numbers like `0.000001` tolerance (line 384), `2` for rounding threshold (line 163) should be named constants.

3. **Inconsistent Error Handling**: Some places throw `\InvalidArgumentException`, others throw `\RuntimeException`. A consistent error strategy would help.

---

## Compatibility Notes

### With Other SugarCraft Libraries

1. **candy-sprinkles**: The README mentions `candy-layout` replaces the layout logic in `candy-sprinkles`. Need to verify API compatibility.

2. **candy-forms**: Uses `candy-layout` for form layout. The `Constraint` factory API is clean and should work well.

3. **sugar-bits**: Consumes `candy-layout`. The path repository is set up correctly.

### PHP Version Compatibility

- Requires PHP 8.3+
- No FFI usage (appropriate for this library)
- No async/await despite being in a ReactPHP ecosystem

---

## Specific Refactoring Suggestions

### High-Priority Refactoring

1. **Extract Constraint Handler Interface**

Currently in `CassowarySolver::solve()`:
```php
foreach ($constraints as $i => $c) {
    $var = $vars[$i];
    if ($c instanceof Min) { /* ... */ }
    elseif ($c instanceof Max) { /* ... */ }
    // ... 60+ lines of instanceof chain
}
```

Should extract to a `ConstraintHandler` interface with implementations for each type.

2. **Fix the Simplex Implementation**

The Cassowary simplex needs a complete rewrite to handle cycling properly. Consider using Bland's rule consistently or implementing the primal-dual method.

### Medium-Priority Refactoring

3. **Consolidate GreedySolver Static/Instance**

Either make it a pure static utility or make the instance hold state for future extensibility.

4. **Add PHPUnit Data Providers for Edge Cases**

The tiling invariant provider could be expanded to cover more edge cases programmatically rather than listing them individually.

---

## Test Coverage Gaps

The following scenarios are not tested:

1. **CassowarySolver with mixed Percentage + Min constraints**
2. **GreedySolver with zero constraints after filtering**
3. **Max constraint exactly equal to available space (boundary case)**
4. **Fill weight sum overflow (very large weights)**
5. **Negative slack distribution (edge case where constraints exceed area)**
6. **CassowarySolver pivot operation edge cases**

---

## Recommendations Summary

### Immediate (Should Fix)

1. **CassowarySolver cycling bug**: Either fix the simplex or deprecate/remove it
2. **Remove factory methods from LayoutSolver interface**: Move to separate factory class
3. **Add division-by-zero protection** in `getVariableValue()`
4. **Clean up Expression zero-coefficient terms**

### Short-term (Should Address)

5. **Implement or remove edit variable support** (dead code)
6. **Add async support** for ReactPHP ecosystem
7. **Make Tableau properties private**
8. **Update comments** that reference wrong line numbers

### Long-term (Nice to Have)

9. **Add debug output** (`__toString__` for Expression)
10. **Extract constraint handler strategy** for cleaner code
11. **Add integration helpers** for TUI rendering
12. **Improve test coverage** for edge cases

---

## Conclusion

The `candy-layout` library is a well-structured implementation of constraint-based layout solving, with good test coverage and clean separation of concerns. The main concerns are:

1. The **CassowarySolver is experimental** and has known correctness issues that should be addressed or documented more prominently
2. The **interface design** with factory methods on an interface is unusual and could be improved
3. The library is **missing async support** despite being in a ReactPHP ecosystem

The library is suitable for production use with `GreedySolver`, but `CassowarySolver` should be clearly marked as experimental or deprecated until the simplex implementation is fixed.
