<?php

declare(strict_types=1);

/**
 * 2D heatmap with a multi-stop palette and legend strip.
 *
 *   php examples/heatmap.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Charts\Heatmap\Heatmap;
use CandyCore\Core\Util\Color;

// Build a 30×10 grid of sin(x)*cos(y) values.
$grid = [];
for ($y = 0; $y < 10; $y++) {
    $row = [];
    for ($x = 0; $x < 30; $x++) {
        $row[] = sin($x * 0.3) * cos($y * 0.5);
    }
    $grid[] = $row;
}

echo Heatmap::new($grid, 30, 10)
    ->withPalette([
        Color::hex('#000050'),
        Color::hex('#5fafff'),
        Color::hex('#ffd75f'),
        Color::hex('#ff5f5f'),
    ])
    ->withLegend()
    ->view() . "\n";
