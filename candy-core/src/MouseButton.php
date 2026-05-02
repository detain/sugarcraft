<?php

declare(strict_types=1);

namespace CandyCore\Core;

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
