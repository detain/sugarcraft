<?php

declare(strict_types=1);

namespace SugarCraft\Top\Msg;

use SugarCraft\Core\Msg;

/**
 * The once-per-second clock tick, carrying the wall time it was produced
 * at (read inside the Cmd, so update() and view() stay pure) and the
 * system uptime for the `/uptime` clock token.
 */
final class ClockTickMsg implements Msg
{
    public function __construct(
        public readonly float $time,
        public readonly float $uptime = 0.0,
    ) {
    }
}
