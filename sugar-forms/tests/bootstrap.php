<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// sugar-forms-009 (SF-10): this suite bounds its waits with timers armed on
// the shared Loop::get() — the async-suggestions debounce/cancel legs
// (AsyncSuggestionsTest, AsyncSuggestionCancelTimeoutTest) arm 0.01s–0.5s
// debounce and fetch-timeout timers exactly like sugar-async's AsyncOpsTest.
// Wherever ext-uv is loaded, Loop::get() returns an ExtUvLoop whose clock is
// refreshed once per loop iteration: a timer armed while that clock is N
// seconds stale is already overdue by N, so arm-time deadlines stop meaning
// what the test thinks they mean, and a timeout armed after synchronous idle
// can detonate before the work it guards has started. Pinning the stable
// clock FIRST (this file is the first thing to touch Loop::get()) makes every
// deadline a real arm-time deadline under either loop implementation.
//
// HangWatchdog (the sugar-pty/tests/bootstrap.php sibling) is deliberately
// absent: this suite never drives PosixPump/MultiPump loops — nothing here can
// only-hang-never-fail, so a forensic SIGKILL bound would guard a shape that
// does not exist. Per-test Loop::set swaps compose with this pin and stay.
//
// See \SugarCraft\Testing\LoopPin for the mechanism.
\SugarCraft\Testing\LoopPin::pinStableClock();