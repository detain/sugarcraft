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


sugar-spring — damped-spring physics, projectile simulation, and easing/timing
curves for animation, for PHP 8.3+. Pure math; no terminal dependency.

```sh
composer require sugarcraft/sugar-spring
```

**I want to…**

| Goal | Reach for |
|------|-----------|
| Move a value to a target with physical feel | `Spring` |
| Know when an animation is actually done | `Spring::settled()` / `SpringChain::isComplete()` / `SpringCollection::settled()` |
| Sequence staggered animations | `SpringChain` |
| Animate many values at once | `SpringCollection` |
| Skip the math, pick a feel | `Spring::fromPreset()` |
| Use physical units (tension/friction/mass) | `SpringConfig` |
| Arcs, bouncing balls, particles | `Projectile` + `Vector` / `Point` |
| CSS-compatible timing curves | `CubicBezier` |
| Named easing curves without control points | `Easing` enum |
| Honour reduced-motion | automatic for spring-driven types (see [Reduced motion](#reduced-motion)) |

## Spring

Damped harmonic oscillator (Ryan-Juckett's algorithm, [spring physics
part 1](https://ryanjuckett.com/posts/spring-physics-part-1/) / [part
2](https://ryanjuckett.com/posts/spring-physics-part-2/)). Choose
`dampingRatio`: < 1 oscillates, = 1 is critical (no overshoot, fastest
convergence), > 1 is over-damped. The frequency and damping arguments must
produce a converging spring (and the frame time must be positive):
`angularFrequency` must be at least `1e-9` and `dampingRatio` must be greater
than `0` — a zero-damping spring oscillates forever and a negative one
diverges, a zero `deltaTime` would freeze the state and a negative one would
integrate backwards, so the
constructor refuses these instead of letting your frame loop run until the
user closes the window.

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

$frame = 0;
while (!Spring::settled($pos, $vel, $target) && $frame < 600) {
    [$pos, $vel] = $spring->update($pos, $vel, $target);
    printf("frame %2d  pos=%.2f  vel=%.2f\n", $frame, $pos, $vel);
    $frame++;
}
```

`Spring::fps(float $n)` returns `1.0 / $n` for the deltaTime — then tick the
simulation `$n` times per second. `Spring::settled($pos, $vel, $target,
$threshold)` is the stop predicate: position within the threshold (default
`Spring::SETTLING_THRESHOLD` = `1e-4`) and velocity below it. Drive loops by
`settled()` rather than by a fixed frame count — a frame budget that completes
one preset can be nowhere near enough for another: `Molasses` alone needs 439
frames (~7.3 s) at 60 fps (see
[Spring presets](#spring-presets)).

### Driving a frame loop

A spring bakes its `deltaTime` in at construction, so the simulation only
matches wall-clock when you actually call `update()` at that rate. Two
honest patterns:

- **Fixed cadence (terminals, replays).** Build one spring with
  `Spring::fps(60)` and advance it by exactly one step per frame — with
  sugar-core programs, once per tick command your `Program::subscriptions()`
  emits (`Subscriptions::withTick()` / `Cmd::tick()`):

  ```php
  use SugarCraft\Spring\Spring;

  $spring = new Spring(Spring::fps(60), 6.0, 1.0);
  $targets = [10.0, 60.0, 100.0];
  $index = 0;
  $pos = 0.0;
  $vel = 0.0;

  while ($index < count($targets)) {
      [$pos, $vel] = $spring->update($pos, $vel, $targets[$index]);
      // render $pos — exactly one update and one paint per frame
      if (Spring::settled($pos, $vel, $targets[$index])) {
          $index++;   // the NEXT frame starts the next stage
      }
  }
  ```

  Every pass of this loop is one frame: one `update()`, one render, never a
  run-to-settled burst inside a single iteration. Motion then advances per
  frame, so a loop that drifts slower than 60 Hz stretches the animation —
  which is the determinism replays record tick counts for; for wall-clock
  motion measure the real frame time instead:

  ```php
  use SugarCraft\Spring\Spring;

  // A real loop measures with hrtime(true); the scripted array keeps the
  // example deterministic. Clamping bounds a stall instead of teleporting.
  $frameTimes = [0.016, 0.033, 0.017, 0.021, 0.016];
  $targets    = [10.0, 60.0, 100.0, 100.0, 100.0];
  $pos = 0.0;
  $vel = 0.0;

  foreach ($frameTimes as $index => $deltaTime) {
      $spring = new Spring($deltaTime, 6.0, 1.0);   // coeffs are cheap (~1 µs)
      [$pos, $vel] = $spring->update($pos, $vel, $targets[$index]);
      // render $pos this frame
  }
  ```

  Rebuilding the spring per frame (above) keeps wall-clock motion honest
  under jitter; if the integer-rendered position is unchanged from last
  frame, skip the repaint — animating a value that rounds to the same cell
  is repaints without information.

### Reduced motion

Spring-driven animation honours a reduced-motion request automatically:
`Spring::update()` — and everything built from springs, `SpringChain` and
`SpringCollection` — snaps to `$target` (returning `[<target>, 0.0]`) when the
signal is on. The signal is **environment-only**: `REDUCE_MOTION` or
`PREFERS_REDUCED_MOTION`, looked up through
`SugarCraft\ColorProfile\Probe::reducedMotion()`. The lookup treats `0`,
`false`, `no` and `off` (case-insensitively) as *off*; any other non-empty
value — `1` among them — as *on*. Both variables are consulted — `REDUCE_MOTION`
first, then `PREFERS_REDUCED_MOTION` — and either reading *on* snaps, so an
*off* value in one never suppresses an *on* value in the other, and the
per-spring `reducedMotionOverride` (below) is the only opt-back-in path when
the environment is set. There is no terminal query or
accessibility-daemon channel, and the value is consulted per call: flipping
the env var mid-session changes behaviour on the next frame. When the host
sets one of the variables this supports the [WCAG 2.3.3 (Animation from
Interactions) reduced-motion
guideline](https://www.w3.org/WAI/WCAG21/Understanding/animate-from-interactions.html);
for the auto-running uses advertised above, [WCAG 2.2.2 (Pause, Stop, Hide),
Level A](https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html)
still applies to anything that moves for longer than five seconds — a math
library can support conformance, not satisfy it for your whole app.
Snap-to-target is an accessible substitute only when the end state carries
the information of the movement; where motion itself carries information,
pin the spring to keep animating (override below).

No terminal, desktop environment or accessibility tooling populates these
variables on its own — they are opt-in conventions — so host apps are
expected to expose their own reduce-motion toggle and gate it through
`Probe::reducedMotion()` as the single read point; the fleet-wide hub for
these variables is
[docs/GETTING-STARTED.md § Accessibility & reduced motion](../docs/GETTING-STARTED.md#accessibility--reduced-motion).

`Projectile`, the `Easing` curves and `CubicBezier` deliberately ignore the
signal: they are pure functions of time with no target to snap *to*. Gate
them app-side by short-circuiting normalized time to the end of the motion:

```php
use SugarCraft\ColorProfile\Probe;
use SugarCraft\Spring\Easing\Easing;

$frame = 12;                                                               // 12th frame of a 60-frame motion
$t = Probe::reducedMotion() ? 1.0 : $frame / 60.0;  // t = 1.0 is "already there"
$progress = Easing::CubicOut->ease($t);
```

Two more knobs: the constructor's fourth parameter
`?bool $reducedMotionOverride` pins the behaviour for one spring —
pass `false` to animate even when the env var is set, `true` to snap even
when it is not (`null`, the default, defers to the environment).
`Spring::fromPreset()`, `SpringConfig::spring()` and
`SpringConfig::springAt60Fps()` take the same override as their final optional
parameter.

```php
use SugarCraft\Spring\Spring;

// With REDUCE_MOTION=1 the spring skips animation entirely:
putenv('REDUCE_MOTION=1');
$spring = new Spring(deltaTime: Spring::fps(60), angularFrequency: 6.0, dampingRatio: 1.0);
[$pos, $vel] = $spring->update(0.0, 0.0, 100.0);  // returns [100.0, 0.0]

// Pin one spring to ignore the environment (e.g. a required progress cue —
// motion whose information is not in the end state):
$alwaysAnimates = new Spring(
    deltaTime: Spring::fps(60),
    angularFrequency: 6.0,
    dampingRatio: 1.0,
    reducedMotionOverride: false,
);
```

### Spring presets

`Spring::fromPreset(SpringPreset $preset, ?float $deltaTime = null, ?bool $reducedMotionOverride = null)`
constructs a spring from a named preset at 60 fps (override the frame time as
needed; `reducedMotionOverride` pins the snap-to-target behaviour — see
[Reduced motion](#reduced-motion)).
Five presets are available, tuned by hand against the react-spring-era folk
tables (they are not literal UIKit values — `UIViewPropertyAnimator` documents
duration/damping, not tension triples):

| Preset | Tension | Friction | Mass | ζ | Overshoot | Settle at 60 fps |
|------------|-----------|---------|------|-------|-----------|------------------|
| `Gentle`   | 100 | 10 | 1 | 0.50 | ~16% | 174 frames (~2.9 s) |
| `Wobbly`   | 180 | 12 | 1 | 0.45 | ~21% | 141 frames (~2.4 s) |
| `Stiff`    | 500 | 20 | 1 | 0.45 | ~21% | 94 frames (~1.6 s) |
| `Slow`     |  50 |  6 | 1 | 0.42 | ~23% | 292 frames (~4.9 s) |
| `Molasses` |  30 |  4 | 1 | 0.37 | ~29% | 439 frames (~7.3 s) |

Every preset is under-damped (ζ < 1) — they all overshoot a little; that is
the family's character. Settle frames are measured for a 0 → 100 move at the
default `1e-4` threshold; under reduced motion every preset instead snaps to
target in one tick (see [Reduced motion](#reduced-motion)). No
critical-by-construction preset ships (none was tuned); build one with
`dampingRatio: 1.0` (the flagship example above) or `new SpringConfig(...)`
with friction `2 * sqrt(tension * mass)`.

```php
use SugarCraft\Spring\{Spring, SpringPreset};

$spring = Spring::fromPreset(SpringPreset::Wobbly);
// With custom frame rate
$spring60 = Spring::fromPreset(SpringPreset::Stiff, Spring::fps(60));
$spring30 = Spring::fromPreset(SpringPreset::Gentle, Spring::fps(30));
```

### SpringConfig

`SpringConfig` accepts physical parameters (tension / friction / mass — all
finite and greater than zero) and derives the `angularFrequency` and
`dampingRatio` consumed by `Spring`. Extreme-but-finite triples are refused
when the derived ω/ζ are non-finite, non-positive, or numerically degenerate
(past ζ ≈ 2^26.5 the float sum ζ² − 1 rounds back to ζ², both over-damped
roots collapse to one number, and the spring would freeze — illegal, not
slow):

```
angularFrequency = sqrt(tension / mass)
dampingRatio     = friction / (2 * sqrt(tension * mass))
```

```php
use SugarCraft\Spring\{SpringConfig, Spring};

$config = new SpringConfig(tension: 180.0, friction: 12.0, mass: 1.0);
$spring = $config->springAt60Fps();  // or ->spring($deltaTime)
$snapped = $config->springAt60Fps(reducedMotionOverride: true);  // pin the snap, ignore the env
echo $config->angularFrequency;     // 13.416... (public readonly)
```

Both `SpringConfig::spring()` and `SpringConfig::springAt60Fps()` return
a pre-wired `Spring` instance ready to drive `update()` calls and accept the
optional `reducedMotionOverride` described above.

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

- **Under-damped** (`0 < ζ < 1`) — oscillates around the target,
  amplitudes decaying each cycle. Good picks for "bouncy" feel.
- **Critically-damped** (`ζ = 1`) — fastest convergence with no
  overshoot. The default for "snap to value" animations.
- **Over-damped** (`ζ > 1`) — converges without overshoot but slower
  than critical. Good picks for slow, weighty motion.

`ζ <= 0` is refused at construction: zero damping is a pure oscillator that
never settles, and negative damping diverges — neither belongs in a frame
loop, so the constructor throws instead of hanging it.

## Coordinate systems

Both `Vector` and `Point` are **3D** (`x`, `y`, `z`) — the constructor's
`$z` defaults to `0.0` so existing 2D call sites still compile
unchanged. Use the third dimension when a scene needs a Z
axis (parallax / depth-shaded particle systems). Both types refuse
non-finite components, and `Vector::normalize()` refuses zero and
overflowed (`INF`) lengths rather than quietly returning garbage. `length()`,
`lengthSquared()` and `distance()` can overflow to `INF` (components ~1e154
and up), so compare magnitudes through `normalize()`d operands on extreme
inputs — it refuses the overflow loudly, where a raw `length()` comparison
would silently see `INF` against `INF`.

The Y-axis convention is **Y-up** by default: `Gravity::standard()` returns `(0, -9.81, 0)` so increasing
Y means "up the screen". Terminal renderers usually grow downward —
flip to `Gravity::standardYDown()` (or its `Projectile::gravityYDown()`
alias) when you want gravity to pull toward the bottom of the grid
without manually negating every coordinate.

`Projectile::update()` returns a **new `Projectile`** instance each
call — `update()` never mutates the receiver. The hazard is discarding
the result: read state from whatever you last assigned (`$p = $p->update();`),
not from the pre-update `$p`. The `position` / `velocity` / `acceleration`
properties and their identically-named accessor methods expose the same
`Vector`s; pick either spelling, just be consistent. Each step builds fresh
`Point`/`Vector` instances; a state component that overflows to `INF`
mid-simulation is refused by their constructors (`InvalidArgumentException`,
naming the component), so bound your accelerations and velocities. A
`deltaTime` of `0` is accepted and freezes the integrator — every Euler term
is multiplied by it, so `update()` keeps handing back an identical state.

## When is it done? (the completion contract)

A spring has finished when `Spring::settled($pos, $vel, $target, $threshold)`
says so — *position near the target and velocity near zero*, both required:
a position-only check lies exactly on under-damped presets, which cross the
target at full speed and come back. Chain and collection expose the same
contract at their own level: `SpringChain::isComplete()` and
`SpringCollection::settled()`. Loops in this README stop on those predicates,
not on frame counts; the `$frame < 600` guards shown are escape hatches for
paths you can't otherwise bound, and presets are documented so you can size
them (Molasses alone needs 439).

For terminal grids the default `1e-4` threshold is finer than one cell:
a spring has usually stopped *changing the frame* long before it has stopped
*settling*. Pass a cell-sized threshold (`0.5`) to `settled()` when the
consumer renders at cell resolution, and skip the repaint when the rounded
cell value is unchanged from last frame. There is no value→cell helper
tree-wide yet; `0.5` on a unit-per-cell mapping is the hand-carried rule of
thumb.

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
    $chain = $chain->tick();
    $positions = $chain->currentPositions();   // this frame's positions
    // render $positions
}
```

`tick()` returns the **advanced chain** — reassign it, or the run replays the
same frame forever. Positions are read back with `currentPositions()`, which
always reflects the chain you call it on (the previous three-tuple return
carried a copy that lagged one frame behind; that shape is gone). Each `tick()`
advances only the active stage. When that stage reaches its target — position
within `SpringChain::SETTLING_THRESHOLD` (`5e-4`) **and** velocity within it —
the chain activates the next stage. The threshold is deliberately looser than
a lone spring's `Spring::SETTLING_THRESHOLD` (`1e-4`) so stages hand off while
still visually moving; a settled stage hands off up to `5e-4` short of its
target by that design. `isComplete()` returns `true` when all stages have
settled. Under reduced motion every stage still snaps, but each hand-off costs
its own tick: with n unsettled stages the final positions are in place after
2n−1 ticks and `isComplete()` turns true on tick 2n. Note `withStage()` restarts the new chain at stage 0:
build the full stage list before the first `tick()`. The constructor accepts
`[Spring, float, float, float]` stages and refuses malformed shapes and an
out-of-range active index at the boundary.

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

$frame = 0;
while (!$group->settled() && $frame < 600) {   // settled(): every member's position AND velocity within Spring::SETTLING_THRESHOLD
    $group = $group->tick();                   // tick(): self — NOT a position map
    $frame++;
}

echo sprintf("menu=%.2f panel=%.2f\n", $group->get('menu'), $group->get('panel'));
```

Read with `get($id)` / `all()` / `target($id)` / `velocity($id)` /
`allVelocities()` / `has($id)`; ask "still animating?" with
`settled($threshold = Spring::SETTLING_THRESHOLD)` (it needs velocities, which
is why they are exposed). Unknown ids are refused by `get()` / `target()` /
`velocity()` / `withTarget()` with an `OutOfBoundsException` naming the id,
and the constructor refuses misaligned or orphaned id planes. The earlier
in-place `add()` / `remove()` / `setTarget()` mutators are **deprecated**
shims that emit a visible `E_USER_DEPRECATED` and will be dropped; migrate to
the `with*()` forms above (the shims mutate the receiver and return `void`,
so `$c = $c->add(...)` assigns `null`).

## Easing

`SugarCraft\Spring\Easing\Easing` provides named easing curves via their
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

Honesty about the range: `ease()` assumes `t` in `[0, 1]` but does not
enforce it (out-of-domain `t` extrapolates), and several curves intentionally
leave `[0, 1]` in the *output* — `Elastic*` and `Back*` overshoot,
`Bounce*` stays inside. One naming caveat: the shipped `BackIn`/`BackOut`/`BackInOut`
are the sin-modulated family (`t³ ∓ sin` shaping), not Penner's classic
`c1 = 1.70158` polynomial — the overshoot is real, but the dip shape differs
from the canonical Penner tables. Six of the sixteen cases exit `[0, 1]`
somewhere, by
design. If you map an eased value to cells (`(int) round($eased * $cols)`),
clamp before indexing — a rubber-band curve is one frame away from a negative
index. Non-finite `t` flows through `ease()` unguarded: `NAN` returns `NAN`
from every case, `INF` survives the polynomial curves (answering `+INF` or
`-INF` depending on the term signs) but flips to `NAN` inside the trig of
every `Elastic*` and `Back*` case — clamp upstream. The solver-backed
`CubicBezier::evaluate()` takes the opposite policy: it refuses a non-finite
`t` outright, because a Newton iteration has nothing honest to answer with.

Curves implement `SugarCraft\Spring\Easing\EasingFunction`, the extension
seam: write your own `ease()` and pass it anywhere an easing function is
consumed.

### CubicBezier

`CubicBezier` implements the CSS `cubic-bezier()` easing algorithm
(Newton-Raphson root-finding with a bounded binary-search fallback). Construct
via static factory methods: 26 named presets in total — the five CSS
`transition-timing-function` keywords (`linear`, `ease`, `ease-in`,
`ease-out`, `ease-in-out`, each expressible as `cubic-bezier()`, which
generalizes them) plus 21 Penner-style curves (Sine/Quad/Cubic/Quart/Quint/Expo/Circ in
In/Out/InOut) approximated with the
[easings.net](https://easings.net/) table expressed as bezier control points.
The Penner rows are named for the easing family, not for a CSS standard list:

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

`CubicBezier::evaluate(float $t): float` takes a finite `t` (a non-finite
value throws; a finite out-of-range `t` is neither refused nor clamped, but
past roughly 1e2 the solver saturates to an endpoint — `evaluate(1e3)` is
exactly `1.0` on `ease()`) and returns the
eased progress. The x-mapping is solved to `[0, 1]` monotonicity (control
x-values are range-checked); the y-output lands exactly on `(0, 1)` for all 26
named presets, and no preset overshoots the interval — every named curve's
control y-values sit inside `[0, 1]`, which keeps the whole Bézier inside it.
Overshoot *between* endpoints arises only from user-supplied elastic- or
back-flavoured control points — same honesty rule as `Easing`.

## Public API

- **`Spring`** — `__construct($deltaTime, $angularFrequency, $dampingRatio,
  ?bool $reducedMotionOverride = null)` (the classic `$dt`/`ω`/`ζ` triple;
  refuses non-finite inputs, `dt <= 0`, `ω < 1e-9`, `ζ <= 0` and coefficient
  overflow) / `update($pos, $vel, $target)` / `fps(float $n)` /
  `fromPreset(SpringPreset, ?float $deltaTime = null, ?bool $reducedMotionOverride = null)` /
  static `settled($pos, $vel, $target, $threshold = SETTLING_THRESHOLD)`.
  `const SETTLING_THRESHOLD = 1e-4`. `update()` rejects non-finite state and
  short-circuits to `[$target, 0.0]` when the env-driven reduced-motion
  lookup — or the per-instance override — resolves to `true`.
- **`SpringChain`** — `__construct($stages, int $activeIndex = 0)` /
  `new($stages)` / `withStage(Spring, $pos, $vel, $target)` (build-time only;
  returns a chain restarted at stage 0) / `tick(): self` (reassign or the run
  never advances) / `currentPositions(): list<float>` / `isComplete(): bool` /
  `activeStage(): int` / `const SETTLING_THRESHOLD = 5e-4`. Under reduced
  motion every stage snaps, but hand-offs still cost a tick (n stages carry
  their final positions after 2n−1 ticks and complete on tick 2n).
- **`SpringCollection`** — `__construct($springs, $positions, $velocities, $targets)` /
  `withSpring($id, Spring, $pos, $vel, $target)` / `without($id)` /
  `withTarget($id, $target)` (unknown ids refused, named in the error) /
  `tick(): self` (new collection; positions map via `all()`) /
  `get($id): float` / `has($id): bool` / `all(): array<string,float>` /
  `target($id): float` / `velocity($id): float` / `allVelocities()` /
  `settled($threshold = Spring::SETTLING_THRESHOLD): bool`. The in-place
  `add()` / `remove()` / `setTarget()` mutators are deprecated
  `E_USER_DEPRECATED` shims slated for removal — use the `with*()` forms.
- **`SpringPreset`** — `Gentle` / `Wobbly` / `Stiff` / `Slow` / `Molasses`.
  `resolve()` returns a `SpringConfig`.
- **`SpringConfig`** — `__construct(tension, friction, mass)` (all finite and
  positive; extreme-but-finite triples are refused when the derived ω/ζ are
  non-finite, non-positive, or numerically degenerate) with public readonly
  `$angularFrequency` / `$dampingRatio` /
  `spring(float $deltaTime = 1/60, ?bool $reducedMotionOverride = null)` /
  `springAt60Fps(?bool $reducedMotionOverride = null)`.
- **`Projectile`** — `Projectile::new(...)` / `update()` / `position()` /
  `velocity()` / `acceleration()` (identical `->position` etc. properties
  exist) / `gravity()` / `terminalGravity()` / `gravityYDown()` /
  `terminalGravityYDown()` / `GRAVITY` / `TERMINAL_GRAVITY`.
- **`Gravity`** — package-level static accessors: `standard()`, `terminal()`,
  `standardYDown()`, `terminalYDown()`.
- **`Vector`** — immutable 3D vector (finite components enforced) with `add` /
  `sub` / `scale` / `length` / `lengthSquared` / `dot` / `cross` / `lerp` /
  `normalize` / `jsonSerialize` / `__toString` / `Vector::zero()`.
- **`Point`** — immutable 3D point (finite components enforced) with
  `add(Vector)` / `distance` / `__toString` / `jsonSerialize` /
  `Point::zero()`.
- **`Easing`** — enum implementing `EasingFunction` (the extension seam) with
  `ease(float $t): float`. Cases: `Linear`, `QuadraticIn/Out/InOut`,
  `CubicIn/Out/InOut`, `ElasticIn/Out/InOut`, `BounceIn/Out/InOut`,
  `BackIn/Out/InOut`.
- **`CubicBezier`** — `evaluate(float $t): float`. 26 static factories: the
  five CSS timing keywords (`linear`, `ease`, `easeIn`, `easeOut`,
  `easeInOut`) and 21 Penner-style curves (`easeIn/Out/InOut` × `Sine`, `Quad`,
  `Cubic`, `Quart`, `Quint`, `Expo`, `Circ`) expressed as bezier control
  points.

Exception messages are translated through `SugarCraft\Spring\Lang` (the
`sugarcraft` i18n registry, `lang/<code>.php` files, 16 locales). Set the
locale with the shared core machinery — `SugarCraft\Core\I18n\T::setLocale()`:

```php
use SugarCraft\Core\I18n\T;

T::setLocale('de');   // every Lang::t() lookup, this lib's messages included,
                      // now resolves German (exact → base → en fallback).
```

## Test

```sh
cd sugar-spring && composer install && vendor/bin/phpunit
```

## Snapshot tests

Numeric trajectory output is pinned as golden files (JSON) in
`tests/fixtures/`, using sugar-testing's `GoldenFile::save()`/`load()`
with plain `assertEquals` equality in `tests/GoldenRenderTest.php` —
sugar-testing's `assertGolden*` helper family is ANSI/cell-grid specific
and not used here. The golden tests clear `REDUCE_MOTION` /
`PREFERS_REDUCED_MOTION` and pin `serialize_precision` in `setUp()`, so host
accessibility settings and ini defaults cannot change the comparison. Any
change to the physics output must be intentional — re-record the fixtures with:

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

## Accessor deprecation (2026-10-24)

One accessor was renamed to the house bare form: `SpringCollection::getTarget()`
→ `target($id)`. The legacy spelling stays a byte-identical `@deprecated` BC
forwarder until sugar-spring v2.0.0; migrate call sites.

| Namespace | Class | Deprecated | Use instead |
|-----------|-------|------------|-------------|
| `Spring` | `SpringCollection` | `getTarget()` | `target()` |

`tests/AccessorDeprecationTest.php` pins the table above; the dated internal
change record lives in `CALIBER_LEARNINGS.md`.

## Credits & inspiration

The spring core mirrors [charmbracelet/harmonica](https://github.com/charmbracelet/harmonica)
after [Ryan Juckett's spring physics
articles](https://ryanjuckett.com/posts/spring-physics-part-1/); preset values
descend from the react-spring-era folk tables and are tuned by hand; the
bezier control points follow [easings.net](https://easings.net/). The
reduced-motion lookup rides sugar-colorprofile's
[Probe](https://github.com/detain/sugarcraft/tree/master/sugar-colorprofile).
Originally inspired by the Go [Charm](https://github.com/charmbracelet)
ecosystem; SugarCraft is developed as a native PHP project.
