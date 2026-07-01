---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: Repeated Logic Patterns Refactoring

## Goal

Address the 10 major categories of repeated logic patterns identified in the repeated_logic.md audit, reducing code duplication and improving maintainability across the SugarCraft monorepo through strategic extraction, documentation, and refactoring.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Extract shared `clamp()` utility to `candy-core/src/Util/Clamp.php` | 4 divergent implementations found; a simple utility eliminates duplication | `ref:repeated_logic.md` Finding #9 |
| Audit Mutable trait adoption gap via delegation | 35 classes re-implement `mutate()` locally instead of using the `candy-core` trait; need to understand root cause before prescribing migration | `ref:repeated_logic.md` Finding #1 |
| Create shared validation utilities in `candy-core` | 131 identical error-throwing patterns using `Lang::t()` can be centralized via `validateNonNeg()`, `validatePositive()` helpers | `ref:repeated_logic.md` Finding #4 |
| Merge `candy-files/src/AsyncOps` into `candy-async/src/AsyncOps` | Both implement the same `Deferred` + `futureTick` pattern; file operations could reuse async utilities | `ref:repeated_logic.md` Finding #5 |
| Document `::new()` factory as required pattern | 3,042 call sites = de facto standard; CALIBER_LEARNINGS.md should explicitly require it for all new classes | `ref:repeated_logic.md` Finding #6 |
| Keep Model interface, i18n, Width, Ansi as-is | Already well-centralized with 92/41/736/700+ lines of well-tested code; no duplication detected | `ref:repeated_logic.md` Findings #2, #3, #7, #10 |
| Keep Validator interface in candy-forms | Already well-structured and re-exported from sugar-prompt; only low duplication outside forms context | `ref:repeated_logic.md` Finding #8 |

---

## Phase 1: Foundation — Shared Utilities [PENDING]

### 1.1 Extract Clamp Utility to candy-core [PENDING]

**What:** Create `candy-core/src/Util/Clamp.php` with a reusable clamping utility.

**Why:** Four different clamp implementations exist across sugar-stash, candy-palette, sugar-bits, and candy-forms. A centralized utility ensures consistency and reduces maintenance burden.

**Severity:** Medium

**Source locations:**
- `sugar-stash/src/App.php:L515` — `private function clamp(int $i, int $size): int`
- `candy-palette/src/Color.php:L67` — `private static function clamp(int $v): int` (0-255 range)
- `sugar-bits/src/Tree/Tree.php:L317` — `private function clamp(): self`
- `candy-forms/src/Viewport/Viewport.php:L462` — `private function clamp(): self`

**Implementation:**

```php
// candy-core/src/Util/Clamp.php
declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Shared value clamping utilities for the SugarCraft monorepo.
 * Mirrors charmbracelet/<repo>.clamp helpers.
 */
final class Clamp
{
    /**
     * Clamp an integer to the closed interval [min, max].
     * Returns min when $value < min, max when $value > max.
     */
    public static function int(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /**
     * Clamp a float to the closed interval [min, max].
     */
    public static function float(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * Clamp a value to [0, max] (non-negative range).
     * Shorthand for int($value, 0, $max).
     */
    public static function nonNeg(int $value, int $max = PHP_INT_MAX): int
    {
        return max(0, min($max, $value));
    }

    /**
     * Clamp byte value to [0, 255] range.
     * Used primarily by color components.
     */
    public static function byte(int $value): int
    {
        return max(0, min(255, $value));
    }
}
```

**Verification:**
- Create `candy-core/tests/Unit/Util/ClampTest.php` with edge case tests (min=max, value<min, value>max, negative values)
- Run `cd candy-core && vendor/bin/phpunit` — all tests pass
- Replace 4 local clamp implementations with the shared utility

---

### 1.2 Create Validation Utilities in candy-core [PENDING]

**What:** Create `candy-core/src/Util/Validation.php` with standardized validation helpers used across the monorepo.

**Why:** 131 identical error-throwing patterns with `Lang::t()` exist. Centralizing common validations (non-negative, positive, range) reduces duplication and ensures consistent error messages.

**Severity:** Medium

**Source locations:**
- `candy-forms/src/Viewport/Viewport.php:L57` — `throw new \InvalidArgumentException(Lang::t('viewport.dim_nonneg'))`
- `sugar-charts/src/Heatmap/Heatmap.php:L55` — `throw new \InvalidArgumentException(Lang::t('heatmap.dim_nonneg'))`
- `sugar-bits/src/Table/Table.php:L66` — `throw new \InvalidArgumentException(Lang::t('table.dim_nonneg'))`
- `candy-mines/src/Board.php:L36` — `throw new \InvalidArgumentException(Lang::t('board.too_small'))`

**Implementation:**

```php
// candy-core/src/Util/Validation.php
declare(strict_types=1);

namespace SugarCraft\Core\Util;

use SugarCraft\Core\I18n\Lang as I18n;

/**
 * Shared validation helpers to eliminate repeated InvalidArgumentException patterns.
 * Each method throws with a translatable message when validation fails.
 */
final class Validation
{
    /**
     * Validate that a dimension value is non-negative (>= 0).
     *
     * @throws \InvalidArgumentException
     */
    public static function nonNeg(int|float $value, string $key, array $params = []): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException(I18n::t($key, $params));
        }
    }

    /**
     * Validate that a value is strictly positive (> 0).
     *
     * @throws \InvalidArgumentException
     */
    public static function positive(int|float $value, string $key, array $params = []): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException(I18n::t($key, $params));
        }
    }

    /**
     * Validate that a value is within an allowed range [min, max].
     *
     * @throws \InvalidArgumentException
     */
    public static function range(int|float $value, int|float $min, int|float $max, string $key, array $params = []): void
    {
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(I18n::t($key, $params));
        }
    }
}
```

**Verification:**
- Create `candy-core/tests/Unit/Util/ValidationTest.php`
- Migrate 3-5 existing validation call sites to use the new utilities
- Run `cd candy-core && vendor/bin/phpunit` — all tests pass

---

### 1.3 Update CALIBER_LEARNINGS.md with Factory Pattern Contract [PENDING]

**What:** Document the `::new()` factory method as a required pattern in CALIBER_LEARNINGS.md.

**Why:** With 3,042 call sites across the codebase, the `::new()` factory is a de facto standard that should be explicitly documented as required for all new classes.

**Severity:** Low

**Source locations:**
- `sugar-bits/src/Stopwatch/Stopwatch.php:L382` — example with validation
- `candy-sprinkles/src/Style.php:L103` — simple factory

**Implementation:**

Add to `CALIBER_LEARNINGS.md`:

```markdown
- **[pattern:new-factory-required]** All new SugarCraft classes MUST provide a `::new(): self` factory method. This is a de facto standard with 3,000+ uses across the monorepo. The factory MAY include validation (e.g., `Stopwatch::new()` validates interval > 0), but MUST return `new self()` for no-arg construction. Canonical: `candy-sprinkles/src/Style.php::new()`, `sugar-bits/src/Stopwatch/Stopwatch.php::new()`.
```

**Verification:**
- Search for any new class added without `::new()` in code review
- No automated check needed — documented convention

---

## Phase 2: AsyncOps Consolidation [PENDING]

### 2.1 Audit candy-async vs candy-files AsyncOps Overlap [PENDING]

**What:** Investigate the overlap between `candy-async/src/AsyncOps.php` and `candy-files/src/AsyncOps.php` to determine if consolidation is feasible.

**Why:** Both implement the same `Deferred` + `futureTick` pattern. The file operations in `candy-files` could potentially reuse `candy-async`'s utilities, reducing code duplication.

**Severity:** Medium

**Investigation notes:**

**candy-async/AsyncOps (212 lines):**
- `withTimeout(LoopInterface, PromiseInterface, float)` — wraps promise with timeout
- `retry(callable, int, float, ?CancellationToken)` — retry with exponential backoff
- `debounce(callable, float, ?LoopInterface)` — returns debounced closure
- `throttle(callable, float, ?LoopInterface)` — returns throttled closure

**candy-files/AsyncOps (207 lines):**
- `copyAsync(string $src, string $dst): PromiseInterface<bool>`
- `moveAsync(string $src, string $dst): PromiseInterface<bool>`
- `renameAsync(string $src, string $newName): PromiseInterface<bool>`
- `copyManyAsync(array $map): PromiseInterface<array<string, bool>>`
- `moveManyAsync(array $map): PromiseInterface<array<string, bool>>`

**Key insight:** These are distinct operations — candy-async handles generic async patterns (timeout, retry, debounce, throttle), while candy-files handles file-specific I/O. They don't directly overlap but could share the underlying `Deferred` + `futureTick` pattern. Recommend keeping separate but extracting shared pattern to `candy-core` if needed.

**Verification:**
- Document findings in a separate ADR (Architecture Decision Record)
- Determine if `candy-files` could use `candy-async` utilities

---

## Phase 3: Mutable Trait Adoption [PENDING]

### 3.1 Investigate Mutable Trait Adoption Gap [PENDING]

**What:** Understand why 35 classes re-implement `mutate()` locally instead of using the `SugarCraft\Core\Concerns\Mutable` trait.

**Why:** The trait exists in `candy-core` but is underutilized. Understanding the gap is prerequisite to any migration effort.

**Severity:** High

**Source locations:**
- Canonical trait: `candy-core/src/Concerns/Mutable.php:L27-L39`
- 35 local implementations found via grep across: `sugar-veil/Veil.php`, `candy-forms/Field/Input.php`, `candy-forms/Field/Select.php`, `candy-buffer/Style.php`, `sugar-stash/App.php`, etc.

**Known reasons for local implementation:**

1. **Sentinel-bool pattern (Style, Select, Input, TextArea):** Classes with nullable fields that need `bool $XSet` sentinels cannot use the simple `array $changes` pattern. Example from `candy-forms/src/Field/Input.php:L550`:
   ```php
   private function mutate(?TextInput $input = null, ?string $title = null, ?string $description = null, ?string $error = null, bool $errorSet = false): self
   ```

2. **Named constructor args (Veil.php):** Uses named arguments but with 13 parameters, the local implementation is more readable than passing an array:
   ```php
   private function mutate(
       ?int $backdropOpacity = null,
       ?AnimationKind $animationKind = null,
       // ... 11 more parameters
   ): self
   ```

3. **Complex cloning (NotificationQueue.php, Sparkline.php):** Some implementations need to clone and manually copy mutable state:
   ```php
   private function mutate(): self
   {
       $clone = new self(...);
       $clone->items = $this->items; // mutable array not in constructor
       return $clone;
   }
   ```

**Implementation:**

1. Create delegation to `researcher` agent to audit all 35 local implementations and categorize them:
   - Type A: Simple cases that CAN use the trait (array-merge pattern)
   - Type B: Sentinel-bool cases that MUST override locally
   - Type C: Complex cases needing architectural changes

2. Update CALIBER_LEARNINGS.md with guidance:
   - When to use the trait (Type A)
   - When local override is acceptable (Type B, Type C)

3. Create migration script for Type A cases

**Verification:**
- Run delegation to audit all 35 implementations
- Document classification in findings file
- Proceed with migration only for Type A (simple) cases

---

## Phase 4: Documentation & Process [PENDING]

### 4.1 Update CALIBER_LEARNINGS.md with Mutable Trait Guidance [PENDING]

**What:** Enhance existing mutable-trait entry in CALIBER_LEARNINGS.md with more detailed guidance on when to use the trait vs. local implementation.

**Why:** The existing entry mentions the sentinel-bool exception but doesn't provide clear enough guidance to prevent future ad-hoc implementations.

**Severity:** Medium

**Current entry at CALIBER_LEARNINGS.md:L8:**
```
[pattern:mutable-trait] Standard immutable-with pattern via `SugarCraft\Core\Concerns\Mutable` trait...
```

**Implementation:**

Update the entry to explicitly list when local override IS required:
- Nullable fields with `bool $XSet` sentinel pattern
- Classes with 10+ constructor parameters (named args read better)
- Classes with mutable (non-constructor) state that must be manually copied

**Verification:**
- Review new class contributions to ensure pattern is followed correctly

---

### 4.2 Create Architecture Decision Record for AsyncOps [PENDING]

**What:** Document the decision on whether to merge AsyncOps utilities or keep them separate.

**Why:** Phase 2 investigation may reveal that consolidation is not beneficial due to different abstraction levels. An ADR captures the rationale for the decision.

**Severity:** Low

**Verification:**
- Create `docs/adr/001-asyncops-consolidation.md` with decision and rationale
- Reference in future code reviews

---

## Phase 5: Validator Interface Evaluation [PENDING]

### 5.1 Evaluate Validator Interface for Broader Adoption [PENDING]

**What:** Assess whether the `Validator` interface in `candy-forms` should be promoted to `candy-core` for broader monorepo use.

**Why:** The Validator interface is well-designed but only used within forms/prompt contexts. It could potentially serve input validation needs across the broader monorepo.

**Severity:** Low

**Source locations:**
- Interface: `candy-forms/src/Validator/Validator.php:L12-L17`
- Implementations: `Required.php`, `Email.php`, `Pattern.php`, `MinLength.php`, `MaxLength.php`

**Implementation:**

If evaluation recommends promotion:
1. Move `Validator` interface + implementations to `candy-core/src/Validator/`
2. Update `candy-forms/composer.json` to depend on `candy-core`
3. Update re-export in `sugar-prompt`

If evaluation recommends keeping as-is:
- Document in CALIBER_LEARNINGS.md that Validator lives in candy-forms and is the standard for input validation

**Verification:**
- Document decision rationale
- If promoted: run full test suite for affected libs

---

## Summary of Changes by Priority

| Priority | Item | Action | Files Affected |
|----------|------|--------|----------------|
| High | 3.1 Mutable trait gap audit | Delegation to researcher | All 35 implementation files |
| Medium | 1.1 Clamp utility | Create new utility + migrate callers | candy-core, sugar-stash, candy-palette, sugar-bits, candy-forms |
| Medium | 1.2 Validation utilities | Create new utility + migrate callers | candy-core, + 5-10 calling libs |
| Medium | 2.1 AsyncOps audit | Delegation/investigation | candy-async, candy-files |
| Medium | 4.1 Mutable trait guidance | Update CALIBER_LEARNINGS.md | CALIBER_LEARNINGS.md |
| Low | 1.3 ::new() factory contract | Update CALIBER_LEARNINGS.md | CALIBER_LEARNINGS.md |
| Low | 4.2 AsyncOps ADR | Create ADR document | docs/adr/001-asyncops-consolidation.md |
| Low | 5.1 Validator evaluation | Investigate and decide | candy-core, candy-forms, sugar-prompt |

---

## Notes

- **2026-06-30:** Plan created based on `findings/repeated_logic.md` audit conducted 2026-06-29.
- **Rationale for delegation:** The 35-class Mutable trait audit (Finding #1) requires examining each implementation to categorize Type A/B/C. This is best handled by a researcher subagent with parallel file access.
- **No breaking changes:** This plan focuses on extraction (new utilities) and migration (updating call sites) rather than interface changes, ensuring backward compatibility throughout.
- **Test strategy:** Each refactored component requires corresponding test coverage before PR merge.
