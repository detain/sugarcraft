<?php
/**
 * sugar-veil — composite overlays on a base terminal view.
 *
 * Run: php examples/multiple-overlays.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;

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
// Veil::composite() composites one foreground over one background.
// For multiple overlays, composite from bottom to top: base → tooltip → modal
//
// Note: Veil uses diff encoding for subsequent composites to optimize SSH bandwidth.
// For demos, we create fresh instances to show full output each time.

$veil = Veil::new();

// Tooltip at top-right with RIGHT anchor
// xOffset/yOffset allow fine-tuning from the anchor position
$withTooltip = $veil->composite(
    $tooltip['content'],
    $base,
    Position::TOP,
    Position::RIGHT,
    0,
    3,
);

echo "With tooltip (top-right) composited:\n";
echo $withTooltip . "\n\n";

// Reset to get full frame output (diff encoding is for SSH optimization)
$veil->resetPreviousFrame();

// Modal centered - composite tooltip result over modal
$withBoth = $veil->composite(
    $modal['content'],
    $withTooltip,
    Position::CENTER,
    Position::CENTER,
    0,
    0,
);

echo "With tooltip + modal (centered) composited:\n";
echo $withBoth . "\n\n";

// Fresh instance for clean full output
$veil2 = Veil::new();

$renderedModal = $veil2->composite(
    $modal['content'],
    $base,
    Position::CENTER,
    Position::CENTER,
);
echo "Modal only:\n";
echo $renderedModal . "\n\n";

// Fresh instance for clean full output
$veil3 = Veil::new();

$renderedTooltip = $veil3->composite(
    $tooltip['content'],
    $base,
    Position::TOP,
    Position::RIGHT,
    0,
    3,
);
echo "Tooltip only:\n";
echo $renderedTooltip . "\n\n";

// Base only (no overlay)
echo "Base only (no overlay):\n";
echo $base . "\n";
