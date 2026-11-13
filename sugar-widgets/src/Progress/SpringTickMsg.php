<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Progress;

use SugarCraft\Core\Msg;

/**
 * Tick dispatched by {@see AnimatedProgress::setPercentWithCmd()} (and
 * re-issued by the in-flight chain). Each tick
 * advances the spring integrator; the model re-issues the Cmd until the
 * bar settles.
 *
 * Carries the destination bar's `id` and generation `epoch` (the same
 * routing identity Timer/Stopwatch ticks hold): `AnimatedProgress::update()`
 * ignores ticks addressed to another instance or armed before the current
 * transition, so N animated bars on one event loop advance only their own
 * chain.
 */
final class SpringTickMsg implements Msg
{
    public function __construct(
        public readonly int $id = 0,
        public readonly int $epoch = 0,
    ) {
    }
}
