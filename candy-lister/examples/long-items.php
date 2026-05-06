<?php

declare(strict_types=1);

/**
 * CandyLister — long items with word wrapping.
 *
 * Run: php examples/long-items.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Lister\{Model, StringItem, DefaultPrefixer, DefaultSuffixer};

$model = Model::new()
    ->setViewport(60, 30)    // narrow viewport to trigger wrapping
    ->setCursorOffset(5)
    ->setPrefixer(new DefaultPrefixer())
    ->setSuffixer(new DefaultSuffixer());

$descriptions = [
    new StringItem('Short item'),
    new StringItem('This is a much longer item that will need to wrap across multiple lines when rendered in the viewport.'),
    new StringItem('Another moderately long description that should wrap at least once in the given viewport width.'),
    new StringItem('Brief'),
];

foreach ($descriptions as $d) {
    $model->addItem($d);
}

echo "=== Word-wrap demo (viewport: 60 cols) ===\n";
echo $model->View();
