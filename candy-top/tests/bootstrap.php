<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// House rule for suites on the ReactPHP loop: pin the loop clock before
// anything touches Loop::get(). Under ext-uv a timer armed after synchronous
// idle is computed against a clock refreshed once per iteration and fires
// early, so a timer-bounded wait (later phases: proc scans, input timing)
// would flake. See \SugarCraft\Testing\LoopPin.
//
// candy-testing is a require-dev, but a vendor/ installed before it was added
// does not carry it yet — `php scripts/refresh-deps.php --mode=linked
// --libs=candy-top` wires it. Until then the suite (which arms no loop timers
// today) runs unpinned rather than fataling.
if (class_exists(\SugarCraft\Testing\LoopPin::class)) {
    \SugarCraft\Testing\LoopPin::pinStableClock();
}
