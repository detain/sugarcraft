<?php

declare(strict_types=1);

use SugarCraft\Charts\LineChart\LineChart;

require __DIR__ . '/../vendor/autoload.php';

// Braille dot-grid mode: `withCanvas()` only needs a non-null object as
// the mode discriminant — the canvas itself is never read or mutated,
// and the chart's own width/height drive the render. Pass a real
// sugar-dashboard BrailleCanvas when composing dashboards; a stdClass
// selects the same mode for a standalone demo like this one.
$series = [];
for ($i = 0; $i < 120; $i++) {
    $series[] = sin($i / 7.0) * ($i / 20.0 + 1);
}

$chart = LineChart::new($series, 60, 20)
    ->withCanvas(new stdClass())
    ->withAxes(true)
    ->withTitle('braille mode — 2x4 dots per cell');

echo $chart->view(), PHP_EOL;
