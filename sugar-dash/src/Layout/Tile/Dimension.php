<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Tile;

/**
 * Dimension represents a width/height pair.
 * Mirrors tealeaves SizeHint.Min/SizeHint.Desired type.
 */
final class Dimension
{
    public function __construct(
        public readonly int $width = 0,
        public readonly int $height = 0,
    ) {}
}
