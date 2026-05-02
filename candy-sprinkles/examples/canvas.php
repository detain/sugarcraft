<?php

declare(strict_types=1);

/**
 * Canvas — multi-layer compositor. Place a styled "popover" Layer
 * over a base Layer to demonstrate the v2 floating-pane API.
 *
 *   php examples/canvas.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Canvas;
use CandyCore\Sprinkles\Layer;
use CandyCore\Sprinkles\Style;

// 1. Base view: a 50×10 grid of dimmed dots.
$baseLines = [];
for ($y = 0; $y < 10; $y++) {
    $line = '';
    for ($x = 0; $x < 50; $x++) {
        $line .= ($x + $y) % 2 === 0 ? '·' : ' ';
    }
    $baseLines[] = $line;
}
$base = Layer::new(Style::new()
    ->foreground(Color::hex('#4a3868'))
    ->render(implode("\n", $baseLines))
);

// 2. Floating popover — pink border, centered title.
$popover = Layer::new(
    Style::new()
        ->border(Border::rounded())
        ->foreground(Color::hex('#fbeefa'))
        ->background(Color::hex('#1f162d'))
        ->padding(0, 2)
        ->render("\x1b[1mPopover\x1b[0m\nfloating above base\nat (8, 3) with z=1")
)->withX(8)->withY(3)->withZ(1);

echo Canvas::new()->addLayer($base)->addLayer($popover)->render() . "\n";
