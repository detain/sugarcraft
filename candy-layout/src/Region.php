<?php

declare(strict_types=1);

namespace SugarCraft\Layout;

/**
 * Own type to keep candy-layout leaf (no dep on candy-buffer).
 *
 * Mirrors ratatui's `Rect` — axis-aligned rectangle within a terminal grid.
 */
final readonly class Region
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {}

    /**
     * Create a 0,0-origin region of the given dimensions.
     */
    public static function fromSize(int $width, int $height): self
    {
        return new self(0, 0, $width, $height);
    }
}
