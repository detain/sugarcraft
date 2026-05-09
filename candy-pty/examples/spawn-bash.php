<?php

declare(strict_types=1);

/**
 * spawn-bash — Run a bash one-liner inside a PTY and stream its
 * output back through the master end.
 *
 * Demonstrates the full spawn → wait → drain cycle:
 *  1. Open a 100×30 PTY pair.
 *  2. Spawn `bash -c '<one-liner>'` with stdio wired to the slave.
 *  3. Drain the master end in non-blocking mode while the child is
 *     still running, accumulating into a buffer.
 *  4. Reap the child via wait() and print the buffer + exit code.
 *
 * Caveat — TIOCSCTTY is NOT yet wired (tracked for the candy-wish
 * in-process upgrade), so do NOT use this pattern with INTERACTIVE
 * commands like `bash -i` or `vim` that depend on Ctrl+C reaching
 * the child via SIGINT — it won't. Non-interactive `bash -c '...'`
 * pipelines work cleanly.
 *
 * Usage:
 *   php examples/spawn-bash.php
 *   php examples/spawn-bash.php 'echo $TERM; uname -s; date'
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Pty\Pty;

$cmd = $argv[1] ?? 'echo "TERM=$TERM"; uname -s; date';

$pty = Pty::open();
try {
    echo "slave path : {$pty->master->slavePath}\n";
    echo "spawn      : bash -c '{$cmd}'\n";
    echo "size       : 100x30\n\n";

    $child = $pty->spawn(
        ['/bin/bash', '-c', $cmd],
        ['TERM' => 'xterm-256color', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        100,
        30,
    );

    $pty->setBlocking(false);
    $captured = '';
    $deadline = microtime(true) + 5.0;

    while (microtime(true) < $deadline) {
        $chunk = $pty->read(4096, 0.05);
        if ($chunk === null) {
            // Timeout — check if child has exited and we should drain
            // any final bytes before bailing.
            if ($child->exited()) {
                $tail = $pty->read(8192);
                if ($tail !== null && $tail !== '') {
                    $captured .= $tail;
                }
                break;
            }
            continue;
        }
        if ($chunk === '') {
            break; // EOF
        }
        $captured .= $chunk;
    }

    $exit = $child->wait();
    echo "── output (exit {$exit}) ──\n{$captured}";
} finally {
    $pty->close();
}
