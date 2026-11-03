<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Timer;

use SugarCraft\Core\Msg;

/**
 * Periodic countdown tick for the Timer with id {@see $id}.
 *
 * The {@see $epoch} disambiguates generations: start() bumps it, so a
 * in-flight tick from a stopped chain can never count toward a timer
 * that was stopped and restarted within one tick window (M12).
 * Ticks minted before the epoch existed carry 0 and still match a
 * never-restarted timer.
 */
final class TickMsg implements Msg
{
    public function __construct(
        public readonly int $id,
        public readonly int $epoch = 0,
    ) {}
}
