<?php

declare(strict_types=1);

/**
 * SugarPanels basic demo — H/V panels with borders.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Panels\{Node, SugarPanels};

$panels = SugarPanels::new();

// Simple horizontal split
$h = $panels->horizontal(
    Node::leaf('Apple')->withMinWidth(8),
    Node::leaf('Banana')->withMinWidth(8),
    Node::leaf('Cherry')->withMinWidth(8),
)->withBorder(true)->withSpacing(1);

echo "=== Horizontal split (3 panels) ===\n";
echo $panels->render($h, 50, 5);

// Simple vertical split
$v = $panels->vertical(
    Node::leaf('TOP SECTION')->withMinWidth(20)->withMinHeight(3),
    Node::leaf('BOTTOM SECTION')->withMinWidth(20)->withMinHeight(3),
)->withBorder(true);

echo "\n=== Vertical split (2 panels) ===\n";
echo $panels->render($v, 30, 10);
