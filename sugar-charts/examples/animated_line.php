<?php

declare(strict_types=1);

/**
 * Animated LineChart — demonstrates progressive rendering via animation progress.
 *
 *   php examples/animated_line.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Charts\LineChart\LineChart;

// Sample data representing a growth trend
$data = [10, 25, 18, 42, 35, 58, 45, 72, 65, 88, 75, 95, 82, 100];

echo "=== Animated LineChart Demo ===\n\n";
echo "Rendering at different animation progress levels:\n\n";

// Render at different progress levels
$progressLevels = [0.0, 0.25, 0.5, 0.75, 1.0];

foreach ($progressLevels as $progress) {
    $chart = LineChart::new($data, 50, 10)
        ->withAnimationProgress($progress)
        ->withAxes(true)
        ->withTitle("Progress: " . (int) ($progress * 100) . "%");

    echo "--- " . ($progress * 100) . "% ---\n";
    echo $chart->view() . "\n\n";
}

echo "=== Multi-series Animation Demo ===\n\n";

// Multi-series example
$chart = LineChart::new([10, 30, 25, 45], 50, 10)
    ->withDataset('Revenue', [15, 25, 35, 50])
    ->withDataset('Expenses', [20, 22, 28, 30])
    ->withAxes(true)
    ->withLegend(true)
    ->withTitle('Financial Overview');

foreach ([0.33, 0.66, 1.0] as $progress) {
    $animated = $chart->withAnimationProgress($progress);
    echo "--- " . ($progress * 100) . "% ---\n";
    echo $animated->view() . "\n\n";
}
