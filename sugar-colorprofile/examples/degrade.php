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
use SugarCraft\ColorProfile\ColorProfile;

echo "=== ProfileWriter Degradation Demo ===\n\n";

// Create a writer wrapping stdout — detects environment automatically
$writer = ProfileWriter::wrap(STDOUT);

echo "Detected profile: " . $writer->profile()->label() . "\n\n";

// Rich TrueColor input string
$trueColorText = "\x1b[38;2;255;100;50mOrange text\x1b[0m "
    . "\x1b[38;2;0;200;100mTeal text\x1b[0m "
    . "\x1b[38;2;200;50;255mPurple text\x1b[0m\n";

echo "TrueColor input (what we write):\n";
$sample = "\x1b[38;2;255;100;50mSample\x1b[0m";
foreach ([ColorProfile::TrueColor, ColorProfile::Ansi256, ColorProfile::Ansi, ColorProfile::Ascii] as $forceProfile) {
    // withProfile() is already clone-on-write — the wrapped stream is shared,
    // the profile is not; no explicit (clone $writer) needed.
    $degraded = $writer->withProfile($forceProfile);
    echo "  As {$forceProfile->label()}: ";
    // write() emits the degraded bytes straight to the stream: what you see
    // on this line IS what a terminal at that profile would receive.
    $degraded->write($sample);
    echo "\n";
}

echo "\n--- Full string degraded through each profile ---\n";
$input = "\x1b[38;2;255;100;50m[Orange]\x1b[0m "
    . "\x1b[38;2;0;200;100m[Teal]\x1b[0m "
    . "\x1b[38;2;200;50;255m[Purple]\x1b[0m\n";

foreach ([ColorProfile::TrueColor, ColorProfile::Ansi256, ColorProfile::Ansi, ColorProfile::Ascii, ColorProfile::NoTTY] as $p) {
    echo "Profile {$p->label()}: ";
    $writer->withProfile($p)->write($input);
}

echo "\nDone.\n";
