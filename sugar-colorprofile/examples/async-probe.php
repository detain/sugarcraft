<?php

/**
 * AsyncProbe demo — the loop contract in its post-CP-14 shape.
 *
 * Run: php examples/async-probe.php
 *
 * This exercises:
 * - AsyncProbe::colorProfile($loop) WITH an explicit loop: the child spawns
 *   on the loop you hand in, and only your run of it settles the promise.
 *   The react/event-loop singleton is never picked up implicitly (CP-14).
 * - AsyncProbe::colorProfile() with no loop at all: the promise resolves
 *   IN PLACE through the bounded sync face — that then() body executes
 *   before the loop runs a single tick, visible in the print order below.
 * - the honest settle contract: every path answers from the detection chain
 *   except the TrueColor promotion read from a Tc-declaring infocmp dump.
 *
 * The script exits as soon as both answers are in — far under the 5 s child
 * ceiling; no timeout path is exercised here (README covers the full
 * bounded-child story).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use SugarCraft\ColorProfile\AsyncProbe;

$loop = Loop::get();
$settled = 0;
$finish = static function () use ($loop, &$settled): void {
    if (++$settled === 2) {
        $loop->stop();
    }
};

$startedAt = microtime(true);

echo "=== 1) loopless call: resolves IN PLACE, before any tick ===\n";
AsyncProbe::colorProfile()->then(
    static function ($profile) use ($loop, $finish): void {
        printf(
            "  loopless answer : %s (printed while the loop has not ticked yet)\n",
            $profile->label(),
        );
        $finish();
    },
);

echo "=== 2) explicit-loop call: settles on the loop you passed ===\n";
echo "    (a child spawns only when the ladder answered Ansi/Ansi256;\n";
echo "     every other rung settles from the chain without one)\n";
AsyncProbe::colorProfile($loop)->then(
    static function ($profile) use ($startedAt, $finish): void {
        printf(
            "  explicit answer : %s after %.3f s of loop time\n",
            $profile->label(),
            microtime(true) - $startedAt,
        );
        $finish();
    },
);

echo "--- now calling Loop::run(); line 1 above already printed without it ---\n";
$loop->run();
echo "Done.\n";
