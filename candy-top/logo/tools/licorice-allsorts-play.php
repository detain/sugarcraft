<?php

declare(strict_types=1);

/**
 * Play a multi-frame .ansi logo (frames joined by "\e[<H>A\r" cursor-up) with a delay,
 * hard-capped so the whole playback stays within 5 seconds.
 * Usage: php licorice-allsorts-play.php file.ansi [ms-per-frame=80]
 */

$data = file_get_contents($argv[1] ?? '');
$ms = (int) ($argv[2] ?? 80);
$parts = preg_split('/(\e\[\d+A\r)/', $data, -1, PREG_SPLIT_DELIM_CAPTURE);
$frames = (int) ceil(count($parts) / 2);
$ms = min($ms, intdiv(4800, max(1, $frames)));   // total budget < 5s
foreach ($parts as $i => $chunk) {
    echo $chunk;
    if ($i % 2 === 0 && $i < count($parts) - 1) {
        fflush(STDOUT);
        usleep($ms * 1000);
    }
}
