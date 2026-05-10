<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * An axis-aligned rectangle within a terminal grid.
 *
 * Mirrors ratatui's `Rect` — top-left corner (x,y) plus width/height.
 * Defined here in candy-core to keep it self-contained; consumers that
 * need more geometry helpers may use {@see \SugarCraft\Sprinkles\Layout\Rect}
 * which extends this type.
 */
final readonly class Rect
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {}

    /**
     * Create a 0,0 origin rect of the given dimensions.
     */
    public static function fromSize(int $width, int $height): self
    {
        return new self(0, 0, $width, $height);
    }
}
