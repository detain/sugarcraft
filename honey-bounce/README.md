# HoneyBounce

PHP port of [charmbracelet/harmonica](https://github.com/charmbracelet/harmonica) —
damped-harmonic-oscillator spring physics for animation. Pure math; no
terminal dependency.

```php
use CandyCore\Bounce\Spring;

$spring = new Spring(Spring::fps(60), 6.0, 0.5);
$pos = 0.0;
$vel = 0.0;
$target = 100.0;

for ($frame = 0; $frame < 60; $frame++) {
    [$pos, $vel] = $spring->update($pos, $vel, $target);
    echo sprintf("%2d  pos=%.2f  vel=%.2f\n", $frame, $pos, $vel);
}
```

Uses the same Ryan-Juckett damped-spring derivation as harmonica, with
under-/critically-/over-damped branches selected by `dampingRatio`.

## Test

```sh
cd honey-bounce && composer install && vendor/bin/phpunit
```
