# Implementation Plan: candy-layout

**Status:** not-started | **Phase:** 1 | **Updated:** 2026-06-30

## Goal

Address all 13 findings from the candy-layout code review including the CassowarySolver cycling bug (critical), LayoutSolver interface design issues (medium-high), expression zero-coefficient handling (medium), tableau encapsulation (medium), division-by-zero protection (medium), comment mismatches (low-medium), and various low-severity/gap items.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Deprecate CassowarySolver simplex path | Cycling bug cannot be fixed without full simplex rewrite; GreedySolver is production-ready | `candy-layout.md:21-42` |
| Remove factory methods from LayoutSolver interface | Violates Interface Segregation Principle; factories should be separate or on concrete classes | `candy-layout.md:45-66` |
| Add division-by-zero protection in getVariableValue() | Floating-point edge case could produce NaN/inf values corrupting solver output | `candy-layout.md:138-157` |
| Make Tableau properties private | Public arrays bypass invariants and can corrupt solver state | `candy-layout.md:113-135` |
| Clean zero-coefficient terms in Expression | Accumulated zero terms waste memory and risk division-by-zero | `candy-layout.md:90-110` |

---

## Phase 1: Critical Issues [PENDING]

- [ ] **1.1 CassowarySolver Cycling Bug — Deprecate Simplex Path** ← CURRENT
- [ ] 1.2 LayoutSolver Interface — Remove Factory Methods

### 1.1: CassowarySolver Cycling Bug

**Severity:** HIGH  
**Finding:** `candy-layout.md:21-42`  
**File:** `candy-layout/src/CassowarySolver.php:306-319`

**What is Expected:**
- Add `@deprecated` docblock to `CassowarySolver` class and `cassowary()` factory method
- Uncomment the convergence guard at lines 315-319 but make it throw a deprecation warning instead of exception (for backward compatibility during migration)
- Add `#[Deprecated]` PHP attribute
- Update README to clearly mark CassowarySolver as experimental/deprecated
- Consider removing the simplex path entirely and delegating ALL constraint types to GreedySolver (like Min already does at line 111-113)

**Why:**
- The prototype is fundamentally broken and cannot be used in production
- Users may unknowingly use it assuming it works correctly
- Keeping it without guard produces incorrect results silently

**Conditions for Success:**
- [ ] All existing tests still pass (CassowarySolver still delegates to GreedySolver)
- [ ] README clearly shows CassowarySolver is experimental
- [ ] Deprecation notices appear when CassowarySolver is instantiated

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/CassowarySolver.php` | 306-319 | Convergence guard (commented out) |
| `candy-layout/src/CassowarySolver.php` | 99-114 | Min delegation to GreedySolver |
| `candy-layout/README.md` | 42-46 | Solver comparison table |

**Investigation Notes:**
- The cycling bug comment at lines 306-319 explicitly states: "The CassowarySolver prototype has a known cycling bug — the simplex never converges (optimizeOneStep never returns false) within 1000 iterations for ANY constraint type"
- The guard is commented out because enabling it would fail all 21 Cassowary tests
- Min constraints already delegate to GreedySolver at line 111-113

---

### 1.2: LayoutSolver Interface — Remove Factory Methods

**Severity:** MEDIUM-HIGH  
**Finding:** `candy-layout.md:45-66`  
**File:** `candy-layout/src/LayoutSolver.php:26-37`

**What is Expected:**
- Remove `greedy()` and `cassowary()` from the `LayoutSolver` interface
- Keep these methods on concrete classes only (`GreedySolver::greedy()` and `CassowarySolver::cassowary()`)
- Add a separate `LayoutSolverFactory` class if factory access is still desired:
  ```php
  final class LayoutSolverFactory
  {
      public static function greedy(): GreedySolver { return new GreedySolver(); }
      public static function cassowary(): CassowarySolver { return new CassowarySolver(); }
  }
  ```
- Update all call-sites that use `LayoutSolver::greedy()` to use `GreedySolver::greedy()` or the factory class
- Update tests and README examples

**Why:**
- Improves interface segregation and reduces coupling
- Makes it clearer that callers depend only on the `solve()` contract
- Follows standard OOP practice of separating interfaces from factory responsibilities

**Conditions for Success:**
- [ ] `LayoutSolver` interface no longer has static factory methods
- [ ] All call-sites updated to use concrete factories
- [ ] Tests pass

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/LayoutSolver.php` | 26-37 | Interface definition |
| `candy-layout/src/GreedySolver.php` | 41-52 | Concrete factory methods |
| `candy-layout/src/CassowarySolver.php` | 79-91 | Concrete factory methods |

---

## Phase 2: Medium Severity Issues [PENDING]

- [ ] **2.1 GreedySolver — Resolve Static/Instance Redundancy** ← CURRENT
- [ ] 2.2 Expression — Clean Zero-Coefficient Terms
- [ ] 2.3 Tableau — Make Properties Private
- [ ] 2.4 CassowarySolver — Add Division-by-Zero Protection
- [ ] 2.5 GreedySolver — Fix Comment/Implementation Mismatch

### 2.1: GreedySolver — Resolve Static/Instance Redundancy

**Severity:** MEDIUM  
**Finding:** `candy-layout.md:71-87`  
**File:** `candy-layout/src/GreedySolver.php:57-60`

**What is Expected:**
- Decide on design intent:
  - **Option A (Recommended):** Keep instance method but have it hold state for future extensibility (e.g., caching, solver configuration). Move `solveStatic` logic into instance state.
  - **Option B:** Remove instance `solve()` entirely, keep only `solveStatic()` as public, mark constructor private to enforce static-only usage.
- If Option A: refactor so instance can hold configuration
- If Option B: Update call-sites to use `GreedySolver::solveStatic()` directly

**Why:**
- Unnecessary indirection increases cognitive load
- Could suggest the class should be fully static (no state)

**Conditions for Success:**
- [ ] Tests pass
- [ ] Code is cleaner with clear intent

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/GreedySolver.php` | 57-60 | Instance solve method (just forwards to static) |
| `candy-layout/src/GreedySolver.php` | 68-78 | Static solve method |
| `candy-layout/tests/GreedySolverTest.php` | - | Tests use solveStatic |

---

### 2.2: Expression — Clean Zero-Coefficient Terms

**Severity:** MEDIUM  
**Finding:** `candy-layout.md:90-110`  
**File:** `candy-layout/src/CassowarySolver.php:586-640`

**What is Expected:**
- Add a private `removeZeroTerms()` method to Expression:
  ```php
  private function removeZeroTerms(): void
  {
      $this->terms = array_filter(
          $this->terms,
          fn(float $coef) => abs($coef) > 0.000001
      );
  }
  ```
- Call `removeZeroTerms()` at the end of `plus()`, `minus()`, and `times()` methods

**Why:**
- Tableau can accumulate zero-coefficient entries, wasting memory
- Could cause division-by-zero in `getVariableValue()` if zero coefficient is treated as non-zero due to floating point

**Conditions for Success:**
- [ ] Expression operations produce clean arrays with no near-zero coefficients
- [ ] Existing tests still pass

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/CassowarySolver.php` | 631-640 | times method (primary issue - zero coefficients remain) |
| `candy-layout/src/CassowarySolver.php` | 613-621 | plus method |
| `candy-layout/src/CassowarySolver.php` | 623-629 | minus method |

---

### 2.3: Tableau — Make Properties Private

**Severity:** MEDIUM  
**Finding:** `candy-layout.md:113-135`  
**File:** `candy-layout/src/Tableau.php:10-36`

**What is Expected:**
- Change all `public` properties to `private`:
  ```php
  final class Tableau
  {
      private array $rows = [];
      private array $b = [];
      private array $colIndex = [];
      private array $externalVars = [];
      private array $slackVars = [];
      private array $artificialVars = [];
      private int $nextSlackVar = 0;
      private int $nextArtificialVar = 0;
  }
  ```
- Add read-only accessor methods for debugging (optional):
  ```php
  public function getRows(): array { return $this->rows; }
  public function getBasicVariables(): array { return array_keys($this->rows); }
  ```
- Update all internal usages in `CassowarySolver` to go through accessors

**Why:**
- Direct external access bypasses invariants and can corrupt solver state
- Prevents future changes to internal representation

**Conditions for Success:**
- [ ] No external code directly accesses Tableau properties
- [ ] Tests still pass

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/Tableau.php` | 10-36 | Class definition with public arrays |
| `candy-layout/src/CassowarySolver.php` | 430-463 | pivot method accesses rows directly |
| `candy-layout/src/CassowarySolver.php` | 358-375 | optimizeOneStep accesses tableau |

---

### 2.4: CassowarySolver — Add Division-by-Zero Protection

**Severity:** MEDIUM  
**Finding:** `candy-layout.md:138-157`  
**File:** `candy-layout/src/CassowarySolver.php:552-561`

**What is Expected:**
- Add bounds checking with a tolerance constant in `getVariableValue()`:
  ```php
  private function getVariableValue(string $varName): float
  {
      foreach ($this->tableau->rows as $rowVar => $row) {
          if (isset($row[$varName]) && $row[$varName] !== 0.0) {
              $coeff = $row[$varName];
              if (abs($coeff) < 1e-10) {
                  return 0.0;
              }
              return $this->tableau->b[$rowVar] / $coeff;
          }
      }
      return 0.0;
  }
  ```
- Define a class constant `private const EPSILON = 1e-10;`

**Why:**
- If `$row[$varName]` is extremely small but non-zero due to floating point, division produces large/inf value
- If `$tableau->b[$rowVar]` is PHP_FLOAT_MAX, dividing produces NaN or 1

**Conditions for Success:**
- [ ] Edge case tests pass
- [ ] Division by very small numbers returns 0.0 instead of inf/NaN

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/CassowarySolver.php` | 552-561 | getVariableValue method (division at line 557) |

---

### 2.5: GreedySolver — Fix Comment/Implementation Mismatch

**Severity:** LOW-MEDIUM  
**Finding:** `candy-layout.md:160-177`  
**File:** `candy-layout/src/GreedySolver.php:327-343`

**What is Expected:**
- Update comment at line 333 to accurately reference line 340 and explain coordinate transformation logic:
  ```php
  // Flip area to use horizontal solver on the "other" dimension.
  // $fakeArea is created with 0,0 origin, then dimensions are swapped
  // (width becomes height and vice versa) so horizontal solver handles vertical layout.
  // The flip-back at line 340 re-adds the original $area->x/$area->y coordinates
  // while swapping width/height back to original orientation.
  ```

**Why:**
- Misleading comment could confuse future maintainers
- The actual re-addition of $area->x/$area->y is correct, but comment is outdated

**Conditions for Success:**
- [ ] Comment accurately reflects code behavior
- [ ] No functional changes

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/GreedySolver.php` | 333 | Comment references line 326 (but flip-back is at line 340) |
| `candy-layout/src/GreedySolver.php` | 340 | Actual flip-back code |

---

## Phase 3: Low Severity Issues [PENDING]

- [ ] **3.1 Min/Max — Document Min(0) and Max(0) Semantics** ← CURRENT
- [ ] 3.2 Expression — Add `__toString()` Debug Method
- [ ] 3.3 CassowarySolver — Review BIG_M Numerical Stability

### 3.1: Min/Max — Document Min(0) and Max(0) Semantics

**Severity:** LOW  
**Finding:** `candy-layout.md:182-189`  
**File:** `candy-layout/src/Constraint/Min.php:14-19`, `candy-layout/src/Constraint/Max.php:14-19`

**What is Expected:**
- Add docblock notes to both `Min` and `Max` classes:
  ```php
  /**
   * At least `$n` cells; takes more if space is available.
   *
   * Note: Min(0) is a no-op (always satisfied) but is intentionally allowed.
   *
   * @param int $n Minimum size in cells (must be non-negative)
   */
  ```

**Why:**
- These edge cases may indicate programming errors when used unintentionally
- Need to document that they are intentionally allowed

**Conditions for Success:**
- [ ] Docblock clearly explains intent
- [ ] No functional changes

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/Constraint/Min.php` | 12-19 | Min class (no validation for n=0) |
| `candy-layout/src/Constraint/Max.php` | 12-19 | Max class (no validation for n=0) |

---

### 3.2: Expression — Add `__toString()` Debug Method

**Severity:** LOW  
**Finding:** `candy-layout.md:193-199`  
**File:** `candy-layout/src/CassowarySolver.php:585-640`

**What is Expected:**
- Add `__toString()` method to Expression class:
  ```php
  public function __toString(): string
  {
      $parts = [];
      foreach ($this->terms as $var => $coef) {
          if ($coef === 1.0) {
              $parts[] = $var;
          } elseif ($coef === -1.0) {
              $parts[] = "-$var";
          } else {
              $parts[] = sprintf('%s*%s', $coef, $var);
          }
      }
      $result = implode(' + ', $parts);
      if ($this->constant !== 0.0) {
          $result .= sprintf(' + %s', $this->constant);
      }
      return $result ?: '0';
  }
  ```

**Why:**
- Expression objects are complex (terms + constant)
- Debugging solver output is difficult without string representation

**Conditions for Success:**
- [ ] Expression objects can be echo'd for debugging
- [ ] Existing tests still pass

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/CassowarySolver.php` | 585-640 | Expression class (no __toString) |

---

### 3.3: CassowarySolver — Review BIG_M Numerical Stability

**Severity:** LOW  
**Finding:** `candy-layout.md:202-213`  
**File:** `candy-layout/src/CassowarySolver.php:55`

**What is Expected:**
- Add a docblock explaining the Big-M approach and its limitations:
  ```php
  /** @var float Big-M penalty value for artificial variables
   *
   * Using a large constant like 1e6 in simplex can cause numerical
   * instability when mixing with small-strength constraints.
   * Consider two-phase simplex without Big-M for production use.
   */
  private const BIG_M = 1000000.0;
  ```

**Why:**
- Can cause numerical instability in simplex
- Two-phase simplex without Big-M might be more numerically stable

**Conditions for Success:**
- [ ] Comment documents the design choice
- [ ] No functional changes

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/CassowarySolver.php` | 55 | BIG_M constant |

---

## Phase 4: Gap Analysis — Async & Integration [PENDING]

- [ ] **4.1 Add Async/Await Pattern for Streaming Constraints** ← CURRENT
- [ ] 4.2 Implement or Remove Edit Variable Support
- [ ] 4.3 Add TUI Component Integration

### 4.1: Add Async/Await Pattern for Streaming Constraints

**Severity:** GAP  
**Finding:** `candy-layout.md:218-235`  
**File:** `candy-layout/src/LayoutSolver.php`

**What is Expected:**
- Add async interface `AsyncLayoutSolver`:
  ```php
  interface AsyncLayoutSolver
  {
      public function solveAsync(
          Region $region,
          Direction $dir,
          array $constraints,
          ?\SugarCraft\Async\CancellationToken $cancellationToken = null
      ): \React\Promise\PromiseInterface;

      public function solveStream(
          \Iterator $constraints,
          Region $region,
          Direction $dir
      ): \React\Promise\PromiseInterface;
  }
  ```
- Add async implementations that wrap GreedySolver in Promise resolution
- Add progress callback support for iterative solvers

**Why:**
- Cannot be used effectively in async TUI applications
- Constraint solving could be a bottleneck in async applications

**Conditions for Success:**
- [ ] Async solves return PromiseInterface
- [ ] Cancellation token stops long-running solves
- [ ] Progress callbacks fire during iteration

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/LayoutSolver.php` | - | Interface (needs async variant) |
| Need to check `candy-async` for CancellationToken implementation | - | Async patterns |

---

### 4.2: Implement or Remove Edit Variable Support

**Severity:** GAP  
**Finding:** `candy-layout.md:238-254`  
**File:** `candy-layout/src/CassowarySolver.php:42-43, 544-547`

**What is Expected:**
- **Option A (Recommended):** Remove dead code:
  - Remove `EditInfo` class (lines 669-675)
  - Remove `$editVars` property (lines 42-43)
  - Remove `addEditVariable()` method (lines 544-547)
  - Update class docblock to note edit variables are not implemented

- **Option B:** Implement edit variable support:
  - Add `solveEdit(array $editValues): array` method
  - Implement the edit variable resolution algorithm per Badros & Borning 2001
  - This is significant work — more appropriate for a future sprint

**Why:**
- Dead code is confusing
- Edit variables are a key Cassowary feature for interactive constraint editing

**Conditions for Success:**
- [ ] If removed: No dead code remains
- [ ] If implemented: Edit variables correctly handle interactive constraint editing

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/CassowarySolver.php` | 42-43 | editVars property |
| `candy-layout/src/CassowarySolver.php` | 544-547 | addEditVariable method |
| `candy-layout/src/CassowarySolver.php` | 669-675 | EditInfo class (unused) |

---

### 4.3: Add TUI Component Integration

**Severity:** GAP  
**Finding:** `candy-layout.md:257-266`

**What is Expected:**
- Consider adding optional integration layer (not a hard dependency)
- Could add `Region::toStyle()` or similar helper for TUI rendering pipeline
- Or create a separate `candy-layout-tui` adapter package

**Why:**
- Consumers need to manually convert Region output to TUI components
- Integration helpers would improve DX

**Conditions for Success:**
- [ ] Integration helpers available for TUI consumers
- [ ] No hard coupling to candy-core

---

## Phase 5: Code Quality Improvements [PENDING]

- [ ] **5.1 Extract Constraint Handler Interface (Refactoring)** ← CURRENT
- [ ] 5.2 Consolidate Magic Numbers into Named Constants
- [ ] 5.3 Consistent Error Handling Strategy

### 5.1: Extract Constraint Handler Interface

**Severity:** MEDIUM (refactoring suggestion)  
**Finding:** `candy-layout.md:312-328`  
**File:** `candy-layout/src/CassowarySolver.php:317-325`

**What is Expected:**
- Create `ConstraintHandler` interface:
  ```php
  interface ConstraintHandler
  {
      public function handles(Constraint $constraint): bool;
      public function apply(Constraint $constraint, Context $context): void;
  }
  ```
- Create handlers for each constraint type (MinHandler, MaxHandler, LengthHandler, etc.)
- Register handlers in a map and dispatch dynamically
- This is a larger refactoring — may warrant its own PR

**Why:**
- Violates Open/Closed Principle
- Hard to add new constraint types
- Could use Strategy pattern or visitor

**Conditions for Success:**
- [ ] instanceof chains replaced with handler dispatch
- [ ] Adding new constraint types is easier
- [ ] Tests still pass

**Related Code Locations:**
| Location | Lines | Role |
|----------|-------|------|
| `candy-layout/src/CassowarySolver.php` | 317-325 | Constraint instanceof chain (60+ lines) |
| `candy-layout/src/GreedySolver.php` | 96-120 | Similar instanceof chain |

---

### 5.2: Consolidate Magic Numbers into Named Constants

**Severity:** LOW  
**Finding:** `candy-layout.md:285-289`

**What is Expected:**
- Define named constants at class level:
  ```php
  private const EPSILON = 0.000001;
  private const ROUNDING_THRESHOLD = 2;
  private const MAX_ITERATIONS = 1000;
  ```
- Replace magic numbers with constants

**Why:**
- Magic numbers scattered throughout code reduce readability
- Hard to understand purpose of values like `0.000001` tolerance or `2` for rounding

**Conditions for Success:**
- [ ] No magic numbers remain
- [ ] Tests still pass

---

### 5.3: Consistent Error Handling Strategy

**Severity:** LOW  
**Finding:** `candy-layout.md:289`

**What is Expected:**
- Define error strategy:
  - `\InvalidArgumentException` for invalid inputs (constraint validation)
  - `\RuntimeException` for solver failures (infeasible constraints, non-convergence)
- Audit and fix inconsistent usages

**Why:**
- Some places throw `\InvalidArgumentException`, others throw `\RuntimeException`
- Inconsistent error handling makes debugging harder

**Conditions for Success:**
- [ ] Consistent exception types used
- [ ] No ambiguous error handling

---

## Phase 6: Test Coverage Gaps [PENDING]

- [ ] **6.1 Add Tests for Untested Scenarios** ← CURRENT

### 6.1: Add Tests for Untested Scenarios

**Severity:** MEDIUM  
**Finding:** `candy-layout.md:345-355`

**What is Expected:**
Add tests for these currently untested scenarios:

1. **CassowarySolver with mixed Percentage + Min constraints**
2. **GreedySolver with zero constraints after filtering**
3. **Max constraint exactly equal to available space (boundary case)**
4. **Fill weight sum overflow (very large weights)**
5. **Negative slack distribution (edge case where constraints exceed area)**
6. **CassowarySolver pivot operation edge cases**

**Why:**
- These scenarios are currently not tested
- Could hide bugs in edge cases

**Conditions for Success:**
- [ ] All new test methods pass
- [ ] Coverage increases for edge cases

**Test methods to add:**
```php
public function testCassowaryMixedPercentageMin(): void
public function testGreedyZeroConstraints(): void
public function testMaxEqualToAvailableSpace(): void
public function testFillWeightOverflow(): void
public function testNegativeSlackDistribution(): void
public function testPivotEdgeCases(): void
```

---

## Notes

- 2026-06-30: Plan created based on findings in `/home/sites/sugarcraft/findings/candy-layout.md`
- The findings file indicates 116 tests, 356 assertions — all passing. Changes must not break existing tests.
- **CassowarySolver cycling bug is the highest priority** — it affects correctness of results.
- **Factory method removal from interface is a breaking change** for any call-sites using `LayoutSolver::greedy()` / `LayoutSolver::cassowary()`.
- Async support requires checking `candy-async` library for CancellationToken and async patterns.
- The library is suitable for production use with `GreedySolver`, but `CassowarySolver` should be clearly marked as experimental or deprecated until the simplex implementation is fixed.
