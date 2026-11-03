<?php

declare(strict_types=1);

/**
 * SugarPanels — the stateful diff renderer.
 *
 * One panel, three renders: the first paints the whole frame, the second
 * changes a single digit in the same viewport and paints only that, the third
 * is a resize and repaints in full behind an erase.
 *
 * Run: php examples/diff.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Panels\{Node, SugarPanels};

$panels = SugarPanels::new();
$cell = static fn (string $value): Node => Node::leaf($value)->withBorder(false)->withMinWidth(20);

$first = $panels->render($cell('00:00:00'), 20, 1);
$second = $panels->render($cell('00:00:01'), 20, 1);
$third = $panels->render($cell('00:00:02'), 24, 1);

\printf("first frame (no history to diff against): %d bytes\n", \strlen($first));
\printf("one digit changed, same viewport:         %d bytes\n", \strlen($second));
\printf("viewport 20 -> 24 (resize repaints):      %d bytes\n", \strlen($third));
