<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Tile;

/**
 * Constraint definitions for tile sizing.
 */
final class Constraint
{
    public function __construct(
        public readonly ?int $minWidth = null,
        public readonly ?int $minHeight = null,
        public readonly ?int $maxWidth = null,
        public readonly ?int $maxHeight = null,
    ) {}

    public static function none(): self
    {
        return new self();
    }

    public static function fixed(int $width, int $height): self
    {
        return new self(
            minWidth: $width,
            minHeight: $height,
            maxWidth: $width,
            maxHeight: $height,
        );
    }
}
