<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Border configuration for data grid tables.
 *
 * Defines which borders are visible and their styles.
 */
final class BorderConfig
{
    public function __construct(
        private readonly bool $top = true,
        private readonly bool $bottom = true,
        private readonly bool $left = true,
        private readonly bool $right = true,
        private readonly bool $innerHorizontal = true,
        private readonly bool $innerVertical = true,
    ) {}

    public static function new(
        bool $top = true,
        bool $bottom = true,
        bool $left = true,
        bool $right = true,
        bool $innerHorizontal = true,
        bool $innerVertical = true,
    ): self {
        return new self(
            top: $top,
            bottom: $bottom,
            left: $left,
            right: $right,
            innerHorizontal: $innerHorizontal,
            innerVertical: $innerVertical,
        );
    }

    public static function none(): self
    {
        return new self(
            top: false,
            bottom: false,
            left: false,
            right: false,
            innerHorizontal: false,
            innerVertical: false,
        );
    }

    public static function all(): self
    {
        return new self();
    }

    public function hasTop(): bool
    {
        return $this->top;
    }

    public function hasBottom(): bool
    {
        return $this->bottom;
    }

    public function hasLeft(): bool
    {
        return $this->left;
    }

    public function hasRight(): bool
    {
        return $this->right;
    }

    public function hasInnerHorizontal(): bool
    {
        return $this->innerHorizontal;
    }

    public function hasInnerVertical(): bool
    {
        return $this->innerVertical;
    }
}
