<?php

declare(strict_types=1);

/**
 * CandyLister basic usage — tree-style list with line numbers.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Lister\{Model, StringItem, DefaultPrefixer, DefaultSuffixer};

$model = Model::new()
    ->setViewport(80, 25)
    ->setCursorOffset(5)
    ->setPrefixer(new DefaultPrefixer())
    ->setSuffixer(new DefaultSuffixer());

$fruits = ['Apple', 'Banana', 'Cherry', 'Dragonfruit', 'Elderberry'];
foreach ($fruits as $f) {
    $model->addItem(new StringItem($f));
}

echo "=== CandyLister Demo (cursor on: Apple) ===\n";
echo $model->View();

// Move cursor down
$model->setCursor(2);
echo "=== Cursor moved to Cherry ===\n";
echo $model->View();
