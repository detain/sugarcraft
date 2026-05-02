<?php

declare(strict_types=1);

namespace CandyCore\Bits\Spinner;

use CandyCore\Core\Msg;

/**
 * Animation tick for a specific spinner instance, identified by {@see $id}.
 * Each Spinner ignores ticks for other ids so multiple spinners can share
 * an event loop without cross-talk.
 */
final class TickMsg implements Msg
{
    public function __construct(public readonly int $id) {}
}
