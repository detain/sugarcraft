# HoneyBounce — End-User Guide

Pure-math animation library: damped harmonic oscillators (spring physics) and Newtonian projectile simulation. No terminal dependency.

**Port of:** [charmbracelet/harmonica](https://github.com/charmbracelet/harmonica)

## Install

```sh
composer require sugarcraft/honey-bounce
```

**Requires:** PHP 8.1+

## Quickstart

### Reduced motion

When `REDUCE_MOTION=1` is set in the environment, `Spring::update()`
snaps to its target instantly and returns `[<target>, 0.0]`, satisfying
the WCAG 2.1 reduced-motion guideline via `SugarCraft\Palette\Probe::reducedMotion()`:

```php
putenv('REDUCE_MOTION=1');
[$pos, $vel] = $spring->update(0.0, 0.0, 100.0);  // returns [100.0, 0.0]
```

### Spring preset

```php
use SugarCraft\Bounce\{Spring, SpringPreset};

$spring = Spring::fromPreset(SpringPreset::Wobbly);
$pos = 0.0;
$vel = 0.0;
$target = 100.0;

for ($f = 0; $f < 60; $f++) {
    [$pos, $vel] = $spring->update($pos, $vel, $target);
}
```

### Manual spring parameters

```php
use SugarCraft\Bounce\Spring;

$spring = new Spring(
    deltaTime:        1.0 / 60.0,
    angularFrequency: 6.0,   // rad/sec
    dampingRatio:     1.0,   // critical: fastest converge, no overshoot
);
```

### Projectile motion

```php
use SugarCraft\Bounce\{Projectile, Point, Vector};

$ball = Projectile::new(
    deltaTime:    1.0 / 60.0,
    position:     Point::zero(),
    velocity:     new Vector(5.0, -10.0),
    acceleration: Projectile::gravity(),
);
for ($i = 0; $i < 60; $i++) {
    $ball = $ball->update();
    // Read $ball->position->x and $ball->position->y
}
```

### CubicBezier easing

```php
use SugarCraft\Bounce\Easing\CubicBezier;

$ease = CubicBezier::easeInOutCubic();
for ($f = 0; $f <= 60; $f++) {
    $t = $f / 60.0;
    $eased = $ease->evaluate($t);  // maps [0,1] → [0,1]
}
```

### SpringChain (staggered animation)

Chain multiple springs so each one begins only when the previous settles:

```php
use SugarCraft\Bounce\{SpringChain, Spring, SpringPreset};

$chain = (new SpringChain([]))
    ->withStage(Spring::fromPreset(SpringPreset::Gentle),  0.0, 0.0, 50.0)
    ->withStage(Spring::fromPreset(SpringPreset::Wobbly), 0.0, 0.0, 100.0)
    ->withStage(Spring::fromPreset(SpringPreset::Stiff),  0.0, 0.0, 75.0);

while (!$chain->isComplete()) {
    [$positions, $complete] = $chain->tick();
}
```

## Use cases

### 1. Spring-based UI animation

Drive scroll position, progress bars, or expand/collapse animations with physical parameters:

```php
use SugarCraft\Bounce\{Spring, SpringPreset};

// Snap-to-value with gentle overshoot
$spring = Spring::fromPreset(SpringPreset::Gentle);
[$pos, $vel] = $spring->update($currentPos, $currentVel, $targetPos);
```

### 2. Named preset animation

Use `SpringPreset` to match UIKit/spring animation conventions:

```php
use SugarCraft\Bounce\{Spring, SpringPreset};

// Stiff = snappy, Molasses = barely-there
$spring = Spring::fromPreset(SpringPreset::Stiff, 1.0 / 60.0);
```

### 3. Physical spring config

Fine-tune tension/friction/mass for custom feel:

```php
use SugarCraft\Bounce\SpringConfig;

$config = new SpringConfig(tension: 200.0, friction: 14.0, mass: 1.0);
$spring = $config->springAt60Fps();
```

### 4. Projectile arcs

Simulate balls, bouncing particles, or arc trajectories:

```php
use SugarCraft\Bounce\{Projectile, Point, Vector};

$p = Projectile::new(
    deltaTime:    1.0 / 60.0,
    position:     new Point(0.0, 0.0),
    velocity:     new Vector(8.0, -15.0),
    acceleration: Projectile::gravity(),
);
for ($i = 0; $i < 120; $i++) {
    $p = $p->update();
}
```

### 5. CSS easing curves

Apply any of 24 CSS-standard cubic-bezier easings to arbitrary timelines:

```php
use SugarCraft\Bounce\Easing\CubicBezier;

$ease = CubicBezier::easeOutExpo();
$values = array_map(
    fn($f) => $ease->evaluate($f / 60.0),
    range(0, 60)
);
```

## API reference

### Spring

| Method | Description |
|--------|-------------|
| `Spring::fromPreset(SpringPreset, ?float $dt)` | Factory from named preset at 60 fps |
| `Spring::fps(int $n)` | Frame time in seconds (1/n) |
| `new Spring(float $dt, float $ω, float $ζ)` | Raw constructor |
| `update(float $pos, float $vel, float $target): array{0:float,1:float}` | Advance one frame |

### SpringPreset

`Gentle` — soft, slow overshoot  
`Wobbly` — bouncy oscillation  
`Stiff` — snappy snap  
`Slow` — heavy, lazy settle  
`Molasses` — barely moves  

Each preset's `resolve()` returns a `SpringConfig`.

### SpringConfig

| Method | Description |
|--------|-------------|
| `new SpringConfig(float $tension, float $friction, float $mass)` | Physical parameters |
| `spring(float $dt): Spring` | Build a Spring at given frame time |
| `springAt60Fps(): Spring` | Shortcut for 60 fps |

### Projectile

| Method | Description |
|--------|-------------|
| `new Projectile(float $dt, Point $position, Vector $velocity, Vector $acceleration)` | Constructor |
| `update(): Projectile` | Advance one frame (immutable) |
| `position: Point` | Current position |
| `velocity: Vector` | Current velocity |
| `acceleration: Vector` | Current acceleration |
| `gravity(): Vector` | Standard gravity (0, −9.81, 0) |
| `terminalGravity(): Vector` | Terminal velocity gravity (0, −53.0, 0) |

### Easing

`Easing` enum cases: `Linear`, `QuadraticIn`, `QuadraticOut`, `QuadraticInOut`, `CubicIn`, `CubicOut`, `CubicInOut`, `ElasticIn`, `ElasticOut`, `ElasticInOut`, `BounceIn`, `BounceOut`, `BounceInOut`, `BackIn`, `BackOut`, `BackInOut`.

`ease(float $t): float` — apply easing to normalized time in [0, 1].

### CubicBezier

| Method | Description |
|--------|-------------|
| `evaluate(float $t): float` | Map [0,1] → [0,1] via Newton-Raphson |
| CSS factories | `ease()`, `easeIn()`, `easeOut()`, `easeInOut()`, `linear()`, `easeIn/OutSine()`, `easeIn/OutQuad()`, `easeIn/OutCubic()`, `easeIn/OutQuart()`, `easeIn/OutQuint()`, `easeIn/OutExpo()`, `easeIn/OutCirc()` |

## Recipes

### Coordinate systems

- **Y-up** (default): `Projectile::gravity()` returns `(0, −9.81, 0)`. Use for physics-first contexts.
- **Y-down** (terminal renderer): `Projectile::gravityYDown()` returns `(0, +9.81, 0)`. Use for TUI animations where increasing Y moves down the screen.

```php
// Switch gravity direction
$p = Projectile::new(
    deltaTime:    1.0 / 60.0,
    position:     Point::zero(),
    velocity:     new Vector(5.0, -10.0),
    acceleration: Projectile::gravityYDown(),  // Y-down
);
```

### Damping ratio regimes

- **Under-damped** (`ζ < 1`): Oscillates around target — good for "bouncy" feel.
- **Critically-damped** (`ζ = 1`): Fastest convergence with no overshoot — good for "snap to position".
- **Over-damped** (`ζ > 1`): No overshoot, slower than critical — good for heavy, weighty motion.

### Custom CubicBezier

```php
use SugarCraft\Bounce\Easing\CubicBezier;

// Fully custom control points
$custom = new CubicBezier(0.68, -0.55, 0.27, 1.55);
for ($f = 0; $f <= 60; $f++) {
    $t = $f / 60.0;
    echo $custom->evaluate($t) . "\n";
}
```
