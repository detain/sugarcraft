<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Tile;

/**
 * Size constraints for a tile.
 */
final class Size
{
    public function __construct(
        public readonly int $width = 0,
        public readonly int $height = 0,
        public readonly float $weight = 1.0,
        public readonly ?int $minWidth = null,
        public readonly ?int $minHeight = null,
        public readonly ?int $maxWidth = null,
        public readonly ?int $maxHeight = null,
        public readonly ?int $fixedWidth = null,
        public readonly ?int $fixedHeight = null,
    ) {}

    public static function fill(): self
    {
        return new self(weight: 1.0);
    }

    public function withWidth(int $width): self
    {
        return new self(
            width: $width,
            height: $this->height,
            weight: $this->weight,
            minWidth: $this->minWidth,
            minHeight: $this->minHeight,
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            fixedWidth: $this->fixedWidth,
            fixedHeight: $this->fixedHeight,
        );
    }

    public function withHeight(int $height): self
    {
        return new self(
            width: $this->width,
            height: $height,
            weight: $this->weight,
            minWidth: $this->minWidth,
            minHeight: $this->minHeight,
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            fixedWidth: $this->fixedWidth,
            fixedHeight: $this->fixedHeight,
        );
    }
}
