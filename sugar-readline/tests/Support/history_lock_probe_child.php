<?php

declare(strict_types=1);

/**
 * Child-process fixture for FileHistoryTest's cross-process write-lock probe.
 *
 * Modes:
 *  - hold <file> <line> : take the sidecar write lock, print READY, sit on it
 *    for ~400 ms, release, then push <line> through a real FileHistory.
 *  - push <file> <line> : push <line> through a real FileHistory immediately.
 *
 * The parent spawns "hold" first, waits for READY, then spawns "push" and
 * times it. With the sidecar lock honored the push child blocks on the
 * lock and its wall time exceeds the hold sleep; with the lock removed the
 * push child finishes in milliseconds.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Readline\History\FileHistory;

$mode = $argv[1] ?? '';
$file = $argv[2] ?? '';
$line = $argv[3] ?? '';

if ($mode === 'hold') {
    $lock = fopen($file . '.lock', 'c');
    if ($lock === false) {
        fwrite(STDERR, "cannot open lock\n");
        exit(1);
    }
    if (!flock($lock, LOCK_EX)) {
        fwrite(STDERR, "cannot take lock\n");
        exit(1);
    }
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    usleep(400_000);
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($mode !== 'hold' && $mode !== 'push') {
    fwrite(STDERR, "unknown mode\n");
    exit(1);
}

(new FileHistory($file))->push($line);
fwrite(STDOUT, "DONE\n");
