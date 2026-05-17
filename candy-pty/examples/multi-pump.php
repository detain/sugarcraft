<?php

declare(strict_types=1);

/**
 * multi-pump — Demux output from multiple PTY shells into a single
 * terminal using {@see \SugarCraft\Pty\Posix\MultiPump}.
 *
 * Spawns two interactive bash sessions side-by-side, tee-ing each
 * master to stdout with a session prefix.  Quit on Ctrl-C.
 *
 * Usage:
 *   php examples/multi-pump.php
 *   php examples/multi-pump.php 'echo one' 'echo two'   # custom commands
 *
 * This demonstrates the "split-pane viewer" use-case from the
 * MultiPump class doc — a host process multiplexing several child
 * outputs without a thread-per-pane or per-pane polling.
 *
 * @see \SugarCraft\Pty\Posix\MultiPump
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Pty\Posix\MultiPump;

// ---- CLI arguments -------------------------------------------------
$commands = $argv[1] ?? null
    ? array_slice($argv, 1)
    : ['echo "--- shell A ---"; sleep 999', 'echo "--- shell B ---"; sleep 999'];

while (\count($commands) < 2) {
    $commands[] = 'sleep 999';
}

// ---- PTY setup ----------------------------------------------------
$system = PtySystemFactory::default();

$sessions = [];
$mp = new MultiPump();

// Register each command as its own session.
foreach ($commands as $idx => $cmd) {
    $pair = $system->open(80, 24);
    $master = $pair->master();

    // Wrap STDOUT so each session gets a visible prefix tag.
    // In a real split-pane viewer this would be a per-pane screen buffer.
    $prefix = "[session-{$idx}] ";
    $sink = fopen('php://memory', 'w+b');
    if ($sink === false) {
        throw new \RuntimeException("fopen php://memory failed");
    }

    $child = $pair->slave()->spawn(
        ['/bin/bash', '-c', $cmd],
        ['TERM' => 'xterm-256color', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        80,
        24,
        controllingTerminal: true,
    );

    $id = $mp->add($master, $sink, $child);

    // Tag each sink with its prefix so the teed output is readable.
    // We store the prefix inline in the sink's wrapped output below.
    $sessions[$id] = [
        'prefix' => $prefix,
        'sink' => $sink,
        'master' => $master,
        'child' => $child,
        'started' => false,
    ];
}

echo "MultiPump running — {$mp->size()} sessions\n";
echo "Press Ctrl+C to stop\n\n";

// ---- Register SIGINT handler so Ctrl+C stops cleanly --------------
$running = true;
if (\function_exists('pcntl_signal')) {
    \pcntl_async_signals(true);
    \pcntl_signal(\SIGINT, function () use (&$running): void {
        $running = false;
    });
}

// ---- Pump loop with per-tick stdout teeing -------------------------
// We use tick() so the caller drives the loop — no tick-per-session
// thread.  After each tick we drain the per-session sink buffers and
// prepend the session prefix for visibility.
while ($running && !$mp->allDone()) {
    $drained = $mp->tick();

    if ($drained > 0) {
        foreach ($sessions as $id => &$s) {
            if (!isset($s['sink'])) {
                continue;
            }
            \fflush($s['sink']);
            $pos = ftell($s['sink']);
            \rewind($s['sink']);
            $chunk = $pos > 0 ? (string) stream_get_contents($s['sink']) : '';
            \rewind($s['sink']);
            if ($chunk !== '') {
                echo $s['prefix'] . \str_replace("\n", "\n" . $s['prefix'], rtrim($chunk)) . "\n";
            }
        }
        unset($s);
    }

    if ($drained === 0 && $mp->size() > 0) {
        // No sessions ready — sleep briefly so the loop does not spin.
        \usleep(20_000); // 20 ms
    }
}

// ---- Teardown ------------------------------------------------------
echo "\nMultiPump done — exiting.\n";

foreach ($sessions as $s) {
    if (isset($s['child']) && !$s['child']->exited()) {
        $s['child']->kill(\SIGTERM);
        $s['child']->wait();
    }
    if (isset($s['master']) && !$s['master']->isClosed()) {
        $s['master']->close();
    }
    if (isset($s['sink']) && \is_resource($s['sink'])) {
        @\fclose($s['sink']);
    }
}
