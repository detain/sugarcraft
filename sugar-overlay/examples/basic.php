<?php

declare(strict_types=1);

/**
 * SugarOverlay basic demo — overlay a modal box over a background.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Overlay\{Position, Veil};

$veil = Veil::new();

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

// Center the modal
echo "=== Centered overlay ===\n";
echo $veil->composite($fg, $bg, Position::CENTER, Position::CENTER) . "\n";

// Top-right corner
echo "=== Top-Right overlay ===\n";
echo $veil->composite($fg, $bg, Position::TOP, Position::RIGHT) . "\n";

// Bottom-left with offset
echo "=== Bottom-Left (offset +2, -1) ===\n";
echo $veil->composite($fg, $bg, Position::BOTTOM, Position::LEFT, xOffset: 2, yOffset: -1) . "\n";
