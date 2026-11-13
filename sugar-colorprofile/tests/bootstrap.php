<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// CP-01: the shared-loop clock pin mandated by the fleet test law
// (AGENTS.md "LoopPin::pinStableClock() … whenever a suite bounds waits with
// timers on Loop::get()"). Since CP-14 made AsyncProbe's loop contract
// explicit, every timer in this suite is armed on a private StreamSelectLoop
// created by the test itself — never on Loop::get() — so the ext-uv
// once-per-iteration clock staleness cannot bite today. The pin is preventive:
// it fixes the clock for any future first-party code or test that touches
// Loop::get() FIRST, which is the exact ordering the hazard requires (a pin
// installed after the singleton exists is already too late on ext-uv hosts).
//
// The guard keeps the local gate honest without a composer install: CI injects
// the full require-dev closure (sugarcraft/sugar-testing declared in
// composer.json), where pinStableClock() runs unconditionally; a bare dev
// checkout that never installed the sibling falls through to the autoload
// bootstrap with identical behavior to this file's absence.
if (\class_exists(\SugarCraft\Testing\LoopPin::class)) {
    \SugarCraft\Testing\LoopPin::pinStableClock();
}
