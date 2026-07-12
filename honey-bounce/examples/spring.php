<?php

declare(strict_types=1);

/**
 * Demonstrate the three spring-damping regimes side by side.
 *
 * Each column drives the same target (100) from rest through
 * `Spring::update()`, using a different damping ratio:
 *
 *   - under-damped (ζ < 1) overshoots the target and rings back,
 *   - critically damped (ζ = 1) is the fastest non-overshooting approach,
 *   - over-damped (ζ > 1) crawls in slowly without overshoot.
 *
 *   php examples/spring.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SugarCraft\Bounce\Spring;

$target = 100.0;
$frames = 60;
$dt     = Spring::fps($frames);

/** @var array<string,float> $regimes label => damping ratio (ζ) */
$regimes = [
    'under-damped ζ=0.3' => 0.3,
    'critical ζ=1.0'     => 1.0,
    'over-damped ζ=2.0'  => 2.0,
];

// Simulate every regime up front so the columns can be printed in lockstep.
$tracks = [];
foreach ($regimes as $label => $zeta) {
    $spring = new Spring($dt, 6.0, $zeta);
    $pos    = 0.0;
    $vel    = 0.0;
    $series = [];
    for ($i = 0; $i < $frames; $i++) {
        [$pos, $vel] = $spring->update($pos, $vel, $target);
        $series[]    = $pos;
    }
    $tracks[$label] = $series;
}

// Bars are scaled so the under-damped overshoot (~113) still fits in a
// fixed 16-cell column; a leading ▏ keeps the zero row visible.
$barWidth = 16;
$scale    = $barWidth / 130.0;
$bar = static function (float $pos) use ($barWidth, $scale): string {
    $n   = max(0, min($barWidth, (int) round($pos * $scale)));
    $out = str_repeat('█', $n) . str_repeat(' ', $barWidth - $n);
    return $n === 0 ? '▏' . substr($out, 1) : $out;
};

$labels = array_keys($regimes);
echo "Damped spring — three regimes converging to target=100 (angularFrequency=6)\n\n";
printf("frame │ %-23s │ %-23s │ %-23s\n", $labels[0], $labels[1], $labels[2]);
echo str_repeat('─', 6) . '┼' . str_repeat('─', 25) . '┼' . str_repeat('─', 25) . '┼' . str_repeat('─', 25) . "\n";

for ($i = 0; $i < $frames; $i += 4) {
    printf(
        " %3d  │ %s %5.1f │ %s %5.1f │ %s %5.1f\n",
        $i,
        $bar($tracks[$labels[0]][$i]),
        $tracks[$labels[0]][$i],
        $bar($tracks[$labels[1]][$i]),
        $tracks[$labels[1]][$i],
        $bar($tracks[$labels[2]][$i]),
        $tracks[$labels[2]][$i],
    );
}
