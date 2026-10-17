<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * DECSCUSR cursor-shape values used by {@see View::$cursor} and the
 * {@see \SugarCraft\Core\Util\Ansi::cursorShape()} helper.
 */
enum CursorShape: int
{
    case Block     = 2;
    case Underline = 4;
    case Bar       = 6;
}
