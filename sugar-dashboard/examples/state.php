<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\StatusBar\StatusIndicator;

/**
 * State display via StatusIndicator.
 *
 * This example previously referenced Layout\Grid\State — a class that has
 * never existed in this library (it came from a mis-templated generator).
 */
foreach ([StatusIndicator::online(), StatusIndicator::warning(), StatusIndicator::offline(), StatusIndicator::new('active')] as $indicator) {
    echo $indicator->render(), "\n";
}
echo "statuses: ", StatusIndicator::online()->status(), ", ", StatusIndicator::warning()->status(), ", ", StatusIndicator::offline()->status(), "\n";
