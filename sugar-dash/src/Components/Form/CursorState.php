<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Form;

/**
 * Cursor blinking states.
 */
enum CursorState
{
    case Visible;
    case Hidden;
    case Blink;
}
