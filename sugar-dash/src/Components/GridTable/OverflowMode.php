<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Overflow handling for data grid tables.
 *
 * Defines behavior when content exceeds cell boundaries.
 */
enum OverflowMode
{
    case Truncate;
    case Wrap;
    case Ellipsis;
}
