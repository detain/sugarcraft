<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\RatioGrid;

/**
 * A grid item with ratio-based sizing.
 */
final class GridItem
{
    public function __construct(
        public readonly mixed $content,
        public readonly float $rowSpan = 1.0,
        public readonly float $colSpan = 1.0,
    ) {}

    public static function full(mixed $content): self
    {
        return new self($content, 1.0, 1.0);
    }
}
