<img src=".assets/icon.png" alt="honey-bounce" width="160" align="right">

# HoneyBounce

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=honey-bounce)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=honey-bounce)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/honey-bounce?label=packagist)](https://packagist.org/packages/sugarcraft/honey-bounce)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


PHP port of [charmbracelet/harmonica](https://github.com/charmbracelet/harmonica) —
damped-spring physics + Newtonian projectile simulation for animation.
Pure math; no terminal dependency.

```sh
composer require sugarcraft/honey-bounce
```

## Spring

Damped harmonic oscillator (Ryan-Juckett's algorithm). Choose `dampingRatio`:
< 1 oscillates, = 1 is critical (no overshoot, fastest convergence), > 1 is
over-damped.

```php
use SugarCraft\Bounce\Spring;

$spring = new Spring(
    deltaTime:        Spring::fps(60),  // 1/60 of a second
    angularFrequency: 6.0,              // rad/sec
    dampingRatio:     1.0,             // critical
);
$pos = 0.0;
$vel = 0.0;
$target = 100.0;

for ($frame = 0; $frame < 60; $frame++) {
    [$pos, $vel] = $spring->update($pos, $vel, $target);
    echo sprintf("frame %2d  pos=%.2f  vel=%.2f\n", $frame, $pos, $vel);
}
```

`Spring::fps(int $n)` returns `1.0 / $n` for the deltaTime — pair with the
same `$n` per-second simulation cadence.

### Reduced motion

When the `REDUCE_MOTION=1` environment variable is set or the terminal
signals `prefers-reduced-motion`, `Spring::update()` snaps to `$target`
instantly and returns `[<target>, 0.0]`. This satisfies the
[WCAG 2.1 reduced-motion guideline](https://www.w3.org/WIA/WDAG-ACCRM/)
and matches the behaviour of `SugarCraft\Palette\Probe::reducedMotion()`.

```php
// With REDUCE_MOTION=1 the spring skips animation entirely:
putenv('REDUCE_MOTION=1');
[$pos, $vel] = $spring->update(0.0, 0.0, 100.0);  // returns [100.0, 0.0]
```

### Spring presets

`Spring::fromPreset(SpringPreset $preset, ?float $deltaTime = null)` constructs
a spring from a named preset at 60 fps (override the frame time as needed).
Five presets are available, translated from UIKit's canonical values:

| Preset     | Feel      | Tension | Friction | Mass |
|------------|-----------|---------|----------|------|
| `Gentle`   | soft, slow overshoot | 100 | 10 | 1 |
| `Wobbly`   | bouncy oscillation   | 180 | 12 | 1 |
| `Stiff`    | snappy snap          | 500 | 20 | 1 |
| `Slow`     | heavy, lazy settle    |  50 |  6 | 1 |
| `Molasses` | barely moves          |  30 |  4 | 1 |

```php
use SugarCraft\Bounce\{Spring, SpringPreset};

$spring = Spring::fromPreset(SpringPreset::Wobbly);
// With custom frame rate
$spring60 = Spring::fromPreset(SpringPreset::Stiff, 1.0 / 60.0);
$spring30 = Spring::fromPreset(SpringPreset::Gentle, 1.0 / 30.0);
```

### SpringConfig

`SpringConfig` accepts physical parameters (tension / friction / mass) and
derives the `angularFrequency` and `dampingRatio` consumed by `Spring`:

```
angularFrequency = sqrt(tension / mass)
dampingRatio     = friction / (2 * sqrt(tension * mass))
```

```php
use SugarCraft\Bounce\{SpringConfig, Spring};

$config = new SpringConfig(tension: 180.0, friction: 12.0, mass: 1.0);
$spring = $config->springAt60Fps();  // or ->spring($deltaTime)
```

Both `SpringConfig::spring()` and `SpringConfig::springAt60Fps()` return
a pre-wired `Spring` instance ready to drive `update()` calls.

## Projectile

Newtonian-physics simulator for arcs / bouncing balls / particle effects.

```php
use SugarCraft\Bounce\{Point, Projectile, Vector};

$p = Projectile::new(
    deltaTime:    Spring::fps(60),
    position:     Point::zero(),
    velocity:     new Vector(5.0, -10.0),
    acceleration: Projectile::gravity(),  // (0, 9.81) — Y-down
);
for ($i = 0; $i < 60; $i++) {
    $p = $p->update();
    echo sprintf("t=%2d  pos=(%.1f, %.1f)\n", $i, $p->position->x, $p->position->y);
}
```

Gravity constants: `Projectile::GRAVITY` (9.81) and
`Projectile::TERMINAL_GRAVITY` (53.0). Helper factories
`Projectile::gravity()` and `Projectile::terminalGravity()` return Y-axis
`Vector` instances ready to drop into the constructor.

`SugarCraft\Bounce\Gravity` exposes the same vectors as static
accessors at the package level — `Gravity::standard()`,
`Gravity::terminal()`, `Gravity::standardYDown()`,
`Gravity::terminalYDown()` — so call sites translating from harmonica's
package-level `Gravity` / `TerminalGravity` constants read uniformly.

## Damping-ratio regimes

The `dampingRatio` argument to `Spring` picks one of three classical
behaviours:

- **Under-damped** (`ζ < 1`) — oscillates around the target,
  amplitudes decaying each cycle. Picks for "bouncy" feel.
- **Critically-damped** (`ζ = 1`) — fastest convergence with no
  overshoot. The default for "snap to value" animations.
- **Over-damped** (`ζ > 1`) — converges without overshoot but slower
  than critical. Picks for slow, weighty motion.

Negative damping ratios are clamped to `0` (a pure oscillator with
no decay would never settle).

## Coordinate systems

Both `Vector` and `Point` are **3D** (`x`, `y`, `z`) — the constructor's
`$z` defaults to `0.0` so existing 2D call sites still compile
unchanged. Use the third dimension when porting demos that need a Z
axis (parallax / depth-shaded particle systems).

The Y-axis convention is **Y-up** by default to match upstream
harmonica: `Gravity::standard()` returns `(0, -9.81, 0)` so increasing
Y means "up the screen". Terminal renderers usually grow downward —
flip to `Gravity::standardYDown()` (or its `Projectile::gravityYDown()`
alias) when you want gravity to pull toward the bottom of the grid
without manually negating every coordinate.

`Projectile::update()` returns a **new `Projectile`** instance each
call (immutable-with-pattern); upstream `Projectile.Update()` returns
the new `Point` and mutates the receiver in place. Read the new
position from `result->position` rather than `$p->position()`.

## SpringChain

Sequence multiple springs so that one spring's settle triggers the next.
Useful for staggered animations where each stage must complete before
the next begins.

```php
use SugarCraft\Bounce\{SpringChain, Spring, SpringPreset};

$chain = (new SpringChain([]))
    ->withStage(Spring::fromPreset(SpringPreset::Gentle), 0.0, 0.0, 50.0)
    ->withStage(Spring::fromPreset(SpringPreset::Wobbly), 0.0, 0.0, 100.0)
    ->withStage(Spring::fromPreset(SpringPreset::Stiff),  0.0, 0.0, 75.0);

while (!$chain->isComplete()) {
    [$positions, $complete] = $chain->tick();
    // $positions reflects settled stages + the currently animating stage
}
```

Each `tick()` call advances only the active stage. When that stage reaches
its target (position and velocity both within 0.001 of target), the chain
activates the next stage. `isComplete()` returns `true` when all stages
have settled.

## Easing

`SugarCraft\Bounce\Easing\Easing` provides named easing curves via its
`ease(float $t): float` method — apply to any normalized time value in
`[0.0, 1.0]`:

```php
use SugarCraft\Bounce\Easing\Easing;

$ease = Easing::ElasticOut;
for ($f = 0; $f <= 60; $f++) {
    $t = $f / 60.0;
    echo sprintf("frame %2d  t=%.3f  eased=%.3f\n", $f, $t, $ease->ease($t));
}
```

### CubicBezier

`CubicBezier` implements the CSS `cubic-bezier()` easing algorithm
(Newton-Raphson root-finding with binary-search fallback) for
monotonic interpolation. Construct via static factory methods covering
all 24 CSS standard easings:

```php
use SugarCraft\Bounce\Easing\CubicBezier;

// CSS named easings
$ease      = CubicBezier::ease();       // 0.25, 0.10, 0.25, 1.00
$easeIn   = CubicBezier::easeIn();      // 0.42, 0.00, 1.00, 1.00
$easeOut  = CubicBezier::easeOut();    // 0.00, 0.00, 0.58, 1.00
$easeInOut = CubicBezier::easeInOut(); // 0.42, 0.00, 0.58, 1.00
$linear   = CubicBezier::linear();      // 0.00, 0.00, 1.00, 1.00

// Sine
$easeInSine      = CubicBezier::easeInSine();
$easeOutSine     = CubicBezier::easeOutSine();
$easeInOutSine   = CubicBezier::easeInOutSine();

// Quadratic
$easeInQuad      = CubicBezier::easeInQuad();
$easeOutQuad     = CubicBezier::easeOutQuad();
$easeInOutQuad   = CubicBezier::easeInOutQuad();

// Cubic
$easeInCubic     = CubicBezier::easeInCubic();
$easeOutCubic    = CubicBezier::easeOutCubic();
$easeInOutCubic  = CubicBezier::easeInOutCubic();

// Quartic / Quintic / Exponential / Circular
$easeInQuart     = CubicBezier::easeInQuart();
$easeOutQuart    = CubicBezier::easeOutQuart();
$easeInOutQuart  = CubicBezier::easeInOutQuart();

$easeInQuint     = CubicBezier::easeInQuint();
$easeOutQuint    = CubicBezier::easeOutQuint();
$easeInOutQuint  = CubicBezier::easeInOutQuint();

$easeInExpo      = CubicBezier::easeInExpo();
$easeOutExpo     = CubicBezier::easeOutExpo();
$easeInOutExpo   = CubicBezier::easeInOutExpo();

$easeInCirc      = CubicBezier::easeInCirc();
$easeOutCirc     = CubicBezier::easeOutCirc();
$easeInOutCirc  = CubicBezier::easeInOutCirc();

for ($f = 0; $f <= 60; $f++) {
    $t = $f / 60.0;
    echo sprintf("frame %2d  eased=%.4f\n", $f, $easeInOutCubic->evaluate($t));
}
```

`CubicBezier::evaluate(float $t): float` maps `[0, 1]` → `[0, 1]`
using the Newton-Raphson algorithm from the W3C CSS Easing spec.

## Public API

- **`Spring`** — `__construct($dt, $ω, $ζ)` / `update($pos, $vel, $target)` /
  `fps(int)` / `fromPreset(SpringPreset, ?float)`. `update()` short-circuits
  to `[$target, 0.0]` when `Probe::reducedMotion()` is true.
- **`SpringChain`** — `__construct($stages)` / `build($stages)` /
  `withStage(Spring, $pos, $vel, $target)` / `tick(): (list<float>, bool)` /
  `currentPositions(): list<float>` / `isComplete(): bool` /
  `activeStage(): int`.
- **`SpringCollection`** — `add($id, Spring, ...)` / `remove($id)` /
  `tick(): array<string,float>` / `get($id): float` / `has($id): bool` /
  `all(): array<string,float>` / `setTarget($id, $target)` /
  `getTarget($id): float`.
- **`SpringPreset`** — `Gentle` / `Wobbly` / `Stiff` / `Slow` / `Molasses`.
  `resolve()` returns a `SpringConfig`.
- **`SpringConfig`** — `__construct(tension, friction, mass)` /
  `spring(float $dt)` / `springAt60Fps()`.
- **`Projectile`** — `Projectile::new(...)` / `update()` / `position()` /
  `velocity()` / `acceleration()` / `gravity()` / `terminalGravity()` /
  `gravityYDown()` / `terminalGravityYDown()` / `GRAVITY` /
  `TERMINAL_GRAVITY`.
- **`Gravity`** — package-level static accessors mirroring harmonica's
  `Gravity` / `TerminalGravity` constants: `standard()`, `terminal()`,
  `standardYDown()`, `terminalYDown()`.
- **`Vector`** — immutable 3D vector with `add` / `sub` / `scale` /
  `length` / `dot` / `cross` / `Vector::zero()`.
- **`Point`** — immutable 3D point with `add(Vector)` / `distance` /
  `Point::zero()`.
- **`Easing`** — enum with `ease(float $t): float`. Cases: `Linear`,
  `QuadraticIn/Out/InOut`, `CubicIn/Out/InOut`, `ElasticIn/Out/InOut`,
  `BounceIn/Out/InOut`, `BackIn/Out/InOut`.
- **`CubicBezier`** — `evaluate(float $t): float`. Static factories for
  all 24 CSS named easings (`ease`, `easeIn`, `easeOut`, `easeInOut`,
  `easeIn/OutSine/Quad/Cubic/Quart/Quint/Expo/Circ`, `linear`).

## Test

```sh
cd honey-bounce && composer install && vendor/bin/phpunit
```

## Snapshot tests

Numeric trajectory output is pinned via `candy-testing`'s `assertGolden` golden-file
snapshots (JSON/CSV). Any change to the physics output must be intentional — re-record the
fixture with `--update-golden` to accept a new canonical trajectory.

## Demos

### Projectile motion

![projectile](.vhs/projectile.gif)

### Spring physics

![spring](.vhs/spring.gif)

