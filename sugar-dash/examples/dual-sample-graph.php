<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Dash\Plot\Chart\Meter;

/**
 * dual-sample-graph.php — btop-style history graphs and meters.
 *
 * Renders the same CPU history through DualSampleGraph's three symbol
 * families (braille / block / tty), an inverted "upload" graph, a one-row
 * mini-graph on btop's graph_bg underlay, and position-coloured Meter bars
 * — all coloured through btop's Default-theme cpu gradient.
 *
 * Run: php examples/dual-sample-graph.php
 */
// Fixed TrueColor keeps the output deterministic (Meter always emits truecolor too).
$profile = ColorProfile::TrueColor;
$cpuStops = [Color::hex('#80d0a3'), Color::hex('#dcd179'), Color::hex('#d45454')];
$inactive = Color::hex('#404040');

$history = [];
for ($i = 0; $i < 48; $i++) {
    $history[] = (int) round(50 + 40 * sin($i / 5) + 8 * sin($i * 1.7));
}

foreach ([DualSampleGraph::FAMILY_BRAILLE, DualSampleGraph::FAMILY_BLOCK, DualSampleGraph::FAMILY_TTY] as $family) {
    echo "── {$family} ──\n";
    echo DualSampleGraph::new(24, 4, $family)
        ->withGradient($cpuStops)
        ->withData(...$history)
        ->render($profile), "\n\n";
}

echo "── inverted (upload), bytes scaled by maxValue ──\n";
echo DualSampleGraph::new(24, 3, invert: true, maxValue: 4096)
    ->withGradient([Color::hex('#3b6eb2'), Color::hex('#a0c8f0')])
    ->withData(...array_map(static fn (int $v): int => $v * 40, $history))
    ->render($profile), "\n\n";

echo "── one-row mini-graph on the graph_bg underlay ──\n";
echo DualSampleGraph::new(5, 1, noZero: true)
    ->withGradient($cpuStops)
    ->withUnderlay($inactive)
    ->push(0, 0, 0, 0, 20, 45, 80)
    ->render($profile), "\n\n";

echo "── Meter position mode (btop Draw::Meter) ──\n";
foreach ([0.15, 0.5, 0.85] as $ratio) {
    echo Meter::new($ratio)
        ->withWidth(30)
        ->withGradient($cpuStops, positionWise: true)
        ->render(), sprintf(" %3d%%\n", (int) round($ratio * 100));
}
echo Meter::new(0.4)
    ->withWidth(30)
    ->withGradient($cpuStops, positionWise: true)
    ->withInvert()
    ->render(), "  40% (inverted, e.g. battery)\n";
