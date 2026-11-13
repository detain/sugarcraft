<?php

declare(strict_types=1);

/**
 * SugarPanels — every border preset sugar-style ships.
 *
 * The list is read from Border::catalog() at runtime rather than written out
 * here, so the demo cannot fall behind a newly added factory: each catalog
 * entry is the name of a zero-argument static on Border.
 *
 * Run: php examples/borders.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Panels\{Node, SugarPanels};
use SugarCraft\Style\Border;

foreach (Border::catalog() as $name) {
    // A fresh renderer per preset. Reusing one instance at the same viewport
    // would still work — different content, so the diff path paints real
    // changes — but it couples the frames: each echo or preset swap lands as
    // a delta on top of the previous border, and the two renderings visually
    // merge on screen.
    $panels = SugarPanels::new();

    echo "=== Border::{$name}() ===\n";
    echo $panels->render(
        Node::leaf("panel: {$name}")
            ->withBorderStyle([Border::class, $name]())
            ->withMinWidth(24),
        28,
        3,
    );
    echo "\n";
}
