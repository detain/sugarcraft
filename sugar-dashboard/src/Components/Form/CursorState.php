<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Form;

/**
 * Cursor blinking states.
 */
enum CursorState
{
    case Visible;
    case Hidden;
    case Blink;
}
