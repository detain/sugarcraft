<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * Snapshot of the detected terminal's image-rendering capabilities.
 * Immutable value object returned by {@see Detect::probe()}.
 */
final class Capability
{
    private function __construct(
        public readonly bool $sixel,
        public readonly bool $kitty,
        public readonly bool $iterm2,
        public readonly bool $halfblock,
        /** Cell pixel dimensions if probed, null otherwise. */
        public readonly ?CellSize $cellSize,
    ) {}

    /**
     * Build a full-capability instance (all protocols available).
     * Used for {@see Mosaic::halfBlock()} which is always available.
     */
    /**
     * Build a full-capability instance (all protocols available).
     * Used for {@see Mosaic::halfBlock()} which is always available.
     */
    public static function universal(?CellSize $cellSize = null): self
    {
        return new self(true, true, true, true, $cellSize);
    }

    /** Kitty-only capability (other protocols unknown until DA1 probing). */
    public static function kitty(?CellSize $cellSize = null): self
    {
        return new self(false, true, false, true, $cellSize);
    }

    /** iTerm2-only capability. */
    public static function iterm2(?CellSize $cellSize = null): self
    {
        return new self(false, false, true, true, $cellSize);
    }

    /** Sixel-only capability. */
    public static function sixel(?CellSize $cellSize = null): self
    {
        return new self(true, false, false, true, $cellSize);
    }

    /**
     * Empty capability set — used when no probe data is available.
     */
    public static function unknown(?CellSize $cellSize = null): self
    {
        return new self(false, false, false, true, $cellSize);
    }
}

/**
 * Terminal cell pixel dimensions (width × height in pixels).
 */
final class CellSize
{
    public function __construct(
        public readonly int $cellWidth,
        public readonly int $cellHeight,
    ) {}
}
