<?php

declare(strict_types=1);

/**
 * Timer — countdown formatting at four checkpoints, then a live
 * 5-second countdown.
 *
 *   php examples/timer.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bits\Timer\Timer;

foreach ([300.0, 60.0, 10.5, 0.0] as $remaining) {
    printf("  remaining = %6.2fs  →  %s\n", $remaining, Timer::format($remaining));
}

echo "\n  Live countdown from 5s:\n";
$start = microtime(true);
$total = 5.0;
while (true) {
    $left = $total - (microtime(true) - $start);
    if ($left <= 0) break;
    printf("\r  %s   ", Timer::format($left));
    usleep(100_000);
}
echo "\r  0:00   \n  done!\n";
