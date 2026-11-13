<?php

declare(strict_types=1);

namespace SugarCraft\Constraints;

/**
 * Axis-aligned rectangle within a terminal grid.
 *
 * Mirrors ratatui's `Rect`.
 *
 * NOTE: this is intentionally NOT the same class as {@see \SugarCraft\CellBuffer\Region}
 * in sugar-cellbuffer — a deliberate leaf-package name collision so sugar-constraints
 * stays dependency-free. The two are converted at the boundary by
 * sugar-style's `RegionBridge`; do not conflate them.
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

    /**
     * Translate without resizing — replaces the 4-arg constructor re-spell
     * (sugar-style Solver.php:37 precedent) for callers that only ever move
     * a box. A negative destination is refused loudly by the same boundary
     * guard the constructor carries.
     */
    public function withOrigin(int $x, int $y): self
    {
        return new self($x, $y, $this->width, $this->height);
    }
}
