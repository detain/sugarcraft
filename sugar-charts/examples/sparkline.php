<?php

declare(strict_types=1);

/**
 * Sparkline — compact visualisation of a 1D series.
 *
 *   php examples/sparkline.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Charts\Sparkline\Sparkline;

$values = [];
for ($i = 0; $i < 60; $i++) {
    $values[] = sin($i * 0.2) * 50 + 50 + cos($i * 0.5) * 10;
}

echo Sparkline::new($values, 60)->withMin(0.0)->withMax(110.0)->view() . "\n";
