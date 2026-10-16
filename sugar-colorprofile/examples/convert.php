<?php

declare(strict_types=1);

/**
 * Color conversion — downgrade a TrueColor color to simpler profiles.
 *
 * Run: php examples/convert.php
 *
 * Exercises:
 * - Color construction (from RGB, from hex, from int)
 * - Color::convert() to each profile level
 * - ANSI foreground escape generation
 * - hex string output
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\Profile;

echo "=== Color Conversion Demo ===\n\n";

// A rich purple: #6b50ff
$original = Color::fromHex(0x6b50ff);
$parsed   = Color::parse('#6b50ff');

echo "Original color: {$original->toHex()} (RGBA: {$original->r}, {$original->g}, {$original->b}, {$original->a})\n\n";

echo "| Profile    | Result  | ANSI escape               |\n";
echo "|------------|---------|---------------------------|\n";

foreach ([Profile::TrueColor, Profile::ANSI256, Profile::ANSI, Profile::Ascii, Profile::NoTTY] as $profile) {
    $converted = $original->convert($profile);
    $fg = $converted->toAnsiForeground();
    // Show the escape as readable escape codes
    $escaped = \addcslashes($fg, '\\x');
    printf("| %-10s | %-7s | %-25s |\n", $profile->label(), $converted->toHex(), $escaped . '[0m');
}

echo "\n--- Rendered (your terminal may not show all correctly) ---\n";
foreach ([Profile::TrueColor, Profile::ANSI256, Profile::ANSI, Profile::Ascii, Profile::NoTTY] as $profile) {
    $converted = $original->convert($profile);
    $fg = $converted->toAnsiForeground();
    echo $fg . "[{$profile->label()}] This is #6b50ff converted to {$profile->label()} \x1b[0m\n";
}

echo "\n--- ANSI256 216-cube sample ---\n";
$rainbow = [
    Color::parse('#ff0000'),
    Color::parse('#ff8800'),
    Color::parse('#ffff00'),
    Color::parse('#00ff00'),
    Color::parse('#0088ff'),
    Color::parse('#8800ff'),
    Color::parse('#ff00ff'),
];
foreach ($rainbow as $c) {
    $fg = $c->convert(Profile::ANSI256)->toAnsi256Foreground();
    echo $fg . '█' . "\x1b[0m";
}
echo "\n";
