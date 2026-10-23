<img src=".assets/icon.png" alt="sugar-spring" width="160" align="right">

# SugarSpring

> Formerly published as `sugarcraft/honey-bounce` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Bounce\` → `SugarCraft\Spring\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-spring)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-spring)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-spring?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-spring)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


sugar-spring — damped-spring physics and Newtonian projectile simulation for animation, for PHP 8.3+. Pure math; no terminal dependency.

```sh
composer require sugarcraft/sugar-spring
```

## Spring

Damped harmonic oscillator (Ryan-Juckett's algorithm). Choose `dampingRatio`:
< 1 oscillates, = 1 is critical (no overshoot, fastest convergence), > 1 is
over-damped.

```php
use SugarCraft\Spring\Spring;

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

`Spring::fps(float $n)` returns `1.0 / $n` for the deltaTime — pair with the
same `$n` per-second simulation cadence.

### Driving a frame loop

A spring bakes its `deltaTime` in at construction, so the simulation only
matches wall-clock when you actually call `update()` at that rate. Two
honest patterns:

- **Fixed cadence (terminals, replays).** Build one spring with
  `Spring::fps(60)` and tick it once per 60 Hz frame — with sugar-core
  programs, once per `Tick` your `Program::subscriptions()` emits at
  `1/60` of a second:

  ```php
  use SugarCraft\Spring\Spring;

  $spring = new Spring(Spring::fps(60), 6.0, 1.0);
  $pos = 0.0;
  $vel = 0.0;

  foreach ([10.0, 60.0, 60.0] as $target) {   // one frame per tick
      [$pos, $vel] = $spring->update($pos, $vel, $target);
      // render $pos this frame
  }
  ```

  If the loop drifts slower than 60 Hz the motion slows with it. That is a
  feature for deterministic replays; for wall-clock motion measure the
  real frame time instead:

  ```php
  use SugarCraft\Spring\Spring;

  $previous = hrtime(true);
  $pos = 0.0;
  $vel = 0.0;
  $target = 100.0;

  foreach ([10.0, 60.0, 100.0] as $target) {   // stand-in for a real frame loop
      $now = hrtime(true);
      $deltaTime = min(($now - $previous) / 1e9, 0.1);  // clamp long stalls
      $previous = $now;

      $spring = new Spring($deltaTime, 6.0, 1.0);       // coeffs are cheap
      [$pos, $vel] = $spring->update($pos, $vel, $target);
      // render $pos this frame
  }
  ```

### Reduced motion

`Spring::update()` snaps to `$target` instantly — returning
`[<target>, 0.0]` — when reduced motion is requested. The signal is
**environment-only**: `REDUCE_MOTION` or `PREFERS_REDUCED_MOTION` set to a
truthy value (the lookup is
`SugarCraft\ColorProfile\Probe::reducedMotion()`, which reads exactly
those two env vars; there is no terminal query or accessibility-daemon
channel). This satisfies the [WCAG 2.1 reduced-motion
guideline](https://www.w3.org/WAI/WCAG21/Understanding/animate-from-interactions.html)
when the host sets one of the variables.

Two more knobs: the constructor's fourth parameter
`?bool $reducedMotionOverride` pins the behaviour for one spring —
pass `false` to animate even when the env var is set, `true` to snap even
when it is not (`null`, the default, defers to the environment). And the
scope is `Spring::update()` only: `Projectile::update()`, the `Easing`
curves and `SpringChain` advance regardless — gate those yourself if your
scene uses them.

```php
use SugarCraft\Spring\Spring;

// With REDUCE_MOTION=1 the spring skips animation entirely:
putenv('REDUCE_MOTION=1');
$spring = new Spring(deltaTime: Spring::fps(60), angularFrequency: 6.0, dampingRatio: 1.0);
[$pos, $vel] = $spring->update(0.0, 0.0, 100.0);  // returns [100.0, 0.0]

// Pin one spring to ignore the environment (e.g. a required progress cue):
$alwaysAnimates = new Spring(
    deltaTime: Spring::fps(60),
    angularFrequency: 6.0,
    dampingRatio: 1.0,
    reducedMotionOverride: false,
);
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
use SugarCraft\Spring\{Spring, SpringPreset};

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
use SugarCraft\Spring\{SpringConfig, Spring};

$config = new SpringConfig(tension: 180.0, friction: 12.0, mass: 1.0);
$spring = $config->springAt60Fps();  // or ->spring($deltaTime)
```

Both `SpringConfig::spring()` and `SpringConfig::springAt60Fps()` return
a pre-wired `Spring` instance ready to drive `update()` calls.

## Projectile

Newtonian-physics simulator for arcs / bouncing balls / particle effects.

```php
use SugarCraft\Spring\{Point, Projectile, Spring, Vector};

$p = Projectile::new(
    deltaTime:    Spring::fps(60),
    position:     Point::zero(),
    velocity:     new Vector(5.0, 10.0),          // up-and-to-the-right (Y-up)
    acceleration: Projectile::gravity(),          // (0, -9.81) — pulls down
);
for ($i = 0; $i < 60; $i++) {
    $p = $p->update();
    echo sprintf("t=%2d  pos=(%.1f, %.1f)\n", $i, $p->position->x, $p->position->y);
}
```

Gravity constants: `Projectile::GRAVITY` (9.81) and
`Projectile::TERMINAL_GRAVITY` (53.0). Helper factories
`Projectile::gravity()` and `Projectile::terminalGravity()` return Y-up
`Vector` instances (`(0, -9.81, 0)` / `(0, -53, 0)`) ready to drop into
the constructor; `gravityYDown()` / `terminalGravityYDown()` keep the
legacy Y-down sign reachable.

`SugarCraft\Spring\Gravity` exposes the same vectors as static
accessors at the package level — `Gravity::standard()`,
`Gravity::terminal()`, `Gravity::standardYDown()`,
`Gravity::terminalYDown()` — so gravity presets read
uniformly across call sites.

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
unchanged. Use the third dimension when a scene needs a Z
axis (parallax / depth-shaded particle systems).

The Y-axis convention is **Y-up** by default: `Gravity::standard()` returns `(0, -9.81, 0)` so increasing
Y means "up the screen". Terminal renderers usually grow downward —
flip to `Gravity::standardYDown()` (or its `Projectile::gravityYDown()`
alias) when you want gravity to pull toward the bottom of the grid
without manually negating every coordinate.

`Projectile::update()` returns a **new `Projectile`** instance each
call (immutable-with-pattern) — `update()` never mutates the
receiver. Read the new
position from `result->position` rather than `$p->position()`.

## SpringChain

Sequence multiple springs so that one spring's settle triggers the next.
Useful for staggered animations where each stage must complete before
the next begins.

```php
use SugarCraft\Spring\Spring;
use SugarCraft\Spring\SpringChain;
use SugarCraft\Spring\SpringPreset;

$chain = (new SpringChain([]))
    ->withStage(Spring::fromPreset(SpringPreset::Gentle), 0.0, 0.0, 50.0)
    ->withStage(Spring::fromPreset(SpringPreset::Wobbly), 0.0, 0.0, 100.0)
    ->withStage(Spring::fromPreset(SpringPreset::Stiff),  0.0, 0.0, 75.0);

while (!$chain->isComplete()) {
    [$positions, $complete, $chain] = $chain->tick();
    // $positions reflects settled stages + the currently animating stage.
    // MUST reassign $chain: tick() is copy-on-write and returns the advanced
    // chain as its third element — dropping it replays the same frame forever.
}
```

Each `tick()` call advances only the active stage and returns
`[positions, chainComplete, newChain]`. When that stage reaches its target
— position within `5e-4` of the target **and** velocity within `5e-4` of
zero (`SpringChain::SETTLING_THRESHOLD`, looser than a lone spring's
`Spring::SETTLING_THRESHOLD` of `1e-4` so stages hand off while still
visually moving) — the chain activates the next stage. `isComplete()`
returns `true` when all stages have settled. Note `withStage()` restarts
the new chain at stage 0: build the full stage list before the first
`tick()`.

## SpringCollection

Keep many named springs animating at once. Like every other type here,
the collection is immutable: `withSpring()`, `without()`, `withTarget()`
and `tick()` each return a **new** collection, so the carry-the-result
assignment is mandatory:

```php
use SugarCraft\Spring\Spring;
use SugarCraft\Spring\SpringCollection;

$group = (new SpringCollection())
    ->withSpring('menu',  Spring::fromPreset(\SugarCraft\Spring\SpringPreset::Stiff),    0.0, 0.0, 40.0)
    ->withSpring('panel', Spring::fromPreset(\SugarCraft\Spring\SpringPreset::Molasses), 0.0, 0.0, 90.0);

for ($frame = 0; $frame < 120; $frame++) {
    $group = $group->tick();               // tick(): self — NOT a position map
    $thisFrame = $group->all();            // array<string, float> lives here
}

echo sprintf("menu=%.2f panel=%.2f\n", $group->get('menu'), $group->get('panel'));
```

Read with `get($id)` / `all()` / `getTarget($id)` / `has($id)`. The
earlier in-place `add()` / `remove()` / `setTarget()` mutators are
**deprecated** shims that emit `E_USER_DEPRECATED` and will be dropped;
migrate to the `with*()` forms above (the shims mutate the receiver and
return `void`, so `$c = $c->add(...)` assigns `null`).

## Easing

`SugarCraft\Spring\Easing\Easing` provides named easing curves via its
`ease(float $t): float` method — apply to any normalized time value in
`[0.0, 1.0]`:

```php
use SugarCraft\Spring\Easing\Easing;

$ease = Easing::ElasticOut;
for ($f = 0; $f <= 60; $f++) {
    $t = $f / 60.0;
    echo sprintf("frame %2d  t=%.3f  eased=%.3f\n", $f, $t, $ease->ease($t));
}
```

### CubicBezier

`CubicBezier` implements the CSS `cubic-bezier()` easing algorithm
(Newton-Raphson root-finding with binary-search fallback). Construct via
static factory methods: 26 named presets in total — the five
CSS-spec-defined timing functions (`ease`, `easeIn`, `easeOut`,
`easeInOut`, `linear`) plus 21 Penner-style curves approximated with the
easings.net table. Note that CSS itself standardises only the four
keywords and arbitrary `cubic-bezier()`; the Sine/Quad/Cubic/Quart/Quint/
Expo/Circ rows are the Penner easing family expressed as bezier control
points, not a CSS standard list:

```php
use SugarCraft\Spring\Easing\CubicBezier;

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

- **`Spring`** — `__construct($dt, $ω, $ζ, ?bool $reducedMotionOverride = null)`
  / `update($pos, $vel, $target)` / `fps(float)` /
  `fromPreset(SpringPreset, ?float)` / static `settled($pos, $vel, $target,
  $threshold = SETTLING_THRESHOLD)`. `update()` short-circuits to
  `[$target, 0.0]` when the env-driven reduced-motion lookup — or the
  per-instance override — resolves to `true`.
- **`SpringChain`** — `__construct($stages)` / `new($stages)` /
  `withStage(Spring, $pos, $vel, $target)` (build-time only; returns a
  chain restarted at stage 0) /
  `tick(): array{0: list<float>, 1: bool, 2: self}` — carry the returned
  chain (`$chain = ...` via list destructuring) or the run never advances /
  `currentPositions(): list<float>` / `isComplete(): bool` /
  `activeStage(): int`.
- **`SpringCollection`** — `__construct($springs, $positions, $velocities, $targets)` /
  `withSpring($id, Spring, $pos, $vel, $target)` / `without($id)` /
  `withTarget($id, $target)` / `tick(): self` (new collection; positions
  map via `all()`) / `get($id): float` / `has($id): bool` /
  `all(): array<string,float>` / `getTarget($id): float`. The in-place
  `add()` / `remove()` / `setTarget()` mutators are deprecated
  `E_USER_DEPRECATED` shims slated for removal — use the `with*()` forms.
- **`SpringPreset`** — `Gentle` / `Wobbly` / `Stiff` / `Slow` / `Molasses`.
  `resolve()` returns a `SpringConfig`.
- **`SpringConfig`** — `__construct(tension, friction, mass)` /
  `spring(float $dt)` / `springAt60Fps()`.
- **`Projectile`** — `Projectile::new(...)` / `update()` / `position()` /
  `velocity()` / `acceleration()` / `gravity()` / `terminalGravity()` /
  `gravityYDown()` / `terminalGravityYDown()` / `GRAVITY` /
  `TERMINAL_GRAVITY`.
- **`Gravity`** — package-level static accessors: `standard()`, `terminal()`,
  `standardYDown()`, `terminalYDown()`.
- **`Vector`** — immutable 3D vector with `add` / `sub` / `scale` /
  `length` / `dot` / `cross` / `Vector::zero()`.
- **`Point`** — immutable 3D point with `add(Vector)` / `distance` /
  `Point::zero()`.
- **`Easing`** — enum with `ease(float $t): float`. Cases: `Linear`,
  `QuadraticIn/Out/InOut`, `CubicIn/Out/InOut`, `ElasticIn/Out/InOut`,
  `BounceIn/Out/InOut`, `BackIn/Out/InOut`.
- **`CubicBezier`** — `evaluate(float $t): float`. 26 static factories: the
  five CSS-spec timing functions (`ease`, `easeIn`, `easeOut`, `easeInOut`,
  `linear`) and 21 Penner-style curves (`easeIn/Out/InOut` × `Sine`, `Quad`,
  `Cubic`, `Quart`, `Quint`, `Expo`, `Circ`) expressed as bezier control
  points.

Exception messages are translated through `SugarCraft\Spring\Lang`
(`sugarcraft` i18n registry, `lang/<code>.php` files); set the locale with
the shared core `Lang` machinery as elsewhere in the stack.

## Test

```sh
cd sugar-spring && composer install && vendor/bin/phpunit
```

## Snapshot tests

Numeric trajectory output is pinned as golden files (JSON) in
`tests/fixtures/`, using sugar-testing's `GoldenFile::save()`/`load()`
with plain `assertEquals` equality in `tests/GoldenRenderTest.php` —
sugar-testing's `assertGolden*` helper family is ANSI/cell-grid specific
and not used here. Any change to the physics output must be intentional —
re-record the fixtures with:

```sh
UPDATE_GOLDENS=1 vendor/bin/phpunit
```

(there is no `--update-golden` phpunit flag).

## Demos

### Projectile motion

![projectile](.vhs/projectile.gif)

### Spring physics

![spring](.vhs/spring.gif)

### Particle field

![particle](.vhs/particle.gif)

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
