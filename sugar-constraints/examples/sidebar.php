<?php

declare(strict_types=1);

/**
 * Runnable worked example — the README quickstart, end to end:
 * a fixed-width sidebar rail beside a content pane that absorbs the rest.
 *
 *     php examples/sidebar.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SugarCraft\Constraints\Constraint\Constraint;
use SugarCraft\Constraints\Direction;
use SugarCraft\Constraints\GreedySolver;
use SugarCraft\Constraints\Region;

$solver = GreedySolver::new();

$format = static function (Region ...$rects): string {
    return implode('  ', array_map(
        static fn (Region $r): string => sprintf('[x=%d w=%d]', $r->x, $r->width),
        $rects,
    ));
};

// A comfortable 100-column terminal: rail 24, content 76.
$wide = $solver->solve(Region::fromSize(100, 24), Direction::Horizontal, [
    Constraint::length(24),   // sidebar
    Constraint::fill(1),      // content
]);
echo "width 100: {$format(...$wide)}\n";

// The same layout squeezed to 10 columns: the fixed pane takes what it
// can first, the elastic pane collapses to zero. Degradation is silent —
// GreedySolver::demandOf($area, $dir, $constraints) answers 24 here, and
// 24 > 10 tells you ahead of the solve that something must give.
$narrow = $solver->solve(Region::fromSize(10, 24), Direction::Horizontal, [
    Constraint::length(24),
    Constraint::fill(1),
]);
echo "width  10: {$format(...$narrow)}\n";

// Four panes: sidebar 24, floor 10 on the list rail, fill weights 2 vs 1
// on content and detail.
$mixed = $solver->solve(Region::fromSize(100, 24), Direction::Horizontal, [
    Constraint::length(24),   // sidebar
    Constraint::min(10),      // list rail, floor 10
    Constraint::fill(2),      // content
    Constraint::fill(1),      // detail
]);
echo "4-pane  : {$format(...$mixed)}\n";
