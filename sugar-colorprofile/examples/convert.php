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
 * - per-profile foreground escape generation (SGR 38;2 / 38;5 / 30-37)
 * - hex string output
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\ColorProfile; // (The old deprecated Profile:: facade still resolves via class_alias; new code names the real enum.)

echo "=== Color Conversion Demo ===\n\n";

// A rich purple: #6b50ff
$original = Color::fromHex(0x6b50ff);
$parsed   = Color::parse('#6b50ff');

echo "Original color: {$original->toHex()} (RGBA: {$original->r}, {$original->g}, {$original->b}, {$original->a})\n\n";

// Each profile level has its OWN encoder — printing toAnsiForeground() for all
// of them would show 38;2 everywhere and erase the whole degradation story:
// TrueColor keeps the full RGB triple, ANSI 256 re-indexes it into the 256
// palette, the 16-colour rungs collapse to SGR 30-37/90-97, and a NoTTY
// writer emits no escape at all (degrade() strips every sequence).
$encode = static fn (Color $converted, ColorProfile $profile): string => match (true) {
    $profile === ColorProfile::TrueColor => $converted->toAnsiForeground(),
    $profile === ColorProfile::Ansi256 => $converted->toAnsi256Foreground(),
    $profile === ColorProfile::Ansi, $profile === ColorProfile::Ascii => $converted->toAnsi16Foreground(),
    default => '',
};

echo "| Profile    | Result  | ANSI escape               |\n";
echo "|------------|---------|---------------------------|\n";

foreach ([ColorProfile::TrueColor, ColorProfile::Ansi256, ColorProfile::Ansi, ColorProfile::Ascii, ColorProfile::NoTTY] as $profile) {
    $converted = $original->convert($profile);
    $fg = $encode($converted, $profile);
    // Show the escape readably: the real 0x1B byte becomes the two-character
    // '\e' so the table stays aligned and the bytes stay copyable.
    $display = $fg === '' ? '— none (stripped)' : \str_replace("\x1b", '\e', $fg);
    printf("| %-10s | %-7s | %-25s |\n", $profile->label(), $converted->toHex(), $display);
}

echo "\n--- Rendered (your terminal may not show all correctly) ---\n";
echo "The escape bytes differ per rung — this is the degradation, live:\n";
foreach ([ColorProfile::TrueColor, ColorProfile::Ansi256, ColorProfile::Ansi, ColorProfile::Ascii, ColorProfile::NoTTY] as $profile) {
    $converted = $original->convert($profile);
    $fg = $encode($converted, $profile);
    echo $fg . "[{$profile->label()}] This is #6b50ff converted to {$profile->label()} " . ($fg !== '' ? "\x1b[0m" : '') . "\n";
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
    $fg = $c->convert(ColorProfile::Ansi256)->toAnsi256Foreground();
    echo $fg . '█' . "\x1b[0m";
}
echo "\n";
