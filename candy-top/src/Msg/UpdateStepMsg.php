<?php

declare(strict_types=1);

namespace SugarCraft\Top\Msg;

use SugarCraft\Core\Msg;

/**
 * A `+`/`-` update_ms step, timestamped inside a Cmd so App::update() never
 * reads a clock. `$held` carries btop's "the whole input history is this
 * key" test, captured when the key arrived; `$at` (seconds) feeds btop's
 * 200 ms `last_press` window. Together they pick the 100 ms or the 1000 ms
 * step — Input::process cpu-box actions, btop_input.cpp.
 */
final class UpdateStepMsg implements Msg
{
    public function __construct(
        public readonly int $direction,
        public readonly bool $held,
        public readonly float $at,
    ) {
    }
}
