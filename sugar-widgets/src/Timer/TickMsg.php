<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Timer;

use SugarCraft\Core\Msg;

/** Periodic countdown tick for the Timer with id {@see $id}. */
final class TickMsg implements Msg
{
    public function __construct(public readonly int $id) {}
}
