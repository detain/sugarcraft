<?php

declare(strict_types=1);

/**
 * Streamline — a simple line chart over a numeric series.
 *
 *   php examples/line.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Charts\LineChart\Streamline;

$line = Streamline::new(width: 60, height: 12);
for ($i = 0; $i < 80; $i++) {
    $v = sin($i * 0.15) * 50 + 50 + cos($i * 0.07) * 15;
    $line = $line->push($v);
}
echo $line->view() . "\n";
