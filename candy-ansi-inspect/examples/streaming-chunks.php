<?php

declare(strict_types=1);

/**
 * Feed a byte stream to StreamingInspector in chunks that split an escape
 * sequence in the middle. The inspector holds the half sequence until its
 * remaining bytes arrive, and finish() flushes whatever is still pending.
 *
 *   $ php examples/streaming-chunks.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\AnsiInspect\StreamingInspector;

// The first chunk ends inside the SGR sequence ESC[31m and the second chunk
// completes it, so feed() #1 returns no segments while feed() #2 and #3 each
// return some.
$chunks = [
    "ok \e[3",
    "1mred",
    "\e[0m done\e[",
];

$streaming = new StreamingInspector();
foreach ($chunks as $i => $chunk) {
    $segments = $streaming->feed($chunk);
    printf("feed #%d (%d bytes) -> %d segment(s)\n", $i + 1, strlen($chunk), count($segments));
    foreach ($segments as $segment) {
        echo '  ', $segment->describe(), "\n";
    }
}

// The trailing ESC[ never completes, so finish() reports it as a truncated
// sequence instead of dropping it.
$tail = $streaming->finish();
echo "finish() -> ", count($tail), " segment(s)\n";
foreach ($tail as $segment) {
    echo '  ', $segment->describe(), "\n";
}
