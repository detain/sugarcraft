<?php

declare(strict_types=1);

/**
 * sugar-overlay narrow-canvas demo — ask BEFORE rendering a wide modal on
 * a terminal too narrow to hold it (sugar-overlay-007).
 *
 * A centered 40-column dialog on a 30-column canvas loses BOTH borders and
 * five columns of text on each side, silently — clipping is the documented
 * contract, but a host that would rather warn than mangle can measure the
 * overhang first with overflowFor(). The shrink-to-fit alternative is a
 * product-design decision this library deliberately does not make for you.
 *
 * Run: php examples/overflow.php
 * (after `composer install` in sugar-overlay/ — vendor/ is gitignored,
 * so a cold clone's require above fatals until dependencies exist)
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Overlay\Position;
use SugarCraft\Overlay\Veil;

$canvas = implode("\n", [
    '┌────────────────────────────┐',
    '│   30 columns of terminal   │',
    '│                            │',
    '└────────────────────────────┘',
]);

// A 40-column dialog built once, honest about its own width.
$dialog = "┌──────────────────────────────────────┐\n"
    . "│ Delete everything?                 │\n"
    . "│ [ Yes, delete ]   [ Cancel ]       │\n"
    . "└──────────────────────────────────────┘\n";

$overflow = Veil::new()->overflowFor($dialog, $canvas, Position::CENTER, Position::CENTER);

if ($overflow === null) {
    echo "Canvas is wide enough — compositing normally.\n";
    exit(0);
}

printf(
    "Modal would lose %d columns left, %d right, %d rows top, %d bottom. %s\n",
    $overflow['clippedLeft'],
    $overflow['clippedRight'],
    $overflow['clippedTop'],
    $overflow['clippedBottom'],
    'Host policy: warn the user and shrink the content, never silently clip.',
);

// What the silent clip actually looks like, for comparison — still valid
// output (the contract), just not what a careful host ships.
$clipped = Veil::new()->composite($dialog, $canvas, Position::CENTER, Position::CENTER);
$fallback = Veil::centered("[!] dialog needs 40 cols — canvas has 30\n    press any key", $canvas);

printf("silent clip frame row 1: %s\n", strtok($clipped, "\n"));
printf("warned frame  row 1: %s\n", strtok($fallback, "\n"));
echo "overflowFor() told the host before either byte hit the screen.\n";

// The other edge of the same contract: an EMPTY background has no canvas
// to clip against, so there is no overhang to report — overflowFor() says
// null and composite() paints the overlay as the whole frame. A host
// treating "no backdrop yet" (first paint, cleared screen) as a special
// case gets the documented pass-through, not a mismeasured clip.
$emptyOverhang = Veil::new()->overflowFor($dialog, '', Position::CENTER, Position::CENTER);
printf(
    "empty canvas: overflowFor=%s, first row of bare composite: %s\n",
    $emptyOverhang === null ? 'null (no clipping)' : 'unexpected',
    strtok(Veil::new()->composite($dialog, '', Position::CENTER, Position::CENTER), "\n"),
);
