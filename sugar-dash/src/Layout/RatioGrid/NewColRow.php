<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\RatioGrid;

/**
 * Represents a new column or row in a ratio grid.
 */
final class NewColRow
{
    public function __construct(
        public readonly float $ratio = 1.0,
        public readonly ?int $fixed = null,
    ) {}

    public static function ratio(float $ratio): self
    {
        return new self($ratio);
    }

    public static function fixed(int $size): self
    {
        return new self(0.0, $size);
    }
}
