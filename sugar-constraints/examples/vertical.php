<?php

declare(strict_types=1);

/**
 * Runnable worked example — the same split the sidebar demo performs, run on
 * Direction::Vertical: fixed-height chrome above and below an elastic body.
 * The solved axis is rows here; a "cell" is one text line.
 *
 *     php examples/vertical.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SugarCraft\Constraints\Constraint\Constraint;
use SugarCraft\Constraints\Direction;
use SugarCraft\Constraints\GreedySolver;
use SugarCraft\Constraints\Region;

$solver = GreedySolver::new();

$format = static function (Region ...$rects): string {
    return implode('  ', array_map(
        static fn (Region $r): string => sprintf('[y=%d h=%d]', $r->y, $r->height),
        $rects,
    ));
};

// A 24-row window: header 3, body takes the rest, status line 1.
$tall = $solver->solve(Region::fromSize(80, 24), Direction::Vertical, [
    Constraint::length(3),    // header
    Constraint::fill(1),      // body
    Constraint::length(1),    // status line
]);
echo "rows 24: {$format(...$tall)}\n";

// The same chrome squeezed into 8 rows: everything still fits, the body
// absorbs the smaller slack.
$short = $solver->solve(Region::fromSize(80, 8), Direction::Vertical, [
    Constraint::length(3),
    Constraint::fill(1),
    Constraint::length(1),
]);
echo "rows  8: {$format(...$short)}\n";

// Demand above supply (demandOf([length(3),min(2)]) on 3 rows = 5 > 3):
// below its fixed panes the frame cannot seat them all, regions shrink
// proportionally and the walk still tiles the span — silently, because a
// resized-below-minimum window is a normal runtime state. GreedySolver::
// demandOf() answers that 5 without a hand-redone comparison.
$crushed = $solver->solve(Region::fromSize(80, 3), Direction::Vertical, [
    Constraint::length(3),    // header wants every row
    Constraint::min(2),       // body floor 2
]);
echo "rows  3: {$format(...$crushed)}\n";
