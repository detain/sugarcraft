<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * A pixel-graphics image registered in a {@see View}'s image layer: the raw
 * protocol bytes plus the cell footprint they occupy. The footprint lets the
 * runtime know which cells an image covers, so it can surgically clear just
 * those cells when the image moves or disappears (instead of repainting the
 * whole screen).
 */
final readonly class ImagePlacement
{
    public function __construct(
        public string $bytes,
        public int $widthCells,
        public int $heightCells,
    ) {
    }
}
