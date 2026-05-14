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

/**
 * SizeHint contains minimum and desired size for a widget.
 * Mirrors tealeaves tealayout_sizehinter.go SizeHint struct.
 */
final class SizeHint
{
    public function __construct(
        public readonly Dimension $min,
        public readonly Dimension $desired,
    ) {}

    public static function empty(): self
    {
        return new self(new Dimension(0, 0), new Dimension(0, 0));
    }
}
