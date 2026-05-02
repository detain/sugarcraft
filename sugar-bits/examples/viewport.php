<?php

declare(strict_types=1);

/**
 * Viewport — render a scrollable region at three Y offsets.
 * Demonstrates the integrated scrollbar.
 *
 *   php examples/viewport.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bits\Viewport\Viewport;

$lines = [];
for ($i = 1; $i <= 30; $i++) {
    $lines[] = sprintf('Line %02d — %s',
        $i,
        str_repeat('▓░', 4));
}
$content = implode("\n", $lines);

$vp = Viewport::new(width: 40, height: 8)
    ->setContent($content)
    ->withScrollbar(true);

foreach ([0, 10, 22] as $offset) {
    printf("\x1b[36my-offset = %d\x1b[0m\n%s\n\n", $offset, $vp->setYOffset($offset)->view());
}
