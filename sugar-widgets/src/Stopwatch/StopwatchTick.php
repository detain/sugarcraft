<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Stopwatch;

use SugarCraft\Core\Msg;

/**
 * Periodic count-up tick for the Stopwatch with id {@see $id}.
 *
 * Named for its owning widget (sugar-widgets-013) — see
 * {@see \SugarCraft\Widgets\Timer\TimerTick} for the collision story.
 *
 * {@see $epoch} disambiguates start()/stop() generations: start() bumps the
 * epoch, so in-flight ticks armed by a stopped chain can never count toward
 * the restarted one (M12). Legacy ticks carry epoch 0.
 */
final class StopwatchTick implements Msg
{
    public function __construct(
        public readonly int $id,
        public readonly int $epoch = 0,
    ) {}
}
