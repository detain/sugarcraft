<?php

declare(strict_types=1);

/**
 * SugarTreelist — long items with word wrapping.
 *
 * Run: php examples/long-items.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\TreeList\Model;

// Model::tree() installs the Default gutters (visible cursor marker); the
// batch array door takes plain strings — no per-item wrapper, no loop
// (one clone and one filter re-apply instead of five).
$model = Model::tree()->withViewport(60, 30); // narrow viewport to trigger wrapping

$descriptions = [
    'Short item',
    'This is a much longer item that will need to wrap across multiple lines when rendered in the viewport.',
    'Another moderately long description that should wrap at least once in the given viewport width.',
    'Brief',
];
// Every with*() returns a NEW Model — rebind or the step is lost.
$model = $model->withItemsFromArray($descriptions);

echo "=== Word-wrap demo (viewport: 60 cols) ===\n";
echo $model->view();
