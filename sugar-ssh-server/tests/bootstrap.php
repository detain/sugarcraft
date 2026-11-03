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
// AGENTS.md "Tests" for the family law (sugar-async, sugar-image,
// sugar-pty, sugar-testing, candy-code all pin the same way).
\SugarCraft\Testing\LoopPin::pinStableClock();

// Logger::auto() resolves its sink from SSH_SERVER_LOG (see
// src/Middleware/Logger.php). The suite must not depend on what the
// developer's shell happens to export: with the variable cleared here,
// `new Logger()` in any test means the stderr fallback, and a test that
// wants the file branch sets the variable itself (LoggerTest does, and
// restores it).
putenv('SSH_SERVER_LOG');
unset($_ENV['SSH_SERVER_LOG'], $_SERVER['SSH_SERVER_LOG']);
