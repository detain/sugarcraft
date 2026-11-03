<?php

declare(strict_types=1);

/**
 * spawn-bash — Run a bash one-liner inside a PTY using the DI-friendly
 * {@see \SugarCraft\Pty\PtySystemFactory} entry point.
 *
 * The simplest end-to-end slice: open a PTY pair, spawn the child
 * against the slave, drain the master, reap, print.
 *
 *   1. PtySystemFactory::new() → PosixPtySystem on Linux/macOS
 *      (ConPtySystem on Windows, auto-selected).
 *   2. $system->open($cols, $rows) → PtyPair with master/slave.
 *   3. $pair->slave()->spawn(...) with controllingTerminal:true so
 *      Ctrl+C in interactive children reaches the right pgroup.
 *   4. Drain master in non-blocking mode while child runs.
 *   5. child->wait() + master->close().
 *
 * Usage:
 *   php examples/spawn-bash.php
 *   php examples/spawn-bash.php 'echo $TERM; uname -s; date'

 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Pty\PtySystemFactory;

$cmd = $argv[1] ?? 'echo "TERM=$TERM"; uname -s';

$system = PtySystemFactory::new();
$pair = $system->open(80, 24);
$master = $pair->master();
$slave = $pair->slave();

try {
    echo "slave path : {$slave->path()}\n";
    echo "spawn      : bash -c '{$cmd}'\n";
    echo "size       : 80x24\n\n";

    $child = $slave->spawn(
        ['/bin/bash', '-c', $cmd],
        [...(array) getenv(), 'TERM' => 'xterm-256color'], // $env REPLACES — spread what you keep
        80,
        24,
        controllingTerminal: true,
    );

    $captured = '';
    $deadline = microtime(true) + 5.0;

    while (microtime(true) < $deadline) {
        $chunk = $master->read(4096, 0.05);
        if ($chunk === null) {
            if ($child->exited()) {
                $tail = $master->read(8192);
                if ($tail !== null && $tail !== '') {
                    $captured .= $tail;
                }
                break;
            }
            continue;
        }
        if ($chunk === '') {
            break;
        }
        $captured .= $chunk;
    }

    $exit = $child->wait();
    echo "── output (exit {$exit}) ──\n{$captured}";
    exit($exit);
} finally {
    if (!$master->isClosed()) {
        $master->close();
    }
}
