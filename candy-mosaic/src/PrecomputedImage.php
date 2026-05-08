<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * A precomputed rendered image — holds the encoded ANSI bytes and the
 * target cell dimensions so the caller knows the layout.
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
