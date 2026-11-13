<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// scriptui-48 (candy-scriptui-134): the suite bounds real waits with timers
// on the shared Loop::get() — the -02 spin drain, the -10 exit watcher,
// TimeoutGuard legs. Wherever ext-uv is loaded, `Loop::get()` is an
// ExtUvLoop and its deadlines are computed against a clock refreshed once
// per loop iteration: a timer armed after synchronous idle (PHPUnit boot,
// fixture building) is ALREADY overdue at arm time, so safety bounds fire
// before the work they bound. The pinned stable clock restores
// StreamSelectLoop arm-time semantics without touching the loop instance
// identity the commands capture (scriptui-51). See SugarCraft\Testing\LoopPin
// for the mechanism.
//
// This call must stay FIRST to touch Loop::get() in the process — a later
// capture would hand out the unpinned loop.
\SugarCraft\Testing\LoopPin::pinStableClock();
