<?php

declare(strict_types=1);

/**
 * Detect the terminal color profile.
 *
 * Run: php examples/detect.php
 *
 * This exercises:
 * - Palette::detect() from environment
 * - Palette::comment() for a human-readable description
 * - Palette::describe() for a full descriptive sentence
 * - Profile enum properties (label(), description(), maxColors(), degradedTo())
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\ColorProfile\Palette;
use SugarCraft\ColorProfile\Profile;

echo "=== SugarColorprofile Detection Demo ===\n\n";

// Detect from current environment (uses TERM, COLORTERM, NO_COLOR, etc.)
$profile = Palette::detect();

echo "Profile enum value : " . $profile->value . "\n";
echo "Label              : " . $profile->label() . "\n";
echo "Description        : " . $profile->description() . "\n";
echo "Max colors         : " . \number_format($profile->maxColors()) . "\n";
echo "Comment            : $profile->comment()\n";

echo "\nDegradation chain:\n";
$cursor = $profile;
while ($cursor !== null) {
    echo "  " . $cursor->label() . " (" . \number_format($cursor->maxColors()) . " colors)\n";
    $cursor = $cursor->degradedTo();
}

// The Palette object also gives you a full description
$palette = new Palette();
echo "\n" . $palette->describe() . "\n";

// Force a specific profile and comment on it
echo "\n--- Forced profiles ---\n";
foreach ([Profile::TrueColor, Profile::ANSI256, Profile::ANSI, Profile::Ascii, Profile::NoTTY] as $p) {
    $forced = (new Palette())->withProfile($p);
    echo "  [{$p->label()}] -> \"{$forced->comment()}\"\n";
}
