<?php

declare(strict_types=1);

/**
 * SugarPanels — no border and padding variations.
 *
 * Run: php examples/borders.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Panels\{Node, SugarPanels};

$panels = SugarPanels::new();

// No border between panels
$flat = $panels->horizontal(
    Node::leaf('NO')->withBorder(false)->withMinWidth(5),
    Node::leaf('BORDER')->withBorder(false)->withMinWidth(10),
    Node::leaf('HERE')->withBorder(false)->withMinWidth(8),
)->withBorder(false)->withSpacing(1);

echo "=== No-border layout ===\n";
echo $panels->render($flat, 40, 5);

// With padding
$padded = Node::leaf('PADDED CONTENT')->withPadding(3)->withBorder(true)->withMinWidth(15);
echo "\n=== With padding (3 cells) ===\n";
echo $panels->render($padded, 30, 8);

// NoBorder wrapper
$nested = Node::noBorder(Node::leaf('Inside noborder wrapper')->withBorder(false));
echo "\n=== NoBorder wrapper ===\n";
echo $panels->render($nested, 35, 5);
