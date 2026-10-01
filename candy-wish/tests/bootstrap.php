<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// The suite bounds waits with timers armed on the shared Loop::get():
// AsyncMiddlewareTest arms a 0.001s resolve timer, PromiseAwaitTest arms
// 0.001s timers on the loop it fetches from the registry, and production
// PromiseAwait::settle drives Loop::run() itself. Wherever ext-uv is
// installed those deadlines are computed against a clock refreshed only
// once per loop iteration, so a timer armed after a stretch of
// synchronous idle (PHPUnit between tests) is already overdue when it
// is armed — the safety-net bound fires before the work it bounds.
//
// pinStableClock() touches Loop::get() once at bootstrap, before any
// test runs, so the loop's clock is fresh when the first timer in each
// test goes in. See \SugarCraft\Testing\LoopPin for the mechanism and
// AGENTS.md "Tests" for the family law (candy-async, candy-mosaic,
// candy-pty, candy-testing, sugar-crush all pin the same way).
\SugarCraft\Testing\LoopPin::pinStableClock();
