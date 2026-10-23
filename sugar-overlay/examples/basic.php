<?php

declare(strict_types=1);

/**
 * sugar-overlay basic demo — overlay a modal box over a background.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Overlay\{Position, Veil};

// Build a simple background panel
$bg = "┌────────────────────────────────────────┐\n" .
      "│                                        │\n" .
      "│         Main Application Window        │\n" .
      "│                                        │\n" .
      "│    Press ENTER to open the modal       │\n" .
      "│                                        │\n" .
      "└────────────────────────────────────────┘";

// A small modal overlay
$fg = "╔════════════════╗\n" .
      "║   CONFIRM      ║\n" .
      "║                ║\n" .
      "║  Continue? [y] ║\n" .
      "╚════════════════╝";

echo "=== Background ===\n{$bg}\n";
echo "=== Foreground (modal) ===\n{$fg}\n\n";

// The diff law: a Veil remembers what it painted and, for a same-size
// canvas, subsequent composite() calls return the DIFF — cursor moves plus
// changed cells only. That is exactly what a live terminal redraw wants and
// exactly what echoing a demo is not: each placement below therefore runs
// on its own fresh Veil (or ->withoutSession()) so every frame is FULL.
echo "=== Centered overlay ===\n";
echo Veil::new()->composite($fg, $bg, Position::CENTER, Position::CENTER) . "\n";

echo "=== Top-Right overlay ===\n";
echo Veil::new()->composite($fg, $bg, Position::TOP, Position::RIGHT) . "\n";

echo "=== Bottom-Left (offset +2, -1) ===\n";
echo Veil::new()->composite($fg, $bg, Position::BOTTOM, Position::LEFT, xOffset: 2, yOffset: -1) . "\n";
