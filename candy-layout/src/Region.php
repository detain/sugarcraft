<?php

declare(strict_types=1);

namespace SugarCraft\Layout;

/**
 * Own type to keep candy-layout a leaf package (no dep on candy-buffer).
 *
 * Mirrors ratatui's `Rect` — axis-aligned rectangle within a terminal grid.
 *
 * NOTE: this is intentionally NOT the same class as {@see \SugarCraft\Buffer\Region}
 * in candy-buffer — a deliberate leaf-package name collision so candy-layout
 * stays dependency-free. The two are converted at the boundary by
 * candy-sprinkles' `RegionBridge`; do not conflate them.
 */
final readonly class Region
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
        if ($x < 0 || $y < 0 || $width < 0 || $height < 0) {
            throw new \InvalidArgumentException(
                'Region components must be non-negative; '
                . "got x={$x}, y={$y}, width={$width}, height={$height}"
            );
        }
    }

    /**
     * Create a 0,0-origin region of the given dimensions.
     */
    public static function fromSize(int $width, int $height): self
    {
        return new self(0, 0, $width, $height);
    }
}
