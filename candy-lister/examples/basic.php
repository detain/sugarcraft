<?php

declare(strict_types=1);

/**
 * CandyLister basic usage — tree-style list with line numbers.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Lister\{Model, StringItem, DefaultPrefixer, DefaultSuffixer};

$model = Model::new()
    ->setViewport(80, 25)
    ->setCursorOffset(5)
    ->setPrefixer(new DefaultPrefixer())
    ->setSuffixer(new DefaultSuffixer());

$fruits = ['Apple', 'Banana', 'Cherry', 'Dragonfruit', 'Elderberry'];
foreach ($fruits as $f) {
    // Fluent setters return NEW models — rebind or the item is lost.
    $model = $model->addItem(new StringItem($f));
}

echo "=== CandyLister Demo (cursor on: Apple) ===\n";
echo $model->view();

// Move cursor down; reset the diff state so this demo paints a full frame
// under its own header instead of a positional delta.
$model = $model->setCursor(2);
$model->resetPreviousFrame();
echo "=== Cursor moved to Cherry ===\n";
echo $model->view();
