# Implementation Plan: honey-bounce Code Audit Fixes

**Status**: not-started
**Phase**: 1
**Updated**: 2026-06-30

---

## Goal

Address all 29 findings from the honey-bounce code audit, starting with critical mutability violations and validation gaps, followed by medium-severity API/compatibility improvements, low-severity code quality items, and finally documentation/testing gaps. Each fix preserves backward compatibility unless otherwise noted.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Return new instance from `SpringChain::tick()` instead of mutating in-place | Immutable pattern consistency — `Projectile::update()` already returns new instance; makes class reentrant and concurrent-call safe | `honey-bounce.md:20-35` |
| Return new instance from `SpringCollection::tick()` | Consistent immutability; avoids async race conditions in ReactPHP contexts | `honey-bounce.md:38-56` |
| Add `InvalidArgumentException` for invalid CubicBezier x-values | CSS spec requires 0 ≤ x ≤ 1; without validation, Newton-Raphson can fail to converge or produce non-monotonic output | `honey-bounce.md:60-82` |
| Throw exception for mass ≤ 0 in `SpringConfig` | Physically nonsensical mass should fail fast rather than silently clamp; value objects should validate invariants | `honey-bounce.md:84-96` |
| Accept `?bool $reducedMotionOverride` in `Spring` constructor | Avoid per-frame `Probe::reducedMotion()` env lookups at 60fps; caller pre-binds preference at construction time | `honey-bounce.md:102-118` |
| Extract `SpringChain::SETTLING_THRESHOLD = 0.001` constant | Magic number appears in `isSettled()` without explanation; should be named constant with optional per-instance override | `honey-bounce.md:122-135` |
| Add `Vector::lengthSquared()`, `Vector::normalize()`, `Vector::lerp()` | Missing common vector operations for physics library; `lengthSquared()` avoids `sqrt()` for comparisons | `honey-bounce.md:137-149` |
| Add `final` to `SpringChain` and `SpringCollection` | SugarCraft convention: public classes are `final` unless intended for extension; neither has `protected` hooks | `honey-bounce.md:151-159` |
| Add `EasingFunction` interface | PHP 8.1+ enums can implement interfaces; enables custom easing mocking in tests and downstream extension | `honey-bounce.md:161-169` |
| Add `__toString()` to `Point`, `Vector`, `SpringConfig`, `Spring` | Debugging physics simulations with `echo` currently produces `"Object id #N"`; `__toString()` enables readable output | `honey-bounce.md:171-185` |

---

## Phase 1: HIGH Severity — Mutability & Validation Fixes

**Status**: [PENDING]

### 1.1 `SpringChain::tick()` mutates internal array in-place → Make immutable

**What**: Change `SpringChain::tick()` to return `array{0: list<float>, 1: bool, 2: self}` — a 3-tuple including a new `SpringChain` instance. The internal `$this->stages` and `$this->activeIndex` must never be mutated.

**Why**: Consistent with `Projectile::update()` which returns new instance; makes chain reentrant (snapshot/rewind support); concurrent `tick()` calls on same instance would otherwise race.

**Severity**: HIGH

**Source file**: `honey-bounce/src/SpringChain.php:63-81`

**Conditions for success**:
- `vendor/bin/phpunit honey-bounce/tests/SpringChainTest.php` passes
- `tick()` return type is `array{0: list<float>, 1: bool, 2: self}` (verified via `php -l`)
- Existing tests use new return shape without breaking call sites

**Investigation notes**:
- Current return at line 65-66: `return [$this->currentPositions(), true];`
- Current mutation at line 77-78: `$this->stages[$this->activeIndex][1] = $newPos;`
- `isSettled()` at line 113 uses magic `0.001` threshold
- `withStage()` at line 47 already returns `new self($stages)` — fluent pattern already established
- `::build()` at line 34 is redundant wrapper (see item 13, LOW)

---

### 1.2 `SpringCollection::tick()` mutates state mid-iteration → Make immutable

**What**: Change `SpringCollection::tick()` to return a **new** `SpringCollection` instance with updated positions/velocities, leaving the original untouched.

**Why**: Consistent with immutable pattern; avoids race conditions if used in ReactPHP async context; callers can keep references to previous collection states for replay/debugging.

**Severity**: HIGH

**Source file**: `honey-bounce/src/SpringCollection.php:56-68`

**Conditions for success**:
- `vendor/bin/phpunit honey-bounce/tests/SpringCollectionTest.php` passes
- `tick()` returns `SpringCollection` instance
- Calling `tick()` twice on same instance yields different results on second call (immutability check)

**Investigation notes**:
- Current mutation at lines 64-65: `$this->positions[$id] = $pos; $this->velocities[$id] = $vel;`
- `add()` at line 30 stores in `$this->springs`, `$this->positions`, `$this->velocities`, `$this->targets`
- `remove()` at line 41 uses `unset()` — safe no-op if key missing (confirmed by test at line 91-95)
- The class already has private `positions` and `velocities` arrays that would need to be carried into new instance

---

### 1.3 `CubicBezier` control point `x` values not validated → Add range validation

**What**: Add validation in `CubicBezier::__construct()` that throws `\InvalidArgumentException` if `x1 < 0.0 || x1 > 1.0 || x2 < 0.0 || x2 > 1.0`. Use `Lang::t('cubicbezier.control_points_range')` for message.

**Why**: CSS spec (W3C) requires 0 ≤ x ≤ 1. Invalid x-values cause Newton-Raphson non-convergence, non-monotonic output, and potential infinite loops in binary subdivision.

**Severity**: HIGH

**Source file**: `honey-bounce/src/Easing/CubicBezier.php:40-47`

**Conditions for success**:
- `new CubicBezier(-0.5, 0.0, 0.5, 1.0)` throws `\InvalidArgumentException`
- `new CubicBezier(0.0, 0.0, 1.5, 1.0)` throws `\InvalidArgumentException`
- Valid cases (`new CubicBezier(0.0, 0.0, 1.0, 1.0)`) still work
- Existing tests pass

**Investigation notes**:
- Constructor at line 40-47: no validation of x1, x2 ranges despite doc comment on line 35-38 noting "CSS requires 0 ≤ x1 ≤ 1"
- Newton-Raphson at line 113-126: `for ($i = 0; $i < self::NEWTON_ITERATIONS; $i++)` — 8 iterations max
- Binary subdivision fallback at line 128-148: `while ($lower < $upper)` — could loop indefinitely if epsilon reached
- `lang/en.php` currently only has `'spring.fps_positive'` entry — would need new `cubicbezier.control_points_range` key

---

### 1.4 `SpringConfig` silently clamps mass to 0.001 → Throw on invalid mass

**What**: Change `SpringConfig` constructor to throw `\InvalidArgumentException` when mass ≤ 0 (including mass = 0.0). The silent clamping at line 33 (`$safeMass = max(0.001, $mass)`) should be replaced with explicit validation.

**Why**: mass ≤ 0 is physically nonsensical for an oscillator; silent substitution creates physics that "look like they work" but are using a fudge factor; fail-fast is the correct behavior for a value object representing physical parameters.

**Severity**: HIGH

**Source file**: `honey-bounce/src/SpringConfig.php:33`

**Conditions for success**:
- `new SpringConfig(tension: 100, friction: 10, mass: 0.0)` throws `\InvalidArgumentException`
- `new SpringConfig(tension: 100, friction: 10, mass: -1.0)` throws `\InvalidArgumentException`
- `new SpringConfig(tension: 100, friction: 10, mass: 1.0)` still works
- Existing tests pass (note: no test currently passes mass = 0, so no test should break)

**Investigation notes**:
- Constructor body lines 28-43: `$safeMass = max(0.001, $mass)` silently substitutes 0.001
- `tension` is also silently clamped at line 34: `$safeTension = max(0.0, $tension)` — this may also warrant throwing for negative tension (but less critical since tension < 0 has physical meaning of "negative stiffness" which is unstable but numerically safe)
- The readonly properties `angularFrequency` and `dampingRatio` at line 20-21 are assigned in constructor body (not promoted) — inconsistency noted in item 21

---

## Phase 2: MEDIUM Severity — Performance, API Completeness, Conventions

**Status**: [PENDING]

### 2.1 `Probe::reducedMotion()` called every `Spring::update()` → Cache per-instance

**What**: Add `?bool $reducedMotionOverride` parameter to `Spring` constructor. When provided, `update()` uses this cached value instead of calling `Probe::reducedMotion()` every frame.

**Why**: At 60fps animation loop, `Probe::reducedMotion()` reads `$_ENV`/`getenv()` 60 times per second; the result is static for the lifetime of the process; caching at construction time eliminates repeated env lookups.

**Severity**: MEDIUM

**Source file**: `honey-bounce/src/Spring.php:34-38, 112-116`

**Conditions for success**:
- `new Spring(dt, ω, ζ, reducedMotionOverride: true)` ignores env vars and always snaps to target
- `new Spring(dt, ω, ζ, reducedMotionOverride: false)` always animates
- `new Spring(dt, ω, ζ)` (no override) falls back to `Probe::reducedMotion()` call — backward compatible
- `vendor/bin/phpunit honey-bounce/tests/ReducedMotionTest.php` still passes

**Investigation notes**:
- `Probe::reducedMotion()` at line 115-128 in `candy-palette/src/Probe.php` reads `REDUCE_MOTION` then `PREFERS_REDUCED_MOTION` env vars
- `Spring::update()` at line 112 calls `Probe::reducedMotion()` on every frame
- `Probe::_reset()` at line 246 exists for testing — but not helpful for per-frame performance

---

### 2.2 Duplicated settling threshold magic number → Extract to constant

**What**: Add `private const SETTLING_THRESHOLD = 0.001` to `SpringChain`. Add optional constructor parameter `?float $settlingThreshold = null` to allow per-instance override.

**Why**: Magic number `0.001` appears at line 115 with no explanation; no way to configure per-chain; `SpringConfig` uses `1e-12` and `Spring` uses `1e-9` for similar "near-zero" checks — inconsistent without being documented.

**Severity**: MEDIUM

**Source file**: `honey-bounce/src/SpringChain.php:115`

**Conditions for success**:
- `isSettled()` uses `self::SETTLING_THRESHOLD` instead of hardcoded `0.001`
- `new SpringChain($stages, settlingThreshold: 0.0001)` allows finer-grained threshold
- Existing tests still pass at default threshold

**Investigation notes**:
- `isSettled()` at line 113-116: `return abs($pos - $target) < 0.001 && abs($vel) < 0.001;`
- `SpringConfig` uses `1e-12` at line 37 for "near zero" sqrt check
- `Spring` uses `1e-9` EPSILON at line 22 for angular frequency near-zero check

---

### 2.3 `Vector` missing `lengthSquared()`, `normalize()`, `lerp()` → Add methods

**What**: Add to `Vector` class:
- `lengthSquared(): float` — returns `$this->x * $this->x + $this->y * $this->y + $this->z * $this->z` (avoids `sqrt()`)
- `normalize(): self` — returns `scale(1.0 / length())`; should throw if length is zero
- `lerp(self $other, float $t): self` — returns `new self($this->x + ($other->x - $this->x) * $t, ...)` for linear interpolation

**Why**: Common vector operations missing from a physics library; `lengthSquared()` is faster for comparisons (no sqrt); `normalize()` needed for direction vectors; `lerp()` needed for animation interpolation.

**Severity**: MEDIUM

**Source file**: `honey-bounce/src/Vector.php`

**Conditions for success**:
- `Vector` has all three new public methods
- `lengthSquared()` returns correct squared length
- `normalize()` returns unit vector; throws `\RuntimeException` if called on zero vector
- `lerp($other, 0.0)` returns `$this`; `lerp($other, 1.0)` returns `$other`; `lerp($other, 0.5)` returns midpoint
- Existing tests pass

**Investigation notes**:
- Current `Vector` methods: `add()`, `sub()`, `scale()`, `length()`, `dot()`, `cross()`
- `Point::distance()` at line 34 already exists — uses `sqrt()` internally
- `Vector` is already immutable (`readonly` properties, `add()`/`sub()`/`scale()` all return new instances)

---

### 2.4 `SpringChain` and `SpringCollection` not declared `final` → Add `final`

**What**: Add `final` keyword to both class declarations:
- `final class SpringChain` at `honey-bounce/src/SpringChain.php:14`
- `final class SpringCollection` at `honey-bounce/src/SpringCollection.php:13`

**Why**: SugarCraft convention: public classes are `final` unless explicitly designed for extension; neither class has `protected` hooks for subclasses; consistent with `Spring`, `SpringConfig`, `Projectile`, `Point`, `Vector` which are all `final`.

**Severity**: MEDIUM

**Source file**: `honey-bounce/src/SpringChain.php:14`, `honey-bounce/src/SpringCollection.php:13`

**Conditions for success**:
- Both classes are declared `final`
- `vendor/bin/phpunit` still passes

**Investigation notes**:
- `final class Spring` at line 20 of `Spring.php`
- `final readonly class SpringConfig` at line 18 of `SpringConfig.php`
- `final class Projectile` at line 36 of `Projectile.php`
- `final class Point` at line 14 of `Point.php`
- `final class Vector` at line 18 of `Vector.php`

---

### 2.5 `Easing` enum has no interface for testability → Add `EasingFunction` interface

**What**: Add `interface EasingFunction { public function ease(float $t): float; }` in `src/Easing/EasingFunction.php`. Have `enum Easing` implement it: `enum Easing implements EasingFunction`.

**Why**: PHP 8.1+ enums can implement interfaces; enables custom easing class mocking in tests; downstream consumers can implement `EasingFunction` with their own curves.

**Severity**: MEDIUM

**Source file**: `honey-bounce/src/Easing/Easing.php:14`, new file `honey-bounce/src/Easing/EasingFunction.php`

**Conditions for success**:
- `EasingFunction` interface exists with `ease(float $t): float` method
- `enum Easing implements EasingFunction` — verified by `php -l`
- Custom class implementing `EasingFunction` can be used in existing code expecting easing

**Investigation notes**:
- Current `Easing` at line 14: `enum Easing` — no interface implementation
- PHP 8.1+ allows backed enums to implement interfaces
- `CubicBezier` class also has `evaluate(float $t): float` — could also implement `EasingFunction` for consistency

---

### 2.6 `Point`, `Vector`, `SpringConfig`, `Spring` lack `__toString()` → Add implementations

**What**: Add `__toString(): string` to all four classes:

```php
// Point
public function __toString(): string
{
    return "Point(x: {$this->x}, y: {$this->y}, z: {$this->z})";
}

// Vector  
public function __toString(): string
{
    return "Vector(x: {$this->x}, y: {$this->y}, z: {$this->z})";
}

// SpringConfig — readonly properties angularFrequency/dampingRatio not promoted
public function __toString(): string
{
    return "SpringConfig(tension: {$this->tension}, friction: {$this->friction}, mass: {$this->mass})";
}

// Spring — readonly coefficients not accessible; use class name only
public function __toString(): string
{
    return "Spring";
}
```

**Why**: Debugging physics simulations with `echo` currently produces `"Object id #N"`; `__toString()` enables readable output in golden-file trajectory tests and interactive debugging.

**Severity**: MEDIUM

**Source file**: `honey-bounce/src/Point.php`, `honey-bounce/src/Vector.php`, `honey-bounce/src/SpringConfig.php`, `honey-bounce/src/Spring.php`

**Conditions for success**:
- `echo (string)$point` produces readable string for each class
- GoldenFile snapshot tests still pass (no string output changes)
- Existing `echo` calls in examples/debug output become readable

**Investigation notes**:
- `Point` at line 14: `final class Point` with promoted readonly `x, y, z`
- `Vector` at line 18: `final class Vector` with promoted readonly `x, y, z`
- `SpringConfig` at line 18: `final readonly class SpringConfig` — angularFrequency/dampingRatio NOT promoted (item 21)
- `Spring` at line 20: `final class Spring` — private readonly coefficients

---

### 2.7 `SpringChain::tick()` doesn't return updated chain → Resolved by item 1.1

**Note**: This is the same issue as item 1.1 (SpringChain mutability). The implementation plan for item 1.1 addresses the missing third return element (`self`) that allows chain state to be captured/snapshotted. This item is resolved by item 1.1's implementation.

---

## Phase 3: LOW Severity — Code Quality & Serialization

**Status**: [PENDING]

### 3.1 `Gravity` class is entirely delegate methods → Document intent

**What**: No code change required. Add a clarifying doc-comment on `Gravity` class noting that it exists as an ergonomic alias for Go-to-PHP translation, and that if `Projectile` factory methods are ever renamed, `Gravity` must be updated in tandem.

**Why**: Code smell noted in audit; every method is one-line delegation to `Projectile`; worth documenting rationale so future maintainers don't "clean up" by inlining.

**Severity**: LOW

**Source file**: `honey-bounce/src/Gravity.php`

**Conditions for success**:
- Doc comment added explaining delegation pattern and maintenance coupling
- `vendor/bin/phpunit` passes

---

### 3.2 `SpringChain::build()` redundant with constructor → Remove or rename to `::new()`

**What**: Remove `SpringChain::build()` static factory method, or rename to `::new()` per SugarCraft convention. All call sites in tests use `SpringChain::build()` — those call sites would need updating if removed.

**Why**: `build()` at line 34-37 is just `return new self($stages)` — redundant with constructor. SugarCraft convention is `::new()` for default factory.

**Severity**: LOW

**Source file**: `honey-bounce/src/SpringChain.php:34-37`, `honey-bounce/tests/SpringChainTest.php:15,24,45,73,94,104`

**Conditions for success**:
- `::build()` removed (or renamed to `::new()`) — verified by `php -l`
- All test call sites updated to use constructor directly (or `::new()`)
- Example files updated if they use `::build()`

**Investigation notes**:
- Tests use `SpringChain::build([])` and `SpringChain::build([[$spring, ...]])` extensively
- Constructor at line 24: `public function __construct(array $stages)` — already public

---

### 3.3 `Point`, `Vector` no `JsonSerializable` → Implement interface

**What**: Have `Point` and `Vector` implement `\JsonSerializable`. `jsonSerialize()` returns `[$this->x, $this->y, $this->z]`.

**Why**: Simplifies golden-file snapshot testing from manual array construction (`['x' => round($p->position()->x, 6), ...]`) to `(object)$point` or `json_encode($point)`.

**Severity**: LOW

**Source file**: `honey-bounce/src/Point.php`, `honey-bounce/src/Vector.php`

**Conditions for success**:
- `json_encode($point)` produces valid JSON
- `json_encode($vector)` produces valid JSON
- GoldenFile tests still pass

**Investigation notes**:
- `Point` and `Vector` already use promoted readonly properties
- `JsonSerializable` interface requires `jsonSerialize(): mixed`
- `candy-vcr` uses `JsonSerializable` on `UserJsonableMsg` as reference pattern (found via grep)

---

### 3.4 `Spring::fps()` only accepts `int` → Accept `float`

**What**: Change `Spring::fps(int $n): float` to `Spring::fps(float $n): float`. This allows `Spring::fps(59.94)` for NTSC frame rates without manual computation.

**Why**: `fps(int $n)` is overly restrictive; users needing fractional frame rates (e.g., 59.94 for NTSC) must compute `1.0 / 59.94` manually.

**Severity**: LOW

**Source file**: `honey-bounce/src/Spring.php:125-131`

**Conditions for success**:
- `Spring::fps(60)` still returns `1.0 / 60.0`
- `Spring::fps(59.94)` returns `1.0 / 59.94`
- `Spring::fps(0)` still throws `\InvalidArgumentException`

**Investigation notes**:
- Current signature: `public static function fps(int $n): float`
- Validation at line 127-129: `if ($n <= 0)` throws exception — float comparison with 0 is valid
- Changing to `float $n` would still work: `0.0` would be caught by `if ($n <= 0.0)`

---

## Phase 4: INFO — Testing & Documentation Gaps

**Status**: [PENDING]

### 4.1 No test for cubic bezier invalid control points → Add test cases

**What**: Add test cases to `CubicBezierTest.php`:
- `$this->expectException(\InvalidArgumentException::class)` for `new CubicBezier(-0.5, 0.0, 0.5, 1.0)`
- `$this->expectException(\InvalidArgumentException::class)` for `new CubicBezier(0.0, 0.0, 1.5, 1.0)`
- After implementing item 1.3, these tests should pass (TDD approach)

**Severity**: INFO (testing)

**Source file**: `honey-bounce/tests/CubicBezierTest.php`

**Conditions for success**:
- New tests throw `\InvalidArgumentException` for out-of-range x values
- Existing tests still pass

---

### 4.2 No `SpringCollection::remove()` non-existent-ID test → Add test

**What**: Add test: `testRemoveNonexistentIdDoesNotThrow()` — calls `$collection->remove('nonexistent')` and asserts no exception is thrown.

**Severity**: INFO (testing)

**Source file**: `honey-bounce/tests/SpringCollectionTest.php`

**Conditions for success**:
- New test passes
- `unset()` at line 43 of `SpringCollection.php` is safe for non-existent keys

---

### 4.3 `ReducedMotionTest` uses `putenv()` not parallel-safe → Document limitation

**What**: Add `@group parallel-unsafe` annotation to `ReducedMotionTest` class. Add a note in the class docblock explaining that `putenv()` modifies global process state and these tests cannot run in parallel (e.g., with Paratest).

**Why**: `putenv()` in `setUp()`/`tearDown()` modifies global process environment; parallel test runners (Paratest) could cause flakiness; documented limitation prevents future confusion.

**Severity**: INFO (testing)

**Source file**: `honey-bounce/tests/ReducedMotionTest.php:10-25`

**Conditions for success**:
- `@group parallel-unsafe` annotation present on test class
- Tests still pass when run individually

---

### 4.4 Examples use hardcoded `__DIR__ . '/../vendor/autoload.php'` → Improve portability

**What**: Update `examples/spring.php` and `examples/projectile.php` to use a more robust autoload path. Options: use `dirname(__DIR__)` (one level up from examples/), or check if autoload exists at multiple possible paths.

**Why**: If examples are run from a different working directory, the hardcoded relative path breaks.

**Severity**: INFO (portability)

**Source file**: `honey-bounce/examples/spring.php:11`, `honey-bounce/examples/projectile.php:12`

**Conditions for success**:
- Examples work when run from project root: `php honey-bounce/examples/spring.php`
- Examples work when run from examples dir: `php examples/spring.php`

---

### 4.5 `SpringConfig` readonly properties not promoted → Add doc note

**What**: Add doc comment noting that `angularFrequency` and `dampingRatio` are intentionally NOT constructor-promoted because they are derived values computed inside the constructor body. They could be promoted but would require careful handling since they're computed from the input parameters.

**Why**: Inconsistency noted in audit; the readonly properties `angularFrequency` and `dampingRatio` (lines 20-21) are assigned in constructor body, not via promoted parameter syntax like `tension`, `friction`, `mass` (lines 29-31).

**Severity**: INFO (style)

**Source file**: `honey-bounce/src/SpringConfig.php:20-21`

**Conditions for success**:
- Doc comment added explaining why these properties are not promoted
- Code behavior unchanged

---

## Phase 5: MISSING FEATURES — Future Enhancements (Not Implemented Here)

**Status**: [PENDING]

These are feature requests that are out of scope for the current audit PR but are logged for future consideration. **They should NOT be implemented in this audit PR.**

### 5.1 No async/ReactPHP integration — Future work

**What would be needed**: `AsyncSpring` class yielding positions via Amp coroutine, or `Spring::stream()` returning a `Generator`. Integration with `candy-async`.

**Why deferred**: Requires `candy-async` dependency analysis and async architecture decision; not a bug or regression.

---

### 5.2 No multi-dimensional spring support — Future work

**What would be needed**: `Spring2D` operating on `Vector` (or `array{0: float, 1: float}`) for x/y position animations.

**Why deferred**: Design decision needed for API shape; not blocking current usage.

---

### 5.3 No spring pause/resume — Future work

**What would be needed**: `pause()`, `resume()`, `isPaused()` methods on `Spring`.

**Why deferred**: Requires API design; not blocking.

---

### 5.4 No `isAtTarget()` without side effects — Future work

**What would be needed**: Static or instance method `isAtTarget(float $pos, float $vel, float $target): bool`.

**Why deferred**: Current workaround: call `update()` and check returned position; not blocking.

---

### 5.5 No varying deltaTime support — Future work

**What would be needed**: Spring variant that accepts deltaTime per-update (currently baked in at construction).

**Why deferred**: Frame-rate independence is usually the correct behavior; adaptive timestepping is a specialized use case.

---

### 5.6 `Vector` missing `normalize()`, `distance()`, `angle()` — Future work (partially covered by item 2.3)

**Note**: `normalize()` and `distance()` (on `Point`) are addressed by item 2.3. `angle()` between vectors is still a future enhancement.

---

### 5.7 No `snapToTarget()` convenience method — Future work

**What would be needed**: `snapToTarget()` method returning `[$target, 0.0]` instantly.

**Why deferred**: One-line workaround exists; not blocking.

---

### 5.8 No Newton-Raphson convergence verification test — Future work

**What would be needed**: Test verifying algorithm converges within expected iteration count (e.g., log iteration counts for typical inputs).

**Why deferred**: Output range tests already verify correctness; convergence timing is an optimization detail.

---

## Summary of Changes by File

| File | Items Changed |
|------|---------------|
| `src/SpringChain.php` | 1.1 (make tick immutable), 2.2 (threshold constant), 2.4 (add final), 3.2 (remove build) |
| `src/SpringCollection.php` | 1.2 (make tick immutable), 2.4 (add final) |
| `src/Easing/CubicBezier.php` | 1.3 (validate x-range), 4.1 (test coverage) |
| `src/SpringConfig.php` | 1.4 (throw on mass ≤ 0), 2.6 (__toString), 4.5 (doc note) |
| `src/Spring.php` | 2.1 (cache reducedMotion), 2.6 (__toString), 3.4 (float fps) |
| `src/Vector.php` | 2.3 (lengthSquared/normalize/lerp), 2.6 (__toString), 3.3 (JsonSerializable) |
| `src/Point.php` | 2.6 (__toString), 3.3 (JsonSerializable) |
| `src/Easing/Easing.php` | 2.5 (implement EasingFunction interface) |
| `src/Easing/EasingFunction.php` | 2.5 (new interface file) |
| `src/Gravity.php` | 3.1 (doc comment) |
| `lang/en.php` | 1.3 (new translation key) |
| `tests/CubicBezierTest.php` | 4.1 (invalid x-value tests) |
| `tests/SpringCollectionTest.php` | 4.2 (remove nonexistent test) |
| `tests/ReducedMotionTest.php` | 4.3 (parallel-unsafe annotation) |
| `examples/spring.php`, `examples/projectile.php` | 4.4 (improve autoload path) |

---

## Notes

- **2026-06-30**: Plan created based on audit findings dated 2026-06-29. All 29 items categorized by severity. Phases 1-3 address HIGH/MEDIUM/LOW items. Phase 4 addresses INFO items (tests, docs, portability). Phase 5 documents MISSING FEATURES for future work — these are NOT implemented in this audit PR.
- Items 1.1 and 1.2 (mutability fixes) are the highest priority — they introduce actual bugs in concurrent/async contexts.
- Item 1.3 (CubicBezier validation) should be implemented TDD: write the exception tests first, then implement validation.
- Items 2.1 (reducedMotion caching) and 2.3 (Vector missing methods) are independent and can be parallelized.
- The `::build()` removal (item 3.2) requires updating all test call sites — plan to do as a batch edit.
- `Probe::reducedMotion()` is called via `use SugarCraft\Palette\Probe` at line 10 of `Spring.php` — the `candy-palette` path repo is already wired in `composer.json`.
