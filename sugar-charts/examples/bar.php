<?php

declare(strict_types=1);

/**
 * BarChart — vertical and horizontal modes side by side.
 *
 *   php examples/bar.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Charts\BarChart\Bar;
use CandyCore\Charts\BarChart\BarChart;

$bars = [
    new Bar('Mon', 12),
    new Bar('Tue', 18),
    new Bar('Wed',  9),
    new Bar('Thu', 22),
    new Bar('Fri', 30),
    new Bar('Sat', 14),
    new Bar('Sun',  7),
];

echo "\x1b[36mVertical (default)\x1b[0m\n";
echo BarChart::new($bars, 30, 10)->withShowLabels(true)->view() . "\n\n";

echo "\x1b[36mHorizontal\x1b[0m\n";
echo BarChart::new($bars, 30, 10)->withHorizontal(true)->withShowLabels(true)->view() . "\n";
