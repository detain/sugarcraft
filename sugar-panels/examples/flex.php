<?php

declare(strict_types=1);

/**
 * SugarPanels — flex layout: fixed children take their natural size.
 *
 * The sidebar declares no width at all. It claims the width of its widest
 * line and the growing child takes what is left, which is the shape a real
 * dashboard wants ("nav, then everything else").
 *
 * Run: php examples/flex.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Panels\{Node, SugarPanels};

$panels = SugarPanels::new();

// Neither the header, the sidebar, nor the footer declares a size: each claims
// the space its own content needs, and the middle row is the one that grows.
$sidebar = Node::leaf("nav\nhome\nabout\nhelp\nquit")->withBorder(false);
$main = Node::leaf("main content area\n\nfills whatever the sidebar did not need")->withBorder(false)->withGrow();

echo $panels->render(
    Node::vertical(
        Node::leaf('DASHBOARD')->withBorder(false),
        Node::horizontal($sidebar, $main)->withBorder(false)->withSpacing(1)->withGrow(),
        Node::leaf('ready')->withBorder(false),
    )->withBorder(false),
    52,
    7,
);
echo "\n";
