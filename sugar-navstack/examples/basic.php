<?php

declare(strict_types=1);

/**
 * SugarNavstack — NavStack and Breadcrumb demo.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\NavStack\{Breadcrumb, NavigationItem, NavStack};

echo "=== NavStack ===\n";
$stack = NavStack::new();
$stack->push('Home');
$stack->push('Settings', ['user_id' => 42]);
$stack->push('Display', ['brightness' => 75]);

echo "Depth: {$stack->depth()}\n";
echo "Current: {$stack->current()->title}\n";
echo "Parent: {$stack->parent()->title}\n";
echo "Items: " . \implode(' › ', \array_map(
    static fn(NavigationItem $item): string => $item->title,
    $stack->items()
)) . "\n\n";

echo "=== Breadcrumb (default) ===\n";
$bc = Breadcrumb::new();
echo $bc->render($stack) . "\n\n";

echo "=== Breadcrumb (custom separator) ===\n";
$bc2 = Breadcrumb::new()->setSeparator(' > ');
echo $bc2->render($stack) . "\n\n";

echo "=== Breadcrumb (with max-width truncation) ===\n";
// 30 would NOT elide the 25-cell trail ("Home › Settings › Display") —
// the demo proved nothing at that cap; 20 forces the oldest crumb out.
$bc3 = Breadcrumb::new()->setMaxWidth(20);
echo $bc3->render($stack) . "\n\n";

echo "=== Pop and re-render ===\n";
$stack->pop();
echo $bc->render($stack) . "\n";
