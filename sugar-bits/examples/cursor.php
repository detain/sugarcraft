<?php

declare(strict_types=1);

/**
 * Cursor — render the cursor in each of its three modes
 * (Blink / Static / Hidden) over a sample character.
 *
 *   php examples/cursor.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bits\Cursor\Cursor;
use CandyCore\Bits\Cursor\Mode;

foreach ([Mode::Blink, Mode::Static, Mode::Hidden] as $mode) {
    $c = Cursor::new('|', $mode);
    printf("  \x1b[36m%-7s\x1b[0m %s\n", $mode->value, $c->view());
}

echo "\n  Blink animation (toggling every 0.5s):\n";
$c = Cursor::new('|', Mode::Blink);
for ($i = 0; $i < 6; $i++) {
    printf("\r  %s", $i % 2 === 0 ? $c->view() : '   ');
    usleep(500_000);
}
echo "\n";
