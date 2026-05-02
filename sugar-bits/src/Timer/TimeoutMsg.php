<?php

declare(strict_types=1);

namespace CandyCore\Bits\Timer;

use CandyCore\Core\Msg;

/**
 * Emitted exactly once when a Timer's remaining time reaches zero.
 * The timer also stops itself.
 */
final class TimeoutMsg implements Msg
{
    public function __construct(public readonly int $id) {}
}
