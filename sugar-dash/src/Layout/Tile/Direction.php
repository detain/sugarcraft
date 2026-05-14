<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Tile;

/**
 * Direction for tile layout arrangement.
 */
enum Direction: string
{
    case Horizontal = 'horizontal';
    case Vertical = 'vertical';
}
