<?php

declare(strict_types=1);

/**
 * SugarNavStack — mouse zones / Scanner reference.
 *
 * The headline feature (click a crumb, jump back) with NO event loop:
 * sugar-mouse's Scanner parses a rendered string offline, so a host can
 * wire this into any terminal driver. This file is that wiring, minified
 * to a script.
 *
 * Run: php examples/mouse.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Mouse\Scan;
use SugarCraft\Mouse\Scanner;
use SugarCraft\NavStack\Breadcrumb;
use SugarCraft\NavStack\NavStack;

const VIEWPORT = 80;

$stack = NavStack::new();
$stack->push('Home', ['view' => 'welcome']);
$stack->push('Settings', ['section' => 'prefs']);
$stack->push('Display', ['brightness' => 75]);
$stack->push('Brightness', ['level' => 75]);

// A bar narrow enough to ELIDE the leftmost crumbs. Zone ids carry FULL
// STACK INDICES, so the clicks below still resolve correctly even though
// Home and Settings are not on screen.
$bc = Breadcrumb::new()
    ->withScanner(new Scanner())
    ->setMaxWidth(24);

/** @return array<int, array{0:int,1:int,\SugarCraft\NavStack\NavigationItem}> cell ranges per crumb */
function crumbRanges(Breadcrumb $bc, NavStack $stack, int $cols): array
{
    $ranges = [];
    $open = null;
    for ($col = 1; $col <= $cols; $col++) {
        $item = $bc->hitItem($stack, $col, 1);
        if ($item !== null && $open !== null && $open[2] === $item) {
            $open[1] = $col; // extend the run
            continue;
        }
        if ($open !== null) {
            $ranges[] = $open;
        }
        $open = $item !== null ? [$col, $col, $item] : null;
    }
    if ($open !== null) {
        $ranges[] = $open;
    }
    return $ranges;
}

// LAW: render → scan → hit, and re-scan after every render. The render
// output carries PRIVATE-USE zone sentinels; strip them before painting
// (README "Click-Region Tracking").
$rendered = $bc->render($stack);
$bc->scan($rendered, VIEWPORT, 1);

echo "=== 4-deep stack in a width-24 bar ===\n";
echo 'painted: ' . Scan::strip($rendered) . "\n";
echo 'visible stack indices: [' . implode(', ', $bc->visibleIndices()) . "]\n\n";

echo "=== zones the scanner found (cell ranges, 1-based) ===\n";
$ranges = crumbRanges($bc, $stack, VIEWPORT);
foreach ($ranges as [$from, $to, $item]) {
    $index = array_search($item, $stack->items(), true);
    echo "cells {$from}-{$to} => \"{$item->title}\" (stack index {$index})\n";
}

echo "\n=== synthetic clicks ===\n";
foreach ($ranges as [$from, $to, $item]) {
    $middle = intdiv($from + $to, 2);
    $hit = $bc->hitItem($stack, $middle, 1);
    $index = array_search($hit, $stack->items(), true);
    echo "click {$middle} => {$hit->title} " . json_encode($hit->data) . "  plan: popTo({$index})\n";
}

echo "\n=== the … marker is a gesture, not navigation ===\n";
$span = $bc->elisionSpan();
if ($span !== null) {
    [$start, $end, $row] = $span;
    echo 'elision marker cells [' . $start . '..' . $end . "] on row {$row}\n";
    echo 'hitItem there: ' . var_export($bc->hitItem($stack, intdiv($start + $end, 2), $row), true)
        . "  (treat as: expand the trail, never popTo)\n";
}

echo "\n=== jump via a click, then re-render (render/scan/hit law) ===\n";
// The OLDEST clickable crumb (the deepest one is the current screen —
// clicking it is the legitimate no-op). Its stack index carried through
// the elision, which is exactly the point of stack-indexed zone ids.
[$from, $to, $jumpTo] = $ranges[0];
$target = array_search($jumpTo, $stack->items(), true);
echo "clicking cells {$from}-{$to} (\"{$jumpTo->title}\") => popTo({$target})\n";
$stack->popTo($target); // what a mouse event would do
$rendered = $bc->render($stack); // clears the scanned flag
$bc->scan($rendered, VIEWPORT, 1); // geometry is fresh again
echo 'painted: ' . Scan::strip($rendered) . "\n";
echo 'depth now: ' . $stack->depth() . "\n";
