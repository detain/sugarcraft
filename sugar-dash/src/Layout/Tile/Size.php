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
        public readonly bool $optional = false,
        public readonly bool $minSizeFit = false,
    ) {}

    /**
     * Create a fill Size (flexible, weight 1.0).
     */
    public static function fill(): self
    {
        return new self(weight: 1.0);
    }

    /**
     * Create a fixed Size with explicit width and height.
     */
    public static function fixed(int $w, int $h): self
    {
        return new self(fixedWidth: $w, fixedHeight: $h, weight: 0.0);
    }

    /**
     * Create a flex Size with optional weight.
     */
    public static function flex(float $weight = 1.0): self
    {
        return new self(weight: $weight);
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
            optional: $this->optional,
            minSizeFit: $this->minSizeFit,
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
            optional: $this->optional,
            minSizeFit: $this->minSizeFit,
        );
    }

    public function withOptional(bool $optional): self
    {
        return new self(
            width: $this->width,
            height: $this->height,
            weight: $this->weight,
            minWidth: $this->minWidth,
            minHeight: $this->minHeight,
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            fixedWidth: $this->fixedWidth,
            fixedHeight: $this->fixedHeight,
            optional: $optional,
            minSizeFit: $this->minSizeFit,
        );
    }

    public function withMinSizeFit(bool $minSizeFit): self
    {
        return new self(
            width: $this->width,
            height: $this->height,
            weight: $this->weight,
            minWidth: $this->minWidth,
            minHeight: $this->minHeight,
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            fixedWidth: $this->fixedWidth,
            fixedHeight: $this->fixedHeight,
            optional: $this->optional,
            minSizeFit: $minSizeFit,
        );
    }

    public function withMinWidth(?int $minWidth): self
    {
        return new self(
            width: $this->width,
            height: $this->height,
            weight: $this->weight,
            minWidth: $minWidth,
            minHeight: $this->minHeight,
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            fixedWidth: $this->fixedWidth,
            fixedHeight: $this->fixedHeight,
            optional: $this->optional,
            minSizeFit: $this->minSizeFit,
        );
    }

    public function withMaxWidth(?int $maxWidth): self
    {
        return new self(
            width: $this->width,
            height: $this->height,
            weight: $this->weight,
            minWidth: $this->minWidth,
            minHeight: $this->minHeight,
            maxWidth: $maxWidth,
            maxHeight: $this->maxHeight,
            fixedWidth: $this->fixedWidth,
            fixedHeight: $this->fixedHeight,
            optional: $this->optional,
            minSizeFit: $this->minSizeFit,
        );
    }

    public function withWeight(float $weight): self
    {
        return new self(
            width: $this->width,
            height: $this->height,
            weight: $weight,
            minWidth: $this->minWidth,
            minHeight: $this->minHeight,
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            fixedWidth: $this->fixedWidth,
            fixedHeight: $this->fixedHeight,
            optional: $this->optional,
            minSizeFit: $this->minSizeFit,
        );
    }
}
