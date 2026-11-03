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
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Palette;

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
