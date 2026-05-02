<?php

declare(strict_types=1);

namespace CandyCore\Bits\Timer;

use CandyCore\Core\Msg;

/**
 * Internal signal Timer/Stopwatch dispatch when their running flag flips,
 * useful for Models that want to react to start/stop events.
 */
final class StartStopMsg implements Msg
{
    public function __construct(
        public readonly int $id,
        public readonly bool $running,
    ) {}
}
