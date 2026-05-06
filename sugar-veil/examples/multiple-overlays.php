<?php
/**
 * sugar-veil — composite overlays on a base terminal view.
 *
 * Run: php examples/multiple-overlays.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CandyCore\Veil\Veil;

// Simulated base terminal content
$base = implode("\n", [
    "══════════════════════════════════════",
    "  Terminal Content Manager v1.0       ",
    "══════════════════════════════════════",
    "  [file]  [edit]  [view]  [help]      ",
    "──────────────────────────────────────",
    "  File: src/Model.php                 ",
    "  Line 42, Col 15                     ",
    "                                      ",
    "  Lorem ipsum dolor sit amet,         ",
    "  consectetur adipiscing elit.        ",
    "                                      ",
    "══════════════════════════════════════",
]);

echo "Base view:\n{$base}\n\n";

// Overlay 1: a tooltip at top-right (single line)
$tooltip = [
    'content' => "  [Tooltip] Unsaved changes  ",
    'x'       => 50,  // column (0 = left, negative = from right edge)
    'y'       => 3,   // row
    'width'   => 28,
];

// Overlay 2: a modal dialog centered
$modal = [
    'content' => "+----------------------------------+\n"
               . "|  Save changes before closing?   |\n"
               . "|  [Y] Yes   [N] No   [C] Cancel |\n"
               . "+----------------------------------+",
    'x'       => 15,
    'y'       => 8,
    'width'   => 36,
];

// Stack: base → tooltip → modal
// The Veil::composite() method takes base and an array of overlays
// Overlays are drawn in order, so put tooltip first (below modal)
$veil = new Veil();
$rendered = $veil->composite($base, [$tooltip, $modal]);

echo "With tooltip (top-right) + modal (centered) composited:\n";
echo $rendered . "\n";

// Composite just the modal
$renderedModal = $veil->composite($base, [$modal]);
echo "Modal only:\n";
echo $renderedModal . "\n";

// Composite just the tooltip
$renderedTooltip = $veil->composite($base, [$tooltip]);
echo "Tooltip only:\n";
echo $renderedTooltip . "\n";

// Empty overlay = base only
$renderedBase = $veil->composite($base, []);
echo "Base only (no overlay):\n";
echo $renderedBase . "\n";
