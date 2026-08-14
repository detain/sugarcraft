<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// candy-testing uses its own tool. Several tests here construct a Program,
// which reaches Loop::get() in the constructor; without a pin, whichever loop
// autodetection picks up owns the process. Today those Programs are never
// run(), so the loop they touch carries no watchers and nothing breaks — but
// that is order-dependent luck, not a property, and this suite is the one place
// that has no excuse for relying on it.
\SugarCraft\Testing\LoopPin::pinStableClock();
