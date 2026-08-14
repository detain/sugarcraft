<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// PtyPoolReactLoopTest and SignalForwarderReactLoopTest bound their waits with
// a timer armed on the shared Loop::get(), which is ExtUvLoop wherever ext-uv
// is installed and therefore computes deadlines against a clock refreshed only
// once per loop iteration.
//
// The demonstrably exposed one is PtyPoolReactLoopTest::testRapidCycleInside-
// LoopDoesNotLeakSignals. Its 5s cap is armed after the 0.01s periodic that
// does the work, so a sooner-due handle ends the first poll and the periodic
// gets exactly one tick before the cap stops the loop — the assertion fails on
// `iterations == 1`, not on zero work. Injecting staleness ahead of it flips it
// between 4.5s (passes) and 4.8s (fails) here: the 5s cap less the ~0.2s the 20
// iterations need.
//
// It survives today only because it is the FIRST thing in the whole suite to
// touch Loop::get() — it constructs the loop, so the clock is fresh — and that
// is a property of file ordering, not of the test. SignalForwarderReactLoopTest
// shares the loop and is covered too, but is not itself exposed: it measured
// green at 30s of injected staleness in both shapes, because its assertion is
// already satisfied by synchronous pcntl dispatch before run() is entered.
//
// Pinning removes the dependency on that ordering. See \SugarCraft\Testing\LoopPin.
//
// The per-test `new StreamSelectLoop()` in tests/Posix/ is a different thing
// and stays: those tests need loop ISOLATION so a leaked stream or timer is
// detectable, and they get clock freshness as a side effect.
\SugarCraft\Testing\LoopPin::pinStableClock();
