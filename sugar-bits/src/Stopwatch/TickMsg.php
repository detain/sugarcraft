<?php

declare(strict_types=1);

namespace CandyCore\Bits\Stopwatch;

use CandyCore\Core\Msg;

/** Periodic count-up tick for the Stopwatch with id {@see $id}. */
final class TickMsg implements Msg
{
    public function __construct(public readonly int $id) {}
}
