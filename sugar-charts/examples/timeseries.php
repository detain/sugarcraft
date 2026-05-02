<?php

declare(strict_types=1);

/**
 * TimeSeries — line chart over a (timestamp, value) series.
 *
 *   php examples/timeseries.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Charts\LineChart\TimeSeries;

// Hourly samples for the past 24 hours.
$points = [];
$start = new \DateTimeImmutable('2026-05-02 00:00:00');
for ($h = 0; $h <= 23; $h++) {
    $points[] = [
        $start->modify("+{$h} hours"),
        50 + sin($h * 0.3) * 25 + (mt_rand(0, 8) - 4),
    ];
}

echo TimeSeries::new($points, width: 60, height: 12)->view() . "\n";
