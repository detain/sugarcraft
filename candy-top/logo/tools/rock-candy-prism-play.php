<?php

declare(strict_types=1);

/**
 * Play an animated .ansi logo whose frames are separated by "\e[<N>A\r"
 * (cursor-up redraw). Sleeps between frames so total playback ≤ 5 s.
 *
 *   php rock-candy-prism-play.php <anim.ansi> [delayMs=110]
 */

$file = $argv[1] ?? exit("usage: play <anim.ansi> [delayMs]\n");
$delay = (int) ($argv[2] ?? 110);
$data = file_get_contents($file);
$parts = preg_split('/(?=\e\[\d+A\r)/', $data);
$delay = min($delay, intdiv(5000, max(1, count($parts))));  // hard 5 s cap
foreach ($parts as $i => $frame) {
    if ($i > 0) {
        usleep($delay * 1000);
    }
    fwrite(STDOUT, $frame);
    fflush(STDOUT);
}
if (!str_contains(end($parts), "\e[?25h")) {
    fwrite(STDOUT, "\e[?25h");
}
