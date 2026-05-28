# SugarCraft/honey-bounce

## Metadata
- **Package**: `sugarcraft/honey-bounce`
- **Upstream**: [charmbracelet/harmonica](https://github.com/charmbracelet/harmonica) (1.53k stars, Go)
- **Language**: PHP 8.3+
- **Namespace**: `SugarCraft\Bounce`
- **Status**: 🟢 v1 ready (public API + tests + docs + demo)
- **Role**: Spring physics + Newtonian projectile simulation for terminal animation

## Overview

HoneyBounce is a pure-math PHP library providing two complementary animation primitives:

1. **Damped Spring Physics** — Ryan Juckett's analytically-exact damped harmonic oscillator algorithm for realistic spring-based animations (under-damped, critically-damped, over-damped regimes)
2. **Newtonian Projectile Simulation** — Euler-integrated 3D point/vector-based projectile motion with configurable gravity

Unlike UI-focused animation libraries, honey-bounce is **framework-agnostic** — it solves the physics equations and returns numbers; consumers decide how to render them. This makes it suitable for TUIs (candy-core), game physics (honey-flap/Flappy Bird clone), particle effects, or any context requiring smooth, physically-grounded motion.

The library extends its upstream (harmonica) with significant ergonomic improvements: spring presets modeled after UIKit's tension/friction constants, `SpringChain` for sequenced animations, `SpringCollection` for multi-spring management, WCAG `REDUCE_MOTION` accessibility support, and a complete `Easing`/`CubicBezier` curve library for tweening.

## Architecture

```
honey-bounce/src/
├── Spring.php              # Core damped spring oscillator (Ryan Juckett algorithm)
├── SpringConfig.php        # tension/friction/mass → ω/ζ derivation
├── SpringPreset.php        # UIKit-inspired named presets (Gentle, Wobbly, Stiff, Slow, Molasses)
├── SpringChain.php         # Sequential spring sequencer
├── SpringCollection.php   # Multi-spring manager with tick()/setTarget()
├── Projectile.php          # Newtonian projectile (Euler integration)
├── Point.php               # Immutable 3D point {x, y, z}
├── Vector.php              # Immutable 3D vector with add/sub/scale/length/dot/cross
├── Gravity.php             # Package-level static gravity vector accessors
├── Easing/
│   ├── Easing.php          # Enum with 15 named easing curves (Linear, Quad, Cubic, Elastic, Bounce, Back)
│   └── CubicBezier.php    # CSS cubic-bezier() with Newton-Raphson solver
└── Lang.php               # i18n facade wrapping SugarCraft\Core\I18n\T
```

### Design Principles

- **Immutable where possible**: `Spring::update()` returns `[pos, vel]` pair; `Projectile::update()` returns new `Projectile` instance; `Vector`/`Point` are value objects
- **O(1) per-frame updates**: All complex math precomputed in constructor; `update()` is 4 multiplies + 2 adds
- **FPS-first timing**: `Spring::fps(int $n)` returns `1.0/n` for consistent simulation cadence
- **No terminal dependency**: Pure math library — consumers handle rendering

## Feature Inventory

### Core Physics

| Class | Description | Key Methods |
|-------|-------------|-------------|
| `Spring` | Damped harmonic oscillator | `update($pos, $vel, $target)`, `fps(int)`, `fromPreset(SpringPreset, ?dt)` |
| `SpringConfig` | Physical parameters | `spring($dt)`, `springAt60Fps()` |
| `SpringPreset` | UIKit-style presets | `Gentle`, `Wobbly`, `Stiff`, `Slow`, `Molasses` (via `resolve()`) |
| `Projectile` | Euler-integrated body | `update()`, `position()`, `velocity()`, `acceleration()` |
| `Point` | 3D position | `add(Vector)`, `distance(Point)` |
| `Vector` | 3D magnitude+direction | `add`, `sub`, `scale`, `length`, `dot`, `cross` |
| `Gravity` | Static gravity accessors | `standard()`, `terminal()`, `standardYDown()`, `terminalYDown()` |

### Animation Sequencers

| Class | Description | Key Methods |
|-------|-------------|-------------|
| `SpringChain` | Sequential stage animator | `tick()`, `isComplete()`, `activeStage()`, `currentPositions()`, `withStage()` |
| `SpringCollection` | Parallel multi-spring manager | `tick()`, `add()`, `remove()`, `setTarget()`, `getTarget()`, `all()` |

### Easing Curves

| Class | Description | Coverage |
|-------|-------------|----------|
| `Easing` | PHP enum with 15 named curves | Linear, Quad, Cubic, Elastic, Bounce, Back (In/Out/InOut) |
| `CubicBezier` | CSS spec implementation | 24 CSS-named easings via Newton-Raphson + binary-search fallback |

### Accessibility

| Feature | Implementation | File |
|---------|----------------|------|
| `REDUCE_MOTION` env var | `Probe::reducedMotion()` check in `Spring::update()` | `Spring.php:114` |
| WCAG 2.1 compliance | Instant snap to target when reduced motion active | `Spring.php:114-116` |

## Physics Implementation Analysis

### Spring Physics: Ryan Juckett's Algorithm

The spring implementation is a direct PHP port of Ryan Juckett's 2008–2012 C++ algorithm for analytically solving the damped harmonic oscillator ODE for a fixed timestep. The key insight is that for a given `(deltaTime, angularFrequency, dampingRatio)` tuple, all coefficients can be precomputed once in the constructor, making each `update()` call constant-time.

**Source**: `honey-bounce/src/Spring.php:34-102`

#### Three Damping Regimes

The algorithm branches on `dampingRatio` to produce coefficients for three distinct physical behaviors:

**1. Over-damped (`ζ > 1`)** — `Spring.php:51-70`
```php
$za = -$angularFrequency * $dampingRatio;
$zb = $angularFrequency * sqrt($dampingRatio * $dampingRatio - 1.0);
$z1 = $za - $zb;
$z2 = $za + $zb;
// Coefficients use hyperbolic functions (exp, sqrt) — no oscillation
```
- Converges monotonically without oscillation
- Slower than critical damping
- Suitable for "heavy, lazy" motion

**2. Under-damped (`ζ < 1`)** — `Spring.php:73-90`
```php
$omegaZeta = $angularFrequency * $dampingRatio;
$alpha = $angularFrequency * sqrt(1.0 - $dampingRatio * $dampingRatio);
// Uses trig functions (cos, sin, exp) — oscillatory decay
```
- Oscillates around target with decaying amplitude
- Suitable for "bouncy" feel (preset: `Wobbly`)

**3. Critically-damped (`ζ = 1`)** — `Spring.php:93-101`
```php
$expTerm = exp(-$angularFrequency * $deltaTime);
$timeExp = $deltaTime * $expTerm;
// Special case: fastest convergence without overshoot
```
- The default for "snap to value" animations
- Presets `Gentle`, `Stiff`, `Slow`, `Molasses` all use critical damping

#### Equilibrium-Relative Update

The `update()` method at `Spring.php:112-122` uses equilibrium-relative coordinates:

```php
$oldPos = $pos - $target;           // Shift to equilibrium-relative space
$newPos = $oldPos * $this->posPosCoef + $vel * $this->posVelCoef + $target;  // Apply coefficients
$newVel = $oldPos * $this->velPosCoef + $vel * $this->velVelCoef;
return [$newPos, $newVel];
```

This is mathematically equivalent to solving the ODE analytically per timestep.

#### Coefficient Caching

The constructor precomputes four coefficients (`posPosCoef`, `posVelCoef`, `velPosCoef`, `velVelCoef`) for the specific `(dt, ω, ζ)` triple. Each `update()` call is:

```
4 multiplications + 2 additions = O(1) constant time
```

This is significantly more stable than numerical integration (Verlet, RK4) at low frame rates.

### Projectile Physics: Euler Integration

**Source**: `honey-bounce/src/Projectile.php:65-70`

```php
public function update(): self
{
    $newPos = $this->position->add($this->velocity->scale($this->deltaTime));
    $newVel = $this->velocity->add($this->acceleration->scale($this->deltaTime));
    return new self($this->deltaTime, $newPos, $newVel, $this->acceleration);
}
```

Uses first-order Euler integration:
- `pos += vel * dt`
- `vel += acc * dt`

**Limitation**: Euler integration is numerically unstable for large timesteps or high-velocity scenarios. For games or simulations requiring accuracy, consider upgrading to RK4 or Verlet integration.

### SpringConfig: User-Facing Parameters

**Source**: `honey-bounce/src/SpringConfig.php:28-44`

```php
angularFrequency = sqrt(tension / mass)
dampingRatio    = friction / (2 * sqrt(tension * mass))
```

This translates the user-facing "spring feel" parameters (tension, friction, mass) into the physically meaningful coefficients (ω, ζ) consumed by the `Spring` constructor. Presets map from UIKit's canonical values (`SpringPreset.php:30-56`):

| Preset | Tension | Friction | Mass | Feel |
|--------|---------|----------|------|------|
| Gentle | 100 | 10 | 1 | soft, slow overshoot |
| Wobbly | 180 | 12 | 1 | bouncy oscillation |
| Stiff | 500 | 20 | 1 | snappy snap |
| Slow | 50 | 6 | 1 | heavy, lazy settle |
| Molasses | 30 | 4 | 1 | barely moves |

### Vector/Point Mathematics

**Source**: `honey-bounce/src/Vector.php`, `honey-bounce/src/Point.php`

Both are immutable 3D value objects with `$z` defaulting to `0.0` for 2D backward compatibility. `Vector` provides:
- `add(self $other)` — vector addition
- `sub(self $other)` — vector subtraction
- `scale(float $s)` — scalar multiplication
- `length()` — Euclidean magnitude
- `dot(self $other)` — dot product
- `cross(self $other)` — cross product (right-hand rule)

`Point` provides:
- `add(Vector)` — translate point
- `distance(self $other)` — Euclidean distance

This is richer than upstream harmonica's `Vector` (which is just 3 public float fields with no methods).

### CubicBezier: CSS Spec Implementation

**Source**: `honey-bounce/src/Easing/CubicBezier.php:109-148`

The CSS `cubic-bezier()` easing algorithm implements Newton-Raphson root-finding with binary-search fallback per [W3C CSS Easing spec](https://www.w3.org/TR/css-easing-3/#cubic-bezier-algo):

```php
private function solveCubicX(float $x): float
{
    $t = $x;
    // Newton iterations (up to 8)
    for ($i = 0; $i < self::NEWTON_ITERATIONS; $i++) {
        $currentX = $this->sampleCurveX($t) - $x;
        if (abs($currentX) < self::NEWTON_MIN_STEP) {
            return $t;
        }
        $derivative = $this->sampleCurveDerivativeX($t);
        if (abs($derivative) < self::NEWTON_MIN_STEP) {
            break;
        }
        $t -= $currentX / $derivative;
    }
    // Binary search fallback for non-monotonic control points
    return $this->solveCubicXSubdivision($x);
}
```

This ensures monotonic behavior even when control point configurations would otherwise cause non-monotonic x(t).

## Integration with candy-core

### Dependency Graph

```
candy-core (TUI runtime)
    └── candy-palette (color detection, Probe::reducedMotion())
            └── honey-bounce (depends on both)

honey-flap (Flappy Bird game)
    └── candy-core
    └── candy-sprinkles
    └── honey-bounce ← Bird uses Projectile for vertical motion
```

### Reduced Motion Integration

**Source**: `honey-bounce/src/Spring.php:114-116` + `candy-palette/src/Probe.php`

```php
// Spring.php
if (Probe::reducedMotion()) {
    return [$target, 0.0];
}
```

`Probe::reducedMotion()` checks:
1. `getenv('REDUCE_MOTION') === '1'`
2. `getenv('PREFERS_REDUCED_MOTION') === '1'`
3. Terminal signals `prefers-reduced-motion` (via `Terminfo`/`ncurses`)

This provides WCAG 2.1 compliance at the physics layer — any consumer using `Spring::update()` gets reduced-motion support automatically.

### honey-flap: Production Integration Example

**Source**: `honey-flap/src/Bird.php`

The Flappy Bird clone uses `Projectile` for vertical bird motion:

```php
public static function spawn(int $x, float $y): self
{
    return new self(
        x: $x,
        body: Projectile::new(
            deltaTime:    Spring::fps(self::TICKS_PER_SEC),  // 30 FPS game loop
            position:     new Point($x, $y),
            velocity:     Vector::zero(),
            acceleration: new Vector(0.0, self::GRAVITY),    // gravity pulls down
        ),
    );
}

public function flap(): self
{
    // Reset vertical velocity to upward kick
    return new self(
        $this->x,
        Projectile::new(
            deltaTime:    Spring::fps(self::TICKS_PER_SEC),
            position:     $this->body->position,
            velocity:     new Vector(0.0, self::FLAP_KICK),  // -22 cells/sec upward
            acceleration: new Vector(0.0, self::GRAVITY),
        ),
    );
}
```

This demonstrates the clean separation between physics (honey-bounce) and rendering (candy-core's `Model::view()`).

## Animation Pattern Opportunities

### 1. SpringChain for Staggered Animations

**Source**: `honey-bounce/src/SpringChain.php`

Sequences multiple springs so each stage activates only when the previous settles (within 0.001 position and 0.001 velocity). Use for UI transitions where elements must animate in sequence:

```php
$chain = (new SpringChain([]))
    ->withStage(Spring::fromPreset(SpringPreset::Gentle), 0.0, 0.0, 50.0)
    ->withStage(Spring::fromPreset(SpringPreset::Wobbly), 0.0, 0.0, 100.0)
    ->withStage(Spring::fromPreset(SpringPreset::Stiff),  0.0, 0.0, 75.0);

while (!$chain->isComplete()) {
    [$positions, $complete] = $chain->tick();
    // $positions reflects settled stages + currently animating stage
}
```

### 2. SpringCollection for Parallax/Multi-Axis Animations

**Source**: `honey-bounce/src/SpringCollection.php`

Manages multiple named springs simultaneously — ideal for multi-axis animations (e.g., X/Y position of a draggable element):

```php
$collection->add('x', Spring::fromPreset(SpringPreset::Stiff), $x, 0.0, $targetX);
$collection->add('y', Spring::fromPreset(SpringPreset::Gentle), $y, 0.0, $targetY);

// In render loop:
$positions = $collection->tick();
// $positions = ['x' => 45.3, 'y' => 12.7]
```

### 3. Easing Curves for Tweening

**Source**: `honey-bounce/src/Easing/Easing.php`

Named easing curves apply to normalized time `[0.0, 1.0]`:

```php
$ease = Easing::ElasticOut;
for ($f = 0; $f <= 60; $f++) {
    $t = $f / 60.0;
    $value = $ease->ease($t);  // Apply easing to linear progression
    // Use $value for custom interpolation
}
```

### 4. CubicBezier for Custom Easing

**Source**: `honey-bounce/src/Easing/CubicBezier.php`

For precise control, use CSS cubic-bezier curves:

```php
$ease = CubicBezier::easeOutExpo();
// or custom:
$ease = new CubicBezier(0.19, 1.0, 0.22, 1.0);
$value = $ease->evaluate($t);
```

## Performance Considerations for Physics

### Strengths

1. **O(1) per-frame updates**: After constructor precomputes coefficients, `Spring::update()` is 4 multiplies + 2 adds. Projectile is 6 multiplies + 6 adds (negligible).

2. **No GC pressure per frame**: Immutable `Projectile::update()` creates a new instance — in tight game loops (honey-flap at 30 FPS), this generates garbage. For performance-critical use cases, consider a mutable variant or object pooling.

3. **Precomputed coefficients**: The heavy math (exp, sqrt, sin, cos) happens once at construction, not per frame.

4. **FPS-based timing**: `Spring::fps(60)` returns consistent `1/60` deltaTime. Frame-rate independence is built into the API.

### Weaknesses

1. **No frame-skip handling**: If the actual frame time differs from the baked-in `deltaTime`, the simulation drifts. Consider adding a `Spring::updateWithElapsed($pos, $vel, $target, $actualDt)` variant.

2. **Euler integration drift**: `Projectile::update()` uses first-order Euler, which accumulates error over time. For games requiring high accuracy over many frames, the projectile's position will drift slightly.

3. **No collision detection**: `Projectile` is pure Kinematics — no collision response. Consumers must implement boundary checking and velocity restitution.

4. **Immutable Projectile allocation**: Each tick allocates a new `Projectile` instance. For particle systems with 100s of particles, this could be a memory/GC concern. Consider a mutable `ProjectileMutable` variant with in-place updates.

## Suggested Roadmap for Animation System

### Short-term (v1.x)

1. **Mutable Projectile variant**: Add `ProjectileMutable` with in-place `update()` for game/particle use cases where allocation overhead matters.

2. **Frame-skip handling**: Add `Spring::updateAdaptive($pos, $vel, $target, $actualDt)` that recomputes coefficients if actual frame time deviates significantly from baked-in dt.

3. **Spring termination callback**: Allow consumers to register a callback (`onSettled`) triggered when a spring reaches its target, rather than polling `isComplete()`.

4. **2D Vector shortcut**: Add `Vector2D` or a `Vector::from2D()` factory for 2D-only use cases, avoiding the unused `$z` allocation.

### Medium-term (v2.0)

1. **Verlet integration for Projectile**: Upgrade from Euler to Verlet integration for better numerical stability in games — position computed directly from acceleration without velocity accumulation error.

2. **Collision Response primitives**: Add `Collidable` interface with `SphereCollider`, `AABBCollider` for physics-based games.

3. **Spring1D/2D/3D generic variants**: Template the spring to specific dimensions to avoid array packing/unpacking (`[$pos, $vel]`) and enable SIMD optimizations.

4. **ParticleSystem class**: Bundle a `Particle` struct + `ParticleSystem` manager with spatial hashing for efficient broad-phase collision detection.

5. **Animation Timeline**: Add `Timeline` class that orchestrates multiple `SpringChain`/`SpringCollection` instances with start/delay/loop/repeat config — modeled after GSAP's timeline.

6. **Keyframe animation**: Add `KeyframeAnimation` with cubic interpolation between keyframes, supporting `Easing` curves between segments.

### Long-term

1. **rust-physics binding**: Consider binding to a Rust physics library (e.g., `rapier3d`, `physave`) via PHP FFI for complex physics (ragdolls, soft bodies).

2. **WebAssembly compilation**: Compile honey-bounce to WASM for use in browser-based TUI rendering (textual-web equivalent).

3. **Particle GPU acceleration**: For >1000 particle systems, offload to GPU via `ext-gpu` or WebGL compute shaders.

## Strengths

1. **Analytically exact spring physics**: Ryan Juckett's algorithm is numerically stable at any frame rate, unlike naive Verlet/RK4
2. **Constant-time updates**: 4 multiplies + 2 adds per spring tick after one-time coefficient setup
3. **Rich presets**: UIKit-inspired spring presets eliminate magic number tuning for common use cases
4. **Framework-agnostic**: Pure math — no terminal or UI dependencies; works in TUIs, games, or any animation context
5. **Accessibility built-in**: `REDUCE_MOTION` support at the physics layer, not bolted on
6. **Complete easing coverage**: 15 named easings + 24 CSS cubic-bezier curves — no need to reach for a separate library
7. **Immutable design**: Value semantics prevent spooky-action-at-a-distance bugs
8. **Y-up/Y-down flexibility**: `Gravity::standard()` vs `Gravity::standardYDown()` matches any coordinate convention
9. **Production validated**: Powers `honey-flap` Flappy Bird clone; harmonica powers TUI animations in bubbletea

## Weaknesses

1. **No collision detection**: `Projectile` is pure kinematics; consumers must implement boundary/collision logic
2. **Euler integration drift**: First-order Euler accumulates error over many frames
3. **Mutable Projectile allocation**: Immutable `update()` creates garbage in tight loops
4. **No frame-skip handling**: Baked-in `deltaTime` assumes consistent frame cadence
5. **No multi-body physics**: No joint/constraint system for connected bodies (ragdolls, soft bodies)
6. **No spatial partitioning**: `SpringCollection` uses linear search — O(n) per tick for n springs
7. **PHP-only**: No WASM/browser target, limiting use to server-side TUI or CLI tools
8. **No animation timeline**: Consumers must manually orchestrate multi-stage animations via `SpringChain` polling
9. **Limited curve types**: No B-spline, Catmull-Rom, or custom piecewise bezier support

## Comparison with Upstream (harmonica)

| Feature | harmonica (Go) | honey-bounce (PHP) | Advantage |
|---------|---------------|-------------------|-----------|
| Spring physics | ✓ | ✓ | parity |
| Projectile physics | ✓ | ✓ | parity |
| Immutable update | ✗ (mutates Projectile) | ✓ | honey-bounce |
| Spring presets | ✗ | ✓ (5 UIKit presets) | honey-bounce |
| SpringChain | ✗ | ✓ (sequential animations) | honey-bounce |
| SpringCollection | ✗ | ✓ (multi-spring manager) | honey-bounce |
| REDUCE_MOTION | ✗ | ✓ | honey-bounce |
| Easing curves | ✗ | ✓ (15 named + 24 CSS bezier) | honey-bounce |
| Vector math methods | ✗ (just 3 floats) | ✓ (add/sub/scale/dot/cross) | honey-bounce |
| Y-down gravity variant | ✗ | ✓ | honey-bounce |
| FPS helper | ✓ | ✓ | parity |

**Verdict**: honey-bounce is a faithful PHP port that meaningfully extends upstream with ergonomic improvements (presets, immutability, easing, accessibility) without distorting the core algorithm.

## Third-Party Animation Libraries (for reference)

### Ratatui (Rust)
- No built-in animation framework — animations must be manually implemented via state and timers
- Uses immediate-mode rendering with buffer diffing — animation state managed by consumer
- **Lesson**: honey-bounce's physics-first approach is the right split; rendering should remain consumer's responsibility

### Textual (Python)
- Built-in `Animator` class with easing functions and transitions
- Reactive state + watchers provide automatic animation triggering
- **Lesson**: A `SugarCraft\Bounce\Animator` wrapper that auto-hooks springs to reactive properties in candy-core could provide similar ergonomics

### Charmbracelet/confettysh (Go)
- Particle system using velocity, gravity, acceleration for confetti/fireworks
- SugarCraft has no particle system — `honey-bounce` + a future `ParticleSystem` class could replicate this
- **Lesson**: Projectile physics can serve as the foundation for particle effects; need only add emission/collision/lifetime management

## Files Reference

| File | Purpose |
|------|---------|
| `honey-bounce/src/Spring.php` | Core damped spring oscillator (Ryan Juckett algorithm) |
| `honey-bounce/src/SpringConfig.php` | Tension/friction/mass → angularFrequency/dampingRatio |
| `honey-bounce/src/SpringPreset.php` | UIKit-inspired named presets |
| `honey-bounce/src/SpringChain.php` | Sequential spring animation sequencer |
| `honey-bounce/src/SpringCollection.php` | Multi-spring parallel manager |
| `honey-bounce/src/Projectile.php` | Euler-integrated projectile with gravity |
| `honey-bounce/src/Vector.php` | Immutable 3D vector with math ops |
| `honey-bounce/src/Point.php` | Immutable 3D point |
| `honey-bounce/src/Gravity.php` | Package-level gravity vector accessors |
| `honey-bounce/src/Easing/Easing.php` | Enum with 15 easing curves |
| `honey-bounce/src/Easing/CubicBezier.php` | CSS cubic-bezier() implementation |
| `honey-bounce/tests/SpringTest.php` | Snapshot + behavior tests for spring physics |
| `honey-bounce/tests/ProjectileTest.php` | Euler integration + vector math tests |
| `honey-bounce/tests/SpringChainTest.php` | Sequential animation tests |
| `honey-bounce/examples/spring.php` | Three damping regimes demo |
| `honey-bounce/examples/projectile.php` | 2D projectile arc demo |
| `honey-bounce/examples/particle.php` | Multi-particle system demo |
| `honey-flap/src/Bird.php` | Real-world integration: Projectile for game physics |
| `candy-palette/src/Probe.php` | `REDUCE_MOTION` detection implementation |
