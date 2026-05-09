<?php

declare(strict_types=1);

/**
 * pump-output — Spawn a long-running command and pump its output
 * line-by-line through the master end, demonstrating non-blocking
 * read with timeout.
 *
 * Streams `seq 1 N` (or any user-supplied counter command) through
 * the PTY. Each line is read off the master in non-blocking mode
 * and printed immediately, so the example doubles as a smoke test
 * for the read-with-timeout path that PR4 introduced.
 *
 * Usage:
 *   php examples/pump-output.php
 *   php examples/pump-output.php 20         # count to 20
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Pty\Pty;

$count = (int) ($argv[1] ?? 5);
if ($count < 1 || $count > 1000) {
    fwrite(STDERR, "usage: php pump-output.php [count 1..1000]\n");
    exit(1);
}

$pty = Pty::open();
try {
    // sleep 0.05 between each line so the pump loop has work to do
    // — without it, `seq` finishes faster than we can demonstrate.
    $script = "for i in \$(seq 1 {$count}); do echo \"tick \$i\"; sleep 0.05; done";

    $child = $pty->spawn(
        ['/bin/sh', '-c', $script],
        null,
        80,
        24,
    );

    $pty->setBlocking(false);
    $linesPrinted = 0;
    $buffer = '';
    $deadline = microtime(true) + 30.0;

    while (microtime(true) < $deadline) {
        $chunk = $pty->read(4096, 0.1);
        if ($chunk === null) {
            // Idle 100ms — check if the child has exited.
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
    $pty->close();
}
