<?php

declare(strict_types=1);

/**
 * Adaptive colour — the store-then-collapse ritual on both axes.
 *
 *   php examples/adaptive-colour.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Style;

$warm = Color::rgb(200, 0, 0);
$cool = Color::rgb(0, 200, 200);

// Scheme axis: the light-vs-dark answer is ambient knowledge (an OSC 11
// probe, COLORFGBG, a stored preference) — so the caller collapses it up
// front. render() refuses the uncollapsed pair outright.
echo Style::new()
    ->foregroundAdaptive($warm, $cool)
    ->resolveAdaptive(isDark: false)
    ->of('the light-scheme colour')
    ->render() . "\n";

try {
    Style::new()->foregroundAdaptive($warm, $cool)->of('x')->render();
} catch (\LogicException $e) {
    echo 'refused: ' . $e->getMessage() . "\n";
}

// Tier axis: the complete triple is self-contained (the colour profile
// lives on the style), so render() collapses it by itself — and
// resolveProfile() materialises the same pick when you want the concrete
// slot set for inspection or further merging.
echo Style::new()
    ->colorProfile(ColorProfile::Ansi)
    ->foregroundComplete(Color::rgb(0, 0, 200), Color::ansi256(21), Color::ansi(4))
    ->of('the 16-colour tier pick')
    ->render() . "\n";

echo Style::new()
    ->colorProfile(ColorProfile::Ansi)
    ->foregroundComplete(Color::rgb(0, 0, 200), Color::ansi256(21), Color::ansi(4))
    ->resolveProfile()
    ->of('same bytes, ritual first')
    ->render() . "\n";
