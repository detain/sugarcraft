<?php

declare(strict_types=1);

// The suite-wide entry point (phpunit.xml bootstrap, SQL-P12/adminpages-014).
// Loading the autoloader was only half the contract: the admin timer suites
// bound waits on the SHARED Loop::get(), and ext-uv computes timer deadlines
// against a clock refreshed once per loop ITERATION — a timer armed after a
// long synchronous idle is already overdue. pinStableClock() (sugar-testing,
// AGENTS.md LoopPin law; sugar-async/sugar-image precedent) swaps in the
// StreamSelect arm-time semantics those waits expect.
//
// The class_exists guard is honest disclosure, not a shortcut: sugar-testing
// is now a require-dev of this lib, but a lane-local vendor tree predating
// the dependency bump (CI re-injects path-repos at install; lanes never run
// composer) simply lacks the file. Missing pin there costs only the flake
// insurance; the rewritten timer legs carry fresh, file-scoped loops anyway,
// so the suite's determinism never DEPENDS on this branch. On CI and on any
// refreshed vendor the pin is live.
require __DIR__ . '/../vendor/autoload.php';

if (\class_exists(\SugarCraft\Testing\LoopPin::class)) {
    \SugarCraft\Testing\LoopPin::pinStableClock();
}
