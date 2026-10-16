<?php

declare(strict_types=1);

/**
 * ProfileWriter — automatic color degradation on stream write.
 *
 * Run: php examples/degrade.php
 *
 * Exercises:
 * - ProfileWriter::wrap() factory from environment
 * - Manual profile override (force ANSI256 / ANSI / Ascii)
 * - write() with TrueColor input that gets degraded
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\ColorProfile\ProfileWriter;
use SugarCraft\ColorProfile\Palette;
use SugarCraft\ColorProfile\Profile;

echo "=== ProfileWriter Degradation Demo ===\n\n";

// Create a writer wrapping stdout — detects environment automatically
$writer = ProfileWriter::wrap(STDOUT);

echo "Detected profile: " . $writer->profile()->label() . "\n\n";

// Rich TrueColor input string
$trueColorText = "\x1b[38;2;255;100;50mOrange text\x1b[0m "
    . "\x1b[38;2;0;200;100mTeal text\x1b[0m "
    . "\x1b[38;2;200;50;255mPurple text\x1b[0m\n";

echo "TrueColor input (what we write):\n";
foreach ([Profile::TrueColor, Profile::ANSI256, Profile::ANSI, Profile::Ascii] as $forceProfile) {
    $degraded = (clone $writer)->withProfile($forceProfile);
    echo "  As {$forceProfile->label()}: ";
    // For demo clarity we print a description of what would be written
    $input = "\x1b[38;2;255;100;50mSample\x1b[0m";
    $output = $degraded->write($input);
    echo "\n";
}

echo "\n--- Full string degraded through each profile ---\n";
$input = "\x1b[38;2;255;100;50m[Orange]\x1b[0m "
    . "\x1b[38;2;0;200;100m[Teal]\x1b[0m "
    . "\x1b[38;2;200;50;255m[Purple]\x1b[0m\n";

foreach ([Profile::TrueColor, Profile::ANSI256, Profile::ANSI, Profile::Ascii, Profile::NoTTY] as $p) {
    $degraded = (clone $writer)->withProfile($p);
    echo "Profile {$p->label()}: ";
    $degraded->write($input);
}

echo "\nDone.\n";
