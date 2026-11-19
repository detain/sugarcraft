<?php

declare(strict_types=1);

/**
 * The line-clear table, computed from the library rather than copied from the
 * README:
 *   php examples/scorecard.php < /dev/null
 *
 * It prints the NES base points for one to four lines at two levels, which
 * shows the level multiplier, and the gravity interval at the levels where the
 * speed changes. It needs no terminal and writes nothing to disk.
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Tetris\Score;

printf("%-8s %-10s %-10s\n", 'lines', 'level 0', 'level 9');
for ($lines = 1; $lines <= 4; $lines++) {
    $at0 = (new Score(points: 0, lines: 0, level: 0))->withLines($lines)->points;
    $at9 = (new Score(points: 0, lines: 0, level: 9))->withLines($lines)->points;
    printf("%-8d %-10d %-10d\n", $lines, $at0, $at9);
}

echo "\ngravity (frames per row):\n";
foreach ([0, 9, 29] as $level) {
    printf("  level %-3d %d\n", $level, (new Score(points: 0, lines: 0, level: $level))->framesPerRow());
}
