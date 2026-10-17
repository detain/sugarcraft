<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Input;

use SugarCraft\Core\Msg;

/**
 * A tick message for deterministic testing.
 *
 * Bubbletea's runtime fires tick messages on a timer to drive subscription
 * handlers. In the test harness we replace the runtime tick with this
 * named value object so models can match on it via `instanceof` instead of
 * relying on anonymous class identity.
 *
 * @final
 * @readonly
 * @implements Msg
 */
final readonly class TickMsg implements Msg
{
    /**
     * @param float $seconds The interval this tick represents
     */
    public function __construct(
        public float $seconds,
    ) {}
}
