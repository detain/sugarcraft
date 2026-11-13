<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * A precomputed rendered image — holds the encoded ANSI bytes and the
 * target cell dimensions so the caller knows the layout.
 *
 * Mirrors charmbracelet/x/mosaic's experimental image pipeline at concept
 * level: precomputed bytes replayed at a cell box. The value object is
 * upstream's idea in PHP; cache retention lives in AdaptiveImage, not
 * here.
 */
final class PrecomputedImage
{
    public function __construct(
        /** Encoded ANSI escape bytes ready to be written to the terminal. */
        private readonly string $bytes,
        /** Target cell width used during rendering. */
        private readonly int $cellWidth,
        /** Target cell height used during rendering. */
        private readonly int $cellHeight,
    ) {}

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function cellWidth(): int
    {
        return $this->cellWidth;
    }

    public function cellHeight(): int
    {
        return $this->cellHeight;
    }
}
