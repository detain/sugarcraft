<?php
/**
 * sugar-overlay — composite overlays on a base terminal view.
 *
 * Run: php examples/multiple-overlays.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Overlay\Position;
use SugarCraft\Overlay\Veil;
use SugarCraft\Overlay\VeilStack;

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

// Overlay contents. Placement is NOT part of the content — it is decided
// at composite time by the Position anchors plus column/row offsets.
$tooltip = "  [Tooltip] Unsaved changes  ";
$modal   = "+----------------------------------+\n"
         . "|  Save changes before closing?   |\n"
         . "|  [Y] Yes   [N] No   [C] Cancel |\n"
         . "+----------------------------------+";

// Veil::composite() composites ONE foreground over ONE background.
// For multiple overlays, composite bottom-to-top: base → tooltip → modal.
//
// The diff law: a reused Veil answers same-size frames with a DIFF
// (cursor moves + changed cells) — right for a live terminal, wrong for a
// demo being echo()d. Each render below runs on a fresh Veil so every
// frame shown here is FULL output.
echo "With tooltip (top-right, +3 rows) composited:\n";
echo Veil::new()->composite(
    $tooltip,
    $base,
    Position::TOP,
    Position::RIGHT,
    0,   // $xOffset: fine-tune from the RIGHT edge in columns (+ right / − left)
    3,   // $yOffset: push 3 rows down from the TOP edge
) . "\n\n";

echo "With tooltip + modal (modal centered) composited:\n";
$withTooltip = Veil::new()->composite($tooltip, $base, Position::TOP, Position::RIGHT, 0, 3);
echo Veil::new()->composite($modal, $withTooltip, Position::CENTER, Position::CENTER) . "\n\n";

// Stacking the declarative way: a VeilStack sorts its layers by z-index
// (lowest painted first) and compositeAll() places each veil at its OWN
// withPosition() anchor — CENTER when unset — with its stored offsets.
// A content-less veil with backdrop dimming is a backdrop-only layer.
echo "VeilStack::compositeAll() — backdrop dim + tooltip + modal:\n";
$stack = VeilStack::new()
    ->add(Veil::new()->withBackdrop(60)->withZIndex(0))
    ->add(Veil::new()
        ->withContent($tooltip)
        ->withZIndex(1)
        ->withPosition(Position::TOP, Position::RIGHT, 0, 3))
    ->add(Veil::new()
        ->withContent($modal)
        ->withZIndex(2)
        ->withPosition(Position::CENTER, Position::CENTER));
echo $stack->compositeAll($base) . "\n\n";

echo "Base only (no overlay):\n";
echo $base . "\n";
