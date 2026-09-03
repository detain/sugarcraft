<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Plot;

/**
 * plot-braille.php — Side-by-side comparison of the Plot marker modes.
 *
 * Both plots render identical data, differing only in Plot::withMode():
 * - Left:  scatter — Plot::MODE_SCATTER, unconnected braille dots at each
 *          data point (canvas->setPoint per sample)
 * - Right: line   — default Plot::MODE_LINE, segments joined between
 *          consecutive points (canvas->setLine runs)
 *
 * Run: php examples/plot-braille.php
 */
$data = [15.0, 25.0, 38.0, 42.0, 31.0, 55.0, 48.0, 62.0, 54.0, 71.0, 68.0, 85.0];

// ── Scatter plot (left) — Plot::MODE_SCATTER, isolated dots ──────────
$dotPlot = Plot::new($data, 35, 14)
    ->withShowAxes(true)
    ->withMode(Plot::MODE_SCATTER);
$dotPlot = $dotPlot->setSize(35, 14);
$dotOutput = $dotPlot->render();

// ── Line plot (right) — default Plot::MODE_LINE, joined segments ─────
$braillePlot = Plot::new($data, 35, 14)
    ->withShowAxes(true);
$braillePlot = $braillePlot->setSize(35, 14);
$brailleOutput = $braillePlot->render();

// ── Combine side-by-side with headers ───────────────────────────
$dotLines = explode("\n", $dotOutput);
$brailleLines = explode("\n", $brailleOutput);
$maxLines = max(count($dotLines), count($brailleLines));

$W = 35;

echo str_pad('Scatter (MODE_SCATTER)', $W) . "  " . 'Line (default)' . "\n";
echo str_repeat('─', $W) . "  " . str_repeat('─', $W) . "\n";

for ($i = 0; $i < $maxLines; $i++) {
    $dotLine = str_pad($dotLines[$i] ?? '', $W);
    $brailleLine = str_pad($brailleLines[$i] ?? '', $W);
    echo $dotLine . "  " . $brailleLine . "\n";
}
