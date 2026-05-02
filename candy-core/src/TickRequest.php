<?php

declare(strict_types=1);

namespace CandyCore\Core;

/**
 * Internal sentinel returned by {@see Cmd::tick()}. The Program intercepts
 * it, schedules the loop timer, and dispatches the produced Msg when the
 * delay elapses. Not part of the user-facing Msg surface.
 *
 * @internal
 */
final class TickRequest implements Msg
{
    /** @param \Closure():?Msg $produce */
    public function __construct(
        public readonly float $seconds,
        public readonly \Closure $produce,
    ) {}
}
