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

// Architectural boundary (audit 090): this suite tests the
// env-contract as this library READS it — the SSH_USER_* /
// SSH_CONNECTION shapes below are the facts a ForceCommand child is
// handed, never claims about who wrote them. The wire codec — which
// variables sshd actually exports, how the auth dance names them —
// lives in sshd's and ForceCommand's design, outside this lib. A
// drift on a live sshd is therefore invisible here by construction,
// and that is the boundary, not a gap to chase; the optional
// localhost-sshd integration seam stays deliberately unbuilt (CI
// sshd availability unverified — a disclosed follow-up for whoever
// can prove the server is there).
