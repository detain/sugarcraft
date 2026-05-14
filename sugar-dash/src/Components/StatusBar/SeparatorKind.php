<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\StatusBar;

/**
 * Separator kinds for status bar menus.
 */
enum SeparatorKind: string
{
    case Line = '─';
    case Dotted = '·';
    case Dashed = '–';
    case Double = '=';
}
