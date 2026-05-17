<?php

declare(strict_types=1);

/**
 * pump-output — Spawn a long-running command and pump its output
 * line-by-line through the master end, demonstrating non-blocking
 * read with timeout.
 *
 * Uses the {@see \SugarCraft\Pty\PtySystemFactory} DI-friendly entry
 * point — same shape as `spawn-bash.php` but with a timed read loop
 * to exercise `MasterPty::read()`'s timeout argument.
 *
 * Usage:
 *   php examples/pump-output.php
 *   php examples/pump-output.php 20         # count to 20
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Pty\PtySystemFactory;

$count = (int) ($argv[1] ?? 5);
if ($count < 1 || $count > 1000) {
    fwrite(STDERR, "usage: php pump-output.php [count 1..1000]\n");
    exit(1);
}

$pair = PtySystemFactory::default()->open(80, 24);
$master = $pair->master();
$slave = $pair->slave();

try {
    // sleep 0.05 between each line so the pump loop has work to do
    // — without it, `seq` finishes faster than we can demonstrate.
    $script = "for i in \$(seq 1 {$count}); do echo \"tick \$i\"; sleep 0.05; done";

    $child = $slave->spawn(
        ['/bin/sh', '-c', $script],
        null,
        80,
        24,
    );

    stream_set_blocking($master->stream(), false);
    $linesPrinted = 0;
    $buffer = '';
    $deadline = microtime(true) + 30.0;

    while (microtime(true) < $deadline) {
        $chunk = $master->read(4096, 0.1);
        if ($chunk === null) {
            if ($child->exited()) {
                break;
            }
            continue;
        }
        if ($chunk === '') {
            break; // EOF
        }
        $buffer .= $chunk;

        // Emit every complete line we have buffered. PTY slave is in
        // cooked mode by default so newlines come back as \r\n.
        while (($eol = strpos($buffer, "\n")) !== false) {
            $line = rtrim(substr($buffer, 0, $eol), "\r\n");
            $buffer = substr($buffer, $eol + 1);
            $linesPrinted++;
            echo "[{$linesPrinted}] {$line}\n";
        }
    }

    $child->wait();
    echo "── done — {$linesPrinted} lines pumped ──\n";
} finally {
    if (!$master->isClosed()) {
        $master->close();
    }
}
