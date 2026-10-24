<?php

declare(strict_types=1);

/**
 * StandardColors — the 16-color ANSI palette as named Color objects.
 *
 * Run: php examples/standard-colors.php
 *
 * Exercises:
 * - StandardColors static color constants
 * - StandardColors::all() (all 16 in array)
 * - StandardColors::fromIndex() (lookup by index)
 * - Rendering each color as a foreground + background swatch
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\ColorProfile\StandardColors;
use SugarCraft\ColorProfile\Color;

echo "=== Standard ANSI 16-Color Palette Demo ===\n\n";

// Print a swatch for each of the 16 standard colors
$names = [
    'black', 'red', 'green', 'yellow', 'blue',
    'magenta', 'cyan', 'white',
    'brightBlack', 'brightRed', 'brightGreen', 'brightYellow',
    'brightBlue', 'brightMagenta', 'brightCyan', 'brightWhite',
];

echo "Index  Name            Swatch (bg)    Hex        R     G     B\n";
echo str_repeat('-', 72) . "\n";

foreach (StandardColors::all() as $i => $color) {
    $name = $names[$i];
    $bg   = $color->toAnsiBackground();
    $hex  = $color->toHex();
    printf("%5d  %-15s %s%-10s%s %-8s %3d %3d %3d\n",
        $i,
        $name,
        $bg,
        '██████████',
        "\x1b[0m",
        $hex,
        $color->r, $color->g, $color->b,
    );
}

echo "\n--- Rendered swatches (foreground) ---\n";
foreach (StandardColors::all() as $i => $color) {
    $name = $names[$i];
    $fg   = $color->toAnsiForeground();
    $bg   = StandardColors::fromIndex(0)->toAnsiBackground(); // black, non-mutating read API
    echo "{$bg}{$fg} {$name} \x1b[0m ";
}
echo "\n";

echo "\n--- StandardColors::fromIndex() ---\n";
for ($i = 0; $i < 16; $i++) {
    $c = StandardColors::fromIndex($i);
    echo "Index {$i}: {$c->toHex()}\n";
}

// Out-of-bounds throws
echo "\n--- Out-of-bounds throws ---\n";
try {
    StandardColors::fromIndex(99);
} catch (\OutOfBoundsException $e) {
    echo "Caught OutOfBoundsException as expected: {$e->getMessage()}\n";
}
