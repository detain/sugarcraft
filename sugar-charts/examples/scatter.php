<?php

declare(strict_types=1);

/**
 * Scatter — two clusters of points and a quadratic curve sample.
 *
 *   php examples/scatter.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Charts\Scatter\Scatter;

mt_srand(42);  // deterministic — same demo every run

// Two clusters
$points = [];
for ($i = 0; $i < 30; $i++) {
    $points[] = [mt_rand(10, 30) / 10.0, mt_rand(10, 40) / 10.0];
    $points[] = [mt_rand(70, 90) / 10.0, mt_rand(60, 90) / 10.0];
}

echo "\x1b[36mTwo clusters\x1b[0m\n";
echo Scatter::new($points, width: 50, height: 12)
    ->withXRange(0, 10)
    ->withYRange(0, 10)
    ->view() . "\n\n";

// A quadratic curve
$curve = [];
for ($x = 0; $x <= 20; $x++) {
    $curve[] = [$x, ($x - 10) * ($x - 10) / 5.0];
}
echo "\x1b[36mQuadratic curve\x1b[0m\n";
echo Scatter::new($curve, width: 50, height: 12)->withRune('●')->view() . "\n";
