<?php

declare(strict_types=1);

/**
 * Stopwatch — start, tick for a few seconds, then show.
 *
 *   php examples/stopwatch.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bits\Stopwatch\Stopwatch;

use CandyCore\Bits\Timer\Timer;

// Stopwatch's view delegates to Timer::format(). Show what the
// rendered output looks like at a few elapsed-time milestones.
foreach ([0.0, 0.5, 1.5, 12.345, 90.0, 3725.5] as $elapsed) {
    printf("  elapsed = %8.3fs  →  %s\n", $elapsed, Timer::format($elapsed));
}

// And animate one running for ~5 seconds at 0.1s interval.
echo "\n  Live tick:\n";
$start = microtime(true);
while (microtime(true) - $start < 5) {
    printf("\r  %s   ", Timer::format(microtime(true) - $start));
    usleep(100_000);
}
echo "\n";

// Touch Stopwatch to show the underlying class is loadable.
Stopwatch::new();
