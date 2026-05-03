<img src=".assets/icon.png" alt="honey-bounce" width="160" align="right">

# HoneyBounce

PHP port of [charmbracelet/harmonica](https://github.com/charmbracelet/harmonica) —
damped-spring physics + Newtonian projectile simulation for animation.
Pure math; no terminal dependency.

```sh
composer require candycore/honey-bounce
```

## Spring

Damped harmonic oscillator (Ryan-Juckett's algorithm). Choose `dampingRatio`:
< 1 oscillates, = 1 is critical (no overshoot, fastest convergence), > 1 is
over-damped.

```php
use CandyCore\Bounce\Spring;

$spring = new Spring(
    deltaTime:        Spring::fps(60),  // 1/60 of a second
    angularFrequency: 6.0,              // rad/sec
    dampingRatio:     1.0,              // critical
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

## Projectile

Newtonian-physics simulator for arcs / bouncing balls / particle effects.

```php
use CandyCore\Bounce\{Point, Projectile, Vector};

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

## Public API

- **`Spring`** — `__construct($dt, $ω, $ζ)` / `update($pos, $vel, $target)`
  / `Spring::fps(int)`.
- **`Projectile`** — `Projectile::new(...)` / `update()` / `position()` /
  `velocity()` / `acceleration()` / `gravity()` / `terminalGravity()` /
  `GRAVITY` / `TERMINAL_GRAVITY`.
- **`Vector`** — immutable 2D vector with `add` / `sub` / `scale` /
  `length` / `Vector::zero()`.
- **`Point`** — immutable 2D point with `add(Vector)` / `Point::zero()`.

## Test

```sh
cd honey-bounce && composer install && vendor/bin/phpunit
```

## Demos

### Projectile motion

![projectile](.vhs/projectile.gif)

### Spring physics

![spring](.vhs/spring.gif)

