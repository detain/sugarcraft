<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Which physical button (or wheel direction) triggered a {@see Msg\MouseMsg}.
 *
 * `WheelUp` / `WheelDown` correspond to scroll-wheel events; `Backward` /
 * `Forward` are the side buttons present on five-button mice. `None` is
 * reported for motion-only events when no button is held.
 */
enum MouseButton: string
{
    case None      = 'none';
    case Left      = 'left';
    case Middle    = 'middle';
    case Right     = 'right';
    case WheelUp   = 'wheel_up';
    case WheelDown = 'wheel_down';
    case Backward  = 'backward';
    case Forward   = 'forward';
}
