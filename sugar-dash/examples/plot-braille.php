<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Plot;

/**
 * plot-braille.php — Side-by-side comparison of MarkerDot vs MarkerBraille.
 *
 * Both plots render identical data with different marker styles:
 * - Left:  MarkerDot    — discrete square markers at each data point
 * - Right: MarkerBraille — smooth braille-character curves (default)
 *
 * Run: php examples/plot-braille.php
 */
$data = [15.0, 25.0, 38.0, 42.0, 31.0, 55.0, 48.0, 62.0, 54.0, 71.0, 68.0, 85.0];

// ── MarkerDot plot (left) ──────────────────────────────────────────
$dotPlot = Plot::new($data, 35, 14)
    ->withMarker(Plot::MARKER_DOT)
    ->withShowAxes(true);
$dotPlot->setSize(35, 14);
$dotOutput = $dotPlot->render();

// ── MarkerBraille plot (right) ───────────────────────────────────
$braillePlot = Plot::new($data, 35, 14)
    ->withMarker(Plot::MARKER_BRAILLE)
    ->withShowAxes(true);
$braillePlot->setSize(35, 14);
$brailleOutput = $braillePlot->render();

// ── Combine side-by-side with headers ───────────────────────────
$dotLines = explode("\n", $dotOutput);
$brailleLines = explode("\n", $brailleOutput);
$maxLines = max(count($dotLines), count($brailleLines));

$W = 35;

echo str_pad('MarkerDot', $W) . "  " . 'MarkerBraille' . "\n";
echo str_repeat('─', $W) . "  " . str_repeat('─', $W) . "\n";

for ($i = 0; $i < $maxLines; $i++) {
    $dotLine = str_pad($dotLines[$i] ?? '', $W);
    $brailleLine = str_pad($brailleLines[$i] ?? '', $W);
    echo $dotLine . "  " . $brailleLine . "\n";
}
