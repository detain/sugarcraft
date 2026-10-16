<?php

declare(strict_types=1);

/**
 * SugarPanels nested layout demo — complex tree structure.
 *
 * Run: php examples/nested.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Panels\{Node, SugarPanels};

$panels = SugarPanels::new();

// Complex: main area (H split) + footer
$layout = $panels->vertical(
    // Header bar
    Node::leaf(' SugarPanels Demo ')->withBorder(true)->withMinWidth(40)->withMinHeight(3),
    // Main content: sidebar + content area
    $panels->horizontal(
        // Sidebar (vertical list)
        $panels->vertical(
            Node::leaf('  Item 1  ')->withMinHeight(2),
            Node::leaf('  Item 2  ')->withMinHeight(2),
            Node::leaf('  Item 3  ')->withMinHeight(2),
        )->withBorder(true)->withMinWidth(12),
        // Content area
        Node::leaf("Content area\n\nThis is the main\ncontent panel.")->withBorder(true)->withMinWidth(30),
    )->withMinHeight(10),
    // Footer bar
    Node::leaf(' Press Ctrl+C to quit ')->withBorder(true)->withMinWidth(40)->withMinHeight(3),
)->withBorder(true);

echo $panels->render($layout, 60, 22);
