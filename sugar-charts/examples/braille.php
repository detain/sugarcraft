<?php

declare(strict_types=1);

use SugarCraft\Charts\LineChart\LineChart;

require __DIR__ . '/../vendor/autoload.php';

// Braille dot-grid mode: `withBraille()` switches the chart to 2x4 dots
// per cell. The chart's own width and height drive the render, so no
// canvas is needed for a standalone demo like this one.
$series = [];
for ($i = 0; $i < 120; $i++) {
    $series[] = sin($i / 7.0) * ($i / 20.0 + 1);
}

$chart = LineChart::new($series, 60, 20)
    ->withBraille()
    ->withAxes(true)
    ->withTitle('braille mode — 2x4 dots per cell');

echo $chart->view(), PHP_EOL;
