<?php

declare(strict_types=1);

/**
 * Play an animated .ansi logo whose frames are separated by "\e[<H>A\r"
 * (lkAnim / cursor-up redraw). Sleeps between frames; total playback is
 * hard-capped below 5 s. Restores the cursor even if the file doesn't.
 *
 *   php logo-play.php <anim.ansi> [delayMs=100]
 */

$file = $argv[1] ?? exit("usage: php logo-play.php <anim.ansi> [delayMs=100]\n");
$delay = (int) ($argv[2] ?? 100);
$data = file_get_contents($file);
$frames = preg_split('/(?=\e\[\d+A\r)/', $data);
$delay = min($delay, intdiv(4800, max(1, count($frames))));
$t0 = microtime(true);
foreach ($frames as $i => $frame) {
    if ($i > 0) {
        usleep($delay * 1000);
    }
    fwrite(STDOUT, $frame);
    fflush(STDOUT);
}
if (!str_contains(end($frames), "\e[?25h")) {
    fwrite(STDOUT, "\e[?25h");
}
fprintf(STDERR, "%d frames, %.2fs\n", count($frames), microtime(true) - $t0);
