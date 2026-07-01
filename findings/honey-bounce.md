# Code Audit: honey-bounce

**Library**: SugarCraft/honey-bounce (PHP port of charmbracelet/harmonica)
**Date**: 2026-06-29
**Reviewer**: Code Audit
**Files Reviewed**: src/Spring.php, src/SpringConfig.php, src/SpringPreset.php, src/SpringChain.php, src/SpringCollection.php, src/Projectile.php, src/Gravity.php, src/Point.php, src/Vector.php, src/Easing/Easing.php, src/Easing/CubicBezier.php, src/Lang.php, tests/*.php

---

## Overview

honey-bounce is a well-structured PHP port of charmbracelet/harmonica providing damped spring physics simulation and Newtonian projectile mechanics for terminal animation. The codebase follows SugarCraft conventions (PSR-12, immutable patterns, i18n via Lang, snapshot testing). The physics implementation is solid and the tests are comprehensive.

The issues below are organized by severity (HIGH → LOW → INFO → MISSING FEATURES).

---

## HIGH Severity

### 1. `SpringChain::tick()` mutates internal array in-place

**File**: `src/SpringChain.php:76-78`

```php
[$newPos, $newVel] = $spring->update($pos, $vel, $target);
$this->stages[$this->activeIndex][1] = $newPos;
$this->stages[$this->activeIndex][2] = $newVel;
```

The `tick()` method directly mutates `$this->stages[$this->activeIndex]` instead of returning the updated state. This breaks the immutable pattern used everywhere else in the library (`Projectile::update()`, `Vector::add()`, `Point::add()` all return new instances). 

**Impact**: If a caller keeps a reference to the stages array and compares later, they'll see mutated positions. Also makes the class non-reentrant — concurrent `tick()` calls on the same instance would race.

**Recommendation**: Change `tick()` to return `array{0: list<float>, 1: bool, 2: self}` (the updated chain), mirroring how `Projectile::update()` returns a new `Projectile`.

---

### 2. `SpringCollection::tick()` mutates state mid-iteration

**File**: `src/SpringCollection.php:56-68`

```php
public function tick(): array
{
    foreach ($this->springs as $id => $spring) {
        [$pos, $vel] = $spring->update(...);
        $this->positions[$id] = $pos;   // mutations during iteration
        $this->velocities[$id] = $vel;
    }
    return $this->positions;
}
```

While PHP's foreach operates on a copy of the array internal pointer, the mutation of `$this->positions` and `$this->velocities` during `tick()` is inconsistent with the immutable pattern. More critically, if `SpringCollection` were used in an async context (ReactPHP event loop), simultaneous calls could cause race conditions since the class has no locking.

**Recommendation**: Either return a new `SpringCollection` instance from `tick()` or add explicit documentation that this class is not thread-safe and should not be used in concurrent async contexts.

---

### 3. `CubicBezier` control point `x` values not validated against CSS spec

**File**: `src/Easing/CubicBezier.php:40-47`

The constructor accepts any `float` for `$x1` and `$x2`, but the CSS spec (and W3C spec this implementation follows) requires `0 ≤ x ≤ 1`. Invalid values (e.g., negative, or > 1) can cause:

- The Newton-Raphson loop to fail to converge (though binary search fallback handles it)
- Non-monotonic output where `evaluate(t)` returns values outside [0, 1]
- Potential infinite loops in the binary subdivision if epsilon is reached improperly

```php
public function __construct(float $x1, float $y1, float $x2, float $y2)
{
    // No validation of x1, x2 range
}
```

**Recommendation**: Add validation:
```php
if ($x1 < 0.0 || $x1 > 1.0 || $x2 < 0.0 || $x2 > 1.0) {
    throw new \InvalidArgumentException(Lang::t('cubicbezier.control_points_range'));
}
```

---

### 4. `SpringConfig` silently clamps mass to 0.001 instead of failing

**File**: `src/SpringConfig.php:33`

```php
$safeMass = max(0.001, $mass);
```

If a user passes `mass = 0.0` (which is physically nonsensical for an oscillator), the library substitutes `0.001` silently. This could produce physics that look like they work but are actually using a fudge factor. The comment says "safeMass" which implies safety, but there's no error or warning.

**Recommendation**: Either throw an exception for mass ≤ 0, or document clearly that mass values are clamped to 0.001 minimum. Given that `SpringConfig` is a value object meant to represent physical parameters, failing fast on invalid input is preferable.

---

## MEDIUM Severity

### 5. `Probe::reducedMotion()` called on every `Spring::update()`

**File**: `src/Spring.php:114-116`

```php
public function update(float $pos, float $vel, float $target): array
{
    if (Probe::reducedMotion()) {  // called every frame
        return [$target, 0.0];
    }
    ...
}
```

`Probe::reducedMotion()` likely reads an environment variable or system setting on each call. In a tight animation loop (60 fps), this is called 60 times per second. The result could be cached per-Spring instance at construction time.

**Recommendation**: Accept an optional `?bool $reducedMotionOverride` in the `Spring` constructor to let callers pre-bind the reduced-motion preference, avoiding per-frame env lookups.

---

### 6. Duplicated settling threshold constants (magic numbers 0.001)

**File**: `src/SpringChain.php:115`

```php
private function isSettled(float $pos, float $vel, float $target): bool
{
    return abs($pos - $target) < 0.001 && abs($vel) < 0.001;
}
```

The value `0.001` appears as a hardcoded threshold for both position and velocity. There's no shared constant, no way to configure it, and no comment explaining why these specific values were chosen. Note that `SpringConfig` also uses `1e-12` as a threshold for a similar "near-zero" check (line 37), and `Spring` uses `1e-9` for EPSILON (line 22).

**Recommendation**: Extract to a class constant `SpringChain::SETTLING_THRESHOLD = 0.001` and add an optional constructor parameter to override it per-chain-instance.

---

### 7. No `normalize()`, `lengthSquared()`, or `lerp()` on `Vector`

**File**: `src/Vector.php`

The `Vector` class has `length()`, `dot()`, `cross()`, `add()`, `sub()`, `scale()` — but is missing commonly-used vector operations:

- `lengthSquared()` — avoids `sqrt()` for comparisons (faster)
- `normalize()` — returns unit vector
- `lerp(Vector $other, float $t)` — linear interpolation between two vectors

These would be natural additions for a physics library.

---

### 8. `SpringChain` and `SpringCollection` are not `final`

**Files**: `src/SpringChain.php:14`, `src/SpringCollection.php:13`

Neither class is declared `final`. The rest of the library's public classes are `final` per the SugarCraft convention. These classes could theoretically be extended, but they don't have `protected` hooks for customization.

**Recommendation**: Add `final` to both class declarations for consistency.

---

### 9. `Easing` enum has no interface for testability

**File**: `src/Easing/Easing.php:14`

The `Easing` enum implements `ease(float $t): float` directly. There's no interface `EasingFunction` that could be implemented by custom easing classes. This makes it harder to mock or substitute custom easing functions in tests or by downstream consumers.

**Recommendation**: Add `interface EasingFunction { public function ease(float $t): float; }` and have `Easing` implement it. This costs nothing (PHP 8.1+ enums can implement interfaces) and improves testability.

---

### 10. No `__toString()` on `Point`, `Vector`, `SpringConfig`, or `Spring`

**Files**: `src/Point.php`, `src/Vector.php`, `src/SpringConfig.php`, `src/Spring.php`

When debugging physics simulations (especially the golden-file trajectory tests), it's common to want to inspect values with `echo`. Without `__toString()`, these objects just become `"Object id #N"` in output.

**Recommendation**: Add `__toString()` implementations to all value objects:
```php
public function __toString(): string
{
    return "Point(x: {$this->x}, y: {$this->y}, z: {$this->z})";
}
```

---

### 11. `SpringChain` doesn't return the updated chain from `tick()`

**File**: `src/SpringChain.php:63-81`

Unlike `Projectile::update()` which returns a new `Projectile`, `SpringChain::tick()` returns `[$this->currentPositions(), $isComplete]`. This means the caller must maintain the chain reference and call `tick()` on the same instance repeatedly. If you want to "rewind" or snapshot the chain mid-animation, you can't.

**Recommendation**: Return a 3rd element `self` (the updated chain) or change the pattern to return a new `SpringChain` each time.

---

## LOW Severity

### 12. `Gravity` class is entirely delegate methods to `Projectile`

**File**: `src/Gravity.php`

```php
public static function standard(): Vector { return Projectile::gravity(); }
public static function terminal(): Vector  { return Projectile::terminalGravity(); }
...
```

Every method is a one-line delegation. The only reason for `Gravity`'s existence is API ergonomic naming (`Gravity::standard()` vs `Projectile::gravity()`). This is fine but worth noting as a code smell — if `Projectile` factories were renamed, `Gravity` would need to be updated too.

---

### 13. `SpringChain::build()` is an unnecessary static factory

**File**: `src/SpringChain.php:34-37`

```php
public static function build(array $stages): self { return new self($stages); }
```

This is just a renaming of the constructor. The SugarCraft convention is `::new()` for the default factory, not `::build()`. Having both a constructor and a `build()` static method creates two ways to do the same thing.

**Recommendation**: Remove `::build()` and use the constructor directly, or rename to `::new()` per convention.

---

### 14. No `jsonSerialize()` on trajectory types

**Files**: `src/Point.php`, `src/Vector.php`

For golden-file snapshot testing, the code manually constructs arrays:
```php
$trajectory[] = ['x' => round($p->position()->x, 6), 'y' => round($p->position()->y, 6)];
```

Implementing `JsonSerializable` would simplify this to `$trajectory[] = (object)$point;` or `json_encode($point)`.

---

### 15. `Spring::fps()` accepts `int` but could accept `float` for more flexibility

**File**: `src/Spring.php:125-131`

```php
public static function fps(int $n): float { return 1.0 / $n; }
```

Accepts only `int`. Users who want to specify fractional frame rates (e.g., 59.94 for NTSC) must compute `1.0 / 59.94` manually.

**Recommendation**: Change to `float $n` or add an additional `hz(float $hz): float` method.

---

### 16. `Easing::ease()` is a large `match` expression

**File**: `src/Easing/Easing.php:44-81`

The `ease()` method is ~35 lines of match with 16 cases. For maintenance, each easing case could be broken into private methods. However, this is a stylistic concern — the current structure is clear and well-commented.

---

## INFO / CODE QUALITY

### 17. Tests don't cover cubic bezier invalid control points

**File**: `tests/CubicBezierTest.php`

No test verifies behavior when `x1 < 0` or `x2 > 1`. The Newton-Raphson and binary-subdivision algorithms should handle these, but they're untested.

---

### 18. Tests don't cover `SpringCollection::remove()` edge case

**File**: `tests/SpringCollectionTest.php`

What happens if you call `remove()` on an ID that doesn't exist? The current `unset()` is safe (no error), but there's no test verifying this is the intended behavior.

---

### 19. `ReducedMotionTest` modifies global process environment

**File**: `tests/ReducedMotionTest.php:12-24`

`putenv()` modifies the global process environment in `setUp()` and `tearDown()`. In a test suite running in parallel (e.g., with Paratest), this could cause flakiness. The tests themselves are correct but the isolation approach (process-level env vars) can't be parallelized safely.

---

### 20. Examples use hardcoded `__DIR__ . '/../vendor/autoload.php'`

**Files**: `examples/spring.php:11`, `examples/projectile.php:12`

If examples are run from a different working directory, the autoload path breaks. Using a more robust require or relying on Composer's bin/directories would be more portable.

---

### 21. `SpringConfig` readonly property assignment in constructor

**File**: `src/SpringConfig.php:20-21`

```php
public float $angularFrequency;
public float $dampingRatio;
```

These are declared as `readonly` properties but are assigned inside the constructor body (not via constructor property promotion). This works in PHP 8.3+ but is inconsistent with the library's use of promoted properties for `tension`, `friction`, `mass` (lines 29-31). Consider promoting these too:

```php
public function __construct(
    public float $tension,
    public float $friction,
    public float $mass,
    private readonly float $angularFrequency = 0.0,
    private readonly float $dampingRatio = 0.0,
) { ... }
```

However, this would require reworking the computation logic since they're derived values.

---

## MISSING FEATURES

### 22. No async/ReactPHP integration

This is a physics library that operates in time-stepped loops. In a ReactPHP-based TUI application, the animation loop is the event loop itself. Currently there's no:

- **Async spring**: A `Spring` variant that yields a Promise/amphp Stream of positions rather than blocking on each frame
- **Integration with `candy-async`**: No use of `AsyncIO` or streaming primitives for batch physics updates
- **Suspensive updates**: No way to schedule spring updates to happen on the next event loop tick rather than synchronously

**Recommendation**: Consider adding a `AsyncSpring` class that wraps a `Spring` and yields positions via an Amp coroutine, or a `Spring::stream()` method that returns a `Generator` of positions.

---

### 23. No multi-dimensional spring support

All springs operate on scalar `float` values. TUI animations typically need multi-dimensional state (x, y positions for a viewport, or x/y/width/height for a resizing panel). Users must manage multiple `Spring` instances manually.

**Recommendation**: Add `Spring2D` (or `SpringVector`) that operates on `Vector2D` (or reuse existing `Vector`), updating both components in a single step. Even `Vector2D = array{0: float, 1: float}` would be more ergonomic than managing separate springs.

---

### 24. No spring pause/resume

Once created, a `Spring` always moves toward its target on every `update()` call. There's no way to:

- Pause animation at the current position
- Resume from where it left off
- Check "is this spring currently moving?"

**Recommendation**: Add `pause()` / `resume()` / `isPaused()` methods to `Spring`.

---

### 25. No way to query "is at target" without side effects

`Spring` has no `isAtTarget()` method. You can only determine if a spring has converged by calling `update()` and checking the returned position. This makes it impossible to subscribe to "spring has arrived" events without advancing the simulation.

**Recommendation**: Add `isAtTarget(float $pos, float $vel, float $target): bool` static method or instance method.

---

### 26. No support for varying deltaTime per update

The `Spring` precomputes coefficients in its constructor based on `deltaTime`. If the caller wants to change frame rates mid-animation, they must create a new `Spring`. This is often the correct behavior (frame-rate independence), but some use cases need adaptive timestepping.

---

### 27. No `normalize()`, `distance()`, `angle()` on `Vector`

Only `length()`, `dot()`, `cross()`, `add()`, `sub()`, `scale()` exist. Common operations like normalizing a vector, computing angle between vectors, or computing distance between points would be natural extensions.

---

### 28. No spring "snap to target" or "reset" method

To immediately place a spring at its target (for example, when disabling animation), you must know the current velocity to pass to `update()`. A `snapToTarget()` method that instantly returns `[$target, 0.0]` would be cleaner.

---

### 29. No cubic-bezier with Newton-Raphson failure path verification

The algorithm uses up to 8 Newton iterations then falls back to binary subdivision. The test `testAllCssStandardPresetsReturnValidRange` verifies output validity but doesn't verify that the algorithm converges within the expected iteration count for typical inputs.

---

## Summary Table

| # | Severity | Category | Location | Issue |
|---|----------|----------|----------|-------|
| 1 | HIGH | Mutability | `SpringChain.php:76-78` | tick() mutates internal state in-place |
| 2 | HIGH | Thread Safety | `SpringCollection.php:56-68` | tick() mutates state mid-iteration, not async-safe |
| 3 | HIGH | Validation | `CubicBezier.php:40-47` | x1/x2 control points not validated to [0,1] CSS range |
| 4 | HIGH | Silent Failure | `SpringConfig.php:33` | mass=0 silently clamped to 0.001 |
| 5 | MEDIUM | Performance | `Spring.php:114-116` | reducedMotion() checked every update() call |
| 6 | MEDIUM | Duplication | `SpringChain.php:115` | Magic number 0.001 duplicated threshold |
| 7 | MEDIUM | Missing API | `Vector.php` | Missing normalize, lengthSquared, lerp |
| 8 | MEDIUM | Convention | `SpringChain.php`, `SpringCollection.php` | Not declared `final` |
| 9 | MEDIUM | Testability | `Easing.php` | No interface for custom easings |
| 10 | MEDIUM | Debugging | `Point.php`, `Vector.php`, etc. | No `__toString()` |
| 11 | MEDIUM | API Design | `SpringChain.php:63-81` | tick() doesn't return updated chain |
| 12 | LOW | Code Smell | `Gravity.php` | Entire class is delegation to Projectile |
| 13 | LOW | API Design | `SpringChain.php:34-37` | Redundant `::build()` static factory |
| 14 | LOW | Serialization | `Point.php`, `Vector.php` | No `JsonSerializable` |
| 15 | LOW | Flexibility | `Spring.php:125-131` | `fps()` only accepts int |
| 16 | LOW | Style | `Easing.php:44-81` | Large match expression (acceptable) |
| 17 | INFO | Testing | `CubicBezierTest.php` | No invalid control point coverage |
| 18 | INFO | Testing | `SpringCollectionTest.php` | No `remove()` non-existent-ID test |
| 19 | INFO | Testing | `ReducedMotionTest.php` | `putenv()` not parallel-safe |
| 20 | INFO | Portability | `examples/*.php` | Hardcoded relative autoload path |
| 21 | INFO | Style | `SpringConfig.php:20-21` | Readonly properties not promoted |
| 22 | MISSING | Async | — | No ReactPHP/async integration |
| 23 | MISSING | API | — | No multi-dimensional spring support |
| 24 | MISSING | API | — | No pause/resume for springs |
| 25 | MISSING | API | — | No `isAtTarget()` without side effects |
| 26 | MISSING | API | — | No varying deltaTime support |
| 27 | MISSING | API | — | Missing vector normalize/distance/angle |
| 28 | MISSING | API | — | No `snapToTarget()` convenience method |
| 29 | MISSING | Testing | `CubicBezier` | No Newton-Raphson convergence verification |

---

## Positive Notes

The library has several strong points worth acknowledging:

1. **Solid physics**: The damped harmonic oscillator implementation is correct, with proper handling of under/critically/over-damped regimes
2. **Good test coverage**: Snapshot tests (golden files), physics correctness tests, boundary condition tests, reduced-motion tests
3. **Immutable pattern**: Most classes (Point, Vector, Projectile, SpringConfig) correctly follow immutable-with-return-new-instance semantics
4. **Clear documentation**: Every class has doc comments explaining the physics and referencing upstream sources
5. **i18n integration**: Proper use of `Lang::t()` for user-facing error messages
6. **CSS spec compliance**: The CubicBezier implementation follows the W3C spec with Newton-Raphson + binary subdivision fallback
7. **Reduced motion support**: Good accessibility feature properly implemented
8. **Consistent coding style**: PSR-12 compliant, strict types everywhere, final classes

---

*End of audit report.*
