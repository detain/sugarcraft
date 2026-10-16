<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout\Tile;

/**
 * Direction for tile layout arrangement.
 */
enum Direction: string
{
    case Horizontal = 'horizontal';
    case Vertical = 'vertical';
}
