<?php

declare(strict_types=1);

/**
 * Demonstrate the three spring-damping regimes side by side.
 *
 *   php examples/spring.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bounce\Spring;

$frames = 60;
$target = 100.0;

foreach (['under-damped 0.3' => 0.3, 'critical 1.0' => 1.0, 'over-damped 2.0' => 2.0] as $label => $zeta) {
    echo "\n$label:\n";
    $spring = new Spring(Spring::fps($frames), 6.0, $zeta);
    $pos = 0.0;
    $vel = 0.0;
    for ($i = 0; $i < $frames; $i++) {
        [$pos, $vel] = $spring->update($pos, $vel, $target);
        $bar = str_repeat('█', max(0, min(60, (int) round($pos * 0.6))));
        if ($i % 3 === 0) {
            printf("  %2d  %s  %.2f\n", $i, $bar, $pos);
        }
    }
}
