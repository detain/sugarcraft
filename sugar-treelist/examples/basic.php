<?php

declare(strict_types=1);

/**
 * SugarTreelist basic usage — tree-style list with line numbers.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\TreeList\Model;

// Model::tree() = new() + the Default prefixer/suffixer pair (line-number
// join column and a visible cursor marker). Plain new() stays undecorated
// for custom-gutter consumers.
$model = Model::tree()->withViewport(80, 25);

$fruits = ['Apple', 'Banana', 'Cherry', 'Dragonfruit', 'Elderberry'];
// Every with*() returns a NEW Model — rebind or the step is lost.
// withItemsFromArray() is the batch door (one clone, one filter re-apply)
// and plain strings are first-class item values.
$model = $model->withItemsFromArray($fruits);

echo "=== SugarTreelist Demo (cursor on: Apple) ===\n";
echo $model->view();

// Move cursor down; reset the diff state so this demo paints a full frame
// under its own header instead of a positional delta.
$model = $model->withCursor(2);
$model->resetPreviousFrame();
echo "=== Cursor moved to Cherry ===\n";
echo $model->view();
