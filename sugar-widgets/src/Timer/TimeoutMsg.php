<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Timer;

use SugarCraft\Core\Msg;

/**
 * Emitted exactly once when a Timer's remaining time reaches zero.
 * The timer also stops itself.
 */
final class TimeoutMsg implements Msg
{
    public function __construct(public readonly int $id) {}
}
