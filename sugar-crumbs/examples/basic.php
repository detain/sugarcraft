<?php

declare(strict_types=1);

/**
 * SugarCrumbs — NavStack and Breadcrumb demo.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Crumbs\{Breadcrumb, NavStack};

echo "=== NavStack ===\n";
$stack = new NavStack();
$stack->push('Home');
$stack->push('Settings', ['user_id' => 42]);
$stack->push('Display', ['brightness' => 75]);

echo "Depth: {$stack->depth()}\n";
echo "Current: {$stack->current()->title}\n";
echo "Parent: {$stack->parent()->title}\n";
echo "Items: {$stack->depth()}\n\n";

echo "=== Breadcrumb (default) ===\n";
$bc = new Breadcrumb();
echo $bc->render($stack) . "\n\n";

echo "=== Breadcrumb (custom separator) ===\n";
$bc2 = (new Breadcrumb())->setSeparator(' > ');
echo $bc2->render($stack) . "\n\n";

echo "=== Breadcrumb (with max-width truncation) ===\n";
$bc3 = (new Breadcrumb())->setMaxWidth(30);
echo $bc3->render($stack) . "\n\n";

echo "=== Pop and re-render ===\n";
$stack->pop();
echo $bc->render($stack) . "\n";
