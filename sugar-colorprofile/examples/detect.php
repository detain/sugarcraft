<?php

declare(strict_types=1);

/**
 * Detect the terminal color profile.
 *
 * Run: php examples/detect.php
 *
 * This exercises:
 * - Palette TTY-aware detection from STDOUT (a null stream is env-only)
 * - Palette::comment() for a human-readable description
 * - Palette::describe() for a full descriptive sentence
 * - ColorProfile enum properties (label(), description(), maxColors(), degradedTo())
 * - The README quickstart hero idiom end to end: detect on a stream, convert
 *   one color, wrap STDOUT in a ProfileWriter and write a TrueColor sequence
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Palette;
use SugarCraft\ColorProfile\ProfileWriter;

echo "=== SugarColorprofile Detection Demo ===\n\n";

// Detect from the current environment (uses TERM, COLORTERM, NO_COLOR, etc.)
// bound to STDOUT, so a piped run lands on the no-color rung like upstream.
$palette = new Palette(STDOUT);
$profile = $palette->profile();

echo "Profile enum value : " . $profile->value . "\n";
echo "Label              : " . $profile->label() . "\n";
echo "Description        : " . $profile->description() . "\n";
echo "Max colors         : " . \number_format($profile->maxColors()) . "\n";
echo "Comment            : " . $palette->comment() . "\n";

echo "\nDegradation chain:\n";
$cursor = $profile;
while ($cursor !== null) {
    echo "  " . $cursor->label() . " (" . \number_format($cursor->maxColors()) . " colors)\n";
    $cursor = $cursor->degradedTo();
}

// The Palette object also gives you a full description
echo "\n" . $palette->describe() . "\n";

// Force a specific profile and comment on it
echo "\n--- Forced profiles ---\n";
foreach ([ColorProfile::TrueColor, ColorProfile::Ansi256, ColorProfile::Ansi, ColorProfile::Ascii, ColorProfile::NoTTY] as $p) {
    $forced = $palette->withProfile($p);
    echo "  [{$p->label()}] -> \"{$forced->comment()}\"\n";
}

// The README quickstart hero idiom, homed here so the printed command runs
// verbatim (CP-37): detect on a stream, downsample one color, wrap and write.
// Run `php examples/detect.php | cat` and the next two lines print exactly
// `Your terminal supports: No TTY` and `Converted: #000000` — the step-0
// stream gate floors the piped run on NoTTY, which degrades #6b50ff to black.
$profile = Palette::detect(STDOUT);
echo "\nYour terminal supports: " . $profile->label() . "\n";

$color = new Color(0x6B, 0x50, 0xFF); // #6b50ff
$converted = $palette->convert($color);
echo "Converted: " . $converted->toHex() . "\n";

// Wrapping turns every write into a degrade to the stream's profile; on the
// piped NoTTY answer the whole ANSI sequence is stripped, not just the color.
$writer = ProfileWriter::wrap(STDOUT);
$writer->write("\x1b[38;2;107;80;255mFancy text\x1b[0m\n");
